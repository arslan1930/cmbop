<?php

namespace App\Services\Partners;

use App\Models\OrderItem;
use Illuminate\Support\Facades\Schema;

class PartnerPriceFreeze
{
    public function freeze(OrderItem $item): void
    {
        if (! PartnerChannel::itemIsPartner($item)) {
            return;
        }
        $payout = $item->publisherPayoutAmount();
        $slb = $item->advertiserEurAmount();
        $payload = [];
        if (Schema::hasColumn('order_items', 'partner_payout_eur') && $item->partner_payout_eur === null) {
            $payload['partner_payout_eur'] = $payout;
        }
        if (Schema::hasColumn('order_items', 'slb_price_eur_internal') && $item->slb_price_eur_internal === null) {
            $payload['slb_price_eur_internal'] = $slb;
        }
        if (Schema::hasColumn('order_items', 'publisher_amount') && $item->publisher_amount === null) {
            $payload['publisher_amount'] = $payout;
        }
        if (Schema::hasColumn('order_items', 'advertiser_eur_amount') && $item->advertiser_eur_amount === null) {
            $payload['advertiser_eur_amount'] = $slb;
        }
        if ($payload !== []) {
            $item->forceFill($payload)->save();
        }
    }
}
