<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerEmailActionToken extends Model
{
    protected $fillable = [
        'user_id',
        'order_item_id',
        'token_hash',
        'actions',
        'expires_at',
        'used_at',
        'revoked_at',
        'bound_email',
        'bound_password_hash',
        'created_ip',
        'used_ip',
        'used_user_agent',
    ];

    protected $casts = [
        'actions' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isOpen(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
