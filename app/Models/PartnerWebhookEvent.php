<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerWebhookEvent extends Model
{
    protected $fillable = ['partner_id', 'public_id', 'type', 'payload', 'livemode'];

    protected $casts = ['payload' => 'array', 'livemode' => 'boolean'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PartnerWebhookDelivery::class, 'event_id');
    }
}
