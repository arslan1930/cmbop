<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;

class OrderStatusChanged extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public User $recipient,
        public string $audience, // advertiser|publisher|admin|marketing
        public string $changeKind, // status|payment_status|created
        public ?string $previousValue,
        public string $newValue,
        public ?string $description = null,
    ) {
        parent::__construct();
        $this->notificationType = 'order_status_changed';
        $this->recipientUser = $recipient;
    }

    public function build()
    {
        $order = $this->order->loadMissing(['user', 'items.site.publisher']);
        $item = $order->items->first();
        $site = $item?->site;
        $firstName = $this->firstName($this->recipient);

        $labels = [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'review' => 'In Review',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'paid' => 'Paid',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
        ];

        $prevLabel = $this->previousValue
            ? ($labels[$this->previousValue] ?? ucfirst($this->previousValue))
            : '—';
        $newLabel = $labels[$this->newValue] ?? ucfirst($this->newValue);

        $subject = match ($this->changeKind) {
            'created' => 'New order #' . $order->order_number . ' created',
            'payment_status' => 'Payment update for order #' . $order->order_number . ' — ' . $newLabel,
            default => 'Order #' . $order->order_number . ' is now ' . $newLabel,
        };

        [$ctaUrl, $ctaLabel] = $this->ctaForAudience();

        $copy = $this->description ?: $this->defaultCopy($newLabel);

        return $this->subject($subject)
            ->markdown('emails.orders.status-changed')
            ->with([
                'firstName' => $firstName,
                'audience' => $this->audience,
                'changeKind' => $this->changeKind,
                'order' => $order,
                'item' => $item,
                'site' => $site,
                'publisherName' => $site?->publisher?->name ?? ($item?->site_name ? 'Publisher' : '—'),
                'advertiserName' => $order->user?->name ?? 'Advertiser',
                'previousLabel' => $prevLabel,
                'newLabel' => $newLabel,
                'previousValue' => $this->previousValue,
                'newValue' => $this->newValue,
                'copy' => $copy,
                'ctaUrl' => $ctaUrl,
                'ctaLabel' => $ctaLabel,
                'updatedAt' => now()->format('M j, Y g:i A'),
                'brand' => $this->brand(),
            ]);
    }

    protected function ctaForAudience(): array
    {
        return match ($this->audience) {
            'advertiser' => [url('/advertiser/orders'), 'View Order'],
            'publisher' => [url('/publisher/orders'), 'View Order'],
            'admin', 'marketing' => [
                url('/admin/payments/' . $this->order->id),
                'View Order Details',
            ],
            default => [url('/'), 'Open Platform'],
        };
    }

    protected function defaultCopy(string $newLabel): string
    {
        return match ($this->changeKind) {
            'created' => 'A new order has been created and is ready for tracking.',
            'payment_status' => "The payment status for this order is now {$newLabel}.",
            default => "The order status has been updated to {$newLabel}.",
        };
    }
}
