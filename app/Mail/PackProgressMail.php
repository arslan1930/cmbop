<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderPack;
use App\Support\MailNotificationPresenter;

class PackProgressMail extends PlatformMailable
{
    public OrderPack $pack;

    public Order $order;

    public bool $complete;

    public function __construct(OrderPack $pack, Order $order, bool $complete = false)
    {
        parent::__construct();
        $this->pack = $pack;
        $this->order = $order;
        $this->complete = $complete;
        $this->recipientUser = $order->user;
    }

    public function build()
    {
        $this->pack->loadMissing(['site', 'orders.items.site']);
        $size = max(1, (int) $this->pack->pack_size);
        $published = (int) $this->pack->published_count;
        $siteName = $this->pack->site?->site_name ?? 'your site';

        $placements = [];
        foreach ($this->pack->orders as $packOrder) {
            foreach ($packOrder->items as $item) {
                $url = MailNotificationPresenter::safeLiveUrl($item->live_url ?? null);
                $placements[] = [
                    'site_label' => MailNotificationPresenter::siteLabel($item),
                    'site_name' => MailNotificationPresenter::siteName($item),
                    'domain' => MailNotificationPresenter::siteDomain($item),
                    'live_url' => $url,
                    'pending' => $url === null,
                    'order_number' => $packOrder->order_number,
                ];
            }
        }

        $subject = $this->complete
            ? $this->pack->label().' complete'
            : $this->pack->label().' — '.$published.' of '.$size.' published';

        return $this->subject($subject)
            ->markdown('emails.advertiser.pack_progress')
            ->with([
                'pack' => $this->pack,
                'order' => $this->order,
                'complete' => $this->complete,
                'published' => $published,
                'packSize' => $size,
                'siteName' => $siteName,
                'placements' => $placements,
                'ordersUrl' => $this->advertiserOrdersUrl((int) $this->order->id, ['pack' => $this->pack->id]),
                'supportLine' => MailNotificationPresenter::supportLine('order'),
                'brand' => $this->brand(),
            ]);
    }
}
