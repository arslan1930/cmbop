<?php

namespace App\Http\Controllers;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Billing\DepositReceiptService;
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
                // Once a top-up has settled the customer wants the receipt, not
                // the page of bank details telling them how to pay it.
                if ($receipts->isSettled($deposit) && ($receipt = $receipts->issue($deposit))) {
                    return redirect()->to(route(
                        $request->boolean('download') ? 'advertiser.billing.download' : 'advertiser.billing.view',
                        $receipt,
                        false
                    ));
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
                    return redirect()->to(route(
                        $request->boolean('download') ? 'advertiser.billing.download' : 'advertiser.billing.view',
                        $taxInvoice,
                        false
                    ));
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

        } catch (\Exception $e) {
            Log::error('Error showing invoice: '.$e->getMessage());

            return redirect()->route('advertiser.dashboard')
                ->with('error', 'Invoice not found');
        }
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
            'markPaidUrl' => route('advertiser.add-funds.mark-paid', $deposit, false),
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
