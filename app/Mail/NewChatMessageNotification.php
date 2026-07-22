<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;

class NewChatMessageNotification extends PlatformMailable
{
    public Order $order;

    public User $sender;

    public string $message;

    public string $receiverName;

    public ?int $chatMessageId;

    public function __construct(
        Order $order,
        User $sender,
        string $message,
        string $receiverName,
        ?int $chatMessageId = null
    ) {
        parent::__construct();
        $this->notificationType = 'chat_message';
        $this->order = $order;
        $this->sender = $sender;
        $this->message = $message;
        $this->receiverName = $receiverName;
        $this->chatMessageId = $chatMessageId;
        $this->dedupeKey = $chatMessageId
            ? 'chat_message:'.$chatMessageId
            : 'chat_message:order:'.$order->id.':hash:'.sha1($message.'|'.$sender->id.'|'.microtime(true));
    }

    public function build()
    {
        $senderIsAdvertiser = (int) $this->order->user_id === (int) $this->sender->id;

        // Link opens the recipient's dashboard thread (opposite of sender).
        $url = $senderIsAdvertiser
            ? route('publisher.tasks', ['focus' => 'messages', 'order' => $this->order->id])
            : route('advertiser.orders', ['focus' => 'messages', 'order' => $this->order->id]);

        return $this->subject('New Message regarding Order #'.$this->order->order_number)
            ->markdown('emails.new-chat-message', [
                'order' => $this->order,
                'sender' => $this->sender,
                'message' => $this->message,
                'receiverName' => $this->receiverName,
                'url' => $url,
                'senderType' => $senderIsAdvertiser ? 'Advertiser' : 'Publisher',
            ]);
    }
}
