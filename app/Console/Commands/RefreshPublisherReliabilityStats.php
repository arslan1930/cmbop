<?php

namespace App\Console\Commands;

use App\Services\Marketplace\PublisherReliabilityService;
use Illuminate\Console\Command;

class RefreshPublisherReliabilityStats extends Command
{
    protected $signature = 'publishers:refresh-reliability-stats';

    protected $description = 'Recompute publisher reliability stats from real SLB order data';

    public function handle(PublisherReliabilityService $reliability): int
    {
        $result = $reliability->refreshAll();
        $this->info("Updated {$result['updated']} publisher reliability row(s).");

        return self::SUCCESS;
    }
}
