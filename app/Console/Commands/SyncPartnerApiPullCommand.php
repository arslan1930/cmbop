<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Services\Partners\JsonPartnerPullAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncPartnerApiPullCommand extends Command
{
    protected $signature = 'partners:sync-api-pull {--partner=} {--skip-reachability}';

    protected $description = 'Pull partner catalogs from configured partner APIs into the import pipeline';

    public function handle(JsonPartnerPullAdapter $adapter): int
    {
        if (! Schema::hasTable('partner_connections')) {
            return self::SUCCESS;
        }
        $query = PartnerConnection::query()
            ->where('type', PartnerConnection::TYPE_API_PULL)
            ->where('status', '!=', PartnerConnection::STATUS_PAUSED)
            ->with('partner');
        if ($this->option('partner')) {
            $query->where('partner_id', (int) $this->option('partner'));
        }
        $n = 0;
        foreach ($query->get() as $connection) {
            $partner = $connection->partner;
            if (! $partner instanceof Partner || ! $partner->isActive()) {
                continue;
            }
            $job = $adapter->sync($partner, $connection, [
                'skip_reachability' => (bool) $this->option('skip-reachability'),
            ]);
            $this->info('Partner #'.$partner->id.' job #'.$job->id.' '.$job->status);
            $n++;
        }
        $this->info('Pulled '.$n.' API connection(s).');

        return self::SUCCESS;
    }
}
