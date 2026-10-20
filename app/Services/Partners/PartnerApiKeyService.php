<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerApiKey;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class PartnerApiKeyService
{
    public const SCOPES = [
        'sites:read', 'sites:write', 'orders:read', 'orders:write',
        'balance:read', 'payouts:write', 'webhooks:write',
    ];

    /**
     * @param  list<string>  $scopes
     * @return array{plain: string, key: PartnerApiKey}
     */
    public function issue(Partner $partner, string $name, array $scopes, string $mode = 'live', ?User $actor = null): array
    {
        $active = PartnerApiKey::query()
            ->where('partner_id', $partner->id)
            ->whereNull('revoked_at')
            ->count();
        if ($active >= 2) {
            throw new RuntimeException('A partner may have at most two active keys.');
        }
        $plain = 'slb_'.$mode.'_'.Str::lower(Str::random(8)).'_'.Str::lower(Str::random(32));
        $key = PartnerApiKey::query()->create([
            'partner_id' => $partner->id,
            'name' => $name,
            'prefix' => substr($plain, 0, 16),
            'key_hash' => $this->hash($plain),
            'scopes' => array_values(array_intersect($scopes !== [] ? $scopes : self::SCOPES, self::SCOPES)),
            'mode' => $mode,
            'created_by' => $actor?->id,
        ]);

        return ['plain' => $plain, 'key' => $key];
    }

    public function findByPlain(string $plain): ?PartnerApiKey
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'slb_')) {
            return null;
        }
        $key = PartnerApiKey::query()->where('key_hash', $this->hash($plain))->first();
        if (! $key || ! $key->isActive()) {
            return null;
        }

        return $key;
    }

    public function revoke(PartnerApiKey $key): void
    {
        $key->revoked_at = now();
        $key->save();
    }

    public function hash(string $plain): string
    {
        return hash('sha256', $plain.'|'.(string) config('app.key'));
    }
}
