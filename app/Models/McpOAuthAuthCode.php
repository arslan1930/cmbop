<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class McpOAuthAuthCode extends Model
{
    protected $table = 'mcp_oauth_auth_codes';

    protected $fillable = [
        'client_id',
        'user_id',
        'code_hash',
        'code_challenge',
        'code_challenge_method',
        'redirect_uri',
        'scopes',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
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
     * @return array{plain: string, code: self}
     */
    public static function issue(
        McpOAuthClient $client,
        User $user,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod = 'S256',
        string $scopes = 'mcp:use',
    ): array {
        $plain = Str::lower(Str::random(64));

        $code = static::query()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $plain),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'expires_at' => now()->addSeconds((int) config('mcp.auth_code_ttl', 600)),
        ]);

        return ['plain' => $plain, 'code' => $code];
    }

    public static function findUsable(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        $code = static::query()->where('code_hash', hash('sha256', $plain))->first();
        if ($code === null || $code->used_at !== null || $code->expires_at->isPast()) {
            return null;
        }

        return $code;
    }

    public function matchesPkce(string $verifier): bool
    {
        $verifier = trim($verifier);
        if ($verifier === '' || strtoupper($this->code_challenge_method) !== 'S256') {
            return false;
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($this->code_challenge, $expected);
    }

    public function markUsed(): void
    {
        $this->forceFill(['used_at' => now()])->save();
    }
}
