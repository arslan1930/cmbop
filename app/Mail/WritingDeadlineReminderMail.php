<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

class WritingDeadlineReminderMail extends PlatformMailable
{
    public function __construct(
        public User $recipient,
        public Order $order,
        public OrderItem $item,
    ) {
        parent::__construct();

        $this->notificationType = 'writing_deadline_reminder';
        $this->recipientUser = $recipient;
        $this->skipUserPreference = true;
        $this->dedupeKey = 'writing_deadline:'.$item->id.':'.optional($item->writing_deadline_at)?->toDateString();
    }

    public function build()
    {
        return $this->subject('Writing deadline soon — order #'.$this->order->order_number)
            ->markdown('emails.writing.deadline-reminder', [
                'firstName' => $this->firstName($this->recipient),
                'orderNumber' => $this->order->order_number,
                'siteName' => $this->item->site_name ?: 'the placement',
                'brand' => $this->brand(),
            ]);
    }
}
