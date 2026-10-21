<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

class ArticleRevisionRequestedMail extends PlatformMailable
{
    public function __construct(
        public User $recipient,
        public Order $order,
        public OrderItem $item,
        public string $reason,
    ) {
        parent::__construct();

        $this->notificationType = 'article_revision_requested';
        $this->recipientUser = $recipient;
        $this->dedupeKey = 'article_revision:'.$item->id.':'.md5($reason);
    }

    public function build()
    {
        return $this->subject('Revision requested — order #'.$this->order->order_number)
            ->markdown('emails.writing.article-revision-requested', [
                'firstName' => $this->firstName($this->recipient),
                'orderNumber' => $this->order->order_number,
                'siteName' => $this->item->site_name ?: 'the placement',
                'reason' => $this->reason,
                'brand' => $this->brand(),
            ]);
    }
}
