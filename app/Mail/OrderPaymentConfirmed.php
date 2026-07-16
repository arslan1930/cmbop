<?php
// app/Mail/OrderPaymentConfirmed.php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class OrderPaymentConfirmed extends PlatformMailable
{

    public $order;
    public $user;
    public $totalAmount;
    public $orderItems;

    public function __construct(Order $order)
    {
        parent::__construct();
        $this->order = $order;
        $this->user = $order->user;
        $this->totalAmount = $order->total_amount;
        $this->orderItems = $order->items;
        
        Log::info('OrderPaymentConfirmed mail initialized', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_email' => $this->user->email ?? 'No email'
        ]);
    }

    public function build()
    {
        $subject = 'Payment Confirmed for Order #' . $this->order->order_number;
        
        return $this->subject($subject)
                    ->markdown('emails.order-payment-confirmed')
                    ->with([
                        'order' => $this->order,
                        'user' => $this->user,
                        'totalAmount' => $this->totalAmount,
                        'orderItems' => $this->orderItems,
                        'orderDate' => $this->order->created_at->format('F j, Y \a\t g:i A'),
                        'paidDate' => now()->format('F j, Y \a\t g:i A')
                    ]);
    }
}