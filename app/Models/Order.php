<?php
// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 
        'order_number', 
        'reference_code',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_response',
        'paid_at',
        'subtotal', 
        'tax', 
        'total_amount', 
        'payment_method', 
        'payment_status', 
        'status',
        'sensitive_type',
        'additional_price',
        'last_chat_message',     // Add this
        'last_chat_at'           // Add this
    ];

    protected $casts = [
        'stripe_response' => 'array',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'additional_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'last_chat_at' => 'datetime'  // Add this
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
    
    /**
     * Get all chat messages for this order
     */
    public function chatMessages()
    {
        return $this->hasMany(OrderChatMessage::class)->orderBy('created_at', 'asc');
    }
    
    /**
     * Get unread chat messages for this order
     */
    public function unreadChatMessages($userId, $userType)
    {
        return $this->chatMessages()
            ->where('is_read', false)
            ->where('user_id', '!=', $userId)
            ->when($userType === 'advertiser', function($q) {
                $q->where('sender_type', 'publisher');
            })
            ->when($userType === 'publisher', function($q) {
                $q->where('sender_type', 'advertiser');
            });
    }
    
    /**
     * Get the latest chat message
     */
    public function getLatestChatMessageAttribute()
    {
        return $this->chatMessages()->latest()->first();
    }
    
    /**
     * Get unread count for this order
     */
    public function getUnreadChatCountAttribute()
    {
        $user = auth()->user();
        if (!$user) return 0;
        
        $isAdvertiser = $this->user_id === $user->id;
        $userType = $isAdvertiser ? 'advertiser' : 'publisher';
        
        return $this->unreadChatMessages($user->id, $userType)->count();
    }
    
    // Helper method to get base price
    public function getBasePriceAttribute()
    {
        return $this->subtotal - $this->additional_price;
    }
    
    // Helper method to check if order has sensitive pricing
    public function hasSensitivePricing()
    {
        return !is_null($this->sensitive_type) && $this->additional_price > 0;
    }
}