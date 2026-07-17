<?php

namespace App\Listeners;

use App\Models\Order;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out order lifecycle emails to Advertiser, Publisher, Marketing, and Admin.
 * Hooks model events only — does not change controller business logic.
 */
class SendOrderLifecycleEmails
{
    public function __construct(private EmailNotificationService $emails)
    {
    }

    public function created(Order $order): void
    {
        try {
            $order->loadMissing(['user', 'items.site.publisher']);

            $description = $this->createdDescription($order);
            if (($order->publication_mode ?? '') === 'scheduled' && $order->scheduled_publish_at) {
                $description = 'Order created and charged. Scheduled publication: '
                    . $order->scheduled_publish_at->timezone($order->schedule_timezone ?: 'UTC')->format('d F Y g:i A')
                    . ' ' . ($order->schedule_timezone ?: 'UTC')
                    . '. Publisher should publish on this date.';
            }

            $this->emails->notifyOrderLifecycle(
                order: $order,
                changeKind: 'created',
                previousValue: null,
                newValue: (string) $order->status,
                description: $description,
            );

            // If created already paid (wallet), also announce payment to all roles.
            if ($order->payment_status === 'paid') {
                $this->emails->notifyOrderLifecycle(
                    order: $order,
                    changeKind: 'payment_status',
                    previousValue: 'pending',
                    newValue: 'paid',
                    description: 'Payment was successful for this order.',
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Order created lifecycle email failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updated(Order $order): void
    {
        try {
            $order->loadMissing(['user', 'items.site.publisher']);

            if ($order->wasChanged('status')) {
                $from = (string) $order->getOriginal('status');
                $to = (string) $order->status;
                if ($from !== $to) {
                    $this->emails->notifyOrderLifecycle(
                        order: $order,
                        changeKind: 'status',
                        previousValue: $from,
                        newValue: $to,
                        description: $this->statusDescription($from, $to),
                    );
                }
            }

            if ($order->wasChanged('payment_status')) {
                $from = (string) $order->getOriginal('payment_status');
                $to = (string) $order->payment_status;
                if ($from !== $to) {
                    $this->emails->notifyOrderLifecycle(
                        order: $order,
                        changeKind: 'payment_status',
                        previousValue: $from,
                        newValue: $to,
                        description: $this->paymentDescription($from, $to),
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Order updated lifecycle email failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function createdDescription(Order $order): string
    {
        if ($order->payment_status === 'paid') {
            return 'A new paid order has been created and is waiting for the next step.';
        }

        return 'A new order has been created. Payment status: ' . ucfirst((string) $order->payment_status) . '.';
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
            'failed' => 'Payment failed. Please review the order and retry if needed.',
            'refunded' => 'A refund has been processed for this order.',
            'pending' => 'Payment is pending.',
            default => "Payment status changed from {$from} to {$to}.",
        };
    }
}
