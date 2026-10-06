<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Catalog\CatalogFaviconResolver;
use Illuminate\Console\Command;

/**
 * Shared Hostinger cannot fetch icons on catalog tile GETs (PHP workers stall).
 * Cron warms a few missing files per minute onto the public disk.
 */
class WarmCatalogFaviconsCommand extends Command
{
    protected $signature = 'catalog:warm-favicons
                            {--limit=5 : Max icons to fetch this run}
                            {--site= : Warm one site id}';

    protected $description = 'Store missing catalog favicons without blocking advertiser page views';

    public function handle(CatalogFaviconResolver $favicons): int
    {
        $limit = max(1, min(20, (int) $this->option('limit')));
        $siteId = (int) $this->option('site');

        $query = Site::query()->catalogVisible()->orderBy('id');
        if ($siteId > 0) {
            $query->where('id', $siteId);
        }

        $warmed = 0;
        $examined = 0;
        $scanCap = $siteId > 0 ? 1 : max(40, $limit * 16);

        $query->chunkById(40, function ($sites) use ($favicons, $limit, $scanCap, &$warmed, &$examined) {
            foreach ($sites as $site) {
                if ($examined >= $scanCap || $warmed >= $limit) {
                    return false;
                }

                if ($favicons->hasStoredFile($site)) {
                    continue;
                }

                $examined++;
                if ($favicons->warm($site)) {
                    $warmed++;
                }

                if ($warmed >= $limit) {
                    return false;
                }
            }

            return true;
        });

        $this->info("Warmed {$warmed} catalog favicon(s).");

        return self::SUCCESS;
    }
}
