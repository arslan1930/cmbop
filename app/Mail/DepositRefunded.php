<?php

namespace App\Mail;

use App\Models\DepositRequest;

class DepositRefunded extends PlatformMailable
{
    public DepositRequest $deposit;

    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();

        $deposit->loadMissing('user');
        $this->deposit = $deposit;
        $this->notificationType = 'deposit_refunded';
        $this->recipientUser = $deposit->user;
        $this->dedupeKey = 'deposit_refunded:'.$deposit->id;
    }

    public function build()
    {
        $deposit = $this->deposit->loadMissing('user');
        $amount = number_format((float) $deposit->amount, 2);
        $methodLabel = $deposit->paymentMethodLabel();

        return $this->subject($methodLabel.' deposit refunded — €'.$amount)
            ->markdown('emails.deposit-refunded', [
                'deposit' => $deposit,
                'debt' => $deposit->refundDebtCreated(),
                'methodLabel' => $methodLabel,
                'balanceUrl' => route('advertiser.balance'),
            ]);
    }
}
