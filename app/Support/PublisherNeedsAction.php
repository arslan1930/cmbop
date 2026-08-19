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
        return $query->where(function ($q) {
            $q->whereHas('order', function ($sub) {
                $sub->where('status', 'pending')->notAwaitingScheduledRelease();
            })->orWhere(function ($sub) {
                $sub->where('modification_requested', 'yes');
            })->orWhere(function ($sub) {
                $sub->whereHas('order', function ($o) {
                    $o->where('status', 'processing');
                })->where(function ($u) {
                    $u->whereNull('live_url')->orWhere('live_url', '');
                })->where(function ($m) {
                    $m->whereNull('modification_requested')
                        ->orWhere('modification_requested', '!=', 'yes');
                });
                if (Schema::hasColumn('order_items', 'content_revision_requested')) {
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
        return static::paidOpenItemsQuery($publisherId)
            ->whereHas('order', function ($q) {
                $q->where('status', 'review');
            })
            ->where(function ($q) {
                $q->whereNull('modification_requested')
                    ->orWhere('modification_requested', '!=', 'yes');
            });
    }

    public static function waitingOnAdvertiserCount(int $publisherId): int
    {
        return static::waitingOnAdvertiserQuery($publisherId)->count();
    }

    /**
     * One-line next step for a recent-task row.
     */
    public static function nextActionForItem(OrderItem $item): string
    {
        $order = $item->order;
        if (! $order) {
            return 'done';
        }

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return 'done';
        }

        if ($order->isAwaitingScheduledRelease()) {
            return 'scheduled';
        }

        if (($item->modification_requested ?? '') === 'yes') {
            return 'revision';
        }

        if ($order->status === 'pending') {
            return 'accept';
        }

        if ($order->status === 'processing') {
            if (Schema::hasColumn('order_items', 'content_revision_requested')
                && ($item->content_revision_requested ?? '') === 'yes') {
                return 'revision';
            }

            return trim((string) ($item->live_url ?? '')) === ''
                ? 'publish'
                : 'waiting_advertiser';
        }

        if ($order->status === 'review') {
            return 'waiting_advertiser';
        }

        return 'done';
    }

    public static function nextActionLabel(string $action): string
    {
        return match ($action) {
            'accept' => 'Accept this order',
            'publish' => 'Submit live URL',
            'revision' => 'Advertiser requested a change',
            'waiting_advertiser' => 'Waiting on advertiser',
            'scheduled' => 'Scheduled — not due yet',
            default => '',
        };
    }
}
