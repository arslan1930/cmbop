<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Advertiser catalog "max turnaround days" filter.
 *
 * Listing forms store 24h / 48h / 3days / 5days / 7days; older rows hold
 * free text. Keep any promised window at or under the chosen max.
 */
class CatalogTurnaroundFilter
{
    /** @var list<int> */
    public const ALLOWED_MAX_DAYS = [1, 2, 3, 5, 7];

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return [
            1 => '1 day or faster',
            2 => '2 days or faster',
            3 => '3 days or faster',
            5 => '5 days or faster',
            7 => '7 days or faster',
        ];
    }

    public static function normalize(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $days = (int) $raw;

        return in_array($days, self::ALLOWED_MAX_DAYS, true) ? $days : null;
    }

    /**
     * @return list<string>
     */
    public static function matchingSlugs(int $maxDays): array
    {
        $maxHours = $maxDays * 24;
        $candidates = [
            '24h', '24 hours', '24 hour', '1 day', '1day',
            '48h', '48 hours', '48 hour', '2 days', '2days',
            '3days', '3 days', '3 day',
            '5days', '5 days', '5 day',
            '7days', '7 days', '7 day',
        ];

        $keep = [];
        foreach ($candidates as $slug) {
            $hours = (new Site(['turnaround_time' => $slug]))->turnaroundHours();
            if ($hours !== null && $hours <= $maxHours) {
                $keep[] = $slug;
            }
        }

        return array_values(array_unique($keep));
    }

    /**
     * @param  Builder<Site>|\Illuminate\Database\Query\Builder  $query
     */
    public static function constrainQuery($query, mixed $raw): void
    {
        $days = self::normalize($raw);
        if ($days === null || ! Site::hasSitesColumn('turnaround_time')) {
            return;
        }

        $slugs = self::matchingSlugs($days);
        if ($slugs === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($q) use ($slugs) {
            foreach ($slugs as $slug) {
                $q->orWhereRaw('LOWER(TRIM(turnaround_time)) = ?', [strtolower($slug)]);
            }
        });
    }
}
