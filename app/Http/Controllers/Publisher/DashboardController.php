<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\PublisherNeedsAction;
use App\Support\PublisherSiteHealth;
use App\Support\UserFacingError;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Display publisher dashboard (server-rendered summary + chart payloads).
     */
    public function index()
    {
        try {
            return $this->renderDashboard();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load your dashboard. Please refresh and try again.')
            );

            $payload = $this->emptyDashboardPayload();
            $payload['dashboardFailed'] = true;
            $payload['welcomeSituation'] = 'we could not refresh your numbers';

            return view('publisher.dashboard', $payload);
        }
    }

    private function renderDashboard()
    {
        $user = auth()->user();
        $userId = $user->id;

        $siteIds = Site::where('publisher_id', $userId)->pluck('id')->all();
        $health = PublisherSiteHealth::snapshot((int) $userId);
        $siteCount = $health['listed'];
        $listingWorkCount = $health['listing_work'];
        $sellableSiteCount = $health['sellable'];

        $stats = $this->buildStatistics($siteIds);
        $needsYou = PublisherNeedsAction::needsYouCount((int) $userId);
        $waitingOnAdvertiser = PublisherNeedsAction::waitingOnAdvertiserCount((int) $userId);

        $wallet = Wallet::forPublisher((int) $userId) ?: $user->activeWallet();
        $availableBalance = $wallet ? (float) $wallet->balance : 0.0;
        $withdrawableBalance = $wallet ? $wallet->withdrawableBalance() : 0.0;

        $metrics = $this->buildPerformanceMetrics($stats);
        $hasPaidOrders = (int) ($stats['total_orders'] ?? 0) > 0;

        return view('publisher.dashboard', [
            'siteCount' => $siteCount,
            'listingWorkCount' => $listingWorkCount,
            'sellableSiteCount' => $sellableSiteCount,
            'needsYou' => $needsYou,
            'waitingOnAdvertiser' => $waitingOnAdvertiser,
            'primaryAction' => $this->resolvePrimaryAction($needsYou, $listingWorkCount, $siteCount),
            'stats' => $stats,
            'metrics' => $metrics,
            'availableBalance' => $availableBalance,
            'withdrawableBalance' => $withdrawableBalance,
            'recentTasks' => $this->buildRecentTasks($siteIds),
            'weeklyEarnings' => $this->buildWeeklyEarnings($siteIds),
            'monthlyEarnings' => $this->buildMonthlyEarnings($siteIds),
            'orderStatus' => $this->buildOrderStatusDistribution($siteIds),
            'hasPaidOrders' => $hasPaidOrders,
            'dashboardFailed' => false,
            'publisherName' => (string) ($user->name ?: 'there'),
            'welcomeSituation' => $this->welcomeSituation($needsYou, $listingWorkCount, $siteCount),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDashboardPayload(): array
    {
        $stats = $this->buildStatistics([]);

        return [
            'siteCount' => 0,
            'listingWorkCount' => 0,
            'sellableSiteCount' => 0,
            'needsYou' => 0,
            'waitingOnAdvertiser' => 0,
            'primaryAction' => 'add_site',
            'stats' => $stats,
            'metrics' => $this->buildPerformanceMetrics($stats),
            'availableBalance' => 0.0,
            'withdrawableBalance' => 0.0,
            'recentTasks' => $this->buildRecentTasks([]),
            'weeklyEarnings' => $this->buildWeeklyEarnings([]),
            'monthlyEarnings' => $this->buildMonthlyEarnings([]),
            'orderStatus' => $this->buildOrderStatusDistribution([]),
            'hasPaidOrders' => false,
            'dashboardFailed' => false,
            'publisherName' => (string) (auth()->user()?->name ?: 'there'),
            'welcomeSituation' => $this->welcomeSituation(0, 0, 0),
        ];
    }

    /**
     * Get dashboard statistics (AJAX)
     */
    public function getStatistics(Request $request)
    {
        try {
            $siteIds = $this->publisherSiteIds();
            $stats = $this->buildStatistics($siteIds);
            $metrics = $this->buildPerformanceMetrics($stats);
            $userId = (int) auth()->id();

            return response()->json([
                'success' => true,
                'data' => array_merge($stats, $metrics, [
                    'needs_you' => PublisherNeedsAction::needsYouCount($userId),
                    'waiting_on_advertiser' => PublisherNeedsAction::waitingOnAdvertiserCount($userId),
                ]),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not load dashboard statistics. Please try again.'),
            ], 500);
        }
    }

    /**
     * Get recent orders for dashboard (AJAX)
     */
    public function getRecentOrders(Request $request)
    {
        try {
            $orders = $this->buildRecentTasks($this->publisherSiteIds());

            return response()->json([
                'success' => true,
                'orders' => $orders,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch recent orders.'),
            ], 500);
        }
    }

    /**
     * Get weekly earnings for chart (AJAX)
     */
    public function getWeeklyEarnings(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->buildWeeklyEarnings($this->publisherSiteIds()),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load weekly earnings.'),
                'data' => [
                    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'values' => [0, 0, 0, 0, 0, 0, 0],
                ],
            ], 500);
        }
    }

    /**
     * Get order status distribution for chart (AJAX)
     */
    public function getOrderStatusDistribution(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->buildOrderStatusDistribution($this->publisherSiteIds()),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load order status.'),
                'data' => [
                    'labels' => ['Pending', 'Processing', 'In Review', 'Scheduled', 'Completed', 'Cancelled'],
                    'values' => [0, 0, 0, 0, 0, 0],
                ],
            ], 500);
        }
    }

    /**
     * Get monthly earnings for chart (AJAX)
     */
    public function getMonthlyEarnings(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->buildMonthlyEarnings($this->publisherSiteIds()),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load monthly earnings.'),
                'data' => [
                    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    'values' => [0, 0, 0, 0, 0, 0],
                ],
            ], 500);
        }
    }

    /**
     * @return array<int>
     */
    private function publisherSiteIds(): array
    {
        return Site::where('publisher_id', auth()->id())->pluck('id')->all();
    }

    /**
     * Hero CTA: work that needs the publisher first, then listing gaps, then grow.
     */
    private function resolvePrimaryAction(int $needsYou, int $listingWorkCount, int $siteCount): string
    {
        if ($needsYou > 0) {
            return 'tasks';
        }
        if ($listingWorkCount > 0) {
            return 'verify_sites';
        }
        if ($siteCount === 0) {
            return 'add_site';
        }

        return 'grow';
    }

    private function welcomeSituation(int $needsYou, int $listingWorkCount, int $siteCount): string
    {
        if ($needsYou > 0) {
            return $needsYou === 1
                ? '1 task needs you'
                : $needsYou.' tasks need you';
        }

        if ($listingWorkCount > 0) {
            return $listingWorkCount === 1
                ? '1 listing still pending'
                : $listingWorkCount.' listings still pending';
        }

        if ($siteCount === 0) {
            return 'add your first site';
        }

        return 'you are caught up';
    }

    /**
     * Orders visible to publishers (paid placements only).
     *
     * @param  array<int>  $siteIds
     * @return array<int>
     */
    private function visibleOrderIds(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return OrderItem::whereIn('site_id', $siteIds)
            ->whereHas('order', function ($q) {
                $q->where('payment_status', 'paid');
            })
            ->pluck('order_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $siteIds
     * @return array<string, float|int>
     */
    private function buildStatistics(array $siteIds): array
    {
        $empty = [
            'total_orders' => 0,
            'pending_orders' => 0,
            'processing_orders' => 0,
            'review_orders' => 0,
            'scheduled_orders' => 0,
            'completed_orders' => 0,
            'cancelled_orders' => 0,
            'total_earnings' => 0.0,
            'pending_earnings' => 0.0,
            'in_progress_earnings' => 0.0,
            'total_sites' => 0,
            'success_rate' => 0.0,
        ];

        if ($siteIds === []) {
            return $empty;
        }

        $orderIds = $this->visibleOrderIds($siteIds);
        $completedOrders = $orderIds === [] ? 0 : Order::whereIn('id', $orderIds)->where('status', 'completed')->count();
        $cancelledOrders = $orderIds === [] ? 0 : Order::whereIn('id', $orderIds)->where('status', 'cancelled')->count();
        $resolvedOrders = $completedOrders + $cancelledOrders;
        $successRate = $resolvedOrders > 0
            ? round(($completedOrders / $resolvedOrders) * 100, 1)
            : 0.0;

        return [
            'total_orders' => count($orderIds),
            'pending_orders' => $orderIds === [] ? 0 : Order::whereIn('id', $orderIds)
                ->where('status', 'pending')
                ->notAwaitingScheduledRelease()
                ->count(),
            'processing_orders' => $orderIds === [] ? 0 : Order::whereIn('id', $orderIds)->where('status', 'processing')->count(),
            'review_orders' => $orderIds === [] ? 0 : Order::whereIn('id', $orderIds)->where('status', 'review')->count(),
            'scheduled_orders' => $orderIds === [] ? 0 : Order::whereIn('id', $orderIds)->awaitingScheduledRelease()->count(),
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'total_sites' => count($siteIds),
            // Of finished work only — not completed/total (that is completion_rate).
            'success_rate' => $successRate,
            'total_earnings' => round((float) OrderItem::whereIn('site_id', $siteIds)
                ->recognizedForFinance()
                ->whereHas('order', function ($q) {
                    $q->where('status', 'completed')
                        ->where('payment_status', 'paid');
                })
                ->sum(OrderItem::publisherPayoutSqlExpression()), 2),
            'pending_earnings' => round((float) OrderItem::whereIn('site_id', $siteIds)
                ->recognizedForFinance()
                ->whereHas('order', function ($q) {
                    $q->where('status', 'review')
                        ->where('payment_status', 'paid');
                })
                ->sum(OrderItem::publisherPayoutSqlExpression()), 2),
            'in_progress_earnings' => round((float) OrderItem::whereIn('site_id', $siteIds)
                ->recognizedForFinance()
                ->whereHas('order', function ($q) {
                    $q->where('status', 'processing')
                        ->where('payment_status', 'paid')
                        ->notAwaitingScheduledRelease();
                })
                ->sum(OrderItem::publisherPayoutSqlExpression()), 2),
        ];
    }

    /**
     * @param  array<string, float|int>  $stats
     * @return array<string, float>
     */
    private function buildPerformanceMetrics(array $stats): array
    {
        $totalOrders = (int) ($stats['total_orders'] ?? 0);
        $completedOrders = (int) ($stats['completed_orders'] ?? 0);
        $openOrders = (int) ($stats['pending_orders'] ?? 0)
            + (int) ($stats['processing_orders'] ?? 0)
            + (int) ($stats['review_orders'] ?? 0)
            + (int) ($stats['scheduled_orders'] ?? 0);
        $totalEarnings = (float) ($stats['total_earnings'] ?? 0);

        return [
            'success_rate' => (float) ($stats['success_rate'] ?? 0),
            'completion_rate' => $totalOrders > 0
                ? round(($completedOrders / $totalOrders) * 100, 1)
                : 0.0,
            'open_rate' => $totalOrders > 0
                ? round(($openOrders / $totalOrders) * 100, 1)
                : 0.0,
            'avg_order_value' => $completedOrders > 0
                ? round($totalEarnings / $completedOrders, 2)
                : 0.0,
        ];
    }

    /**
     * @param  array<int>  $siteIds
     * @return list<array<string, mixed>>
     */
    private function buildRecentTasks(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $needsYouIds = [];
        $userId = (int) auth()->id();
        if ($userId > 0) {
            $needsYouIds = array_fill_keys(
                PublisherNeedsAction::needsYouQuery($userId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
                true
            );
        }

        $items = OrderItem::whereIn('site_id', $siteIds)
            ->whereHas('order', function ($q) {
                $q->where('payment_status', 'paid');
            })
            ->with(['order', 'site'])
            ->orderByDesc('created_at')
            ->take(20)
            ->get();

        $rows = [];
        foreach ($items as $item) {
            if (! $item->order) {
                continue;
            }

            $needsYou = isset($needsYouIds[(int) $item->id]);
            $rows[] = [
                'order_id' => $item->order->id,
                'order_item_id' => $item->id,
                'order_number' => $item->order->order_number,
                'status' => $item->order->isAwaitingScheduledRelease()
                    ? 'scheduled'
                    : $item->order->status,
                'needs_you' => $needsYou,
                'next_action' => $this->recentTaskNextAction($item, $needsYou),
                'payout' => $item->publisherPayoutAmount(),
                'created_at' => optional($item->created_at)?->toIso8601String(),
                'created_at_human' => optional($item->created_at)?->diffForHumans(),
                'site_name' => $item->site_name,
                'site_url' => $item->site_url,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['needs_you'] !== $b['needs_you']) {
                return $a['needs_you'] ? -1 : 1;
            }

            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return array_values(array_slice($rows, 0, 5));
    }

    private function recentTaskNextAction(OrderItem $item, bool $needsYou): string
    {
        $order = $item->order;
        if ($order && $order->isAwaitingScheduledRelease()) {
            return 'Scheduled';
        }
        if ($needsYou) {
            if (($item->modification_requested ?? '') === 'yes') {
                return 'Modification requested';
            }
            if ($order && $order->status === 'pending') {
                return 'Accept';
            }
            if ($order && $order->status === 'processing' && ! filled($item->live_url)) {
                return 'Publish live URL';
            }

            return 'Needs you';
        }

        return match ($order?->status) {
            'review' => 'In review',
            'processing' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst((string) ($order?->status ?: 'pending')),
        };
    }

    /**
     * Earnings attributed to the day the order was marked completed (orders.updated_at).
     *
     * @param  array<int>  $siteIds
     * @return array{labels: list<string>, values: list<float>}
     */
    private function buildWeeklyEarnings(array $siteIds): array
    {
        $labels = [];
        $values = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->format('D');
            $values[] = $siteIds === []
                ? 0.0
                : $this->netEarningsInWindow(
                    $siteIds,
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay()
                );
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * @param  array<int>  $siteIds
     * @return array{labels: list<string>, values: list<float>}
     */
    private function buildMonthlyEarnings(array $siteIds): array
    {
        $labels = [];
        $values = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $labels[] = $date->format('M');
            $values[] = $siteIds === []
                ? 0.0
                : $this->netEarningsInWindow(
                    $siteIds,
                    $date->copy()->startOfMonth(),
                    $date->copy()->endOfMonth()
                );
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Stable completion timestamp: prefer item.completed_at when present, else order.updated_at.
     */
    private function completionTimestampSql(): string
    {
        if (Schema::hasColumn('order_items', 'completed_at')) {
            return 'COALESCE(order_items.completed_at, orders.updated_at)';
        }

        return 'orders.updated_at';
    }

    /**
     * Recognize completed payouts (including later-refunded sales), then
     * reverse clawed / refunded-non-clawed lines in this window. Filtering
     * to currently-paid recognizedForFinance() rows erases the completion
     * week after a full clawback flips the order to refunded.
     *
     * @param  array<int>  $siteIds
     */
    private function completedEarningsQuery(array $siteIds)
    {
        return OrderItem::query()
            ->whereIn('order_items.site_id', $siteIds)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.payment_status', ['paid', 'refunded'])
            ->where(function ($q) {
                $q->where('orders.status', 'completed')
                    ->orWhereNotNull('orders.completed_at');
            });
    }

    /**
     * @param  array<int>  $siteIds
     */
    private function netEarningsInWindow(array $siteIds, Carbon $start, Carbon $end): float
    {
        $ts = $this->completionTimestampSql();
        $recognized = (float) $this->completedEarningsQuery($siteIds)
            ->whereRaw($ts.' BETWEEN ? AND ?', [$start, $end])
            ->sum(OrderItem::publisherPayoutSqlExpression('order_items'));

        return round(
            $recognized
            - $this->clawedPublisherPayoutsInWindow($siteIds, $start, $end)
            - $this->refundedNonClawedPayoutsInWindow($siteIds, $start, $end),
            2
        );
    }

    /**
     * @param  array<int>  $siteIds
     */
    private function clawedPublisherPayoutsInWindow(array $siteIds, Carbon $start, Carbon $end): float
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0.0;
        }

        return (float) OrderItem::query()
            ->clawedBack()
            ->whereIn('site_id', $siteIds)
            ->whereHas('order', function ($q) {
                $q->whereIn('payment_status', ['paid', 'refunded'])
                    ->where(function ($order) {
                        $order->where('status', 'completed')
                            ->orWhereNotNull('completed_at');
                    });
            })
            ->whereHas('disputes', function ($disputes) use ($start, $end) {
                $disputes->where('status', OrderItemDispute::STATUS_UPHELD)
                    ->whereRaw('COALESCE(resolved_at, created_at) BETWEEN ? AND ?', [$start, $end]);
            })
            ->sum(OrderItem::publisherPayoutSqlExpression());
    }

    /**
     * @param  array<int>  $siteIds
     */
    private function refundedNonClawedPayoutsInWindow(array $siteIds, Carbon $start, Carbon $end): float
    {
        return (float) OrderItem::query()
            ->whereIn('site_id', $siteIds)
            ->when(OrderItemDispute::tableAvailable(), function ($items) {
                $items->whereDoesntHave('disputes', function ($disputes) {
                    $disputes->where('status', OrderItemDispute::STATUS_UPHELD);
                });
            })
            ->whereHas('order', function ($q) use ($start, $end) {
                $q->where('payment_status', 'refunded')
                    ->where(function ($order) {
                        $order->where('status', 'completed')
                            ->orWhereNotNull('completed_at');
                    });
                $this->constrainOrderRefundedInWindow($q, $start, $end);
            })
            ->sum(OrderItem::publisherPayoutSqlExpression());
    }

    private function constrainOrderRefundedInWindow($query, Carbon $start, Carbon $end): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $query->whereBetween('orders.updated_at', [$start, $end]);

            return;
        }

        $refundAt = '(SELECT MAX(wallet_transactions.created_at) FROM wallet_transactions'
            .' WHERE wallet_transactions.related_id = orders.id'
            .' AND wallet_transactions.related_type = ?'
            .' AND wallet_transactions.type = ?'
            .' AND wallet_transactions.direction = ?)';
        $expr = 'COALESCE('.$refundAt.', orders.updated_at)';

        $query->whereRaw($expr.' BETWEEN ? AND ?', [
            (new Order)->getMorphClass(),
            WalletTransaction::TYPE_REFUND,
            'credit',
            $start,
            $end,
        ]);
    }

    /**
     * @param  array<int>  $siteIds
     * @return array{labels: list<string>, values: list<int>}
     */
    private function buildOrderStatusDistribution(array $siteIds): array
    {
        $labels = ['Pending', 'Processing', 'In Review', 'Scheduled', 'Completed', 'Cancelled'];

        if ($siteIds === []) {
            return [
                'labels' => $labels,
                'values' => [0, 0, 0, 0, 0, 0],
            ];
        }

        $orderIds = $this->visibleOrderIds($siteIds);

        $statuses = [
            'pending' => 0,
            'processing' => 0,
            'review' => 0,
            'scheduled' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        if ($orderIds !== []) {
            foreach (array_keys($statuses) as $status) {
                $statuses[$status] = match ($status) {
                    'scheduled' => Order::whereIn('id', $orderIds)->awaitingScheduledRelease()->count(),
                    'pending' => Order::whereIn('id', $orderIds)
                        ->where('status', 'pending')
                        ->notAwaitingScheduledRelease()
                        ->count(),
                    default => Order::whereIn('id', $orderIds)->where('status', $status)->count(),
                };
            }
        }

        return [
            'labels' => $labels,
            'values' => array_values($statuses),
        ];
    }
}
