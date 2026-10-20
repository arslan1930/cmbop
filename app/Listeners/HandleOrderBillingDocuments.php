<?php

namespace App\Listeners;

use App\Models\Order;
use App\Services\Billing\BillingDocumentService;
use App\Services\Partners\PartnerSandbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hooks Order model events to generate invoices/receipts.
 * Does not modify payment gateway logic — reacts only to payment_status changes.
 * Runs after DB commit so wallet checkout can attach order items first.
 */
class HandleOrderBillingDocuments
{
    public function __construct(private BillingDocumentService $billing) {}

    public function created(Order $order): void
    {
        if (PartnerSandbox::isSandboxOrder($order)) {
            return;
        }
        $orderId = $order->id;
        $status = (string) $order->payment_status;

        $this->afterCommit(function () use ($orderId, $status) {
            try {
                $order = Order::with(['user', 'items'])->find($orderId);
                if (! $order) {
                    return;
                }

                if ($status === 'paid') {
                    $this->billing->handlePaymentPaid($order);

                    return;
                }

                if ($status === 'pending') {
                    $this->billing->handlePaymentPending($order);

                    return;
                }

                if ($status === 'failed') {
                    $this->billing->handlePaymentFailed($order);
                }
            } catch (\Throwable $e) {
                Log::warning('Billing documents on order created failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function updated(Order $order): void
    {
        if (PartnerSandbox::isSandboxOrder($order)) {
            return;
        }
        if (! $order->wasChanged('payment_status')) {
            return;
        }

        $orderId = $order->id;
        $from = (string) $order->getOriginal('payment_status');
        $to = (string) $order->payment_status;

        $this->afterCommit(function () use ($orderId, $from, $to) {
            try {
                $order = Order::with(['user', 'items'])->find($orderId);
                if (! $order) {
                    return;
                }

                match ($to) {
                    'paid' => $this->billing->handlePaymentPaid($order),
                    // Only paid→failed credits the wallet. An unpaid row that
                    // is already cancelled must still get a failure report —
                    // status===cancelled alone is not proof money moved.
                    'failed' => $from === 'paid' && $order->status === 'cancelled'
                        ? $this->billing->handlePaymentRefunded($order, 'Admin marked payment failed')
                        : $this->billing->handlePaymentFailed($order),
                    'refunded' => $this->billing->handlePaymentRefunded($order),
                    'pending' => $this->billing->handlePaymentPending($order),
                    default => null,
                };
            } catch (\Throwable $e) {
                Log::warning('Billing documents on order updated failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
