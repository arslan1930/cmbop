<?php

namespace App\Mail;

use App\Models\Order;

class PaymentPendingMail extends PlatformMailable
{
    public function __construct(public Order $order)
    {
        parent::__construct();
        $this->notificationType = 'payment_pending';
        $this->recipientUser = $order->user;
        $this->dedupeKey = 'payment_pending:'.$order->id.':'.now()->format('YmdHi');
    }

    public function build()
    {
        $hours = (int) config('billing.pending_verification_hours', 24);
        $symbol = config('billing.currency_symbol', '€');

        return $this->subject('Payment Pending Verification')
            ->markdown('emails.billing.payment-pending', [
                'order' => $this->order,
                'user' => $this->order->user,
                'hours' => $hours,
                'symbol' => $symbol,
                'statusUrl' => route('advertiser.orders'),
            ]);
    }
}
