<?php

namespace App\Mail;

use App\Models\OrderItemDispute;
use App\Models\User;

class DisputeRefundAdvertiser extends PlatformMailable
{
    public function __construct(
        public OrderItemDispute $dispute,
        public User $advertiser,
        public float $credited,
    ) {
        parent::__construct();
        $this->notificationType = 'dispute_refund_advertiser';
        $this->recipientUser = $advertiser;
        $this->dedupeKey = 'dispute-refund-advertiser-'.$dispute->id;
    }

    public function build()
    {
        $order = $this->dispute->order;

        return $this->subject('Refund credited for order #'.($order->order_number ?? $this->dispute->order_id))
            ->markdown('emails.advertiser.dispute_refund', [
                'balanceUrl' => $this->publicRoute('advertiser.balance'),
            ]);
    }
}
