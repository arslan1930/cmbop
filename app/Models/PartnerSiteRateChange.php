<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerSiteRateChange extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'item_id',
        'old_values',
        'new_values',
        'source',
        'status',
        'decided_by',
        'decided_at',
        'effective_from',
        'slb_price_action',
        'decision_reason',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'decided_at' => 'datetime',
        'effective_from' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PartnerCatalogItem::class, 'item_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
