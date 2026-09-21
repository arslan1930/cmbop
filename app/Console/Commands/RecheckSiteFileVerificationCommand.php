<?php

namespace App\Console\Commands;

use App\Services\SiteFileVerificationService;
use Illuminate\Console\Command;

class RecheckSiteFileVerificationCommand extends Command
{
    protected $signature = 'sites:recheck-file-verification {--limit=100 : Max pending sites to recheck}';

    protected $description = 'Auto-check pending (not live) publisher verification files and verify matching drafts';

    public function handle(SiteFileVerificationService $verification): int
    {
        $result = $verification->recheckPending((int) $this->option('limit'));

        $this->info("Checked {$result['checked']} pending site(s); verified {$result['verified']}.");

        return self::SUCCESS;
    }
}
