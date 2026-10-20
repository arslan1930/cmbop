<?php

namespace App\Services\Partners;

use App\Models\OrderItem;
use App\Models\User;

class PartnerSettlement
{
    /**
     * When true, caller must skip the publisher wallet credit.
     */
    public function settleOnApproval(OrderItem $item): bool
    {
        if (! PartnerChannel::tablesReady() || ! PartnerChannel::itemIsPartner($item)) {
            return false;
        }
        $partner = PartnerChannel::partnerForItem($item);
        if (! $partner) {
            return false;
        }
        $sandbox = PartnerSandbox::isSandboxItem($item);
        app(PartnerPriceFreeze::class)->freeze($item);
        if (! $sandbox) {
            app(PartnerLedgerService::class)->onApproved($item->fresh(), $partner);
        }
        app(PartnerWebhookService::class)->emit($partner, 'order.completed', [
            'object' => app(PartnerApiSerializer::class)->order($partner->user ?: new User, $item->fresh()),
        ], ! $sandbox);

        return true;
    }
}
