<?php

namespace App\Services\Partners;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PartnerSandboxOrderService
{
    public function __construct(
        private PartnerWebhookService $webhooks,
        private PartnerApiSerializer $serializer,
        private PartnerPriceFreeze $freeze,
    ) {}

    /**
     * Create a fake paid order against one of the partner's own sites.
     * No money, invoices, advertiser email, or marketplace side effects.
     */
    public function create(Partner $partner, string $siteRef, array $attrs = []): OrderItem
    {
        PartnerSandbox::assertAvailable();
        $site = $this->ownSite($partner, $siteRef);
        $anchor = trim((string) ($attrs['anchor_text'] ?? 'sandbox test link'));
        $target = trim((string) ($attrs['target_url'] ?? 'https://example.com/sandbox'));

        return DB::transaction(function () use ($partner, $site, $anchor, $target) {
            $userId = (int) $partner->user_id;
            $order = Order::query()->create([
                'user_id' => $userId,
                'order_number' => $this->sandboxOrderNumber(),
                'reference_code' => 'SBX-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                'subtotal' => 0,
                'tax' => 0,
                'total_amount' => 0,
                'payment_method' => 'sandbox',
                'payment_status' => 'paid',
                'status' => 'pending',
                'paid_at' => now(),
                'is_sandbox' => true,
            ]);

            $brief = $target !== '' ? $target : 'https://example.com/sandbox';
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'content_link' => $brief,
                'anchor_text' => $anchor !== '' ? $anchor : 'sandbox test link',
                'target_url' => $brief,
                'price' => 0,
                'additional_price' => 0,
                'platform_fee_percent' => 0,
                'platform_fee_amount' => 0,
                'publisher_price' => 0,
                'advertiser_eur_amount' => 0,
                'publisher_amount' => 0,
                'publisher_currency' => 'EUR',
                'publisher_status' => 'pending',
                'partner_payout_eur' => 0,
                'slb_price_eur_internal' => 0,
                'is_sandbox' => true,
            ]);
            $this->freeze->freeze($item->fresh());

            $fresh = $item->fresh();
            try {
                $this->webhooks->emit($partner, 'order.created', [
                    'object' => $this->serializer->order($partner->user, $fresh),
                ], false);
            } catch (\Throwable) {
                // Best-effort outbox.
            }

            return $fresh;
        });
    }

    public function accept(OrderItem $item, Partner $partner): OrderItem
    {
        PartnerSandbox::assertAvailable();
        $this->assertSandbox($item);

        $fresh = DB::transaction(function () use ($item) {
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'cancelled') {
                throw new PartnerInvalidTransitionException('invalid_state_transition');
            }
            if ($item->accepted_at || in_array($item->publisher_status, ['accepted', 'completed', 'rejected'], true)) {
                return $item;
            }
            if ($order->status === 'pending') {
                $order->status = 'processing';
                $order->save();
            }
            $item->accepted_at = now();
            $item->publisher_status = 'accepted';
            $item->save();

            return $item->fresh();
        });
        $this->webhooks->emit($partner, 'order.accepted', [
            'object' => $this->serializer->order($partner->user, $fresh),
        ], false);

        return $fresh;
    }

    public function reject(OrderItem $item, Partner $partner, string $reason): OrderItem
    {
        PartnerSandbox::assertAvailable();
        $this->assertSandbox($item);

        $fresh = DB::transaction(function () use ($item, $reason) {
            $item = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'cancelled') {
                throw new PartnerInvalidTransitionException('invalid_state_transition');
            }
            $order->status = 'cancelled';
            $order->save();
            $item->rejected_at = now();
            $item->publisher_status = 'rejected';
            $item->rejection_reason = $reason;
            $item->save();

            return $item->fresh();
        });
        $this->webhooks->emit($partner, 'order.rejected', [
            'object' => $this->serializer->order($partner->user, $fresh),
        ], false);

        return $fresh;
    }

    private function assertSandbox(OrderItem $item): void
    {
        if (! PartnerSandbox::isSandboxItem($item)) {
            throw new RuntimeException('not_a_sandbox_order');
        }
    }

    private function ownSite(Partner $partner, string $siteRef): Site
    {
        $id = (int) preg_replace('/^(ord_|site_)/', '', $siteRef);
        $catalog = PartnerCatalogItem::query()
            ->where('partner_id', $partner->id)
            ->where('id', $id)
            ->first();
        if ($catalog) {
            if ((int) ($catalog->site_id ?? 0) > 0) {
                $site = Site::query()->find($catalog->site_id);
                if ($site && (int) $site->publisher_id === (int) $partner->user_id) {
                    return $site;
                }
            }
            $byDomain = Site::query()
                ->where('publisher_id', $partner->user_id)
                ->where('domain', $catalog->domain)
                ->first();
            if ($byDomain) {
                return $byDomain;
            }
        }
        $site = Site::query()
            ->where('publisher_id', $partner->user_id)
            ->where('id', $id)
            ->first();
        if (! $site) {
            throw new RuntimeException('site_not_found');
        }

        return $site;
    }

    private function sandboxOrderNumber(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $candidate = 'SB'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $exists = Order::query()->where('order_number', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return 'SB'.substr((string) time(), -4);
    }
}
