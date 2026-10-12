<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class McpOAuthAccessToken extends Model
{
    protected $table = 'mcp_oauth_access_tokens';

    protected $fillable = [
        'client_id',
        'user_id',
        'token_prefix',
        'token_hash',
        'refresh_token_hash',
        'scopes',
        'expires_at',
        'refresh_expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
        'refresh_token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpOAuthClient::class, 'client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token: self}
     */
    public static function issue(McpOAuthClient $client, User $user, string $scopes = 'mcp:use'): array
    {
        $access = 'slb_oa_'.Str::lower(Str::random(48));
        $refresh = 'slb_rt_'.Str::lower(Str::random(48));

        $token = static::query()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'token_prefix' => substr($access, 0, 12),
            'token_hash' => hash('sha256', $access),
            'refresh_token_hash' => hash('sha256', $refresh),
            'scopes' => $scopes,
            'expires_at' => now()->addSeconds((int) config('mcp.access_token_ttl', 60 * 60 * 24 * 30)),
            'refresh_expires_at' => now()->addSeconds((int) config('mcp.refresh_token_ttl', 60 * 60 * 24 * 90)),
        ]);

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token' => $token,
        ];
    }

    public static function findUsableAccess(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'slb_oa_')) {
            return null;
        }

        $token = static::query()->where('token_hash', hash('sha256', $plain))->first();
        if ($token === null || $token->revoked_at !== null || $token->expires_at->isPast()) {
            return null;
        }

        return $token;
    }

    public static function findUsableRefresh(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'slb_rt_')) {
            return null;
        }

        $token = static::query()->where('refresh_token_hash', hash('sha256', $plain))->first();
        if ($token === null || $token->revoked_at !== null) {
            return null;
        }

        if ($token->refresh_expires_at !== null && $token->refresh_expires_at->isPast()) {
            return null;
        }

        return $token;
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
