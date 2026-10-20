<?php

namespace App\Console\Commands;

use App\Services\Partners\PartnerWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DeliverPartnerWebhooksCommand extends Command
{
    protected $signature = 'partners:deliver-webhooks {--limit=50}';

    protected $description = 'Deliver pending partner webhook events (signed HMAC, retries)';

    public function handle(PartnerWebhookService $webhooks): int
    {
        if (! Schema::hasTable('partner_webhook_deliveries')) {
            return self::SUCCESS;
        }
        $done = $webhooks->deliverDue((int) $this->option('limit'));
        $this->info('Delivered or attempted '.$done.' webhook(s).');

        return self::SUCCESS;
    }
}
