<?php

namespace App\Services\Advertiser;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\PlatformFeeService;
use App\Services\Wallet\WalletOverviewService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Support\Collection;

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
     *     spendCandles: array<string, mixed>
     * }
     */
    public function build(User $user): array
    {
        $stats = $this->orderStats((int) $user->id);
        $isNewAdvertiser = ! Order::query()->where('user_id', $user->id)->exists();
        $upcomingScheduledCount = $this->scheduler->upcomingCount((int) $user->id);

        return [
            'stats' => $stats,
            'recentOrders' => $this->recentOrders((int) $user->id),
            'recommendedSites' => $this->recommendedSites($user),
            'hasOrderableArticle' => ContentSubmission::query()
                ->where('user_id', $user->id)
                ->orderable()
                ->exists(),
            'isNewAdvertiser' => $isNewAdvertiser,
            'upcomingScheduledCount' => $upcomingScheduledCount,
            'wallet' => $this->walletStrip($user),
            'budgetStatus' => $this->budgets->status($user),
            'spendSummary' => $this->spend->summary((int) $user->id),
            'spendCandles' => $this->spend->candles((int) $user->id, 'day', [
                'from' => now()->subDays(13)->startOfDay(),
                'to' => now()->endOfDay(),
                'fill_gaps' => true,
            ]),
        ];
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

        $needsReview = (clone $base)->where('status', 'review')->count();
        $needsAction = AdvertiserOrderStatus::needsActionCountForUser($userId);

        $inProgress = (clone $base)
            ->where(function ($q) {
                $q->where(function ($pendingPaid) {
                    $pendingPaid->where('status', 'pending')
                        ->where('payment_status', 'paid');
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

        return [
            'total' => $completed + $inProgress + $needsReview,
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
                $q->select('id', 'order_id', 'site_id', 'site_name', 'site_url');
            }, 'items.site'])
            ->latest()
            ->take(5)
            ->get();
    }

    protected function recommendedSites(?User $user = null): Collection
    {
        $query = Site::query()
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('verified', 1)->orWhere('verified', true);
            });

        if ($user) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('publisher_id')
                    ->orWhere('publisher_id', '!=', $user->id);
            });
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
