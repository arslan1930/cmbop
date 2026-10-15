<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Carbon;

class LinkGuaranteeRestoreMail extends PlatformMailable
{
    public function __construct(
        public User $publisher,
        public Order $order,
        public OrderItem $item,
        public Carbon $restoreDueAt,
    ) {
        parent::__construct();

        $this->notificationType = 'link_guarantee_restore';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'link_guarantee_restore:'.$order->id.':'.$restoreDueAt->toDateString();
    }

    public function build()
    {
        $due = $this->restoreDueAt->timezone(config('app.timezone'))->format('j M Y');

        return $this->subject('Restore the live link for order #'.$this->order->order_number)
            ->markdown('emails.publisher.link-guarantee-restore', [
                'firstName' => $this->firstName($this->publisher),
                'orderNumber' => $this->order->order_number,
                'siteName' => $this->item->site_name ?: 'your site',
                'due' => $due,
                'tasksUrl' => $this->publisherTasksUrl((int) $this->order->id),
                'brand' => $this->brand(),
            ]);
    }
}
