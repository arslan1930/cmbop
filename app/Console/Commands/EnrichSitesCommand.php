<?php

namespace App\Console\Commands;

use App\Jobs\EnrichSiteJob;
use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use Illuminate\Console\Command;

class EnrichSitesCommand extends Command
{
    protected $signature = 'sites:enrich
                            {--site= : Specific site ID}
                            {--failed : Re-run failed enrichments only}
                            {--stale : Only sites with stale or missing metrics}
                            {--metrics : Refresh metrics only}
                            {--screenshot : Refresh screenshots only}
                            {--sync : Run synchronously instead of queueing}
                            {--limit= : Max sites to process}';

    protected $description = 'Refresh SEO metrics and/or homepage screenshots for publisher sites';

    public function handle(SiteEnrichmentService $enrichment): int
    {
        if (! config('site_enrichment.enabled', true)) {
            $this->warn('Site enrichment is disabled (SITE_ENRICHMENT_ENABLED=false).');

            return self::SUCCESS;
        }

        $metrics = ! $this->option('screenshot') || $this->option('metrics');
        $screenshot = ! $this->option('metrics') || $this->option('screenshot');
        if ($this->option('metrics') && ! $this->option('screenshot')) {
            $screenshot = false;
            $metrics = true;
        }
        if ($this->option('screenshot') && ! $this->option('metrics')) {
            $metrics = false;
            $screenshot = true;
        }

        $limit = (int) ($this->option('limit') ?: config('site_enrichment.batch_limit', 40));
        $maxAge = (int) config('site_enrichment.max_age_days', 90);
        $staleBefore = now()->subDays($maxAge);

        $query = Site::query()->where('active', 1);

        if ($siteId = $this->option('site')) {
            $query->where('id', (int) $siteId);
        }

        if ($this->option('failed')) {
            $failedIds = SiteEnrichmentRun::query()
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(30))
                ->pluck('site_id')
                ->unique();
            $query->where(function ($q) use ($failedIds) {
                $q->whereIn('id', $failedIds)
                    ->orWhere('enrichment_status', 'failed');
            });
        }

        if ($this->option('stale') || (! $this->option('site') && ! $this->option('failed'))) {
            $query->where(function ($q) use ($staleBefore) {
                $q->whereNull('metrics_fetched_at')
                    ->orWhere('metrics_fetched_at', '<', $staleBefore)
                    ->orWhereNull('screenshot_path')
                    ->orWhereNull('screenshot_fetched_at')
                    ->orWhere('screenshot_fetched_at', '<', $staleBefore);
            });
        }

        $sites = $query->orderByRaw('metrics_fetched_at IS NULL DESC')
            ->orderBy('metrics_fetched_at')
            ->limit(max(1, $limit))
            ->get();

        if ($sites->isEmpty()) {
            $this->info('No sites need enrichment.');

            return self::SUCCESS;
        }

        $this->info('Enriching '.$sites->count().' site(s)...');

        foreach ($sites as $site) {
            if ($this->option('sync')) {
                $enrichment->enrich($site, 'schedule', $metrics, $screenshot);
                $this->line("  ✓ #{$site->id} {$site->domain}");
            } else {
                EnrichSiteJob::dispatch($site->id, 'schedule', $metrics, $screenshot);
                $this->line("  → queued #{$site->id} {$site->domain}");
            }
        }

        return self::SUCCESS;
    }
}
