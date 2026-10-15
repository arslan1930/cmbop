<?php

namespace App\Console\Commands;

use App\Services\Marketplace\SavedSearchAlertService;
use Illuminate\Console\Command;

class SendSavedSearchAlerts extends Command
{
    protected $signature = 'emails:send-saved-search-alerts
                            {--limit=200 : Max saved searches to evaluate}';

    protected $description = 'Email advertisers when new catalog sites match a saved search';

    public function handle(SavedSearchAlertService $alerts): int
    {
        $result = $alerts->sendDueAlerts((int) $this->option('limit'));
        $this->info("Evaluated {$result['searches']} saved search(es); mailed {$result['mailed']}.");

        return self::SUCCESS;
    }
}
