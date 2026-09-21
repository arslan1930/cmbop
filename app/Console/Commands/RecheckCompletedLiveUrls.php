<?php

namespace App\Console\Commands;

use App\Services\Orders\CompletedLiveUrlRecheckService;
use Illuminate\Console\Command;

class RecheckCompletedLiveUrls extends Command
{
    protected $signature = 'orders:recheck-completed-live-urls
                            {--limit=40 : Max completed placements to recheck}
                            {--stale-days=7 : Recheck URLs last checked this many days ago}';

    protected $description = 'Recheck live URLs on completed advertiser placements';

    public function handle(CompletedLiveUrlRecheckService $recheck): int
    {
        $result = $recheck->recheck(
            (int) $this->option('limit'),
            (int) $this->option('stale-days')
        );

        $this->info(
            "Checked {$result['checked']} live URL(s); {$result['down']} down; skipped {$result['skipped']}."
        );

        return self::SUCCESS;
    }
}
