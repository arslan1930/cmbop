<?php
// app/Mail/DepositApproved.php

namespace App\Mail;

use App\Models\DepositRequest;

class DepositApproved extends PlatformMailable
{
    
    public $deposit;
    
    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();
        $this->deposit = $deposit;
    }
    
    public function build()
    {
        return $this->subject('Deposit Approved - €' . number_format($this->deposit->amount, 2))
                    ->markdown('emails.deposit-approved');
    }
}