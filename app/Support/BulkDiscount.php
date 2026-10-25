<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Publisher pack (bulk) discount tiers.
 *
 * 3-article: qty 3–4  (sites.bulk_discount_percent)
 * 5-article: qty 5–9  (sites.bulk_discount_percent_5)
 * 10-article: qty 10+ (sites.bulk_discount_percent_10)
 *
 * Each tier is optional. The highest set tier the quantity qualifies for
 * applies; if that tier is empty, the next lower set tier is used.
 */
class BulkDiscount
{
    public const TIER_3 = 3;

    public const TIER_5 = 5;

    public const TIER_10 = 10;

    public const COLUMN_3 = 'bulk_discount_percent';

    public const COLUMN_5 = 'bulk_discount_percent_5';

    public const COLUMN_10 = 'bulk_discount_percent_10';

    /**
     * @return list<int>
     */
    public static function tierQtys(): array
    {
        return [self::TIER_3, self::TIER_5, self::TIER_10];
    }

    public static function minPercent(): float
    {
        return (float) config('site_promotions.bulk.min_percent', 10);
    }

    public static function maxPercent(): float
    {
        return (float) config('site_promotions.bulk.max_percent', 80);
    }

    public static function defaultPercent(): float
    {
        return (float) config('site_promotions.bulk.default_percent', 10);
    }

    public static function minQty(): int
    {
        return (int) config('site_promotions.bulk.min_qty', 3);
    }

    public static function maxQty(): int
    {
        return (int) config('site_promotions.bulk.max_qty', 10);
    }

    public static function columnFor(int $tier): string
    {
        return match ($tier) {
            self::TIER_5 => self::COLUMN_5,
            self::TIER_10 => self::COLUMN_10,
            default => self::COLUMN_3,
        };
    }

    public static function hasColumn(int $tier): bool
    {
        return Site::hasSitesColumn(self::columnFor($tier));
    }

    public static function clamp(float $percent): float
    {
        return max(self::minPercent(), min(self::maxPercent(), round($percent, 2)));
    }

    public static function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2), '0'), '.');
    }

    /**
     * @return array{3: ?float, 5: ?float, 10: ?float}
     */
    public static function emptyTiers(): array
    {
        return [
            self::TIER_3 => null,
            self::TIER_5 => null,
            self::TIER_10 => null,
        ];
    }

    /**
     * @return array{3: ?float, 5: ?float, 10: ?float}
     */
    public static function tiersFromSite(Site $site): array
    {
        $tiers = self::emptyTiers();
        foreach (self::tierQtys() as $tier) {
            if (! self::hasColumn($tier)) {
                continue;
            }
            $raw = $site->{self::columnFor($tier)};
            if ($raw === null || $raw === '') {
                continue;
            }
            $percent = (float) $raw;
            if ($percent > 0) {
                $tiers[$tier] = $percent;
            }
        }

        return $tiers;
    }

    /**
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $tiers
     */
    public static function anyTierSet(array $tiers): bool
    {
        return self::bestPercent($tiers) !== null;
    }

    /**
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $tiers
     */
    public static function bestPercent(array $tiers): ?float
    {
        $best = null;
        foreach (self::tierQtys() as $tier) {
            $percent = $tiers[$tier] ?? null;
            if ($percent !== null && (float) $percent > 0 && ($best === null || (float) $percent > $best)) {
                $best = (float) $percent;
            }
        }

        return $best;
    }

    public static function siteJoins(Site $site): bool
    {
        if (! Site::hasSitesColumn('bulk_discount_enabled')) {
            return false;
        }

        return (bool) $site->bulk_discount_enabled && self::anyTierSet(self::tiersFromSite($site));
    }

    /**
     * Highest set tier the quantity qualifies for, else the next lower set tier.
     */
    public static function percentForQuantity(Site $site, int $quantity): ?float
    {
        if ($quantity < self::minQty() || ! self::siteJoins($site)) {
            return null;
        }

        $tiers = self::tiersFromSite($site);
        $order = $quantity >= self::TIER_10
            ? [self::TIER_10, self::TIER_5, self::TIER_3]
            : ($quantity >= self::TIER_5
                ? [self::TIER_5, self::TIER_3]
                : [self::TIER_3]);

        foreach ($order as $tier) {
            $percent = $tiers[$tier] ?? null;
            if ($percent !== null && $percent > 0) {
                return (float) $percent;
            }
        }

        return null;
    }

    /**
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $tiers
     */
    public static function label(array $tiers): ?string
    {
        $parts = [];
        foreach (self::tierQtys() as $tier) {
            $percent = $tiers[$tier] ?? null;
            if ($percent === null || (float) $percent <= 0) {
                continue;
            }
            $parts[] = $tier.' for −'.self::formatPercent((float) $percent).'%';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public static function labelForSite(Site $site): ?string
    {
        return self::siteJoins($site) ? self::label(self::tiersFromSite($site)) : null;
    }

    /**
     * Compact admin/publisher badge, e.g. "Bulk −10% · −15% · −25%".
     *
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $tiers
     */
    public static function badge(array $tiers): ?string
    {
        $full = self::label($tiers);
        if ($full === null) {
            return null;
        }

        return 'Bulk '.$full;
    }

    /**
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $from
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $to
     */
    public static function changeSummary(array $from, array $to): string
    {
        $left = self::label($from) ?? 'off';
        $right = self::label($to) ?? 'off';

        return $left.' → '.$right;
    }

    public static function parseOptionalPercent(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Clamp each provided tier and require 3-tier ≤ 5-tier ≤ 10-tier when set.
     *
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $tiers
     * @return array{3: ?float, 5: ?float, 10: ?float}
     */
    public static function normalizeTiers(array $tiers, bool $clamp = true): array
    {
        $out = self::emptyTiers();
        foreach (self::tierQtys() as $tier) {
            if (! self::hasColumn($tier)) {
                $out[$tier] = $tier === self::TIER_3 ? self::nullableClamped($tiers[$tier] ?? null, $clamp) : null;

                continue;
            }
            $out[$tier] = self::nullableClamped($tiers[$tier] ?? null, $clamp);
        }

        self::assertMonotonic($out);

        return $out;
    }

    /**
     * @param  array{3?: ?float, 5?: ?float, 10?: ?float}  $tiers
     */
    public static function assertMonotonic(array $tiers): void
    {
        $previous = null;
        $previousQty = null;
        foreach (self::tierQtys() as $tier) {
            $percent = $tiers[$tier] ?? null;
            if ($percent === null) {
                continue;
            }
            if ($previous !== null && (float) $percent + 0.0001 < (float) $previous) {
                $field = $tier === self::TIER_3 ? 'percent' : 'percent_'.$tier;
                throw ValidationException::withMessages([
                    $field => 'The '.$tier.'-article discount must be at least the '
                        .$previousQty.'-article discount.',
                ]);
            }
            $previous = (float) $percent;
            $previousQty = $tier;
        }
    }

    /**
     * SQL fragment matching sites that currently join the pack programme.
     *
     * @param  Builder<Site>|\Illuminate\Database\Query\Builder  $query
     */
    public static function constrainJoined($query): void
    {
        if (! Site::hasSitesColumn('bulk_discount_enabled')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('bulk_discount_enabled', 1)->where(function ($inner) {
            if (Site::hasSitesColumn(self::COLUMN_3)) {
                $inner->orWhere(function ($tier) {
                    $tier->whereNotNull(self::COLUMN_3)->where(self::COLUMN_3, '>', 0);
                });
            }
            if (Site::hasSitesColumn(self::COLUMN_5)) {
                $inner->orWhere(function ($tier) {
                    $tier->whereNotNull(self::COLUMN_5)->where(self::COLUMN_5, '>', 0);
                });
            }
            if (Site::hasSitesColumn(self::COLUMN_10)) {
                $inner->orWhere(function ($tier) {
                    $tier->whereNotNull(self::COLUMN_10)->where(self::COLUMN_10, '>', 0);
                });
            }
        });
    }

    private static function nullableClamped(mixed $value, bool $clamp): ?float
    {
        $percent = self::parseOptionalPercent($value);
        if ($percent === null || $percent <= 0) {
            return null;
        }

        return $clamp ? self::clamp($percent) : round($percent, 2);
    }
}
