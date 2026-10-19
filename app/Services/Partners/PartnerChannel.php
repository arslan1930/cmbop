<?php

namespace App\Services\Partners;

use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class PartnerChannel
{
    public const MODE_HELD = 'held';

    public const MODE_INSTANT = 'instant';

    public static function tablesReady(): bool
    {
        try {
            return Schema::hasTable('partners') && Schema::hasTable('partner_ledger_accounts');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function itemIsPartner(?OrderItem $item): bool
    {
        if (! $item) {
            return false;
        }
        $item->loadMissing(['site.publisher']);
        $site = $item->site;
        if (! $site instanceof Site) {
            return false;
        }
        if (Schema::hasColumn('sites', 'partner_catalog_item_id') && (int) ($site->partner_catalog_item_id ?? 0) > 0) {
            return true;
        }
        $publisher = $site->publisher;

        return $publisher instanceof User && $publisher->isSupplyPartner();
    }

    public static function partnerForItem(OrderItem $item): ?Partner
    {
        $item->loadMissing(['site.publisher.supplyPartner']);
        $user = $item->site?->publisher;
        if (! $user instanceof User) {
            return null;
        }

        return Partner::forUser($user);
    }

    public static function payoutMode(Partner $partner): string
    {
        $mode = (string) ($partner->payout_mode ?? self::MODE_HELD);

        return $mode === self::MODE_INSTANT ? self::MODE_INSTANT : self::MODE_HELD;
    }

    public static function eurosToMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function minorToEuros(int $minor): float
    {
        return round($minor / 100, 2);
    }
}
