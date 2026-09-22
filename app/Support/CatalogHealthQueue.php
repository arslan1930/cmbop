<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Admin records hygiene queues for live catalog junk:
 * missing market, below quality bar, unverified-active, placeholder, missing cover.
 */
class CatalogHealthQueue
{
    public const MISSING_MARKET = 'missing_market';

    public const BELOW_QUALITY = 'below_quality';

    public const UNVERIFIED = 'unverified';

    public const PLACEHOLDER = 'placeholder';

    public const MISSING_COVER = 'missing_cover';

    /** @var array<string, string> */
    public const LABELS = [
        self::MISSING_MARKET => 'Missing market',
        self::BELOW_QUALITY => 'Below quality bar',
        self::UNVERIFIED => 'Unverified active',
        self::PLACEHOLDER => 'Placeholder',
        self::MISSING_COVER => 'Missing cover',
    ];

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }

    public static function normalize(mixed $health): ?string
    {
        if (is_bool($health)) {
            return null;
        }

        $value = strtolower(trim(scalar_text($health)));
        if ($value === '' || $value === 'all') {
            return null;
        }

        return in_array($value, self::values(), true) ? $value : null;
    }

    public static function fromRequest(Request $request): ?string
    {
        try {
            // Leftover ?missing_market[]=1 TypeErrors $request->boolean().
            if (filter_var(scalar_text($request->query('missing_market')), FILTER_VALIDATE_BOOLEAN)) {
                return self::MISSING_MARKET;
            }

            return self::normalize($request->query('health'));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function label(?string $filter): ?string
    {
        $normalized = self::normalize($filter);

        return $normalized === null ? null : self::LABELS[$normalized];
    }

    /**
     * @return array<string, int>
     */
    public static function emptyCounts(): array
    {
        return array_fill_keys(self::values(), 0);
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public static function apply(Builder $query, string $filter): Builder
    {
        $normalized = self::normalize($filter);
        if ($normalized === null) {
            return $query;
        }

        return match ($normalized) {
            self::MISSING_MARKET => $query->activeMissingMarketplaceCountry(),
            self::BELOW_QUALITY => self::constrainBelowQuality($query->catalogVisible()),
            self::UNVERIFIED => self::constrainUnverified($query->catalogVisible()),
            self::PLACEHOLDER => CatalogPlaceholderListing::constrainQuery($query->catalogVisible()),
            self::MISSING_COVER => self::constrainMissingCover($query->catalogVisible()),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = self::emptyCounts();

        foreach (self::values() as $filter) {
            try {
                $counts[$filter] = (int) self::apply(Site::query(), $filter)->count();
            } catch (\Throwable $e) {
                report($e);
                $counts[$filter] = 0;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public static function flags(Site $site): array
    {
        $flags = [];

        try {
            if ((bool) $site->active && ! $site->hasMarketplaceCountry()) {
                $flags[] = self::MISSING_MARKET;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $live = false;
        try {
            $live = $site->isCatalogVisible();
        } catch (\Throwable $e) {
            report($e);
            try {
                $live = (bool) $site->active && ! $site->isArchived();
            } catch (\Throwable) {
                $live = (bool) ($site->active ?? false);
            }
        }

        try {
            if ($live && ! $site->hasGoodMetrics()) {
                $flags[] = self::BELOW_QUALITY;
            }
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            if ($live && ! (bool) $site->verified) {
                $flags[] = self::UNVERIFIED;
            }
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            if ($live && CatalogPlaceholderListing::matches($site)) {
                $flags[] = self::PLACEHOLDER;
            }
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            if ($live && ! $site->hasCatalogCover()) {
                $flags[] = self::MISSING_COVER;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $flags;
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    private static function constrainBelowQuality(Builder $query): Builder
    {
        $checks = [];
        if (Site::hasSitesColumn('da')) {
            $checks[] = 'da';
        }
        if (Site::hasSitesColumn('dr')) {
            $checks[] = 'dr';
        }
        if (Site::hasSitesColumn('traffic')) {
            $checks[] = 'traffic';
        }

        if ($checks === []) {
            return $query->whereRaw('1 = 0');
        }

        $mins = [
            'da' => Site::GOOD_MIN_DA,
            'dr' => Site::GOOD_MIN_DR,
            'traffic' => Site::GOOD_MIN_TRAFFIC,
        ];

        return $query->where(function (Builder $q) use ($checks, $mins) {
            $first = array_shift($checks);
            $q->where($first, '<', $mins[$first])->orWhereNull($first);
            foreach ($checks as $column) {
                $q->orWhere($column, '<', $mins[$column])->orWhereNull($column);
            }
        });
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    private static function constrainUnverified(Builder $query): Builder
    {
        if (! Site::hasSitesColumn('verified')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) {
            $q->where('verified', 0)->orWhereNull('verified');
        });
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    private static function constrainMissingCover(Builder $query): Builder
    {
        $imageCols = array_values(array_filter(
            ['site_image', 'screenshot_path', 'screenshot_thumb_path'],
            static fn (string $column) => Site::hasSitesColumn($column)
        ));

        if ($imageCols === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($imageCols as $column) {
            $query->where(function (Builder $q) use ($column) {
                $q->whereNull($column)->orWhere($column, '');
            });
        }

        return $query;
    }
}
