<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;

/**
 * Escalation: the automated cadence has run out of patience and a person needs
 * to look. Sent per admin (PlatformMailable's default dedupe key includes the
 * recipient) so nobody is dropped.
 */
class AdminStalledOrderAlert extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public OrderItem $orderItem,
        public ?Site $site,
        public ?User $publisher,
        public int $stage,
        public int $hoursOverdue,
        public string $track,
    ) {
        parent::__construct();

        $this->notificationType = 'admin_stalled_order';
    }

    public function build()
    {
        $siteName = $this->site?->site_name ?: ($this->orderItem->site_name ?: 'unknown site');
        $days = max(1, (int) round($this->hoursOverdue / 24));

        $subject = $this->track === 'accept'
            ? '[Admin] Order #'.$this->order->order_number.' unaccepted for '.$days.' day(s)'
            : '[Admin] Order #'.$this->order->order_number.' overdue by '.$days.' day(s)';

        return $this->subject($subject)
            ->markdown('emails.admin.stalled-order', [
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'siteName' => $siteName,
                'publisher' => $this->publisher,
                'stage' => $this->stage,
                'days' => $days,
                'track' => $this->track,
                'adminUrl' => $this->publicRoute('admin.orders.show', $this->order->id),
                'brand' => $this->brand(),
            ]);
    }
}
