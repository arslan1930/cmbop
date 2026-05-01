<?php
// app/Mail/NewChatMessageNotification.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\User;

class NewChatMessageNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $sender;
    public $message;
    public $receiverName;

    public function __construct(Order $order, User $sender, $message, $receiverName)
    {
        $this->order = $order;
        $this->sender = $sender;
        $this->message = $message;
        $this->receiverName = $receiverName;
    }

    public function build()
    {
        // Check if user has roles and get the first role name
        $senderType = 'User';
        if ($this->sender->roles && $this->sender->roles->count() > 0) {
            $firstRole = $this->sender->roles->first();
            $senderType = $firstRole->name ?? 'User';
        }
        
        // Determine URL based on sender type (not role)
        // Advertiser = order.user_id matches sender id
        $isAdvertiser = $this->order->user_id === $this->sender->id;
        $url = $isAdvertiser ? url('/') : url('/');
        
        return $this->subject('New Message regarding Order #' . $this->order->order_number)
                    ->markdown('emails.new-chat-message')
                    ->with([
                        'url' => $url,
                        'senderType' => $isAdvertiser ? 'Advertiser' : 'Publisher'
                    ]);
    }
}