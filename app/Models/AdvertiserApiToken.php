<?php

namespace App\Models;

use App\Services\Advertiser\AdvertiserApiKeyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdvertiserApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'token_prefix',
        'token_hash',
        'scopes',
        'mode',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{plain: string, token: self}
     */
    public static function issue(User $user, string $name, array $scopes = [], string $mode = 'live'): array
    {
        if (Schema::hasColumn((new static)->getTable(), 'mode')) {
            return app(AdvertiserApiKeyService::class)->issue(
                $user,
                $name,
                $scopes !== [] ? $scopes : AdvertiserApiKeyService::LEGACY_SCOPES,
                $mode
            );
        }

        $plain = 'slb_'.Str::lower(Str::random(48));
        $token = static::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => static::hashPlain($plain),
        ]);

        return ['plain' => $plain, 'token' => $token];
    }

    public static function findByPlain(string $plain): ?self
    {
        return app(AdvertiserApiKeyService::class)->findByPlain($plain)
            ?? static::legacyFind($plain);
    }

    public static function hashPlain(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function isActive(): bool
    {
        if (Schema::hasColumn($this->getTable(), 'revoked_at') && $this->revoked_at) {
            return false;
        }

        return true;
    }

    public function isTest(): bool
    {
        return ($this->mode ?? 'live') === 'test';
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->effectiveScopes();

        return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
    }

    /**
     * @return list<string>
     */
    public function effectiveScopes(): array
    {
        $scopes = $this->scopes;
        if (! is_array($scopes) || $scopes === []) {
            return AdvertiserApiKeyService::LEGACY_SCOPES;
        }

        return array_values($scopes);
    }

    public function markUsed(?string $ip = null): void
    {
        $payload = ['last_used_at' => now()];
        if ($ip !== null && Schema::hasColumn($this->getTable(), 'last_used_ip')) {
            $payload['last_used_ip'] = substr($ip, 0, 45);
        }
        $this->forceFill($payload)->save();
    }

    private static function legacyFind(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'slb_')) {
            return null;
        }

        $token = static::query()->where('token_hash', static::hashPlain($plain))->first();
        if (! $token || ! $token->isActive()) {
            return null;
        }

        return $token;
    }
}
