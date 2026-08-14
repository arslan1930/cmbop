<?php

namespace App\Support;

use App\Models\BulkSiteRequest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared marketing / staff ops queue queries.
 *
 * Dashboard cards, sidebar badge, and the bulk index "Waiting on you"
 * count must use the same predicates so leftover Done rows are not invisible.
 */
class MarketingOpsQueues
{
    /** Bulk index `?status=` value for marketer-actionable rows (not a DB enum). */
    public const FILTER_NEEDS_MARKETER = 'needs_marketer';

    /**
     * Sites ready for staff activate / review (not publisher drafts or invites).
     *
     * @return Builder<Site>
     */
    public static function sitesReadyForStaff(): Builder
    {
        return Site::query()->needsAdminReview()->notArchived();
    }

    public static function sitesReadyForStaffCount(): int
    {
        return self::rememberCount('marketing.ops.ready_count', fn () => self::sitesReadyForStaff()->count());
    }

    public static function bulkWaitingOnMarketerCount(): int
    {
        return self::rememberCount('marketing.ops.bulk_waiting_count', fn () => self::bulkWaitingOnMarketer()->count());
    }

    private static function rememberCount(string $key, callable $resolve): int
    {
        if (! app()->bound($key)) {
            app()->instance($key, (int) $resolve());
        }

        return (int) app($key);
    }

    /**
     * Unpublished listings still with the publisher (details or accept).
     *
     * @return Builder<Site>
     */
    public static function sitesWaitingOnPublisher(): Builder
    {
        return Site::query()
            ->notArchived()
            ->where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })
            ->where(function ($q) {
                $q->where('active', 0)->orWhereNull('active');
            })
            ->where(function ($q) {
                $q->whereIn('onboarding_status', [
                    Site::ONBOARDING_AWAITING_DETAILS,
                    Site::ONBOARDING_DETAILS_COMPLETE,
                ]);

                if (Site::hasSitesColumn('publisher_accepted_at')
                    && Site::hasSitesColumn('assigned_by_user_id')) {
                    $q->orWhere(function ($invite) {
                        $invite->whereNull('publisher_accepted_at')
                            ->whereNotNull('assigned_by_user_id');
                    });
                }
            });
    }

    /**
     * Every bulk request that still needs someone — including publisher-owned
     * batches and leftover Done rows. Prefer bulkWaitingOnMarketer() for the
     * index badge and "Waiting on you" filter so the two numbers cannot disagree.
     *
     * @return Builder<BulkSiteRequest>
     */
    public static function openBulkForMarketer(): Builder
    {
        return BulkSiteRequest::query()->where(fn ($q) => self::constrainOpenBulk($q));
    }

    /**
     * Bulk work the marketer can do now, including awaiting_publisher / completed
     * batches that still have URL+price rows for Done.
     *
     * @return Builder<BulkSiteRequest>
     */
    public static function bulkWaitingOnMarketer(): Builder
    {
        return BulkSiteRequest::query()->where(fn ($q) => self::constrainWaitingOnMarketer($q));
    }

    /**
     * @param  Builder<BulkSiteRequest>  $query
     * @return Builder<BulkSiteRequest>
     */
    public static function applyBulkIndexStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            '', 'all' => $query,
            self::FILTER_NEEDS_MARKETER => $query->where(fn ($q) => self::constrainWaitingOnMarketer($q)),
            default => $query->where('status', $status),
        };
    }

    /**
     * @param  Builder<BulkSiteRequest>  $q
     */
    public static function constrainOpenBulk(Builder $q): void
    {
        $q->whereNotIn('status', [
            BulkSiteRequest::STATUS_COMPLETED,
            BulkSiteRequest::STATUS_CANCELLED,
        ])->orWhere(function ($inner) {
            $inner->where('status', BulkSiteRequest::STATUS_COMPLETED)
                ->whereHas('items', fn ($items) => $items->pending());
        });
    }

    /**
     * @param  Builder<BulkSiteRequest>  $q
     */
    public static function constrainWaitingOnMarketer(Builder $q): void
    {
        $q->where('status', '!=', BulkSiteRequest::STATUS_CANCELLED)
            ->where(function ($inner) {
                $inner->whereIn('status', [
                    BulkSiteRequest::STATUS_REQUESTED,
                    BulkSiteRequest::STATUS_SHEET_SENT,
                    BulkSiteRequest::STATUS_SEEDED,
                ])->orWhereHas('items', fn ($items) => $items->pending());
            });
    }

    /**
     * Seeded drafts still with the publisher, and no leftover Done rows.
     *
     * @return Builder<BulkSiteRequest>
     */
    public static function bulkWaitingOnPublisher(): Builder
    {
        return BulkSiteRequest::query()
            ->where('status', BulkSiteRequest::STATUS_AWAITING_PUBLISHER)
            ->whereDoesntHave('items', fn ($items) => $items->pending());
    }

    public static function siteQueueLabel(Site $site): string
    {
        if ($site->isPendingPublisherAcceptance()) {
            return 'Waiting on accept';
        }

        return match ($site->onboarding_status) {
            Site::ONBOARDING_AWAITING_DETAILS => 'Filling details',
            Site::ONBOARDING_DETAILS_COMPLETE => 'Publisher reviewing',
            Site::ONBOARDING_READY_FOR_REVIEW => 'Ready for review',
            default => 'Needs review',
        };
    }
}
