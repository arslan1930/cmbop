<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerOrderCorrection extends Model
{
    public const PENDING_RELAY = 'pending_relay';

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'order_item_id', 'number', 'instructions', 'requested_by_message_id',
        'relayed_by', 'status', 'due_by', 'resolved_at', 'decline_reason',
    ];

    protected $casts = [
        'due_by' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
