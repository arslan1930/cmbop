<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CaptureSiteScreenshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $siteId,
        public string $triggeredBy = 'system',
    ) {
    }

    public function handle(SiteEnrichmentService $enrichment): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $enrichment->refreshScreenshot($site, $this->triggeredBy);
    }
}
