<?php

namespace App\Services\Advertiser;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Catalog\SiteUrlVisibility;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\PlatformFeeService;
use App\Services\Wallet\WalletOverviewService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Single payload builder for the advertiser home dashboard.
 * Order KPI definitions match CatalogController::getOrderStatistics().
 */
class AdvertiserDashboardService
{
    public function __construct(
        private AdvertiserSpendService $spend,
        private SpendBudgetService $budgets,
        private WalletOverviewService $walletOverview,
        private PlatformFeeService $fees,
        private ScheduledOrderService $scheduler,
    ) {}

    /**
     * @return array{
     *     stats: array<string, int>,
     *     recentOrders: Collection,
     *     recommendedSites: Collection,
     *     hasOrderableArticle: bool,
     *     isNewAdvertiser: bool,
     *     upcomingScheduledCount: int,
     *     wallet: array<string, mixed>,
     *     budgetStatus: array<string, mixed>,
     *     spendSummary: array<string, mixed>,
     *     spendCandles: array<string, mixed>,
     *     primaryAction: string,
     *     welcomeSituation: string,
     *     needsActionOrders: Collection
     * }
     */
    public function build(User $user): array
    {
        $visibility = app(SiteUrlVisibility::class);
        $this->safe($user, 'url visibility schema', function () use ($visibility) {
            $visibility->ensureSchema();
        });

        $emptyStats = [
            'total' => 0,
            'completed' => 0,
            'in_progress' => 0,
            'cancelled' => 0,
            'needs_review' => 0,
            'needs_action' => 0,
            'awaiting_payment' => 0,
        ];

        $stats = $this->safe($user, 'order stats', fn () => $this->orderStats((int) $user->id), $emptyStats);
        $isNewAdvertiser = (bool) $this->safe(
            $user,
            'new-advertiser check',
            fn () => ! Order::query()->where('user_id', $user->id)->exists(),
            true
        );
        $upcomingScheduledCount = (int) $this->safe(
            $user,
            'upcoming scheduled',
            fn () => $this->scheduler->upcomingCount((int) $user->id),
            0
        );
        $recentOrders = $this->safe($user, 'recent orders', fn () => $this->recentOrders((int) $user->id), collect());
        $needsActionOrders = $this->safe($user, 'needs-you queue', fn () => $this->needsActionOrders((int) $user->id), collect());
        $recommendedSites = $this->safe($user, 'recommended sites', fn () => $this->recommendedSites($user), collect());
        $this->safe($user, 'url visibility warm', function () use ($visibility, $user, $recommendedSites) {
            $visibility->warmFor($user, $recommendedSites);
        });

        $stats = is_array($stats) ? $stats : $emptyStats;
        $needsAction = (int) ($stats['needs_action'] ?? 0);
        $awaitingPayment = (int) ($stats['awaiting_payment'] ?? 0);

        return [
            'stats' => $stats,
            'recentOrders' => $recentOrders instanceof Collection ? $recentOrders : collect(),
            'needsActionOrders' => $needsActionOrders instanceof Collection ? $needsActionOrders : collect(),
            'recommendedSites' => $recommendedSites instanceof Collection ? $recommendedSites : collect(),
            'hasOrderableArticle' => (bool) $this->safe(
                $user,
                'orderable article',
                fn () => ContentSubmission::query()
                    ->where('user_id', $user->id)
                    ->checkoutReady()
                    ->exists(),
                false
            ),
            'isNewAdvertiser' => $isNewAdvertiser,
            'upcomingScheduledCount' => $upcomingScheduledCount,
            'primaryAction' => $this->resolvePrimaryAction(
                $isNewAdvertiser,
                $needsAction,
                $awaitingPayment,
                $upcomingScheduledCount
            ),
            'welcomeSituation' => $this->welcomeSituation(
                $isNewAdvertiser,
                $needsAction,
                $awaitingPayment,
                $upcomingScheduledCount
            ),
            'wallet' => $this->safe($user, 'wallet strip', fn () => $this->walletStrip($user), [
                'spendable' => 0.0,
                'available' => 0.0,
                'bonus' => 0.0,
                'currency' => 'EUR',
            ]),
            'budgetStatus' => $this->safe($user, 'budget status', fn () => $this->budgets->status($user), [
                'has_budget' => false,
                'low_balance' => false,
            ]),
            'spendSummary' => $this->safe($user, 'spend summary', fn () => $this->spend->summary((int) $user->id), [
                'net' => 0,
                'spent' => 0,
                'in_progress' => 0,
            ]),
            'spendCandles' => $this->safe($user, 'spend candles', fn () => $this->spend->candles((int) $user->id, 'day', [
                'from' => now()->subDays(13)->startOfDay(),
                'to' => now()->endOfDay(),
                'fill_gaps' => true,
            ]), ['has_spend' => false, 'series' => []]),
        ];
    }

    /**
     * One home CTA. Needs-you uses AdvertiserOrderStatus via stats['needs_action'].
     */
    public function resolvePrimaryAction(
        bool $isNewAdvertiser,
        int $needsAction,
        int $awaitingPayment,
        int $upcomingScheduled
    ): string {
        if ($isNewAdvertiser) {
            return 'get_started';
        }
        if ($needsAction > 0) {
            return 'needs_action';
        }
        if ($awaitingPayment > 0) {
            return 'awaiting_payment';
        }
        if ($upcomingScheduled > 0) {
            return 'scheduled';
        }

        return 'catalog';
    }

    public function welcomeSituation(
        bool $isNewAdvertiser,
        int $needsAction,
        int $awaitingPayment,
        int $upcomingScheduled
    ): string {
        if ($isNewAdvertiser) {
            return 'browse the catalog to buy placements';
        }
        if ($needsAction > 0) {
            return $needsAction === 1
                ? '1 order needs your attention'
                : $needsAction.' orders need your attention';
        }
        if ($awaitingPayment > 0) {
            return $awaitingPayment === 1
                ? '1 order is awaiting payment'
                : $awaitingPayment.' orders are awaiting payment';
        }
        if ($upcomingScheduled > 0) {
            return $upcomingScheduled === 1
                ? '1 publication is scheduled'
                : $upcomingScheduled.' publications are scheduled';
        }

        return 'you are caught up';
    }

    /**
     * Optional dashboard strips must not 500 the home page on leftover schema.
     */
    private function safe(User $user, string $context, callable $fn, mixed $fallback = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('Advertiser dashboard '.$context.' failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return array{
     *     total: int,
     *     completed: int,
     *     in_progress: int,
     *     cancelled: int,
     *     needs_review: int,
     *     needs_action: int,
     *     awaiting_payment: int
     * }
     */
    public function orderStats(int $userId): array
    {
        $base = Order::query()->where('user_id', $userId);

        $needsReview = AdvertiserOrderStatus::constrainReviewReady(clone $base)->count();
        $reviewWaitingUrl = (clone $base)
            ->where('status', 'review')
            ->whereDoesntHave('items', function ($items) {
                $items->whereNotNull('live_url')->where('live_url', '!=', '');
            })
            ->count();
        $needsAction = AdvertiserOrderStatus::needsActionCountForUser($userId);

        $inProgress = (clone $base)
            ->where(function ($q) {
                $q->where(function ($pendingPaid) {
                    $pendingPaid->where('status', 'pending')
                        ->where('payment_status', 'paid')
                        ->notAwaitingScheduledRelease();
                })->orWhere('status', 'processing');
            })
            ->count();

        $completed = (clone $base)->where('status', 'completed')->count();
        $cancelled = (clone $base)->where('status', 'cancelled')->count();
        $awaitingPayment = (clone $base)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 'paid');
            })
            ->count();
        $upcomingScheduled = (clone $base)
            ->awaitingScheduledRelease()
            ->where('payment_status', 'paid')
            ->count();

        return [
            'total' => $completed + $inProgress + $needsReview + $reviewWaitingUrl + $upcomingScheduled,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'cancelled' => $cancelled,
            'needs_review' => $needsReview,
            'needs_action' => $needsAction,
            'awaiting_payment' => $awaitingPayment,
        ];
    }

    protected function recentOrders(int $userId): Collection
    {
        return Order::query()
            ->where('user_id', $userId)
            ->with(['items' => function ($q) {
                $cols = [
                    'id',
                    'order_id',
                    'site_id',
                    'site_name',
                    'site_url',
                    'live_url',
                    'accepted_at',
                    'modification_requested',
                ];
                if (Schema::hasColumn('order_items', 'content_revision_requested')) {
                    $cols[] = 'content_revision_requested';
                }
                $q->select($cols);
            }, 'items.site'])
            ->latest()
            ->take(5)
            ->get();
    }

    /**
     * @return Collection<int, Order>
     */
    protected function needsActionOrders(int $userId): Collection
    {
        if ($userId <= 0) {
            return collect();
        }

        $with = ['items'];
        if (Schema::hasColumn('orders', 'project_id')) {
            $with[] = 'project:id,project_name';
        }

        return AdvertiserOrderStatus::needsActionQuery($userId)
            ->with($with)
            ->latest('id')
            ->take(8)
            ->get()
            ->each(function (Order $order) {
                $item = $order->items->first();
                $meta = AdvertiserOrderStatus::meta($order, $item);
                $order->setAttribute('status_label', $meta['label']);
                $order->setAttribute('next_action', $meta['next']);
            });
    }

    protected function recommendedSites(?User $user = null): Collection
    {
        $query = Site::query()->catalogVisible();

        if ($user) {
            $uid = (int) $user->id;
            $query->where(function ($q) use ($uid) {
                $q->whereNull('publisher_id')
                    ->orWhere('publisher_id', '!=', $uid);
            });
            if (Schema::hasColumn('sites', 'owner_id')) {
                $query->where(function ($q) use ($uid) {
                    $q->whereNull('owner_id')
                        ->orWhere('owner_id', '!=', $uid);
                });
            }
        }

        return $query
            ->orderByDesc('dr')
            ->orderByDesc('traffic')
            ->take(3)
            ->get()
            ->map(function ($site) {
                $site->display_price = $this->fees->advertiserBase((float) $site->price);

                return $site;
            });
    }

    /**
     * @return array{spendable: float, available: float, bonus: float, currency: string}
     */
    protected function walletStrip(User $user): array
    {
        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return [
                'spendable' => 0.0,
                'available' => 0.0,
                'bonus' => 0.0,
                'currency' => 'EUR',
            ];
        }

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->first();

        if (! $wallet) {
            return [
                'spendable' => 0.0,
                'available' => 0.0,
                'bonus' => 0.0,
                'currency' => 'EUR',
            ];
        }

        $summary = $this->walletOverview->summary((int) $user->id, $wallet);

        return [
            'spendable' => (float) ($summary['spendable_balance'] ?? 0),
            'available' => (float) ($summary['available_balance'] ?? 0),
            'bonus' => (float) ($summary['bonus_balance'] ?? 0),
            'currency' => (string) ($summary['currency'] ?? 'EUR'),
        ];
    }
}
