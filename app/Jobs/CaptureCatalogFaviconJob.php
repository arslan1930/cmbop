<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Catalog\CatalogFaviconResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CaptureCatalogFaviconJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 20;

    public int $uniqueFor = 300;

    public function __construct(public int $siteId) {}

    public function uniqueId(): string
    {
        return 'catalog-favicon:'.$this->siteId;
    }

    public function handle(CatalogFaviconResolver $favicons): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        if (! $favicons->capture($site)) {
            $favicons->rememberMiss($site);
        }
    }
}
