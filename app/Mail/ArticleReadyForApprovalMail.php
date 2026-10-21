<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

class ArticleReadyForApprovalMail extends PlatformMailable
{
    public function __construct(
        public User $advertiser,
        public Order $order,
        public OrderItem $item,
    ) {
        parent::__construct();

        $this->notificationType = 'article_ready_for_approval';
        $this->recipientUser = $advertiser;
        $this->dedupeKey = 'article_ready:'.$item->id.':'.($item->article_submitted_at?->timestamp ?? time());
    }

    public function build()
    {
        return $this->subject('Article ready for approval — order #'.$this->order->order_number)
            ->markdown('emails.advertiser.article-ready-for-approval', [
                'firstName' => $this->firstName($this->advertiser),
                'orderNumber' => $this->order->order_number,
                'siteName' => $this->item->site_name ?: 'your placement',
                'ordersUrl' => rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $this->order->id], false),
                'brand' => $this->brand(),
            ]);
    }
}
