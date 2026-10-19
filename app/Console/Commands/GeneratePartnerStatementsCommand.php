<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\Partners\PartnerLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class GeneratePartnerStatementsCommand extends Command
{
    protected $signature = 'partners:generate-statements {--period=}';

    protected $description = 'Issue partner-visible monthly statements (no SLB price or margin)';

    public function handle(PartnerLedgerService $ledger): int
    {
        if (! Schema::hasTable('partner_statements')) {
            return self::SUCCESS;
        }
        $period = (string) ($this->option('period') ?: now()->subMonth()->format('Y-m'));
        $n = 0;
        Partner::query()->orderBy('id')->each(function (Partner $partner) use ($ledger, $period, &$n) {
            $ledger->statement($partner, $period);
            $n++;
        });
        $this->info('Issued statements for '.$n.' partner(s) for '.$period.'.');

        return self::SUCCESS;
    }
}
