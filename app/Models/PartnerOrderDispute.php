<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerOrderDispute extends Model
{
    public const OPEN = 'open';

    public const RESOLVED_PARTNER = 'resolved_partner';

    public const RESOLVED_BUYER = 'resolved_buyer';

    protected $fillable = [
        'order_item_id', 'opened_by_type', 'correction_id', 'reason', 'message',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
