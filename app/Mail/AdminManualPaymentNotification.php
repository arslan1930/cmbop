<?php
// app/Mail/AdminManualPaymentNotification.php

namespace App\Mail;


class AdminManualPaymentNotification extends PlatformMailable
{

    public $customer;
    public $orders;
    public $paymentMethod;
    public $totalAmount;

    public function __construct($customer, $orders, $paymentMethod, $totalAmount)
    {
        parent::__construct();
        $this->customer = $customer;
        $this->orders = $orders;
        $this->paymentMethod = $paymentMethod;
        $this->totalAmount = $totalAmount;
    }

    public function build()
    {
        $orderNumbers = [];
        foreach ($this->orders as $order) {
            $orderNumbers[] = $order->order_number;
        }
        
        $paymentMethodText = '';
        switch($this->paymentMethod) {
            case 'wise': $paymentMethodText = 'Wise Transfer'; break;
            case 'crypto': $paymentMethodText = 'Cryptocurrency'; break;
            case 'bank': $paymentMethodText = 'Bank Transfer'; break;
            default: $paymentMethodText = ucfirst($this->paymentMethod);
        }
        
        return $this->subject('Manual Payment Required - New Order #' . $this->orders[0]->order_number)
                    ->markdown('emails.admin-manual-payment-notification')
                    ->with([
                        'customer' => $this->customer,
                        'orders' => $this->orders,
                        'paymentMethod' => $this->paymentMethod,
                        'paymentMethodText' => $paymentMethodText,
                        'totalAmount' => $this->totalAmount,
                        'orderNumbers' => implode(', ', $orderNumbers),
                        'orderCount' => count($this->orders),
                        'referenceCode' => $this->orders[0]->reference_code
                    ]);
    }
}