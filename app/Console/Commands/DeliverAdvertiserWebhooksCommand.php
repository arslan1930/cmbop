<?php

namespace App\Console\Commands;

use App\Services\Advertiser\AdvertiserWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DeliverAdvertiserWebhooksCommand extends Command
{
    protected $signature = 'advertisers:deliver-webhooks {--limit=50}';

    protected $description = 'Deliver pending advertiser webhook events (signed HMAC, retries)';

    public function handle(AdvertiserWebhookService $webhooks): int
    {
        if (! Schema::hasTable('advertiser_webhook_deliveries')) {
            return self::SUCCESS;
        }
        $done = $webhooks->deliverDue((int) $this->option('limit'));
        $this->info('Delivered or attempted '.$done.' webhook(s).');

        return self::SUCCESS;
    }
}
