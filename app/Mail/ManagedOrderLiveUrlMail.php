<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\MailNotificationPresenter;

class ManagedOrderLiveUrlMail extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public OrderItem $orderItem,
        public string $bodyCopy,
    ) {
        parent::__construct();
        $this->recipientUser = $order->user;
    }

    public function build()
    {
        $this->order->loadMissing(['user', 'items.site']);
        $safeUrl = MailNotificationPresenter::safeLiveUrl($this->orderItem->live_url);

        return $this->subject('Your article is live · #'.$this->order->order_number)
            ->markdown('emails.advertiser.managed-order-live-url')
            ->with([
                'firstName' => $this->firstName($this->order->user),
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'bodyCopy' => $this->bodyCopy,
                'placements' => [[
                    'site_label' => MailNotificationPresenter::siteLabel($this->orderItem),
                    'site_name' => MailNotificationPresenter::siteName($this->orderItem),
                    'domain' => MailNotificationPresenter::siteDomain($this->orderItem),
                    'live_url' => $safeUrl,
                    'pending' => $safeUrl === null,
                ]],
                'ctaUrl' => $this->advertiserOrdersUrl((int) $this->order->id),
                'supportLine' => MailNotificationPresenter::supportLine('order'),
                'brand' => $this->brand(),
            ]);
    }
}
