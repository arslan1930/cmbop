<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

class OrderAccepted extends PlatformMailable
{

    public $order;
    public $orderItem;
    public $site;
    public $basePrice;

    public function __construct(Order $order, OrderItem $orderItem, Site $site)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->basePrice = $orderItem->price - ($orderItem->additional_price ?? 0);
    }

    public function build()
    {
        return $this->subject('Order Accepted - #' . $this->order->order_number)
                    ->markdown('emails.publisher.order_accepted');
    }
}