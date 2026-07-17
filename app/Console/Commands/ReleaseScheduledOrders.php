<?php

namespace App\Console\Commands;

use App\Services\ContentUpload\ScheduledOrderService;
use Illuminate\Console\Command;

class ReleaseScheduledOrders extends Command
{
    protected $signature = 'orders:release-scheduled';

    protected $description = 'Release due scheduled orders into the publisher queue and send reminders';

    public function handle(ScheduledOrderService $scheduler): int
    {
        $released = $scheduler->releaseDueOrders();
        $reminders = $scheduler->sendUpcomingReminders();

        $this->info('Released ' . $released->count() . ' scheduled order(s); sent ' . $reminders . ' reminder(s).');

        return self::SUCCESS;
    }
}
