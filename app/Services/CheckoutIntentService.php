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

    public static function submissionHoldCacheKey(int $submissionId): string
    {
        return 'content_checkout_hold:'.$submissionId;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<int, int>
     */
    public static function submissionIdsFromPackage(array $package): array
    {
        $ids = [];
        foreach (is_array($package['lines'] ?? null) ? $package['lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $id = (int) ($line['content_submission_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function storePackage(string $referenceCode, array $package, int $hours = 48): void
    {
        $existing = $this->getPackage($referenceCode);
        $this->holdSubmissionsFromPackage($referenceCode, $package, $hours);
        if (is_array($existing)) {
            $newIds = self::submissionIdsFromPackage($package);
            $stale = ['lines' => []];
            foreach (self::submissionIdsFromPackage($existing) as $id) {
                if (! in_array($id, $newIds, true)) {
                    $stale['lines'][] = ['content_submission_id' => $id];
                }
            }
            $this->releaseSubmissionHolds($referenceCode, $stale);
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
        $intent = $this->findIntent($referenceCode);
        $fromRow = $intent ? round((float) $intent->bonus_applied, 2) : 0.0;
        $fromPackage = is_array($intent?->package)
            ? round((float) ($intent->package['bonus_applied'] ?? 0), 2)
            : 0.0;
        $bonus = max($fromCache, $fromRow, $fromPackage, round((float) ($fallback ?? 0), 2));

        if ($intent && $fromRow > 0) {
            $intent->update(['bonus_applied' => 0]);
        }

        return $bonus > 0 ? $bonus : 0.0;
    }

    public function forgetBonus(int $userId, string $referenceCode): void
    {
        Cache::forget(self::bonusCacheKey($userId, $referenceCode));
        $intent = $this->findIntent($referenceCode);
        if ($intent && (float) $intent->bonus_applied > 0) {
            $intent->update(['bonus_applied' => 0]);
        }
    }

    public function forget(string $referenceCode, ?int $userId = null): void
    {
        $package = $this->getPackage($referenceCode);
        if (is_array($package)) {
            $this->releaseSubmissionHolds($referenceCode, $package);
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

    public function submissionHoldReference(int $submissionId): ?string
    {
        if ($submissionId < 1) {
            return null;
        }

        $cached = Cache::get(self::submissionHoldCacheKey($submissionId));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->durableHoldReference($submissionId);
    }

    public function submissionIsHeld(int $submissionId, ?string $exceptReference = null): bool
    {
        $ref = $this->submissionHoldReference($submissionId);
        if ($ref === null) {
            return false;
        }

        return $exceptReference === null || $ref !== $exceptReference;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function holdSubmissionsFromPackage(string $referenceCode, array $package, int $hours = 48): void
    {
        $held = [];
        foreach (self::submissionIdsFromPackage($package) as $submissionId) {
            if (! $this->holdSubmission($submissionId, $referenceCode, $hours)) {
                $this->releaseSubmissionHolds($referenceCode, ['lines' => array_map(
                    fn (int $id) => ['content_submission_id' => $id],
                    $held
                )]);

                throw new \RuntimeException('Article reserved for another checkout');
            }
            $held[] = $submissionId;
        }
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function releaseSubmissionHolds(string $referenceCode, array $package): void
    {
        foreach (self::submissionIdsFromPackage($package) as $submissionId) {
            $key = self::submissionHoldCacheKey($submissionId);
            if (Cache::get($key) === $referenceCode) {
                Cache::forget($key);
            }
        }
    }

    public function holdSubmission(int $submissionId, string $referenceCode, int $hours = 48): bool
    {
        if ($submissionId < 1 || $referenceCode === '') {
            return false;
        }

        $key = self::submissionHoldCacheKey($submissionId);
        $existing = Cache::get($key);
        if ($existing === $referenceCode) {
            Cache::put($key, $referenceCode, now()->addHours($hours));

            return true;
        }

        if (is_string($existing) && $existing !== '') {
            return false;
        }

        $durable = $this->durableHoldReference($submissionId);
        if ($durable !== null && $durable !== $referenceCode) {
            return false;
        }

        return Cache::add($key, $referenceCode, now()->addHours($hours));
    }

    private function durableHoldReference(int $submissionId): ?string
    {
        if (! $this->tableReady()) {
            return null;
        }

        try {
            $intents = CheckoutIntent::query()
                ->whereNotNull('package')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('id')
                ->limit(50)
                ->get(['reference_code', 'package']);

            foreach ($intents as $intent) {
                $package = is_array($intent->package) ? $intent->package : [];
                if (in_array($submissionId, self::submissionIdsFromPackage($package), true)) {
                    return (string) $intent->reference_code;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
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
