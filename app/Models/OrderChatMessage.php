<?php
// app/Models/OrderChatMessage.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChatMessage extends Model
{
    protected $table = 'order_chat_messages';
    
    protected $fillable = [
        'order_id',
        'user_id',
        'sender_type',
        'message',
        'images',
        'is_read',
        'read_at'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'images' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnreadForUser($query, $userId, $userType)
    {
        return $query->where('is_read', false)
            ->where('user_id', '!=', $userId)
            ->when($userType === 'advertiser', function($q) {
                $q->where('sender_type', 'publisher');
            })
            ->when($userType === 'publisher', function($q) {
                $q->where('sender_type', 'advertiser');
            });
    }

    public function markAsRead()
    {
        $this->update([
            'is_read' => true,
            'read_at' => now()
        ]);
    }

    public function getPreviewAttribute()
    {
        if ($this->message) {
            return strip_tags(substr($this->message, 0, 100)) . (strlen($this->message) > 100 ? '...' : '');
        }
        if ($this->images) {
            return '📷 ' . count($this->images) . ' image(s)';
        }
        return '';
    }
}