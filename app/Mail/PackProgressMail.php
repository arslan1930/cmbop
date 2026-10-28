<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderPack;

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
    }

    public function build()
    {
        $size = max(1, (int) $this->pack->pack_size);
        $published = (int) $this->pack->published_count;
        $siteName = $this->pack->site?->site_name ?? 'your site';

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
                'ordersUrl' => route('advertiser.orders', ['pack' => $this->pack->id]),
            ]);
    }
}
