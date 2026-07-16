<?php
// app/Mail/DepositRequestSubmitted.php

namespace App\Mail;

use App\Models\DepositRequest;

class DepositRequestSubmitted extends PlatformMailable
{
    
    public $deposit;
    public $user;
    
    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();
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