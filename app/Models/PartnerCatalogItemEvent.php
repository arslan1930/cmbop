<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCatalogItemEvent extends Model
{
    protected $fillable = [
        'item_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'reason',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PartnerCatalogItem::class, 'item_id');
    }
}
