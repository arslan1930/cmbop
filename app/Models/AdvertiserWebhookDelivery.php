<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertiserWebhookDelivery extends Model
{
    protected $fillable = [
        'event_id', 'endpoint_id', 'attempt', 'status', 'response_code',
        'error', 'next_attempt_at', 'delivered_at',
    ];

    protected $casts = [
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(AdvertiserWebhookEvent::class, 'event_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(AdvertiserWebhookEndpoint::class, 'endpoint_id');
    }
}
