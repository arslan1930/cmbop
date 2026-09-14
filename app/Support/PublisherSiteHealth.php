<?php

namespace App\Support;

use App\Models\Site;

/**
 * My Sites counters for the publisher dashboard KPI / CTA.
 */
class PublisherSiteHealth
{
    /**
     * @return array{listed: int, pending: int, invites: int, sellable: int, listing_work: int}
     */
    public static function snapshot(int $publisherId): array
    {
        $base = Site::query()->where('publisher_id', $publisherId);
        $accepted = (clone $base)->acceptedByPublisher();

        $pending = (clone $accepted)->notArchived()->notFromCancelledBulk()
            ->where('active', 0)
            ->where('verified', 0)
            ->count();
        $invites = (clone $base)->pendingPublisherAcceptance()->count();
        $sellable = (clone $accepted)->notArchived()->notFromCancelledBulk()
            ->where(function ($q) {
                $q->where('active', 1)->orWhere('verified', 1);
            })
            ->count();
        $listed = (clone $base)->notArchived()->count();

        return [
            'listed' => $listed,
            'pending' => $pending,
            'invites' => $invites,
            'sellable' => $sellable,
            'listing_work' => $pending + $invites,
        ];
    }
}
