<?php

namespace App\Console\Commands;

use App\Services\SitePromotionService;
use Illuminate\Console\Command;

class NotifyExpiredSiteDiscountsCommand extends Command
{
    protected $signature = 'sites:notify-expired-discounts {--limit=100}';

    protected $description = 'Email publishers when timed site discounts end and clear expired offers';

    public function handle(SitePromotionService $promotions): int
    {
        $sent = $promotions->notifyExpiredCustomDiscounts((int) $this->option('limit'));
        $this->info("Notified {$sent} expired discount(s).");

        return self::SUCCESS;
    }
}
