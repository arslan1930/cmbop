<?php

namespace App\Console\Commands;

use App\Services\Partners\PartnerLinkMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class MonitorPartnerLinksCommand extends Command
{
    protected $signature = 'partners:monitor-links {--limit=40}';

    protected $description = 'Recheck keep-live partner links and claw back after the 48h grace';

    public function handle(PartnerLinkMonitorService $monitor): int
    {
        if (! Schema::hasTable('partner_link_incidents')) {
            return self::SUCCESS;
        }
        $checked = $monitor->run((int) $this->option('limit'));
        $this->info('Checked '.$checked.' live URL(s).');

        return self::SUCCESS;
    }
}
