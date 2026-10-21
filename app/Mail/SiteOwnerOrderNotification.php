<?php

// app/Mail/SiteOwnerOrderNotification.php

namespace App\Mail;

use App\Models\User;

class SiteOwnerOrderNotification extends PlatformMailable
{
    public $site;

    public $orders;

    public $publisher;

    public function __construct($site, $orders)
    {
        parent::__construct();
        $this->site = $site;
        $this->orders = $orders;
        $this->publisher = $site->relationLoaded('publisher') && $site->publisher
            ? $site->publisher
            : User::find($site->publisher_id);
    }

    public function build()
    {
        $publisherPayout = 0;
        $orderNumbers = [];

        foreach ($this->orders as $order) {
            $order->loadMissing('items');
            foreach ($order->items as $item) {
                $publisherPayout += $item->publisherPayoutAmount();
            }
            $orderNumbers[] = $order->order_number;
        }

        // Get publisher name safely
        $publisherName = 'Publisher';
        if ($this->publisher) {
            $publisherName = $this->publisher->name;
        }

        $firstOrderId = null;
        foreach ($this->orders as $order) {
            if (isset($order->id)) {
                $firstOrderId = (int) $order->id;
                break;
            }
        }

        return $this->subject('New Order for Your Site: '.$this->site->site_name)
            ->markdown('emails.site-owner-order-notification')
            ->with([
                'site' => $this->site,
                'orders' => $this->orders,
                'publisherPayout' => $publisherPayout,
                'orderNumbers' => implode(', ', $orderNumbers),
                'orderCount' => count($this->orders),
                'publisherName' => $publisherName,
                'publisher' => $this->publisher,
                'tasksUrl' => $this->publisherTasksUrl($firstOrderId),
            ]);
    }
}
