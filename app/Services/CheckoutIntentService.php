<?php

namespace App\Services;

use App\Models\CheckoutIntent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Durable Stripe-first checkout packages and leftover-bonus holds.
 * Cache alone expires before Stripe Checkout (6–12h vs ~24h) and dies on flush.
 */
class CheckoutIntentService
{
    public static function pendingCheckoutCacheKey(string $referenceCode): string
    {
        return 'pending_card_checkout:'.$referenceCode;
    }

    public static function bonusCacheKey(int $userId, string $referenceCode): string
    {
        return 'checkout_bonus:'.$userId.':'.$referenceCode;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function storePackage(string $referenceCode, array $package, int $hours = 48): void
    {
        $newUserId = isset($package['user_id']) ? (int) $package['user_id'] : 0;
        $this->forgetExpiredForeignIntent($referenceCode, $newUserId);
        $existingUserId = $this->ownerIdForReference($referenceCode);
        if ($existingUserId > 0 && $newUserId > 0 && $existingUserId !== $newUserId) {
            throw new \RuntimeException('Checkout package already belongs to another user for ref '.$referenceCode);
        }

        Cache::put(self::pendingCheckoutCacheKey($referenceCode), $package, now()->addHours($hours));

        $bonus = round((float) ($package['bonus_applied'] ?? 0), 2);
        $userId = isset($package['user_id']) ? (int) $package['user_id'] : null;

        $this->upsertIntent($referenceCode, [
            'user_id' => $userId,
            'package' => $package,
            'bonus_applied' => $bonus,
            'expires_at' => now()->addHours($hours),
        ]);

        if ($userId && $bonus > 0) {
            Cache::put(self::bonusCacheKey($userId, $referenceCode), $bonus, now()->addHours($hours));
        }
    }

    public function rememberBonus(int $userId, string $referenceCode, float $amount, int $hours = 720): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        Cache::put(self::bonusCacheKey($userId, $referenceCode), $amount, now()->addHours($hours));

        $existing = $this->findIntent($referenceCode);
        $expiresAt = now()->addHours($hours);
        if ($existing?->expires_at && $existing->expires_at->greaterThan($expiresAt)) {
            $expiresAt = $existing->expires_at;
        }

        $this->upsertIntent($referenceCode, [
            'user_id' => $userId,
            'bonus_applied' => $amount,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPackage(string $referenceCode): ?array
    {
        $cached = Cache::get(self::pendingCheckoutCacheKey($referenceCode));
        if (is_array($cached)) {
            return $cached;
        }

        $intent = $this->findFreshIntent($referenceCode);
        $package = is_array($intent?->package) ? $intent->package : null;
        if ($package) {
            $ttl = $intent->expires_at ? now()->diffInSeconds($intent->expires_at, false) : 3600;
            if ($ttl > 0) {
                Cache::put(self::pendingCheckoutCacheKey($referenceCode), $package, now()->addSeconds($ttl));
            }
        }

        return $package;
    }

    /**
     * Pull the reserved bonus for this reference (cache, durable row, then fallback).
     */
    public function takeBonus(int $userId, string $referenceCode, ?float $fallback = null): float
    {
        $fromCache = round((float) Cache::pull(self::bonusCacheKey($userId, $referenceCode), 0), 2);
        $intent = $this->intentOwnedBy($referenceCode, $userId);
        $fromRow = $intent ? round((float) $intent->bonus_applied, 2) : 0.0;
        $fromPackage = is_array($intent?->package)
            ? round((float) ($intent->package['bonus_applied'] ?? 0), 2)
            : 0.0;
        $cachedPackage = Cache::get(self::pendingCheckoutCacheKey($referenceCode));
        $fromCachedPackage = $this->cachedPackageBonusForUser($cachedPackage, $userId);
        $bonus = max($fromCache, $fromRow, $fromPackage, $fromCachedPackage, round((float) ($fallback ?? 0), 2));

        if ($intent && ($fromRow > 0 || $fromPackage > 0)) {
            $updates = ['bonus_applied' => 0];
            if ($fromPackage > 0) {
                $package = is_array($intent->package) ? $intent->package : [];
                $package['bonus_applied'] = 0;
                $updates['package'] = $package;
            }
            $intent->update($updates);
        }
        if (is_array($cachedPackage) && $fromCachedPackage > 0) {
            $cachedPackage['bonus_applied'] = 0;
            Cache::put(self::pendingCheckoutCacheKey($referenceCode), $cachedPackage, now()->addHours(48));
        }

        return $bonus > 0 ? $bonus : 0.0;
    }

    /**
     * Bonus still recorded for this reference (cache, row, or package JSON).
     */
    public function recordedBonus(int $userId, string $referenceCode, ?float $fallback = null): float
    {
        $fromCache = round((float) Cache::get(self::bonusCacheKey($userId, $referenceCode), 0), 2);
        $intent = $this->intentOwnedBy($referenceCode, $userId);
        $fromRow = $intent ? round((float) $intent->bonus_applied, 2) : 0.0;
        $fromPackage = is_array($intent?->package)
            ? round((float) ($intent->package['bonus_applied'] ?? 0), 2)
            : 0.0;
        $cachedPackage = Cache::get(self::pendingCheckoutCacheKey($referenceCode));
        $fromCachedPackage = $this->cachedPackageBonusForUser($cachedPackage, $userId);

        return max($fromCache, $fromRow, $fromPackage, $fromCachedPackage, round((float) ($fallback ?? 0), 2));
    }

    /**
     * Promo still held for this user's other checkout references.
     */
    public function otherRecordedBonus(int $userId, string $exceptReference): float
    {
        if ($userId <= 0 || ! $this->tableReady()) {
            return 0.0;
        }

        $total = 0.0;
        $intents = CheckoutIntent::query()
            ->where('user_id', $userId)
            ->where('reference_code', '!=', $exceptReference)
            ->get();

        foreach ($intents as $intent) {
            if ($intent->expires_at && $intent->expires_at->isPast()) {
                continue;
            }

            $fromRow = round((float) $intent->bonus_applied, 2);
            $fromPackage = is_array($intent->package)
                ? round((float) ($intent->package['bonus_applied'] ?? 0), 2)
                : 0.0;
            $fromCache = round((float) Cache::get(self::bonusCacheKey($userId, (string) $intent->reference_code), 0), 2);
            $held = max($fromRow, $fromPackage, $fromCache);
            if ($held <= 0) {
                continue;
            }
            $total += $held;
        }

        return round($total, 2);
    }

    /**
     * Reduce leftover checkout bonus without clearing the rest of the reference.
     */
    public function decrementBonus(int $userId, string $referenceCode, float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $userId <= 0 || $referenceCode === '') {
            return;
        }

        $intent = $this->intentOwnedBy($referenceCode, $userId);
        $fromRow = $intent ? round((float) $intent->bonus_applied, 2) : 0.0;
        $fromCache = round((float) Cache::get(self::bonusCacheKey($userId, $referenceCode), 0), 2);
        $fromPackage = is_array($intent?->package)
            ? round((float) ($intent->package['bonus_applied'] ?? 0), 2)
            : 0.0;
        $current = max($fromRow, $fromCache, $fromPackage);
        $left = max(0, round($current - $amount, 2));

        if ($intent) {
            $package = is_array($intent->package) ? $intent->package : [];
            $package['bonus_applied'] = $left;
            $intent->update([
                'bonus_applied' => $left,
                'package' => $package,
            ]);
        }

        $key = self::bonusCacheKey($userId, $referenceCode);
        if ($left > 0) {
            Cache::put($key, $left, now()->addHours(720));
        } else {
            Cache::forget($key);
        }
    }

    public function forgetBonus(int $userId, string $referenceCode): void
    {
        Cache::forget(self::bonusCacheKey($userId, $referenceCode));
        $intent = $this->intentOwnedBy($referenceCode, $userId);
        if ($intent && ((float) $intent->bonus_applied > 0
            || (float) ($intent->package['bonus_applied'] ?? 0) > 0)) {
            $package = is_array($intent->package) ? $intent->package : [];
            $package['bonus_applied'] = 0;
            $intent->update([
                'bonus_applied' => 0,
                'package' => $package,
            ]);
        }
    }

    public function forget(string $referenceCode, ?int $userId = null): void
    {
        $ownerId = $this->ownerIdForReference($referenceCode);
        if ($userId && $userId > 0 && $ownerId > 0 && $ownerId !== $userId) {
            return;
        }

        Cache::forget(self::pendingCheckoutCacheKey($referenceCode));
        if ($userId) {
            Cache::forget(self::bonusCacheKey($userId, $referenceCode));
        }

        $intent = $this->findIntent($referenceCode);
        if ($intent) {
            if (! $userId && $intent->user_id) {
                Cache::forget(self::bonusCacheKey((int) $intent->user_id, $referenceCode));
            }
            $intent->delete();
        }
    }

    /**
     * @param  mixed  $cachedPackage
     */
    private function cachedPackageBonusForUser($cachedPackage, int $userId): float
    {
        if (! is_array($cachedPackage)) {
            return 0.0;
        }

        $ownerId = (int) ($cachedPackage['user_id'] ?? 0);
        if ($ownerId > 0 && $userId > 0 && $ownerId !== $userId) {
            return 0.0;
        }

        return round((float) ($cachedPackage['bonus_applied'] ?? 0), 2);
    }

    private function ownerIdForReference(string $referenceCode): int
    {
        $cached = Cache::get(self::pendingCheckoutCacheKey($referenceCode));
        if (is_array($cached) && isset($cached['user_id'])) {
            return (int) $cached['user_id'];
        }

        $intent = $this->findFreshIntent($referenceCode);

        return (int) ($intent?->user_id ?? 0);
    }

    /**
     * Expired durable rows must not block a later advertiser from the same
     * 6-digit REF. Allocator already treats them as free via getPackage().
     */
    private function forgetExpiredForeignIntent(string $referenceCode, int $newUserId): void
    {
        $intent = $this->findIntent($referenceCode);
        if (! $intent || ! $intent->expires_at || ! $intent->expires_at->isPast()) {
            return;
        }

        $ownerId = (int) ($intent->user_id ?? 0);
        if ($ownerId <= 0 || $newUserId <= 0 || $ownerId === $newUserId) {
            return;
        }

        Cache::forget(self::bonusCacheKey($ownerId, $referenceCode));
        $intent->delete();
    }

    private function intentOwnedBy(string $referenceCode, int $userId): ?CheckoutIntent
    {
        $intent = $this->findIntent($referenceCode);
        if (! $intent) {
            return null;
        }

        $ownerId = (int) ($intent->user_id ?? 0);
        if ($ownerId > 0 && $userId > 0 && $ownerId !== $userId) {
            return null;
        }

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertIntent(string $referenceCode, array $attributes): void
    {
        if (! $this->tableReady()) {
            return;
        }

        try {
            $existing = CheckoutIntent::query()->where('reference_code', $referenceCode)->first();
            if ($existing) {
                if (array_key_exists('package', $attributes) && $attributes['package'] === null) {
                    unset($attributes['package']);
                }
                $existing->fill($attributes)->save();

                return;
            }

            CheckoutIntent::query()->create(array_merge(
                ['reference_code' => $referenceCode],
                $attributes
            ));
        } catch (\Throwable $e) {
            Log::warning('CheckoutIntent persist failed; cache-only fallback', [
                'reference_code' => $referenceCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findIntent(string $referenceCode): ?CheckoutIntent
    {
        if (! $this->tableReady()) {
            return null;
        }

        try {
            return CheckoutIntent::query()->where('reference_code', $referenceCode)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function findFreshIntent(string $referenceCode): ?CheckoutIntent
    {
        $intent = $this->findIntent($referenceCode);
        if (! $intent) {
            return null;
        }
        if ($intent->expires_at && $intent->expires_at->isPast()) {
            return null;
        }

        return $intent;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('checkout_intents');
        } catch (\Throwable) {
            return false;
        }
    }
}
