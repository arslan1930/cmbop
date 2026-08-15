<?php

namespace App\Mail;

use App\Models\OrderItemDispute;
use App\Models\User;

class DisputeClawbackPublisher extends PlatformMailable
{
    public function __construct(
        public OrderItemDispute $dispute,
        public User $publisher,
        public float $debited,
        public float $debtCreated,
    ) {
        parent::__construct();
        $this->notificationType = 'dispute_clawback_publisher';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'dispute-clawback-publisher-'.$dispute->id;
    }

    public function build()
    {
        $order = $this->dispute->order;

        return $this->subject('Clawback on order #'.($order->order_number ?? $this->dispute->order_id))
            ->markdown('emails.publisher.dispute_clawback', [
                'balanceUrl' => $this->publicRoute('publisher.balance'),
            ]);
    }
}
