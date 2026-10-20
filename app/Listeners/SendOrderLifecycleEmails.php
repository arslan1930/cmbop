<?php

namespace App\Listeners;

use App\Models\Order;
use App\Services\EmailNotificationService;
use App\Services\Partners\PartnerSandbox;
use App\Support\OrderLifecycleMailSuppressor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out order lifecycle emails to Advertiser, Publisher, Marketing, and Admin.
 * Runs after DB commit so order items (and publishers) exist on create.
 *
 * Dedicated mailables (OrderAccepted / OrderRejected / LiveUrlSubmitted) already
 * cover the advertiser for some transitions — those paths call
 * OrderLifecycleMailSuppressor so we do not double-send OrderStatusChanged.
 */
class SendOrderLifecycleEmails
{
    public function __construct(
        private EmailNotificationService $emails,
        private OrderLifecycleMailSuppressor $suppressor,
    ) {}

    public function created(Order $order): void
    {
        if (PartnerSandbox::isSandboxOrder($order)) {
            return;
        }
        $orderId = $order->id;

        $this->afterCommit(function () use ($orderId) {
            try {
                $order = Order::with(['user', 'items.site.publisher'])->find($orderId);
                if (! $order) {
                    return;
                }

                // Snapshot+clear now so a no-op status update (or rollback) cannot
                // leave a sticky skip for a later unrelated lifecycle mail.
                $skip = $this->suppressor->pull($orderId);

                $description = $this->createdDescription($order);
                if (($order->publication_mode ?? '') === 'scheduled' && $order->scheduled_publish_at) {
                    $description = 'Order created and charged. Scheduled publication: '
                        .$order->scheduled_publish_at->timezone($order->schedule_timezone ?: 'UTC')->format('d F Y g:i A')
                        .' '.($order->schedule_timezone ?: 'UTC')
                        .'. Publisher should publish on this date.';
                }

                $this->emails->notifyOrderLifecycle(
                    order: $order,
                    changeKind: 'created',
                    previousValue: null,
                    newValue: (string) $order->status,
                    description: $description,
                    skipAudiences: $skip,
                );

                // If created already paid (wallet), also announce payment to all roles.
                if ($order->payment_status === 'paid') {
                    $this->emails->notifyOrderLifecycle(
                        order: $order,
                        changeKind: 'payment_status',
                        previousValue: 'pending',
                        newValue: 'paid',
                        description: 'Payment was successful for this order.',
                        skipAudiences: $skip,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Order created lifecycle email failed', [
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
        if (! $order->wasChanged('status') && ! $order->wasChanged('payment_status')) {
            return;
        }

        $orderId = $order->id;
        $statusChanged = $order->wasChanged('status');
        $statusFrom = $statusChanged ? (string) $order->getOriginal('status') : null;
        $statusTo = $statusChanged ? (string) $order->status : null;
        $paymentChanged = $order->wasChanged('payment_status');
        $paymentFrom = $paymentChanged ? (string) $order->getOriginal('payment_status') : null;
        $paymentTo = $paymentChanged ? (string) $order->payment_status : null;
        // Capture before afterCommit so callers can clear leftovers on no-op paths.
        $skip = $this->suppressor->pull($orderId);

        $this->afterCommit(function () use ($orderId, $statusFrom, $statusTo, $paymentFrom, $paymentTo, $skip) {
            try {
                $order = Order::with(['user', 'items.site.publisher'])->find($orderId);
                if (! $order) {
                    return;
                }

                if ($statusFrom !== null && $statusFrom !== $statusTo) {
                    $this->emails->notifyOrderLifecycle(
                        order: $order,
                        changeKind: 'status',
                        previousValue: $statusFrom,
                        newValue: $statusTo,
                        description: $this->statusDescription($statusFrom, $statusTo),
                        skipAudiences: $skip,
                    );
                }

                if ($paymentFrom !== null && $paymentFrom !== $paymentTo) {
                    $this->emails->notifyOrderLifecycle(
                        order: $order,
                        changeKind: 'payment_status',
                        previousValue: $paymentFrom,
                        newValue: $paymentTo,
                        description: $this->paymentDescription($paymentFrom, $paymentTo),
                        skipAudiences: $skip,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Order updated lifecycle email failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    protected function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }

    protected function createdDescription(Order $order): string
    {
        if ($order->payment_status === 'paid') {
            return 'A new paid order has been created and is waiting for the next step.';
        }

        return 'A new order has been created. Payment status: '.ucfirst((string) $order->payment_status).'.';
    }

    protected function statusDescription(string $from, string $to): string
    {
        return match ($to) {
            'processing' => $from === 'review'
                ? 'Revision was requested — the order is back in processing.'
                : 'Great news — the publisher accepted this order and work can begin.',
            'review' => 'The guest post / live URL was submitted and is ready for review.',
            'completed' => 'This order has been completed successfully.',
            'cancelled' => 'This order was cancelled.',
            'pending' => 'This order is pending the next action.',
            default => "Order status changed from {$from} to {$to}.",
        };
    }

    protected function paymentDescription(string $from, string $to): string
    {
        return match ($to) {
            'paid' => 'Payment was successful.',
            'failed' => $from === 'paid'
                ? 'This payment was reversed and the amount was returned to the advertiser wallet.'
                : 'Payment failed. Please review the order and retry if needed.',
            'refunded' => 'A refund has been processed for this order.',
            'pending' => 'Payment is pending.',
            default => "Payment status changed from {$from} to {$to}.",
        };
    }
}
