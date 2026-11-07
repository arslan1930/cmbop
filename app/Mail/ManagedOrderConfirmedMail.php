<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\MailNotificationPresenter;

class ManagedOrderConfirmedMail extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public string $bodyCopy,
    ) {
        parent::__construct();
        $this->recipientUser = $order->user;
    }

    public function build()
    {
        $this->order->loadMissing(['user', 'items.site']);
        $item = $this->order->items->first();

        return $this->subject('Order confirmed · Managed by SLB · #'.$this->order->order_number)
            ->markdown('emails.advertiser.managed-order-confirmed')
            ->with([
                'firstName' => $this->firstName($this->order->user),
                'order' => $this->order,
                'bodyCopy' => $this->bodyCopy,
                'placements' => $item ? [[
                    'site_label' => MailNotificationPresenter::siteLabel($item),
                    'site_name' => MailNotificationPresenter::siteName($item),
                    'domain' => MailNotificationPresenter::siteDomain($item),
                    'live_url' => null,
                    'pending' => true,
                ]] : [],
                'ctaUrl' => $this->advertiserOrdersUrl((int) $this->order->id),
                'supportLine' => MailNotificationPresenter::supportLine('order'),
                'brand' => $this->brand(),
            ]);
    }
}
