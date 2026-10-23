<?php

namespace App\Console\Commands;

use App\Services\Staff\BulkPublisherReminderService;
use Illuminate\Console\Command;

class RemindBulkPublishers extends Command
{
    protected $signature = 'bulk:remind-publishers
                            {--dry-run : List eligible batches without sending}';

    protected $description = 'Remind publishers on overdue bulk site requests (disabled unless BULK_PUBLISHER_REMINDER_AUTO=true)';

    public function handle(BulkPublisherReminderService $reminders): int
    {
        if (! $reminders->autoEnabled()) {
            $this->warn('Automatic bulk publisher reminders are disabled (BULK_PUBLISHER_REMINDER_AUTO). Nothing sent.');

            return self::SUCCESS;
        }

        $eligible = $reminders->eligibleForAutomatic();
        $this->info($eligible->count().' bulk request(s) eligible for an automatic publisher reminder.');

        if ((bool) $this->option('dry-run')) {
            foreach ($eligible as $bulk) {
                $this->line('  [dry-run] #'.$bulk->id.' '.$bulk->publisher?->email);
            }
            $this->info('Dry run complete. Nothing sent.');

            return self::SUCCESS;
        }

        $result = $reminders->remindMany($eligible, null, true);
        $this->info('Sent '.$result['sent'].'; skipped '.$result['skipped'].'; errors '.$result['errors'].'.');

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
