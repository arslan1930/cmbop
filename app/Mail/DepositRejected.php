<?php
// app/Mail/DepositRejected.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\DepositRequest;

class DepositRejected extends Mailable
{
    use Queueable, SerializesModels;
    
    public $deposit;
    
    public function __construct(DepositRequest $deposit)
    {
        $this->deposit = $deposit;
    }
    
    public function build()
    {
        return $this->subject('Deposit Request Update - ' . $this->deposit->reference_code)
                    ->markdown('emails.deposit-rejected');
    }
}