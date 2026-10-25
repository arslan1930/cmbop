<?php

namespace App\Console\Commands;

use App\Services\Reminders\AbandonedCartReminderService;
use Illuminate\Console\Command;

class SendAbandonedCartReminders extends Command
{
    protected $signature = 'emails:send-abandoned-cart-reminders
                            {--dry-run : List eligible users without sending}
                            {--user= : Limit to one advertiser id}';

    protected $description = 'Send 24h / 72h abandoned-cart reminders to advertisers (off until the owner enables them)';

    public function handle(AbandonedCartReminderService $reminders): int
    {
        if (! $reminders->isEnabled()) {
            $this->info('Abandoned cart reminders are off. Enable them under Admin → Abandoned carts or set ABANDONED_CART_REMINDERS_ENABLED=true.');

            return self::SUCCESS;
        }

        $userId = $this->option('user');
        $onlyUserId = is_numeric($userId) ? (int) $userId : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $reminders->sendDue($onlyUserId, $dryRun);

        $this->info($dryRun
            ? sprintf('Dry run: %d eligible, %d skipped, %d considered.', $result['sent'], $result['skipped'], $result['considered'])
            : sprintf('Queued/sent %d abandoned cart reminder(s). Skipped %d of %d considered.', $result['sent'], $result['skipped'], $result['considered']));

        return self::SUCCESS;
    }
}
