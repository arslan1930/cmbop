<?php

namespace App\Services\Catalog;

use App\Models\Site;
use App\Services\PlatformFeeService;
use App\Support\ListingMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Advertiser catalog min/max price: same buyer-facing total as checkout
 * (unbuffered sites.price + FX buffer + tiered portal fee).
 *
 * Filtering is SQL so a priced search does not load the whole sites table.
 * phpMatchingIds() is the previous in-memory algorithm, kept for tests.
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

        $sql = '('.$this->advertiserFacingSql($listing).')';
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
        $fees = $this->fees;
        $buyer = $listing->buyerCurrency();
        $columns = ['id', 'price'];
        if (Schema::hasColumn('sites', 'listing_currency')) {
            $columns[] = 'listing_currency';
        }

        $rows = $query instanceof Builder
            ? (clone $query)->get($columns)
            : Site::query()->get($columns);

        return $rows
            ->filter(function (Site $site) use ($listing, $fees, $buyer, $min, $max): bool {
                $advertiser = $fees->advertiserBase(
                    $listing->buyerFacingEuros((float) $site->price, $site->listingCurrency(), $buyer)
                );
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

        $listing ??= $this->listing;
        $fees = $this->fees;
        $buyer = $listing->buyerCurrency();
        $columns = ['id', 'price'];
        if (Schema::hasColumn('sites', 'listing_currency')) {
            $columns[] = 'listing_currency';
        }

        return Site::query()
            ->get($columns)
            ->filter(function (Site $site) use ($listing, $fees, $buyer, $min, $max): bool {
                $advertiser = $fees->advertiserBase(
                    $listing->buyerFacingEuros((float) $site->price, $site->listingCurrency(), $buyer)
                );
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
}
