<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiteVerifiedTraffic;
use App\Models\Site;
use App\Models\SiteTrafficConnection;
use App\Services\VerifiedTraffic\VerifiedTrafficService;
use App\Support\VerifiedTraffic;
use Illuminate\Console\Command;

class SyncVerifiedTrafficCommand extends Command
{
    protected $signature = 'sites:sync-verified-traffic
                            {--site= : Specific site ID}
                            {--sync : Run synchronously instead of queueing}
                            {--limit=40 : Max sites to process}';

    protected $description = 'Refresh verified monthly traffic from connected GA4 / Search Console accounts';

    public function handle(): int
    {
        if (! VerifiedTraffic::schemaReady()) {
            $this->warn('Verified traffic tables are not installed yet.');

            return self::SUCCESS;
        }

        if (! VerifiedTraffic::oauthConfigured() && ! $this->option('site')) {
            $this->warn('GOOGLE_TRAFFIC_CLIENT_ID is not configured.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $query = SiteTrafficConnection::query()
            ->whereIn('status', [VerifiedTraffic::STATUS_CONNECTED, VerifiedTraffic::STATUS_ERROR])
            ->where(function ($q) {
                $q->whereNotNull('ga4_property_id')->orWhereNotNull('gsc_site_url');
            })
            ->whereHas('site', function ($q) {
                $q->whereNull('archived_at')
                    ->where(function ($inner) {
                        $inner->where('active', 1)->orWhere('verified', 1);
                    });
            });

        if ($siteId = $this->option('site')) {
            $query->where('site_id', (int) $siteId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<=', now()->subDays(VerifiedTraffic::STALE_AFTER_DAYS));
            });
        }

        $ids = $query->orderBy('last_synced_at')
            ->limit($limit)
            ->pluck('site_id');

        if ($ids->isEmpty()) {
            $this->info('No sites need a verified-traffic refresh.');

            return self::SUCCESS;
        }

        $this->info('Refreshing verified traffic for '.$ids->count().' site(s)...');

        foreach ($ids as $id) {
            if ($this->option('sync')) {
                $site = Site::query()->find($id);
                if ($site) {
                    app(VerifiedTrafficService::class)->sync($site);
                }
            } else {
                SyncSiteVerifiedTraffic::dispatch((int) $id);
            }
        }

        return self::SUCCESS;
    }
}
