<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbandonedCartReminderSend extends Model
{
    use ToleratesMissingSchema;

    public const STAGE_1 = 1;

    public const STAGE_2 = 2;

    protected $fillable = [
        'user_id',
        'cart_fingerprint',
        'stage',
        'sent_at',
    ];

    protected $casts = [
        'stage' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function alreadySent(int $userId, string $fingerprint, int $stage): bool
    {
        if ($fingerprint === '' || ! static::tableAvailable()) {
            return false;
        }

        try {
            return static::query()
                ->where('user_id', $userId)
                ->where('cart_fingerprint', $fingerprint)
                ->where('stage', $stage)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function record(int $userId, string $fingerprint, int $stage): void
    {
        if ($fingerprint === '' || ! static::tableAvailable()) {
            return;
        }

        try {
            static::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'cart_fingerprint' => $fingerprint,
                    'stage' => $stage,
                ],
                ['sent_at' => now()]
            );
        } catch (\Throwable) {
        }
    }
}
