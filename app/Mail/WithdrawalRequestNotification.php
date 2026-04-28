<?php
// app/Mail/WithdrawalRequestNotification.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WithdrawalRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $withdrawal;
    public $user;
    public $platformChargePercent;

    public function __construct($withdrawal, $user)
    {
        $this->withdrawal = $withdrawal;
        $this->user = $user;
        $this->platformChargePercent = 18; // 18% platform charge
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