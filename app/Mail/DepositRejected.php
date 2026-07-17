<?php
// app/Mail/DepositRejected.php

namespace App\Mail;

use App\Models\DepositRequest;

class DepositRejected extends PlatformMailable
{
    
    public $deposit;
    
    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();
        $this->deposit = $deposit;
    }
    
    public function build()
    {
        return $this->subject('Deposit Request Update - ' . $this->deposit->reference_code)
                    ->markdown('emails.deposit-rejected');
    }
}