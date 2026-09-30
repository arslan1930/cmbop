<?php

namespace App\Http\Controllers;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Billing\DepositReceiptService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    /**
     * Show invoice page for both deposits and orders
     */
    public function downloadPdf($referenceCode, DepositReceiptService $receipts, InvoicePdfGenerator $pdfs)
    {
        $userId = auth()->id();
        $user = auth()->user();

        $deposit = DepositRequest::where('reference_code', $referenceCode)
            ->where('user_id', $userId)
            ->first();

        if ($deposit) {
            if ($receipts->isSettled($deposit) && ($receipt = $receipts->issue($deposit))) {
                return $pdfs->download($receipt);
            }

            return $pdfs->attachment(
                $this->pendingDepositDocument($deposit, $user),
                $this->pdfFilename((string) $deposit->reference_code)
            );
        }

        $order = Order::where('reference_code', $referenceCode)
            ->where('user_id', $userId)
            ->with('items')
            ->first();

        if ($order) {
            $taxInvoice = Invoice::query()
                ->where('user_id', $userId)
                ->where('type', Invoice::TYPE_TAX_INVOICE)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->where(function ($q) use ($order) {
                    $q->where('order_id', $order->id)
                        ->orWhere('reference_code', $order->reference_code)
                        ->orWhere('order_number', $order->order_number);
                })
                ->latest('id')
                ->first();

            if ($taxInvoice) {
                return $pdfs->download($taxInvoice);
            }

            return $pdfs->attachment(
                $this->pendingOrderDocument($order, $user),
                $this->pdfFilename((string) $order->reference_code)
            );
        }

        return redirect()->route('advertiser.add-funds')
            ->with('error', 'Invoice not found');
    }

    public function showInvoice(Request $request, $referenceCode, DepositReceiptService $receipts, InvoicePdfGenerator $pdfs)
    {
        try {
            $userId = auth()->id();
            $user = auth()->user();

            // First check if it's a deposit
            $deposit = DepositRequest::where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->first();

            if ($deposit) {
                // Once a top-up has settled the customer wants the receipt, not
                // the page of bank details telling them how to pay it.
                if ($receipts->isSettled($deposit) && ($receipt = $receipts->issue($deposit))) {
                    return redirect()->route(
                        $request->boolean('download') ? 'advertiser.billing.download' : 'advertiser.billing.view',
                        $receipt
                    );
                }

                if ($request->boolean('download')) {
                    return $this->downloadPdf($referenceCode, $receipts, $pdfs);
                }

                return response()->view('advertiser.invoice', $this->depositInvoiceData($deposit, $user));
            }

            // Check if it's an order
            $order = Order::where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->with('items')
                ->first();

            if ($order) {
                // Prefer the PDF tax invoice when one already exists for this order/ref.
                $taxInvoice = Invoice::query()
                    ->where('user_id', $userId)
                    ->where('type', Invoice::TYPE_TAX_INVOICE)
                    ->where('status', '!=', Invoice::STATUS_CANCELLED)
                    ->where(function ($q) use ($order) {
                        $q->where('order_id', $order->id)
                            ->orWhere('reference_code', $order->reference_code)
                            ->orWhere('order_number', $order->order_number);
                    })
                    ->latest('id')
                    ->first();

                if ($taxInvoice) {
                    return redirect()->route(
                        $request->boolean('download') ? 'advertiser.billing.download' : 'advertiser.billing.view',
                        $taxInvoice
                    );
                }

                if ($request->boolean('download')) {
                    return $this->downloadPdf($referenceCode, $receipts, $pdfs);
                }

                return response()->view('advertiser.invoice', $this->orderInvoiceData($order, $user));
            }

            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Invoice not found');

        } catch (\Throwable $e) {
            Log::error('Error showing invoice: '.$e->getMessage());

            return redirect()->route('advertiser.add-funds')
                ->with('error', UserFacingError::message($e, 'Invoice not found'));
        }
    }

    private function pdfFilename(string $reference): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $reference) ?: 'invoice';

        return 'invoice-REF'.$safe.'.pdf';
    }

    private function pendingDepositDocument($deposit, $user): Invoice
    {
        $amount = round((float) $deposit->amount, 2);

        return new Invoice([
            'invoice_number' => 'REF'.$deposit->reference_code,
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'status' => Invoice::STATUS_PENDING,
            'user_id' => $user->id,
            'reference_code' => $deposit->reference_code,
            'currency' => 'EUR',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'payment_method' => $deposit->payment_method,
            'payment_status' => 'pending',
            'invoice_date' => $deposit->created_at ?? now(),
            'customer_name' => $user->billing_name ?? $user->name,
            'customer_email' => $user->email,
            'billing_snapshot' => [
                'company' => $user->company_name ?? null,
                'address' => $user->address ?? null,
                'city' => $user->city ?? null,
                'state' => $user->state ?? null,
                'postal_code' => $user->postal_code ?? null,
                'country' => $user->country ?? null,
                'vat_number' => $user->vat_number ?? null,
            ],
            'line_items' => [[
                'description' => 'Wallet top-up',
                'reference' => $deposit->reference_code,
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ]],
            'notes' => $this->pendingDepositNote($deposit),
        ]);
    }

    private function pendingDepositNote($deposit): string
    {
        $pay = config('billing.deposit_payment', []);
        $lines = [
            'Waiting for payment. The wallet is credited after this transfer is confirmed. Put REF'.$deposit->reference_code.' in the payment note.',
        ];
        $method = (string) $deposit->payment_method;

        if (in_array($method, ['wise', 'bank'], true)) {
            if (! empty($pay['beneficiary'])) {
                $lines[] = 'Beneficiary: '.$pay['beneficiary'].'.';
            }
            if (! empty($pay['uk_sort_code'])) {
                $lines[] = 'Sort code (from the UK): '.$pay['uk_sort_code'].'.';
            }
            if (! empty($pay['uk_account_number'])) {
                $lines[] = 'GBP account (from the UK): '.$pay['uk_account_number'].'.';
            }
            if (! empty($pay['iban'])) {
                $lines[] = 'IBAN (from outside the UK): '.$pay['iban'].'.';
            }
            if (! empty($pay['bic'])) {
                $lines[] = 'Swift/BIC (from outside the UK): '.$pay['bic'].'.';
            }
        }

        if ($method === 'crypto') {
            if (! empty($pay['crypto']['note'])) {
                $lines[] = $pay['crypto']['note'];
            }
            foreach ($pay['crypto']['networks'] ?? [] as $network) {
                if (! empty($network['address'])) {
                    $lines[] = ($network['label'] ?? 'Address').': '.$network['address'].'.';
                }
            }
        }

        return implode(' ', $lines);
    }

    private function pendingOrderDocument($order, $user): Invoice
    {
        $data = $this->orderInvoiceData($order, $user);
        $amount = round((float) $data['amount'], 2);
        $lines = [];
        foreach ($data['orderItems'] as $item) {
            $price = round((float) ($item['price'] ?? 0), 2);
            $lines[] = [
                'description' => $item['site_name'] ?? 'Guest post',
                'publisher_website' => $item['site_url'] ?? '',
                'quantity' => 1,
                'unit_price' => $price,
                'line_total' => $price,
            ];
        }

        return new Invoice([
            'invoice_number' => 'REF'.$order->reference_code,
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => ($order->payment_status ?? '') === 'paid' ? Invoice::STATUS_PAID : Invoice::STATUS_PENDING,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'reference_code' => $order->reference_code,
            'order_number' => $order->order_number,
            'currency' => 'EUR',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status ?: 'pending',
            'invoice_date' => $order->created_at ?? now(),
            'paid_at' => ($order->payment_status ?? '') === 'paid' ? ($order->paid_at ?? $order->created_at) : null,
            'customer_name' => $data['billingName'],
            'customer_email' => $data['userEmail'],
            'billing_snapshot' => [
                'company' => $data['companyName'] ?: null,
                'address' => $data['address'] ?: null,
                'city' => $data['city'] ?: null,
                'state' => $data['state'] ?: null,
                'postal_code' => $data['postalCode'] ?: null,
                'country' => $data['country'] ?: null,
                'vat_number' => $data['vatNumber'] ?: null,
            ],
            'line_items' => $lines,
        ]);
    }

    private function depositInvoiceData($deposit, $user): array
    {
        return [
            'invoiceType' => 'deposit',
            'referenceCode' => $deposit->reference_code,
            'amount' => $deposit->amount,
            'billingName' => $user->billing_name ?? $user->name,
            'companyName' => $user->company_name ?? '',
            'country' => $user->country ?? '',
            'state' => $user->state ?? '',
            'city' => $user->city ?? '',
            'address' => $user->address ?? '',
            'postalCode' => $user->postal_code ?? '',
            'vatNumber' => $user->vat_number ?? '',
            'userName' => $user->name,
            'userEmail' => $user->email,
            'userId' => $user->id,
            'status' => $deposit->status,
            'paymentMethod' => $deposit->payment_method,
            'orderDate' => $deposit->created_at,
            'orderItems' => [],
            'totalBaseAmount' => 0,
            'totalSensitiveAmount' => 0,
            'deposit' => $deposit,
            'canMarkPaid' => $deposit->canUserMarkPaid(),
            'userMarkedPaid' => $deposit->userHasMarkedPaid(),
            'markPaidUrl' => route('advertiser.add-funds.mark-paid', $deposit),
        ];
    }

    private function orderInvoiceData($order, $user): array
    {
        $orderItems = [];
        $totalBaseAmount = 0;
        $totalSensitiveAmount = 0;
        $totalHomepageAmount = 0;

        foreach ($order->items as $item) {
            $additionalPrice = (float) ($item->additional_price ?? 0);
            $homepagePrice = (float) ($item->homepage_price ?? 0);
            $basePrice = max(0, (float) $item->price - $additionalPrice - $homepagePrice);
            $totalBaseAmount += $basePrice;
            $totalSensitiveAmount += $additionalPrice;
            $totalHomepageAmount += $homepagePrice;

            $orderItems[] = [
                'site_name' => $item->site_name,
                'site_url' => $item->site_url,
                'price' => $item->price,
                'base_price' => $basePrice,
                'additional_price' => $additionalPrice,
                'homepage_days' => $item->homepage_days,
                'homepage_price' => $homepagePrice,
                'social_channels' => $item->enabledSocialChannels(),
                'sensitive_type' => $item->sensitive_type,
                'content_link' => $item->content_link,
                'live_url' => $item->live_url ?? '',
            ];
        }

        return [
            'invoiceType' => 'order',
            'referenceCode' => $order->reference_code,
            'amount' => $order->total_amount,
            'billingName' => $user->billing_name ?? $user->name,
            'companyName' => $user->company_name ?? '',
            'country' => $user->country ?? '',
            'state' => $user->state ?? '',
            'city' => $user->city ?? '',
            'address' => $user->address ?? '',
            'postalCode' => $user->postal_code ?? '',
            'vatNumber' => $user->vat_number ?? '',
            'userName' => $user->name,
            'userEmail' => $user->email,
            'userId' => $user->id,
            'status' => $order->status,
            'paymentMethod' => $order->payment_method,
            'orderDate' => $order->created_at,
            'orderItems' => $orderItems,
            'totalBaseAmount' => $totalBaseAmount,
            'totalSensitiveAmount' => $totalSensitiveAmount,
            'totalHomepageAmount' => $totalHomepageAmount,
        ];
    }
}
