<?php

namespace App\Services\Catalog;

use App\Models\Site;
use App\Services\PlatformFeeService;
use App\Support\ListingMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Advertiser catalog min/max/sort/from-€: the price the buyer actually pays
 * (list + FX buffer + fee, then live custom sale floored at publisher payout).
 *
 * Filtering is SQL so a priced search does not load the whole sites table.
 * phpMatchingIds() is the in-memory algorithm, kept for tests.
 */
class CatalogAdvertiserPriceFilter
{
    public function __construct(
        private readonly PlatformFeeService $fees,
        private readonly ListingMoney $listing,
    ) {}

    public function advertiserFacingSql(?ListingMoney $listing = null): string
    {
        $listing ??= $this->listing;
        $eurSql = $listing->buyerFacingEurSql('price');

        return $this->fees->advertiserBaseSqlExpression($eurSql);
    }

    /**
     * SQL for the painted catalog pay price (sale when live, else list).
     *
     * Same rounding as CartPricingService qty-1: discount_amount =
     * round(list * pct/100, 2), then floor at sites.price (publisher payout).
     * Bulk packs do not apply on qty 1, so they are omitted.
     */
    public function buyerPaySql(?ListingMoney $listing = null): string
    {
        $list = '('.$this->advertiserFacingSql($listing).')';
        $pct = $this->activeCustomDiscountPercentSql();
        if ($pct === null) {
            return $list;
        }

        $discounted = "ROUND({$list} - ROUND({$list} * {$pct} / 100, 2), 2)";

        return "(CASE WHEN {$discounted} < price THEN price WHEN {$discounted} < 0 THEN 0 ELSE {$discounted} END)";
    }

    public function activeCustomDiscountPercentSql(): ?string
    {
        if (! Site::hasSitesColumn('custom_discount_percent')) {
            return null;
        }

        $now = now()->format('Y-m-d H:i:s');
        $ceil = Site::PLAUSIBLE_SQL_DATETIME_CEIL;
        $floor = Site::PLAUSIBLE_SQL_DATETIME_FLOOR;

        return '(CASE WHEN custom_discount_percent IS NOT NULL'
            .' AND custom_discount_percent > 0'
            .' AND custom_discount_ends_at IS NOT NULL'
            ." AND custom_discount_ends_at > '{$now}'"
            ." AND custom_discount_ends_at <= '{$ceil}'"
            .' AND (custom_discount_starts_at IS NULL'
            ." OR (custom_discount_starts_at <= '{$now}'"
            ." AND custom_discount_starts_at >= '{$floor}'"
            ." AND custom_discount_starts_at <= '{$ceil}'))"
            .' THEN custom_discount_percent ELSE 0 END)';
    }

    /**
     * @param  Builder<Site>  $query
     */
    public function constrain($query, ?float $min, ?float $max, ?ListingMoney $listing = null): void
    {
        if ($min === null && $max === null) {
            return;
        }

        // SQLite cannot apply this nested CASE in WHERE (SELECT of the same
        // expression is fine). PHPUnit uses sqlite; production MySQL uses SQL.
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            $this->constrainWithPhpOnQuery($query, $min, $max, $listing);

            return;
        }

        $sql = '('.$this->buyerPaySql($listing).')';
        if ($min !== null) {
            $query->whereRaw($sql.' >= ?', [$min]);
        }
        if ($max !== null) {
            $query->whereRaw($sql.' <= ?', [$max]);
        }
    }

    /**
     * Evaluate checkout rounding in PHP on the already-constrained query
     * (never a full unfiltered sites table scan).
     *
     * @param  Builder<Site>  $query
     */
    private function constrainWithPhpOnQuery($query, ?float $min, ?float $max, ?ListingMoney $listing): void
    {
        $ids = $this->phpMatchingIdsFrom($query, $min, $max, $listing);
        $query->whereIn('id', $ids !== [] ? $ids : [0]);
    }

    /**
     * @param  Builder<Site>|null  $query
     * @return list<int>
     */
    public function phpMatchingIdsFrom($query, ?float $min, ?float $max, ?ListingMoney $listing = null): array
    {
        $listing ??= $this->listing;
        $buyer = $listing->buyerCurrency();
        $columns = $this->phpPriceColumns();

        $rows = $query instanceof Builder
            ? (clone $query)->get($columns)
            : Site::query()->get($columns);

        return $rows
            ->filter(function (Site $site) use ($buyer, $min, $max): bool {
                $advertiser = $this->phpBuyerPay($site, $buyer);
                if ($min !== null && $advertiser < $min) {
                    return false;
                }
                if ($max !== null && $advertiser > $max) {
                    return false;
                }

                return true;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Legacy full-table PHP filter (pre-SQL). Used to prove the SQL expression
     * returns the same site ids as checkout rounding.
     *
     * @return list<int>
     */
    public function phpMatchingIds(?float $min, ?float $max, ?ListingMoney $listing = null): array
    {
        if ($min === null && $max === null) {
            return Site::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->phpMatchingIdsFrom(Site::query(), $min, $max, $listing);
    }

    /**
     * @return list<string>
     */
    private function phpPriceColumns(): array
    {
        $columns = ['id', 'price'];
        foreach ([
            'listing_currency',
            'listing_price',
            'custom_discount_percent',
            'custom_discount_starts_at',
            'custom_discount_ends_at',
            'bulk_discount_enabled',
            'bulk_discount_percent',
        ] as $column) {
            if (Schema::hasColumn('sites', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public function phpBuyerPay(Site $site, ?string $buyerCurrency = null): float
    {
        try {
            $pricing = $site->advertiserCatalogPricing(null, 1, $buyerCurrency);

            return round((float) $pricing['article_total'], 2);
        } catch (\Throwable) {
            $listing = $this->listing;
            $buyer = $buyerCurrency ?? $listing->buyerCurrency();

            return $this->fees->advertiserBase(
                $listing->buyerFacingEuros((float) $site->price, $site->listingCurrency(), $buyer)
            );
        }
    }
}
