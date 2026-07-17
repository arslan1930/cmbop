<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

class OrderApprovedByAdvertiser extends PlatformMailable
{

    public $order;
    public $orderItem;
    public $site;
    public $basePrice;
    public $payoutAmount;

    public function __construct(Order $order, OrderItem $orderItem, Site $site)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        // Show publisher earnings (excludes the 15% platform fee)
        $this->basePrice = $orderItem->publisherBasePrice();
        $this->payoutAmount = $orderItem->publisherPayoutAmount();
    }

    public function build()
    {
        return $this->subject('Order Approved by Advertiser - #' . $this->order->order_number)
                    ->markdown('emails.advertiser.order_approved_publisher');
    }
}