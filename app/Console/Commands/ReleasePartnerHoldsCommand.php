<?php

namespace App\Console\Commands;

use App\Models\OrderItem;
use App\Services\Partners\PartnerApiSerializer;
use App\Services\Partners\PartnerChannel;
use App\Services\Partners\PartnerLedgerService;
use App\Services\Partners\PartnerWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReleasePartnerHoldsCommand extends Command
{
    protected $signature = 'partners:release-holds {--limit=100}';

    protected $description = 'Move held partner earnings to available after hold_until';

    public function handle(PartnerLedgerService $ledger, PartnerWebhookService $webhooks): int
    {
        if (! Schema::hasColumn('order_items', 'hold_until')) {
            return self::SUCCESS;
        }
        $items = OrderItem::query()
            ->where('partner_earnings_status', 'on_hold')
            ->whereNotNull('hold_until')
            ->where('hold_until', '<=', now())
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();
        $n = 0;
        foreach ($items as $item) {
            $partner = PartnerChannel::partnerForItem($item);
            if (! $partner) {
                continue;
            }
            if ($ledger->releaseHold($item, $partner)) {
                $webhooks->emit($partner, 'earning.available', [
                    'object' => app(PartnerApiSerializer::class)->order($partner->user, $item->fresh()),
                ]);
                $n++;
            }
        }
        $this->info('Released '.$n.' hold(s).');

        return self::SUCCESS;
    }
}
