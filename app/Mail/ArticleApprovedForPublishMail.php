<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

class ArticleApprovedForPublishMail extends PlatformMailable
{
    public function __construct(
        public User $publisher,
        public Order $order,
        public OrderItem $item,
    ) {
        parent::__construct();

        $this->notificationType = 'article_approved_for_publish';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'article_approved_publish:'.$item->id;
    }

    public function build()
    {
        return $this->subject('Article ready to publish — order #'.$this->order->order_number)
            ->markdown('emails.publisher.article-approved-for-publish', [
                'firstName' => $this->firstName($this->publisher),
                'orderNumber' => $this->order->order_number,
                'siteName' => $this->item->site_name ?: 'your site',
                'tasksUrl' => $this->publisherTasksUrl((int) $this->order->id),
                'brand' => $this->brand(),
            ]);
    }
}
