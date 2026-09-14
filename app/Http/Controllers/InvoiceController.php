<?php

namespace App\Http\Controllers;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Billing\DepositReceiptService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    /**
     * Show invoice page for both deposits and orders
     */
    public function showInvoice(Request $request, $referenceCode, DepositReceiptService $receipts)
    {
        try {
            $userId = auth()->id();
            $user = auth()->user();

            // First check if it's a deposit
            $deposit = DepositRequest::where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->first();

            if ($deposit) {
                // Settled top-ups, and clawed-back receipts, belong on the PDF
                // — not the page of bank details telling them how to pay.
                $receipt = $receipts->find($deposit)
                    ?: ($receipts->isSettled($deposit) ? $receipts->issue($deposit) : null);
                if ($receipt) {
                    return redirect()->route(
                        $request->boolean('download') ? 'advertiser.billing.download' : 'advertiser.billing.view',
                        $receipt
                    );
                }

                $response = response()->view('advertiser.invoice', $this->depositInvoiceData($deposit, $user));
                if ($request->boolean('download')) {
                    $response->header(
                        'Content-Disposition',
                        'attachment; filename="invoice-REF'.$deposit->reference_code.'.html"'
                    );
                }

                return $response;
            }

            // Check if it's an order
            $order = Order::where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->with('items')
                ->first();

            if ($order) {
                // Prefer the PDF when one already exists — including refund /
                // failure docs so leftover HTML does not look payable.
                $document = $this->preferredOrderDocument($order, $userId);

                if ($document) {
                    return redirect()->route(
                        $request->boolean('download') ? 'advertiser.billing.download' : 'advertiser.billing.view',
                        $document
                    );
                }

                $response = response()->view('advertiser.invoice', $this->orderInvoiceData($order, $user));
                if ($request->boolean('download')) {
                    $response->header(
                        'Content-Disposition',
                        'attachment; filename="invoice-REF'.$order->reference_code.'.html"'
                    );
                }

                return $response;
            }

            return redirect()->route('advertiser.dashboard')
                ->with('error', 'Invoice not found');

        } catch (\Throwable $e) {
            Log::error('Error showing invoice: '.$e->getMessage());

            return redirect()->route('advertiser.dashboard')
                ->with('error', UserFacingError::message($e, 'Invoice not found'));
        }
    }

    private function preferredOrderDocument(Order $order, int $userId): ?Invoice
    {
        $payment = (string) $order->payment_status;
        $rank = match (true) {
            $payment === 'refunded' => [
                Invoice::TYPE_REFUND_RECEIPT => 0,
                Invoice::TYPE_TAX_INVOICE => 1,
                Invoice::TYPE_PAYMENT_RECEIPT => 2,
                Invoice::TYPE_PAYMENT_FAILURE => 3,
            ],
            $payment === 'failed' => [
                Invoice::TYPE_PAYMENT_FAILURE => 0,
                Invoice::TYPE_TAX_INVOICE => 1,
                Invoice::TYPE_REFUND_RECEIPT => 2,
                Invoice::TYPE_PAYMENT_RECEIPT => 3,
            ],
            default => [
                Invoice::TYPE_TAX_INVOICE => 0,
                Invoice::TYPE_REFUND_RECEIPT => 1,
                Invoice::TYPE_PAYMENT_FAILURE => 2,
                Invoice::TYPE_PAYMENT_RECEIPT => 3,
            ],
        };

        return Invoice::query()
            ->where('user_id', $userId)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where(function ($q) use ($order) {
                $q->where('order_id', $order->id)
                    ->orWhere('reference_code', $order->reference_code)
                    ->orWhere('order_number', $order->order_number);
            })
            ->get()
            ->sortBy(function (Invoice $invoice) use ($rank) {
                return ($rank[$invoice->type] ?? 9) * 1_000_000_000 - (int) $invoice->id;
            })
            ->first();
    }

    private function depositInvoiceData($deposit, $user): array
    {
        $status = (string) $deposit->status;
        $isClosed = in_array($status, ['rejected', 'refunded', 'cancelled', 'failed'], true);

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
            'status' => $status,
            'documentStatus' => $status,
            'isClosed' => $isClosed,
            'paymentMethod' => $deposit->payment_method,
            'orderDate' => $deposit->created_at,
            'orderItems' => [],
            'totalBaseAmount' => 0,
            'totalSensitiveAmount' => 0,
            'deposit' => $deposit,
            'canMarkPaid' => $isClosed ? false : $deposit->canUserMarkPaid(),
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
            'paymentStatus' => $order->payment_status,
            'documentStatus' => $order->payment_status === 'failed' || $order->payment_status === 'refunded'
                ? $order->payment_status
                : $order->status,
            'isClosed' => in_array((string) $order->payment_status, ['failed', 'refunded'], true)
                || $order->status === 'cancelled',
            'paymentMethod' => $order->payment_method,
            'orderDate' => $order->created_at,
            'orderItems' => $orderItems,
            'totalBaseAmount' => $totalBaseAmount,
            'totalSensitiveAmount' => $totalSensitiveAmount,
            'totalHomepageAmount' => $totalHomepageAmount,
        ];
    }
}
