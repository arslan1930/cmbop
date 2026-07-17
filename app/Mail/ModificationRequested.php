<?php
// app/Mail/ModificationRequested.php

namespace App\Mail;

use App\Models\Order;

class ModificationRequested extends PlatformMailable
{

    public $order;
    public $reason;

    public function __construct(Order $order, $reason)
    {
        parent::__construct();
        $this->order = $order;
        $this->reason = $reason;
    }

    public function build()
    {
        return $this->subject('Modification Requested for Order #' . $this->order->order_number)
                    ->markdown('emails.publisher.modification_requested');
    }
}