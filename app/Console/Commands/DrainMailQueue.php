<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Platform mail is queued (PlatformMailable implements ShouldQueue), so nothing
 * reaches an inbox until a worker consumes the "emails" queue. Shared hosting
 * cannot keep `queue:work` resident, so the scheduler runs this each minute.
 */
class DrainMailQueue extends Command
{
    protected $signature = 'mail:drain-queue
                            {--max-time=55 : Seconds to keep working before exiting}
                            {--tries=3 : Attempts per job before it is marked failed}';

    protected $description = 'Deliver queued platform mail on hosts without a resident queue worker';

    public function handle(): int
    {
        $this->recoverStalledCampaigns();

        if (! config('email_notifications.auto_drain')) {
            $this->info('Mail queue auto-drain is disabled (MAIL_QUEUE_AUTO_DRAIN=false).');

            return self::SUCCESS;
        }

        $connection = (string) config('email_notifications.queue_connection', 'sync');

        if ($connection === 'sync') {
            $this->info('Mail is sent synchronously; there is no queue to drain.');

            return self::SUCCESS;
        }

        if (! $this->backendReady($connection)) {
            $this->warn("Queue connection [{$connection}] is not ready; skipping drain.");

            return self::SUCCESS;
        }

        $queues = collect([config('email_notifications.queue', 'emails'), 'default'])
            ->filter()
            ->unique()
            ->implode(',');

        return $this->call('queue:work', [
            'connection' => $connection,
            '--queue' => $queues,
            '--stop-when-empty' => true,
            '--max-time' => (int) $this->option('max-time'),
            '--tries' => (int) $this->option('tries'),
        ]);
    }

    /**
     * Lost campaign continuation jobs (PHP killed mid-batch) must be
     * re-queued even when auto-drain is off — those hosts still run this
     * command from the scheduler.
     */
    private function recoverStalledCampaigns(): void
    {
        $connection = (string) config('email_notifications.queue_connection', 'sync');
        if ($connection === 'sync' || ! $this->backendReady($connection)) {
            return;
        }

        try {
            $n = EmailCampaign::recoverStalled();
            if ($n > 0) {
                $this->info("Re-queued {$n} stalled campaign(s).");
            }
        } catch (\Throwable $e) {
            $this->warn('Campaign stall recovery failed: '.$e->getMessage());
        }
    }

    /**
     * A database queue on a deployment that skipped migrations has no jobs table;
     * running the worker there would only emit noise every minute.
     */
    private function backendReady(string $connection): bool
    {
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return true;
        }

        try {
            return Schema::hasTable((string) config("queue.connections.{$connection}.table", 'jobs'));
        } catch (\Throwable $e) {
            $this->warn('Queue backend check failed: '.$e->getMessage());

            return false;
        }
    }
}
