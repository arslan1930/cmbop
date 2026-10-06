<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Catalog-wide Sites header / strip counts. Cached briefly so every
 * Sites index hit does not re-run the same COUNT(*) bag.
 */
class StaffSitesIndexCounts
{
    public const CACHE_KEY = 'staff.sites.index_counts';

    public const CACHE_TTL_SECONDS = 45;

    /**
     * @return array{
     *     liveUnverifiedCount: int,
     *     belowQualityListCount: int,
     *     readyToActivateCount: int,
     *     placeholderListCount: int,
     *     missingCoverListCount: int,
     *     missingMarketListCount: int,
     *     scanFailedListCount: int,
     *     archivedListCount: int,
     *     healthCounts: array<string, int>,
     *     missingMarketCount: int
     * }
     */
    public static function empty(): array
    {
        $healthCounts = CatalogHealthQueue::emptyCounts();

        return [
            'liveUnverifiedCount' => 0,
            'belowQualityListCount' => 0,
            'readyToActivateCount' => 0,
            'placeholderListCount' => 0,
            'missingCoverListCount' => 0,
            'missingMarketListCount' => 0,
            'scanFailedListCount' => 0,
            'archivedListCount' => 0,
            'healthCounts' => $healthCounts,
            'missingMarketCount' => (int) ($healthCounts[CatalogHealthQueue::MISSING_MARKET] ?? 0),
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $resolve
     * @return array<string, mixed>
     */
    public static function remember(callable $resolve): array
    {
        try {
            $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () use ($resolve) {
                return array_merge(self::empty(), $resolve());
            });

            return is_array($cached) ? array_merge(self::empty(), $cached) : self::empty();
        } catch (\Throwable $e) {
            report($e);

            return array_merge(self::empty(), $resolve());
        }
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
