<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class AdvertiserWebhookEndpoint extends Model
{
    protected $fillable = [
        'user_id', 'url', 'events', 'secret_encrypted', 'status',
        'failure_streak', 'disabled_at', 'description',
    ];

    protected $hidden = ['secret_encrypted'];

    protected $casts = [
        'events' => 'array',
        'disabled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEnabled(): bool
    {
        return $this->status === 'enabled';
    }

    public function matchesEvent(string $type): bool
    {
        $events = $this->events ?? [];
        foreach ($events as $pattern) {
            if ($pattern === $type || $pattern === '*') {
                return true;
            }
            if (str_ends_with((string) $pattern, '.*')) {
                $prefix = substr((string) $pattern, 0, -1);
                if (str_starts_with($type, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function plainSecret(): string
    {
        try {
            return Crypt::decryptString((string) $this->secret_encrypted);
        } catch (\Throwable) {
            return (string) $this->secret_encrypted;
        }
    }
}
