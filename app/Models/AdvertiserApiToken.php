<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdvertiserApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'token_prefix',
        'token_hash',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{plain: string, token: self}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = 'slb_'.Str::lower(Str::random(48));

        $token = static::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => hash('sha256', $plain),
        ]);

        return ['plain' => $plain, 'token' => $token];
    }

    public static function findByPlain(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'slb_')) {
            return null;
        }

        return static::query()->where('token_hash', hash('sha256', $plain))->first();
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
