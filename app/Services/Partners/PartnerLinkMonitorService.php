<?php

namespace App\Services\Partners;

use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerClawback;
use App\Models\PartnerLinkIncident;
use App\Services\LiveUrlHealthChecker;
use Illuminate\Support\Facades\Schema;

class PartnerLinkMonitorService
{
    public function __construct(
        private LiveUrlHealthChecker $health,
        private PartnerLedgerService $ledger,
        private PartnerWebhookService $webhooks,
    ) {}

    public function run(int $limit = 40): int
    {
        if (! Schema::hasColumn('order_items', 'keep_live_until')) {
            return 0;
        }
        $items = OrderItem::query()
            ->with(['site.publisher'])
            ->whereNotNull('live_url')
            ->where('live_url', '!=', '')
            ->where('payout_mode_at_submission', PartnerChannel::MODE_INSTANT)
            ->whereNotNull('keep_live_until')
            ->where('keep_live_until', '>', now())
            ->orderBy('id')
            ->limit($limit);
        if (OrderItem::hasSandboxColumn()) {
            $items->notSandbox();
        }
        $items = $items->get();
        $checked = 0;
        foreach ($items as $item) {
            $this->checkItem($item);
            $checked++;
        }
        $this->clawbackOverdue();

        return $checked;
    }

    public function checkItem(OrderItem $item): void
    {
        $partner = PartnerChannel::partnerForItem($item);
        if (! $partner) {
            return;
        }
        $check = $this->health->check((string) $item->live_url);
        if ($check['ok'] ?? false) {
            $open = PartnerLinkIncident::query()
                ->where('order_item_id', $item->id)
                ->whereNull('resolved')
                ->latest('id')
                ->first();
            if ($open) {
                $open->restored_at = now();
                $open->resolved = 'restored';
                $open->save();
            }
            $item->link_alert = false;
            $item->save();

            return;
        }
        $item->link_alert = true;
        $item->save();
        $open = PartnerLinkIncident::query()
            ->where('order_item_id', $item->id)
            ->whereNull('resolved')
            ->latest('id')
            ->first();
        if ($open) {
            $open->fail_count = (int) $open->fail_count + 1;
            $open->save();

            return;
        }
        PartnerLinkIncident::query()->create([
            'order_item_id' => $item->id,
            'partner_id' => $partner->id,
            'failure_code' => 'link_down',
            'restore_by' => now()->addHours(48),
            'fail_count' => 1,
        ]);
        $this->webhooks->emit($partner, 'order.link_alert', [
            'object' => ['id' => 'ord_'.$item->id, 'status' => PartnerOrderStatus::fromItem($item)],
        ]);
    }

    public function clawbackOverdue(): int
    {
        $rows = PartnerLinkIncident::query()
            ->whereNull('resolved')
            ->whereNotNull('restore_by')
            ->where('restore_by', '<=', now())
            ->get();
        $n = 0;
        foreach ($rows as $row) {
            $item = OrderItem::query()->find($row->order_item_id);
            $partner = Partner::query()->find($row->partner_id);
            if (! $item || ! $partner) {
                continue;
            }
            if ((string) $item->partner_earnings_status === 'deducted') {
                continue;
            }
            $this->ledger->clawback($item, $partner, 'keep_live_breach', (int) $row->id);
            $row->resolved = 'clawed_back';
            $row->save();
            $this->afterTakedown($partner);
            $n++;
        }
        $this->suspendUnrecovered();

        return $n;
    }

    public function afterTakedown(Partner $partner): void
    {
        $takedowns = PartnerClawback::query()
            ->where('partner_id', $partner->id)
            ->where('reason', 'keep_live_breach')
            ->where('opened_at', '>=', now()->subDays(90))
            ->count();
        if ($takedowns >= 2 && PartnerChannel::payoutMode($partner) === PartnerChannel::MODE_INSTANT) {
            app(PartnerPayoutModeService::class)->switchToHeld($partner, null, 'two_takedowns_90d');
        }
    }

    public function suspendUnrecovered(): int
    {
        $partnerIds = PartnerClawback::query()
            ->where('status', PartnerClawback::OPEN)
            ->where('opened_at', '<=', now()->subDays(30))
            ->pluck('partner_id')
            ->unique()
            ->all();
        $n = 0;
        foreach ($partnerIds as $id) {
            $partner = Partner::query()->find($id);
            if (! $partner) {
                continue;
            }
            PartnerClawback::query()
                ->where('partner_id', $partner->id)
                ->where('status', PartnerClawback::OPEN)
                ->where('opened_at', '<=', now()->subDays(30))
                ->update(['status' => PartnerClawback::REPAYMENT_REQUESTED]);
            $partner->status = Partner::STATUS_SUSPENDED;
            $partner->save();
            PartnerCatalogItem::query()
                ->where('partner_id', $partner->id)
                ->where('listing_status', PartnerCatalogItem::STATUS_LIVE)
                ->update(['listing_status' => PartnerCatalogItem::STATUS_PAUSED]);
            $this->webhooks->emit($partner, 'partner.suspended', [
                'object' => ['reason' => 'unrecovered_clawback'],
            ]);
            $n++;
        }

        return $n;
    }
}
