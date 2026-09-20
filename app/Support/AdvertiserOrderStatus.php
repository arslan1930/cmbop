<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CheckoutSchemaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Shared advertiser-facing order status labels and next-action copy.
 */
class AdvertiserOrderStatus
{
    public static function itemsTableAvailable(): bool
    {
        try {
            return Schema::hasTable('order_items');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Orders that need advertiser attention: live-URL review and/or open content revisions.
     *
     * @return Builder<Order>
     */
    public static function needsActionQuery(int $userId): Builder
    {
        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $query = Order::query()->where('user_id', $userId);
        if (! static::itemsTableAvailable()) {
            return $query->whereRaw('0 = 1');
        }

        $query
            ->where(function ($q) {
                $q->where(function ($reviewReady) {
                    static::constrainReviewReady($reviewReady);
                });

                if (Schema::hasColumn('order_items', 'content_revision_requested')) {
                    $q->orWhere(function ($contentRevision) {
                        $contentRevision->whereIn('status', ['processing', 'review'])
                            ->whereHas('items', function ($iq) {
                                $iq->where('content_revision_requested', 'yes');
                            });
                    });
                }

                if (Schema::hasColumn('order_items', 'live_url_check_ok')) {
                    $windowDays = max(1, (int) config('orders.live_url_down_window_days', 90));
                    $q->orWhere(function ($linkDown) use ($windowDays) {
                        $linkDown->where('status', 'completed')
                            ->where('payment_status', 'paid')
                            ->whereHas('items', function ($iq) use ($windowDays) {
                                $iq->whereNotNull('live_url')
                                    ->where('live_url', '!=', '')
                                    ->where('live_url_check_ok', false)
                                    ->where(function ($recent) use ($windowDays) {
                                        $recent->where('live_url_checked_at', '>=', now()->subDays($windowDays));
                                        if (Schema::hasColumn('order_items', 'completed_at')) {
                                            $recent->orWhere('completed_at', '>=', now()->subDays($windowDays));
                                        }
                                    });
                            });
                    });
                }
            });
        static::constrainWithoutFailedPayment($query);

        return $query;
    }

    public static function needsActionCountForUser(int $userId): int
    {
        try {
            return static::needsActionQuery($userId)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Advertiser Orders queue: Needs review / needs action, then other active,
     * then Completed + Cancelled. Newest first inside each bucket.
     *
     * Skip the sink when the list is already filtered to a terminal status.
     *
     * @param  Builder<Order>  $query
     */
    public static function applyQueueOrder(Builder $query, string $statusFilter = ''): void
    {
        $statusFilter = strtolower(trim($statusFilter));
        if (in_array($statusFilter, ['completed', 'cancelled'], true)) {
            return;
        }

        $revisionClause = '';
        if (static::itemsTableAvailable() && Schema::hasColumn('order_items', 'content_revision_requested')) {
            $revisionClause = " OR (orders.status IN ('processing', 'review') AND EXISTS (
                SELECT 1 FROM order_items
                WHERE order_items.order_id = orders.id
                  AND order_items.content_revision_requested = 'yes'
            ))";
        }

        $reviewReadySql = static::itemsTableAvailable() ? static::liveUrlExistsSql() : '0 = 1';

        $query->orderByRaw(
            "CASE
                WHEN orders.status IN ('completed', 'cancelled') THEN 2
                WHEN ((orders.status = 'review' AND {$reviewReadySql}){$revisionClause}) THEN 0
                ELSE 1
            END"
        );
    }

    /**
     * Advertiser “Needs review” / Live URL ready: status=review and at least one live URL.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function constrainReviewReady(Builder $query): Builder
    {
        $query->where('status', 'review')
            ->whereHas('items', function ($items) {
                $items->whereNotNull('live_url')->where('live_url', '!=', '');
            });
        static::constrainWithoutFailedPayment($query);

        return $query;
    }

    /**
     * Awaiting payment: pending, not paid, and not a failed or refunded charge.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function constrainAwaitingPayment(Builder $query): Builder
    {
        $query->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'paid');
            });
        static::constrainWithoutFailedPayment($query);

        return $query;
    }

    /**
     * Paid and waiting on the publisher, or the publisher is already working.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function constrainInProgress(Builder $query): Builder
    {
        $query->where(function ($q) {
            $q->where(function ($pendingPaid) {
                $pendingPaid->where('status', 'pending')
                    ->where('payment_status', 'paid')
                    ->notAwaitingScheduledRelease();
            })->orWhere('status', 'processing');
        });
        static::constrainWithoutFailedPayment($query);

        return $query;
    }

    /**
     * Approve / revision actions and live KPIs: not cancelled, failed, or refunded.
     */
    public static function isLiveAdvertiserWork(Order $order): bool
    {
        $status = (string) $order->status;
        $payment = (string) $order->payment_status;

        if ($status === 'cancelled') {
            return false;
        }

        return ! in_array($payment, ['failed', 'refunded'], true);
    }

    /**
     * Failed and refunded charges are not live work (same as meta()).
     *
     * Completed clawbacks stay `payment_status=refunded` with `status=completed`
     * — pass `$alsoRefunded = false` so those rows remain in the Completed filter.
     *
     * @param  Builder<Order>  $query
     */
    public static function constrainWithoutFailedPayment(Builder $query, bool $alsoRefunded = true): void
    {
        $query->where(function ($q) use ($alsoRefunded) {
            $q->whereNull('payment_status')
                ->orWhere(function ($live) use ($alsoRefunded) {
                    $live->where('payment_status', '!=', 'failed');
                    if ($alsoRefunded) {
                        $live->where('payment_status', '!=', 'refunded');
                    }
                });
        });
    }

    public static function liveUrlExistsSql(string $orderIdColumn = 'orders.id'): string
    {
        return "EXISTS (
            SELECT 1 FROM order_items
            WHERE order_items.order_id = {$orderIdColumn}
              AND order_items.live_url IS NOT NULL
              AND order_items.live_url != ''
        )";
    }

    /**
     * Order-level by default so chat/list still see a sibling content-revision.
     * Pass `$itemScoped = true` for per-line Project counts.
     *
     * @return array{label: string, next: string, cls: string, stage: string, auto_approve_hint: ?string}
     */
    public static function meta(Order $order, ?OrderItem $item = null, bool $itemScoped = false): array
    {
        try {
            $item = $item ?? $order->items->first();
        } catch (\Throwable $e) {
            $item = null;
        }
        $hasLiveUrl = $item && filled($item->live_url);
        $modRequested = false;
        if ($item) {
            $modRequested = method_exists($item, 'isModificationRequested')
                ? $item->isModificationRequested()
                : (($item->modification_requested ?? 'no') === 'yes');
        }
        $lineNeedsContentRevision = function ($line): bool {
            if (! $line) {
                return false;
            }

            return method_exists($line, 'isContentRevisionRequested')
                ? $line->isContentRevisionRequested()
                : (($line->content_revision_requested ?? 'no') === 'yes');
        };
        if ($itemScoped) {
            $contentRevisionRequested = $lineNeedsContentRevision($item);
        } else {
            try {
                $contentRevisionRequested = $order->items->contains($lineNeedsContentRevision);
            } catch (\Throwable $e) {
                $contentRevisionRequested = false;
            }
            if (! $contentRevisionRequested && $item) {
                $contentRevisionRequested = $lineNeedsContentRevision($item);
            }
        }
        $payment = (string) $order->payment_status;
        $status = (string) $order->status;

        $autoHint = null;
        if ($status === 'review' && $hasLiveUrl && $item && ! $modRequested) {
            $hours = (int) $item->getAutoApproveHoursRemaining();
            if ($hours > 0) {
                $autoHint = $hours >= 24
                    ? 'Auto-approves in about '.ceil($hours / 24).' day(s) if you take no action'
                    : 'Auto-approves in about '.$hours.' hour(s) if you take no action';
            } else {
                $autoHint = 'Ready for auto-approve — approve now or request changes';
            }
        }

        if ($status === 'cancelled' && $payment === 'refunded') {
            return [
                'label' => 'Cancelled · refunded',
                'next' => 'Refunded to your wallet (usually instant). No further action needed.',
                'cls' => 'status-cancelled',
                'stage' => 'refunded',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'cancelled') {
            return [
                'label' => 'Cancelled',
                'next' => 'No further action needed.',
                'cls' => 'status-cancelled',
                'stage' => 'cancelled',
                'auto_approve_hint' => null,
            ];
        }

        if ($payment === 'failed') {
            return [
                'label' => 'Payment failed',
                'next' => 'Pay again from Orders, or choose another payment method.',
                'cls' => 'status-cancelled',
                'stage' => 'payment_failed',
                'auto_approve_hint' => null,
            ];
        }

        if ($payment === 'refunded' && $status !== 'completed') {
            return [
                'label' => 'Refunded',
                'next' => 'Refunded to your wallet. No further action needed.',
                'cls' => 'status-cancelled',
                'stage' => 'refunded',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'pending' && $payment !== 'paid') {
            return [
                'label' => 'Awaiting payment',
                'next' => 'Complete payment so the publisher can start.',
                'cls' => 'status-pending',
                'stage' => 'awaiting_payment',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'pending' && $payment === 'paid') {
            if ($order->isAwaitingScheduledRelease()) {
                $when = $order->scheduledPublishAtInScheduleTimezone();
                $whenLabel = $when
                    ? $when->format('d M Y g:i A').' '.$order->scheduleTimezoneOrUtc()
                    : null;

                return [
                    'label' => $whenLabel ? 'Scheduled · '.$whenLabel : 'Scheduled',
                    'next' => 'Publishes on the scheduled date. The publisher is not chased until then.',
                    'cls' => 'status-processing',
                    'stage' => 'scheduled',
                    'auto_approve_hint' => null,
                ];
            }

            return [
                'label' => 'Paid · waiting for publisher',
                'next' => 'Publisher will accept the order and start working.',
                'cls' => 'status-pending',
                'stage' => 'paid',
                'auto_approve_hint' => null,
            ];
        }

        if ($contentRevisionRequested && in_array($status, ['processing', 'review'], true)) {
            return [
                'label' => 'Publisher needs revised article',
                'next' => 'Upload or link an updated article so the publisher can continue.',
                'cls' => 'status-processing',
                'stage' => 'content_revision',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'processing' && $modRequested) {
            return [
                'label' => 'Revision requested',
                'next' => 'Waiting on the publisher to update the post and resubmit the live URL.',
                'cls' => 'status-processing',
                'stage' => 'revision',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'processing') {
            $accepted = $item && ! empty($item->accepted_at);

            return [
                'label' => $accepted ? 'Accepted · processing' : 'Processing',
                'next' => 'Publisher is preparing and publishing your content, then will send a live URL.',
                'cls' => 'status-processing',
                'stage' => 'processing',
                'auto_approve_hint' => null,
            ];
        }

        if ($status === 'review') {
            return [
                'label' => $hasLiveUrl ? 'URL delivered · your review' : 'In review',
                'next' => $hasLiveUrl
                    ? 'Check the live URL, then approve or request changes.'
                    : 'Waiting for live URL.',
                'cls' => 'status-review',
                'stage' => $hasLiveUrl ? 'url_delivered' : 'review',
                'auto_approve_hint' => $autoHint,
            ];
        }

        if ($status === 'completed') {
            if ($payment === 'refunded') {
                return [
                    'label' => 'Completed · refunded',
                    'next' => 'Refunded to your wallet. The publisher payout for this placement was reversed.',
                    'cls' => 'status-completed',
                    'stage' => 'completed',
                    'auto_approve_hint' => null,
                ];
            }

            if (! $item) {
                return [
                    'label' => 'Completed',
                    'next' => 'Placement details are missing for this order.',
                    'cls' => 'status-completed',
                    'stage' => 'completed',
                    'auto_approve_hint' => null,
                ];
            }

            $linkDown = $hasLiveUrl && $item->live_url_check_ok === false;
            if ($linkDown) {
                return [
                    'label' => 'Completed · link may be down',
                    'next' => 'The live URL did not respond. Recheck it, or open the published page.',
                    'cls' => 'status-review',
                    'stage' => 'completed',
                    'auto_approve_hint' => null,
                ];
            }

            return [
                'label' => 'Completed',
                'next' => $hasLiveUrl
                    ? 'Your post is live. Open the published URL.'
                    : 'Placement finished. Open the order for details.',
                'cls' => 'status-completed',
                'stage' => 'completed',
                'auto_approve_hint' => null,
            ];
        }

        return [
            'label' => ucfirst($status),
            'next' => '',
            'cls' => 'status-pending',
            'stage' => $status,
            'auto_approve_hint' => null,
        ];
    }

    /**
     * @return list<array{label: string, done: bool, current: bool}>
     */
    public static function timelineSteps(Order $order, ?OrderItem $item = null): array
    {
        try {
            $item = $item ?? $order->items->first();
        } catch (\Throwable $e) {
            $item = $item ?? null;
        }
        $status = (string) $order->status;
        $hasItems = $order->items->isNotEmpty();
        $hasLiveUrl = $order->items->contains(fn ($line) => filled($line->live_url));
        $paid = in_array($order->payment_status, ['paid', 'completed', 'refunded'], true)
            || in_array($status, ['processing', 'review', 'completed'], true);
        $acceptedOrLater = $hasItems && (
            in_array($status, ['processing', 'review', 'completed'], true)
            || ($item && ! empty($item->accepted_at))
        );
        $urlDelivered = $hasLiveUrl && in_array($status, ['review', 'completed'], true);
        $completed = $status === 'completed';
        $modRequested = $item && (($item->modification_requested ?? 'no') === 'yes');

        $steps = [
            ['label' => 'Paid', 'done' => $paid, 'current' => false],
            ['label' => 'Accepted', 'done' => $acceptedOrLater, 'current' => false],
            ['label' => 'Processing', 'done' => $hasItems && in_array($status, ['review', 'completed'], true), 'current' => false],
            ['label' => 'URL delivered', 'done' => $urlDelivered, 'current' => false],
            ['label' => 'Completed', 'done' => $completed && $hasItems, 'current' => false],
        ];

        if ($status === 'cancelled') {
            return $steps;
        }

        $payment = (string) $order->payment_status;
        if ($payment === 'failed') {
            $steps[0]['label'] = 'Payment failed';
            $steps[0]['done'] = false;
            $steps[0]['current'] = true;
            for ($i = 1; $i < count($steps); $i++) {
                $steps[$i]['done'] = false;
                $steps[$i]['current'] = false;
            }

            return $steps;
        }
        if ($payment === 'refunded' && $status !== 'completed') {
            $steps[0]['label'] = 'Refunded';
            $steps[0]['done'] = true;
            $steps[0]['current'] = true;
            for ($i = 1; $i < count($steps); $i++) {
                $steps[$i]['done'] = false;
                $steps[$i]['current'] = false;
            }

            return $steps;
        }

        if ($status === 'pending' && ! $paid) {
            $steps[0]['current'] = true;
            $steps[0]['done'] = false;
        } elseif ($status === 'pending' && $paid && $order->isAwaitingScheduledRelease()) {
            $steps[1]['label'] = 'Scheduled';
            $steps[1]['current'] = true;
        } elseif ($status === 'pending' && $paid) {
            $steps[1]['current'] = true;
        } elseif ($status === 'processing' && $modRequested) {
            $steps[2]['current'] = true;
            $steps[2]['label'] = 'Revision';
            $steps[2]['done'] = false;
            $steps[3]['done'] = false;
        } elseif ($status === 'processing') {
            $steps[2]['current'] = true;
        } elseif ($status === 'review' && $hasLiveUrl) {
            $steps[3]['current'] = true;
            $steps[3]['done'] = false;
        } elseif ($status === 'review') {
            $steps[2]['current'] = true;
            $steps[2]['done'] = false;
        } elseif ($status === 'completed') {
            $steps[4]['current'] = true;
            $steps[4]['done'] = $hasItems;
            if ($payment === 'refunded') {
                $steps[4]['label'] = 'Completed · refunded';
            }
        }

        return $steps;
    }
}
