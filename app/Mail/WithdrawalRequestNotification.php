<?php
// app/Mail/WithdrawalRequestNotification.php

namespace App\Mail;


class WithdrawalRequestNotification extends PlatformMailable
{

    public $withdrawal;
    public $user;
    public $platformChargePercent;

    public function __construct($withdrawal, $user)
    {
        parent::__construct();
        $this->withdrawal = $withdrawal;
        $this->user = $user;
        $this->platformChargePercent = (float) config('billing.withdrawal_fee_percent', 0);
    }

    public function build()
    {
        return $this->subject('New Withdrawal Request - €' . number_format($this->withdrawal->amount, 2))
                    ->markdown('emails.publisher.withdrawal-request')
                    ->with([
                        'withdrawal' => $this->withdrawal,
                        'user' => $this->user,
                        'platformChargePercent' => $this->platformChargePercent
                    ]);
    }
}