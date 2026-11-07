<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Support\MailNotificationPresenter;

class LiveUrlSubmitted extends PlatformMailable
{
    public $order;

    public $orderItem;

    public $site;

    public $liveUrl;

    public function __construct(Order $order, OrderItem $orderItem, Site $site, $liveUrl)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->liveUrl = $liveUrl;
        $this->recipientUser = $order->user;
    }

    public function build()
    {
        $order = $this->order->loadMissing(['user', 'items.site']);
        $safeUrl = MailNotificationPresenter::safeLiveUrl($this->liveUrl)
            ?: MailNotificationPresenter::safeLiveUrl($this->orderItem->live_url ?? null);

        if ($this->site && ! $this->orderItem->relationLoaded('site')) {
            $this->orderItem->setRelation('site', $this->site);
        }

        $placements = [[
            'site_label' => MailNotificationPresenter::siteLabel($this->orderItem),
            'site_name' => MailNotificationPresenter::siteName($this->orderItem),
            'domain' => MailNotificationPresenter::siteDomain($this->orderItem),
            'live_url' => $safeUrl,
            'pending' => $safeUrl === null,
        ]];

        $advertiser = $order->user;

        return $this->subject('Live URL submitted — order #'.$order->order_number)
            ->markdown('emails.publisher.live_url_submitted')
            ->with([
                'firstName' => $this->firstName($advertiser),
                'order' => $order,
                'orderItem' => $this->orderItem,
                'site' => $this->site,
                'placements' => $placements,
                'liveUrl' => $safeUrl,
                'autoApproveHours' => OrderItem::autoApproveHours(),
                'ctaUrl' => $this->advertiserOrdersUrl((int) $order->id),
                'supportLine' => MailNotificationPresenter::supportLine('order'),
                'brand' => $this->brand(),
            ]);
    }
}
