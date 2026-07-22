<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

class AutoApproveReminderMail extends PlatformMailable
{
    public Order $order;

    public OrderItem $orderItem;

    public ?Site $site;

    public int $hoursRemaining;

    public function __construct(Order $order, OrderItem $orderItem, ?Site $site, int $hoursRemaining)
    {
        parent::__construct();
        $this->notificationType = 'order_status_changed';
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->hoursRemaining = $hoursRemaining;
        $this->dedupeKey = 'auto_approve_reminder:'.$orderItem->id;
    }

    public function build()
    {
        $siteName = $this->site?->site_name ?? $this->orderItem->site_name ?? 'your placement';

        return $this->subject('1 day left to review order #'.$this->order->order_number)
            ->markdown('emails.advertiser.auto-approve-reminder', [
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'site' => $this->site,
                'siteName' => $siteName,
                'hoursRemaining' => $this->hoursRemaining,
                'liveUrl' => $this->orderItem->live_url,
                'ordersUrl' => route('advertiser.orders'),
            ]);
    }
}
