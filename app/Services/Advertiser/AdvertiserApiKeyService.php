<?php

namespace App\Services\Advertiser;

use App\Models\AdvertiserApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AdvertiserApiKeyService
{
    public const SCOPES = [
        'catalog:read',
        'sites:read',
        'wallet:read',
        'orders:read',
        'orders:write',
        'webhooks:write',
    ];

    public const LEGACY_SCOPES = ['catalog:read'];

    /**
     * @param  list<string>  $scopes
     * @return array{plain: string, token: AdvertiserApiToken}
     */
    public function issue(User $user, string $name, array $scopes = [], string $mode = 'live'): array
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        $plain = 'slb_'.$mode.'_'.Str::lower(Str::random(8)).'_'.Str::lower(Str::random(32));

        $token = AdvertiserApiToken::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => AdvertiserApiToken::hashPlain($plain),
            'scopes' => array_values(array_intersect($scopes !== [] ? $scopes : self::SCOPES, self::SCOPES)),
            'mode' => $mode,
        ]);

        return ['plain' => $plain, 'token' => $token];
    }

    public function findByPlain(string $plain): ?AdvertiserApiToken
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'slb_')) {
            return null;
        }

        $token = AdvertiserApiToken::query()->where('token_hash', AdvertiserApiToken::hashPlain($plain))->first();
        if (! $token || ! $token->isActive()) {
            return null;
        }

        if ($token->mode === null || $token->mode === '') {
            $token->mode = str_starts_with($plain, 'slb_test_') ? 'test' : 'live';
        }

        return $token;
    }

    public function revoke(AdvertiserApiToken $token): void
    {
        if (AdvertiserApiToken::query()->getModel()->isFillable('revoked_at')
            || Schema::hasColumn('advertiser_api_tokens', 'revoked_at')) {
            $token->revoked_at = now();
            $token->save();

            return;
        }

        $token->delete();
    }

    public function assertKnownScope(string $scope): void
    {
        if (! in_array($scope, self::SCOPES, true) && $scope !== '*') {
            throw new RuntimeException('Unknown advertiser API scope: '.$scope);
        }
    }
}
