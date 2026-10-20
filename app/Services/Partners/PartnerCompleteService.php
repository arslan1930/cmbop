<?php

namespace App\Services\Partners;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Services\Advertiser\AdvertiserOrderWebhookBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PartnerCompleteService
{
    public function __construct(
        private PartnerLiveLinkChecker $links,
        private PartnerLedgerService $ledger,
        private PartnerWebhookService $webhooks,
        private PartnerPriceFreeze $freeze,
    ) {}

    /**
     * @return array{item: OrderItem, status: string, link_check: array<string, mixed>, earnings_status: ?string}
     */
    public function complete(OrderItem $item, string $liveUrl, ?Partner $partner = null): array
    {
        $partner = $partner ?: PartnerChannel::partnerForItem($item);
        if (! $partner) {
            throw new RuntimeException('not_a_partner_order');
        }
        $this->freeze->freeze($item);
        $status = PartnerOrderStatus::fromItem($item);
        if (! in_array($status, [PartnerOrderStatus::IN_PROGRESS, PartnerOrderStatus::AWAITING_CORRECTION], true)) {
            throw new PartnerInvalidTransitionException('invalid_state_transition');
        }

        $check = $this->links->check($liveUrl, (string) ($item->target_url ?: ''), (string) ($item->anchor_text ?: ''));
        if (! ($check['ok'] ?? false)) {
            throw new PartnerLinkCheckFailedException('link_check_failed', $check);
        }

        $sandbox = PartnerSandbox::isSandboxItem($item);

        return DB::transaction(function () use ($item, $liveUrl, $check, $partner, $status, $sandbox) {
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            $oldUrl = (string) ($item->live_url ?? '');
            $now = now();
            $first = $item->first_submitted_at ?: $now;
            $mode = $this->modeForSubmission($partner, $item);
            $payload = [
                'live_url' => $liveUrl,
                'live_url_submitted_at' => $now,
                'modification_requested' => 'no',
                'auto_approve_triggered' => false,
                'partner_awaiting_relay' => false,
            ];
            if (Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
                $payload['auto_approve_reminder_sent_at'] = null;
            }
            if (Schema::hasColumn('order_items', 'live_url_check_ok')) {
                $payload['live_url_check_ok'] = true;
                $payload['live_url_http_status'] = $check['http_status'] ?? $check['status'] ?? 200;
                $payload['live_url_checked_at'] = $check['checked_at'] ?? $now;
            }
            if (Schema::hasColumn('order_items', 'live_url_href_found')) {
                $payload['live_url_href_found'] = (bool) ($check['href_found'] ?? false);
            }
            if (Schema::hasColumn('order_items', 'live_url_rel')) {
                $payload['live_url_rel'] = $check['rel'] ?? null;
            }
            if (Schema::hasColumn('order_items', 'live_url_anchor_match')) {
                $payload['live_url_anchor_match'] = (bool) ($check['anchor_match'] ?? false);
            }
            if (Schema::hasColumn('order_items', 'first_submitted_at') && ! $item->first_submitted_at) {
                $payload['first_submitted_at'] = $first;
            }
            if (Schema::hasColumn('order_items', 'payout_mode_at_submission') && ! $item->payout_mode_at_submission) {
                $payload['payout_mode_at_submission'] = $mode;
            }
            if ($mode === PartnerChannel::MODE_HELD) {
                $holdFrom = ($oldUrl !== '' && $oldUrl !== $liveUrl) ? $now : $first;
                $payload['hold_until'] = $holdFrom->copy()->addDays(14);
                $payload['keep_live_until'] = null;
            } else {
                $payload['hold_until'] = null;
                $months = (int) ($partner->keep_live_months ?: 12);
                $keepFrom = ($oldUrl !== '' && $oldUrl !== $liveUrl) ? $now : $first;
                $payload['keep_live_until'] = $keepFrom->copy()->addMonths($months);
                $payload['keep_live_terms_version'] = 'KLP-1.0';
            }
            $item->forceFill($payload)->save();

            if (in_array($order->status, ['pending', 'processing'], true)) {
                $order->status = 'review';
                $order->save();
            }

            if ($status === PartnerOrderStatus::AWAITING_CORRECTION) {
                app(PartnerCorrectionService::class)->resolveOpen($item);
            }

            if (! $item->payout_mode_at_submission) {
                $item->payout_mode_at_submission = $mode;
            }
            if (! $sandbox) {
                $this->ledger->onSubmitted($item->fresh(), $partner, $mode);
            }
            $fresh = $item->fresh();
            $this->webhooks->emit($partner, $mode === PartnerChannel::MODE_INSTANT ? 'earning.available' : 'order.published', [
                'object' => app(PartnerApiSerializer::class)->order($partner->user, $fresh),
            ], ! $sandbox);
            try {
                app(AdvertiserOrderWebhookBridge::class)->emitForItem($fresh, 'order.published');
            } catch (\Throwable) {
                // Advertiser webhook outbox is best-effort.
            }

            return [
                'item' => $fresh,
                'status' => PartnerOrderStatus::SUBMITTED,
                'link_check' => [
                    'status' => 'ok',
                    'http_status' => $fresh->live_url_http_status,
                    'href_found' => (bool) ($fresh->live_url_href_found ?? $check['href_found'] ?? false),
                    'rel' => $fresh->live_url_rel ?: ($check['rel'] ?? null),
                    'anchor_match' => (bool) ($fresh->live_url_anchor_match ?? $check['anchor_match'] ?? false),
                    'checked_at' => optional($fresh->live_url_checked_at)?->toIso8601String(),
                ],
                'earnings_status' => $fresh->partner_earnings_status,
            ];
        });
    }

    private function modeForSubmission(Partner $partner, OrderItem $item): string
    {
        if ($item->payout_mode_at_submission) {
            return (string) $item->payout_mode_at_submission;
        }
        $mode = PartnerChannel::payoutMode($partner);
        if ($mode !== PartnerChannel::MODE_INSTANT) {
            return PartnerChannel::MODE_HELD;
        }
        $cap = (int) ($partner->instant_exposure_cap_minor ?: 50000);
        $exposure = $this->ledger->instantExposureMinor($partner);
        $next = PartnerChannel::eurosToMinor($item->publisherPayoutAmount());
        if ($exposure + $next > $cap) {
            return PartnerChannel::MODE_HELD;
        }

        return PartnerChannel::MODE_INSTANT;
    }
}
