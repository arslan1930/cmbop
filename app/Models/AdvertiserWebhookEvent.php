<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdvertiserWebhookEvent extends Model
{
    protected $fillable = ['user_id', 'public_id', 'type', 'payload', 'livemode'];

    protected $casts = ['payload' => 'array', 'livemode' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AdvertiserWebhookDelivery::class, 'event_id');
    }
}
