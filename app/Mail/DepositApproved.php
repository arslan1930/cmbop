<?php
// app/Mail/DepositApproved.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\DepositRequest;

class DepositApproved extends Mailable
{
    use Queueable, SerializesModels;
    
    public $deposit;
    
    public function __construct(DepositRequest $deposit)
    {
        $this->deposit = $deposit;
    }
    
    public function build()
    {
        return $this->subject('Deposit Approved - €' . number_format($this->deposit->amount, 2))
                    ->markdown('emails.deposit-approved');
    }
}