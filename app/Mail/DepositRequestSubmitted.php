<?php
// app/Mail/DepositRequestSubmitted.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\DepositRequest;

class DepositRequestSubmitted extends Mailable
{
    use Queueable, SerializesModels;
    
    public $deposit;
    public $user;
    
    public function __construct(DepositRequest $deposit)
    {
        $this->deposit = $deposit;
        $this->user = $deposit->user;
    }
    
    public function build()
    {
        return $this->subject('New Deposit Request - €' . number_format($this->deposit->amount, 2))
                    ->markdown('emails.deposit-request-submitted')
                    ->with([
                        'deposit' => $this->deposit,
                        'user' => $this->user,
                        'adminUrl' => route('admin.deposits.show', $this->deposit->id),
                    ]);
    }
}