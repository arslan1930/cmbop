<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

class OrderRejected extends PlatformMailable
{

    public $order;
    public $orderItem;
    public $site;
    public $reason;

    public function __construct(Order $order, OrderItem $orderItem, Site $site, $reason)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->reason = $reason;
    }

    public function build()
    {
        return $this->subject('Order Rejected - #' . $this->order->order_number)
                    ->markdown('emails.publisher.order_rejected');
    }
}