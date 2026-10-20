<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerLinkIncident extends Model
{
    protected $fillable = [
        'order_item_id', 'partner_id', 'failure_code', 'restore_by',
        'restored_at', 'resolved', 'fail_count',
    ];

    protected $casts = [
        'restore_by' => 'datetime',
        'restored_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved === null;
    }
}
