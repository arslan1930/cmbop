<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\VerifiedTraffic\VerifiedTrafficService;
use App\Support\VerifiedTraffic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSiteVerifiedTraffic implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $siteId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'verified-traffic:'.$this->siteId;
    }

    public function handle(VerifiedTrafficService $service): void
    {
        if (! VerifiedTraffic::schemaReady()) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $service->sync($site);
    }
}
