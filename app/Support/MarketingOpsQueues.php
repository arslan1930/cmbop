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

    public static function sitesWaitingOnPublisherCount(): int
    {
        return self::rememberCount(
            'marketing.ops.waiting_publisher_sites_count',
            fn () => self::sitesWaitingOnPublisher()->count()
        );
    }

    /**
     * Unpublished listings still with the publisher (details or accept).
     *
     * @param  'filling'|'reviewing'|'accept'|null  $stage
     * @return Builder<Site>
     */
    public static function sitesWaitingOnPublisher(?string $stage = null): Builder
    {
        $query = Site::query();
        self::constrainSitesWaitingOnPublisher($query, $stage);

        return $query;
    }

    /**
     * Filling details, publisher review, and the old accept invite, counted apart.
     *
     * @return array{filling: int, reviewing: int, accept: int}
     */
    public static function sitesWaitingStageCounts(): array
    {
        return [
            'filling' => self::rememberCount(
                'marketing.ops.waiting_filling_count',
                fn () => self::sitesWaitingOnPublisher('filling')->count()
            ),
            'reviewing' => self::rememberCount(
                'marketing.ops.waiting_reviewing_count',
                fn () => self::sitesWaitingOnPublisher('reviewing')->count()
            ),
            'accept' => self::rememberCount(
                'marketing.ops.waiting_accept_count',
                fn () => self::sitesWaitingOnPublisher('accept')->count()
            ),
        ];
    }

    public static function normalizeWaitingStage(mixed $stage): ?string
    {
        $value = strtolower(trim(scalar_text($stage)));

        return in_array($value, ['filling', 'reviewing', 'accept'], true) ? $value : null;
    }

    /**
     * @param  Builder<Site>  $q
     * @param  'filling'|'reviewing'|'accept'|null  $stage
     */
    public static function constrainSitesWaitingOnPublisher(Builder $q, ?string $stage = null): void
    {
        $stage = self::normalizeWaitingStage($stage);

        $q->notArchived()
            ->where(function ($inner) {
                $inner->where('verified', 0)->orWhereNull('verified');
            })
            ->where(function ($inner) {
                $inner->where('active', 0)->orWhereNull('active');
            })
            ->where(function ($inner) use ($stage) {
                $started = false;
                $add = function ($branch) use ($inner, &$started) {
                    if ($started) {
                        $inner->orWhere($branch);

                        return;
                    }
                    $inner->where($branch);
                    $started = true;
                };

                if ($stage === null || $stage === 'filling') {
                    $add(function ($filling) {
                        $filling->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
                            ->acceptedByPublisher();
                    });
                }
                if ($stage === null || $stage === 'reviewing') {
                    $add(function ($reviewing) {
                        $reviewing->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)
                            ->acceptedByPublisher();
                    });
                }
                if (($stage === null || $stage === 'accept')
                    && Site::hasSitesColumn('publisher_accepted_at')
                    && Site::hasSitesColumn('assigned_by_user_id')) {
                    $add(function ($invite) {
                        $invite->pendingPublisherAcceptance();
                    });
                }
                if (! $started) {
                    $inner->whereRaw('1 = 0');
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
                ->whereHas('items', fn ($items) => $items->whereNull('site_id'));
        });
    }

    /**
     * @param  Builder<BulkSiteRequest>  $q
     */
    public static function constrainWaitingOnMarketer(Builder $q): void
    {
        // Status alone is not enough: reject-all leaves requested/seeded
        // with zero pending rows and was still inflating the badge.
        // Legacy sheet batches (count set, no item rows, no live sites)
        // still block the publisher and must stay on "Waiting on you".
        $q->where('status', '!=', BulkSiteRequest::STATUS_CANCELLED)
            ->where(function ($inner) {
                $inner->whereHas('items', fn ($items) => $items->whereNull('site_id'))
                    ->orWhere(function ($legacy) {
                        $legacy->whereNotIn('status', [
                            BulkSiteRequest::STATUS_COMPLETED,
                            BulkSiteRequest::STATUS_CANCELLED,
                        ])
                            ->where('estimated_count', '>', 0)
                            ->whereDoesntHave('items')
                            ->whereDoesntHave('sites', fn ($sites) => $sites->notArchived());
                    });
            });
    }

    public static function bulkWaitingOnPublisherCount(): int
    {
        return self::rememberCount(
            'marketing.ops.waiting_publisher_bulk_count',
            fn () => self::bulkWaitingOnPublisher()->count()
        );
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
            ->whereDoesntHave('items', fn ($items) => $items->whereNull('site_id'));
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
