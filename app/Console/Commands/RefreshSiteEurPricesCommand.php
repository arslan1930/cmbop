<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\ListingMoney;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshSiteEurPricesCommand extends Command
{
    protected $signature = 'sites:refresh-eur-prices {--limit= : Max sites to refresh}';

    protected $description = 'Refresh sites.price (EUR catalog) from listing_price using the live rate and FX buffer';

    public function handle(ListingMoney $listing): int
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'listing_price')) {
            $this->warn('Listing price columns are missing; nothing to refresh.');

            return self::SUCCESS;
        }

        $query = Site::query()->whereNotNull('listing_price');
        $limit = $this->option('limit');
        if ($limit !== null && $limit !== '') {
            $query->limit(max(1, (int) $limit));
        }

        $updated = 0;
        $query->orderBy('id')->chunkById(200, function ($sites) use ($listing, &$updated) {
            foreach ($sites as $site) {
                $site->refreshCatalogEuros($listing);
                $site->save();
                $updated++;
            }
        });

        $this->info("Refreshed EUR catalog prices for {$updated} site(s).");

        return self::SUCCESS;
    }
}
