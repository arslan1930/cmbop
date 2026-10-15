<?php

namespace App\Console\Commands;

use App\Services\Marketplace\LinkGuaranteeService;
use Illuminate\Console\Command;

class CheckLinkGuarantees extends Command
{
    protected $signature = 'orders:check-link-guarantees
                            {--limit=40 : Max guaranteed placements to inspect}';

    protected $description = 'Monthly-cadence link guarantee checks (HTTP + href match)';

    public function handle(LinkGuaranteeService $guarantees): int
    {
        $result = $guarantees->runDueChecks((int) $this->option('limit'));

        $this->info(
            "Checked {$result['checked']}; missing {$result['missing']}; "
            ."restore notices {$result['restoreNotified']}; refunded {$result['refunded']}; "
            ."skipped {$result['skipped']}."
        );

        return self::SUCCESS;
    }
}
