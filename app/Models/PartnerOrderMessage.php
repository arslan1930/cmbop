<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class PartnerOrderMessage extends Model
{
    protected $fillable = [
        'order_item_id', 'author_type', 'author_id', 'item_type', 'visibility',
        'body_masked', 'body_original_encrypted', 'masked', 'mask_rules',
        'relay_status', 'edited_at',
    ];

    protected $hidden = ['body_original_encrypted'];

    protected $casts = [
        'masked' => 'boolean',
        'mask_rules' => 'array',
        'edited_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PartnerOrderAttachment::class, 'message_id');
    }

    public function originalBody(): string
    {
        $raw = (string) $this->body_original_encrypted;
        if ($raw === '') {
            return (string) $this->body_masked;
        }
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return $raw;
        }
    }
}
