<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerConnection;
use App\Services\Partners\PartnerCatalogGoLiveService;
use App\Services\Partners\PartnerImportPipeline;
use App\Services\Partners\PartnerPricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncPartnerFeedsCommand extends Command
{
    protected $signature = 'partners:sync-feeds {--partner= : Partner id} {--skip-reachability} {--confirm-drop}';

    protected $description = 'Re-fetch saved partner feed URLs (CSV/XLSX/JSON/XML) and apply the catalog import pipeline';

    public function handle(PartnerImportPipeline $pipeline, PartnerPricingService $pricing, PartnerCatalogGoLiveService $goLive): int
    {
        if (! Schema::hasTable('partner_connections')) {
            $this->warn('Partner tables are missing.');

            return self::SUCCESS;
        }

        $query = PartnerConnection::query()
            ->where('type', PartnerConnection::TYPE_FEED_URL)
            ->whereNotNull('feed_url')
            ->where('status', '!=', PartnerConnection::STATUS_PAUSED)
            ->with('partner');
        if ($this->option('partner')) {
            $query->where('partner_id', (int) $this->option('partner'));
        }

        $synced = 0;
        foreach ($query->get() as $connection) {
            $partner = $connection->partner;
            if (! $partner instanceof Partner || ! $partner->isActive()) {
                continue;
            }
            $job = $pipeline->importFeed($partner, $connection, [
                'skip_reachability' => (bool) $this->option('skip-reachability'),
                'confirm_drop' => (bool) $this->option('confirm-drop'),
            ]);
            $this->info('Partner #'.$partner->id.' job #'.$job->id.' '.$job->status);
            $synced++;
        }

        $this->pauseBelowHardFloor($pricing, $goLive);
        $this->info("Synced {$synced} feed(s).");

        return self::SUCCESS;
    }

    private function pauseBelowHardFloor(PartnerPricingService $pricing, PartnerCatalogGoLiveService $goLive): void
    {
        if (! Schema::hasTable('partner_catalog_items')) {
            return;
        }

        PartnerCatalogItem::query()
            ->where('listing_status', PartnerCatalogItem::STATUS_LIVE)
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($pricing, $goLive) {
                foreach ($items as $item) {
                    $slb = (float) ($item->slb_price_publish_eur ?? 0);
                    $partner = (float) $item->partner_rate_publish_eur;
                    $pricing->refreshFloorFlag($item);
                    if ($pricing->belowHardFloor($slb, $partner) && ! $item->margin_override) {
                        $goLive->pauseLive($item, 'hard_floor');
                    } else {
                        $item->save();
                    }
                }
            });
    }
}
