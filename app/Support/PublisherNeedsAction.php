<?php

namespace App\Support;

use App\Models\OrderItem;
use App\Services\CheckoutSchemaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Shared publisher "needs you" predicates for the Tasks badge, list filter, and dashboard.
 *
 * Paid placements only. Scheduled slots that have not been released are not actionable.
 */
class PublisherNeedsAction
{
    /**
     * Paid items on this publisher's sites that can still move (pending / processing / review).
     *
     * @return Builder<OrderItem>
     */
    public static function paidOpenItemsQuery(int $publisherId): Builder
    {
        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        return OrderItem::query()
            ->whereHas('site', function ($q) use ($publisherId) {
                $q->where('publisher_id', $publisherId);
            })
            ->whereHas('order', function ($q) {
                $q->where('payment_status', 'paid')
                    ->whereIn('status', ['pending', 'processing', 'review']);
            });
    }

    /**
     * Restrict an item query to accept / publish / modification work.
     *
     * Caller must already scope to this publisher and paid orders (Tasks list does).
     *
     * @param  Builder<OrderItem>  $query
     * @return Builder<OrderItem>
     */
    public static function applyNeedsYouFilter(Builder $query): Builder
    {
        $hasModification = static::orderItemsHasColumn('modification_requested');
        $hasLiveUrl = static::orderItemsHasColumn('live_url');
        $hasRevision = static::orderItemsHasColumn('content_revision_requested');

        return $query->where(function ($q) use ($hasModification, $hasLiveUrl, $hasRevision) {
            $q->whereHas('order', function ($sub) {
                $sub->where('status', 'pending')->notAwaitingScheduledRelease();
            })->orWhere(function ($sub) use ($hasModification) {
                if (! $hasModification) {
                    $sub->whereRaw('0 = 1');

                    return;
                }
                $sub->where('modification_requested', 'yes');
            })->orWhere(function ($sub) use ($hasModification, $hasLiveUrl, $hasRevision) {
                $sub->whereHas('order', function ($o) {
                    $o->where('status', 'processing');
                });
                if ($hasLiveUrl) {
                    $sub->where(function ($u) {
                        $u->whereNull('live_url')->orWhere('live_url', '');
                    });
                }
                if ($hasModification) {
                    $sub->where(function ($m) {
                        $m->whereNull('modification_requested')
                            ->orWhere('modification_requested', '!=', 'yes');
                    });
                }
                if ($hasRevision) {
                    $sub->where(function ($c) {
                        $c->whereNull('content_revision_requested')
                            ->orWhere('content_revision_requested', '!=', 'yes');
                    });
                }
            });
        });
    }

    /**
     * @return Builder<OrderItem>
     */
    public static function needsYouQuery(int $publisherId): Builder
    {
        return static::applyNeedsYouFilter(static::paidOpenItemsQuery($publisherId));
    }

    public static function needsYouCount(int $publisherId): int
    {
        return static::needsYouQuery($publisherId)->count();
    }

    /**
     * Paid review items the publisher already handed off (not modification / accept / publish).
     *
     * @return Builder<OrderItem>
     */
    public static function waitingOnAdvertiserQuery(int $publisherId): Builder
    {
        $query = static::paidOpenItemsQuery($publisherId)
            ->whereHas('order', function ($q) {
                $q->where('status', 'review');
            });

        if (static::orderItemsHasColumn('modification_requested')) {
            $query->where(function ($q) {
                $q->whereNull('modification_requested')
                    ->orWhere('modification_requested', '!=', 'yes');
            });
        }

        return $query;
    }

    public static function waitingOnAdvertiserCount(int $publisherId): int
    {
        return static::waitingOnAdvertiserQuery($publisherId)->count();
    }

    private static function orderItemsHasColumn(string $column): bool
    {
        try {
            return Schema::hasTable('order_items') && Schema::hasColumn('order_items', $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
