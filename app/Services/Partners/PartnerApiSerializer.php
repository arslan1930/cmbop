<?php

namespace App\Services\Partners;

use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\User;

class PartnerApiSerializer
{
    public function __construct(private PartnerOrderPresenter $presenter) {}

    /**
     * @return array<string, mixed>
     */
    public function me(Partner $partner): array
    {
        $keep = null;
        if (PartnerChannel::payoutMode($partner) === PartnerChannel::MODE_INSTANT) {
            $keep = [
                'promise_version' => 'KLP-1.0',
                'period_months' => (int) ($partner->keep_live_months ?: 12),
            ];
        }

        return [
            'id' => $partner->public_id ?: 'ptn_'.$partner->id,
            'name' => $partner->displayName(),
            'role' => 'supply',
            'status' => $partner->status,
            'payout_mode' => PartnerChannel::payoutMode($partner),
            'payout_policy' => PartnerChannel::payoutMode($partner) === PartnerChannel::MODE_INSTANT ? 'instant' : 'hold_14d',
            'payout_mode_effective_from' => optional($partner->payout_mode_effective_from)?->toIso8601String(),
            'keep_live' => $keep,
            'hold_days' => PartnerChannel::payoutMode($partner) === PartnerChannel::MODE_INSTANT ? 0 : 14,
            'payout_minimum' => ['amount' => 2000, 'currency' => 'EUR'],
            'contracting_entity' => 'Teqno LTD (17392540)',
            'test_mode' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(User $partnerUser, OrderItem $item): array
    {
        $base = $this->presenter->present($partnerUser, $item);
        $earningsStatus = $item->partner_earnings_status;
        $payload = [
            'id' => 'ord_'.$item->id,
            'status' => $base['status'],
            'status_reason' => $item->partner_dispute_status === 'open' ? 'disputed' : null,
            'link_alert' => (bool) $item->link_alert,
            'site' => [
                'id' => 'site_'.(int) $item->site_id,
                'domain' => $base['site_domain'],
            ],
            'buyer_ref' => $base['buyer_ref'],
            'your_earnings' => [
                'amount' => PartnerChannel::eurosToMinor((float) $base['your_payout']),
                'currency' => 'EUR',
                'status' => $earningsStatus,
                'hold_until' => optional($item->hold_until)?->toIso8601String(),
            ],
            'links' => [[
                'anchor' => $base['anchor_text'],
                'target_url' => $base['target_url'],
                'rel_required' => 'dofollow',
            ]],
            'live_url' => $base['live_url'],
            'submitted_at' => optional($item->live_url_submitted_at)?->toIso8601String(),
            'hold_until' => optional($item->hold_until)?->toIso8601String(),
            'keep_live_until' => optional($item->keep_live_until)?->toIso8601String(),
            'corrections' => [
                'used' => (int) ($item->partner_corrections_used ?? 0),
                'free_limit' => 2,
            ],
            'created_at' => optional($item->created_at)?->toIso8601String(),
            'updated_at' => optional($item->updated_at)?->toIso8601String(),
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function site(PartnerCatalogItem $item): array
    {
        return [
            'id' => 'site_'.$item->id,
            'domain' => $item->domain,
            'listing_status' => $item->listing_status,
            'your_price' => [
                'normal' => [
                    'amount' => PartnerChannel::eurosToMinor((float) $item->partner_rate_publish_eur),
                    'currency' => 'EUR',
                ],
            ],
            'available' => $item->listing_status === PartnerCatalogItem::STATUS_LIVE,
        ];
    }
}
