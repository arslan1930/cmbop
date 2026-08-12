<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Billing\WithdrawalPayoutStatementService;
use Illuminate\Console\Command;

class BackfillPayoutStatements extends Command
{
    protected $signature = 'billing:backfill-payout-statements
                            {--limit=50 : Max rows to process}
                            {--dry-run : List candidates without creating statements}
                            {--force-pdf : Regenerate PDFs for existing payout statements}';

    protected $description = 'Create missing PAY payout statements (or regenerate existing PDFs)';

    public function handle(WithdrawalPayoutStatementService $statements): int
    {
        $limit = (int) $this->option('limit');

        if ($this->option('force-pdf')) {
            if ($this->option('dry-run')) {
                $count = Invoice::query()
                    ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
                    ->where('status', '!=', Invoice::STATUS_CANCELLED)
                    ->count();
                $this->info("Dry run: would regenerate PDFs for up to {$limit} of {$count} payout statement(s).");

                return self::SUCCESS;
            }

            $result = $statements->regenerateExistingPdfs($limit);
            $this->info(sprintf(
                'Payout PDFs: regenerated=%d failed=%d',
                $result['regenerated'],
                $result['failed']
            ));

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $missing = $statements->missingCompletedWithdrawalsQuery()->count();
            $wouldProcess = min(max(1, min(200, $limit)), $missing);

            $this->info("Dry run: {$missing} completed withdrawal(s) missing a payout statement (would process {$wouldProcess}).");

            return self::SUCCESS;
        }

        $result = $statements->backfillMissing($limit);

        $this->info(sprintf(
            'Payout statements: created=%d skipped=%d failed=%d',
            $result['created'],
            $result['skipped'],
            $result['failed']
        ));

        if ($result['invoice_ids'] !== []) {
            $this->line('Invoice ids: '.implode(', ', $result['invoice_ids']));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
