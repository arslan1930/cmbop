<?php

namespace App\Console\Commands;

use App\Models\PartnerCatalogItem;
use App\Services\Partners\PartnerFx;
use App\Services\Partners\PartnerPricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshPartnerFxCommand extends Command
{
    protected $signature = 'partners:refresh-fx';

    protected $description = 'Refresh ECB daily CZK/PLN reference rates and recompute partner EUR rates';

    public function handle(PartnerFx $fx, PartnerPricingService $pricing): int
    {
        $rates = $fx->refresh();
        $this->info('Partner FX quotes: '.json_encode($rates));

        if (! Schema::hasTable('partner_catalog_items')) {
            return self::SUCCESS;
        }

        $updated = 0;
        PartnerCatalogItem::query()
            ->whereIn('partner_rate_currency', ['CZK', 'PLN'])
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($fx, $pricing, &$updated) {
                foreach ($items as $item) {
                    $converted = $fx->toEuros((float) $item->partner_rate_publish, (string) $item->partner_rate_currency);
                    $item->partner_rate_publish_eur = $converted['amount'];
                    $item->fx_rate = $converted['rate'];
                    $item->fx_rate_date = $converted['date'];
                    if ($item->partner_rate_writing !== null) {
                        $item->partner_rate_writing_eur = $fx->toEuros((float) $item->partner_rate_writing, (string) $item->partner_rate_currency)['amount'];
                    }
                    if ($item->partner_rate_sensitive_extra !== null) {
                        $item->partner_rate_sensitive_extra_eur = $fx->toEuros((float) $item->partner_rate_sensitive_extra, (string) $item->partner_rate_currency)['amount'];
                    }
                    $pricing->refreshFloorFlag($item);
                    $item->save();
                    $updated++;
                }
            });

        $this->info("Updated {$updated} non-EUR partner rate(s).");

        return self::SUCCESS;
    }
}
