<?php

namespace App\Services\Publisher;

use App\Models\BulkSiteRequest;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Wallet\PayoutProfileService;
use App\Support\PublisherNeedsAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Server-rendered publisher home: work queues, honest money KPIs, charts.
 */
class PublisherDashboardService
{
    public function __construct(private PayoutProfileService $payouts) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $userId = (int) $user->id;
        $siteIds = $this->publisherSiteIds($userId);
        $siteQueues = $this->siteQueues($userId, $siteIds);
        $stats = $this->buildStatistics($siteIds);
        $needsYou = $this->safeInt(fn () => PublisherNeedsAction::needsYouCount($userId));
        $waitingOnAdvertiser = $this->safeInt(fn () => PublisherNeedsAction::waitingOnAdvertiserCount($userId));
        $unreadChat = $this->unreadChatCount($userId);
        $latestUnreadOrderId = $this->latestUnreadOrderId($userId);
        $openDisputes = $this->openDisputeCount($userId);
        $money = $this->moneyStrip($user);
        $primaryAction = $this->resolvePrimaryAction(
            $needsYou,
            $unreadChat,
            $openDisputes,
            $siteQueues,
            $money,
        );

        return array_merge($siteQueues, $money, [
            'needsYou' => $needsYou,
            'waitingOnAdvertiser' => $waitingOnAdvertiser,
            'unreadChat' => $unreadChat,
            'latestUnreadOrderId' => $latestUnreadOrderId,
            'openDisputes' => $openDisputes,
            'primaryAction' => $primaryAction,
            'attentionQueues' => $this->safeAttentionQueues(
                $primaryAction,
                $needsYou,
                $unreadChat,
                $latestUnreadOrderId,
                $openDisputes,
                $siteQueues,
                $money,
            ),
            'stats' => $stats,
            'metrics' => $this->buildPerformanceMetrics($stats),
            'recentTasks' => $this->buildRecentTasks($siteIds, $userId),
            'weeklyEarnings' => $this->buildWeeklyEarnings($siteIds),
            'monthlyEarnings' => $this->buildMonthlyEarnings($siteIds),
            'orderStatus' => $this->buildOrderStatusDistribution($siteIds),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyPayload(): array
    {
        $stats = $this->buildStatistics([]);
        $siteQueues = $this->emptySiteQueues();
        $money = $this->emptyMoneyStrip();

        return array_merge($siteQueues, $money, [
            'needsYou' => 0,
            'waitingOnAdvertiser' => 0,
            'unreadChat' => 0,
            'latestUnreadOrderId' => null,
            'openDisputes' => 0,
            'primaryAction' => 'add_site',
            'attentionQueues' => [],
            'stats' => $stats,
            'metrics' => $this->buildPerformanceMetrics($stats),
            'recentTasks' => [],
            'weeklyEarnings' => $this->buildWeeklyEarnings([]),
            'monthlyEarnings' => $this->buildMonthlyEarnings([]),
            'orderStatus' => $this->buildOrderStatusDistribution([]),
        ]);
    }

    /**
     * JSON /dashboard/statistics payload (legacy AJAX + tests).
     *
     * @return array<string, mixed>
     */
    public function statisticsPayload(User $user): array
    {
        try {
            $userId = (int) $user->id;
            $siteIds = $this->publisherSiteIds($userId);
            $stats = $this->buildStatistics($siteIds);
            $metrics = $this->buildPerformanceMetrics($stats);
            $siteQueues = $this->siteQueues($userId, $siteIds);
            $money = $this->moneyStrip($user);
            $needsYou = $this->safeInt(fn () => PublisherNeedsAction::needsYouCount($userId));
            $unreadChat = $this->unreadChatCount($userId);
            $openDisputes = $this->openDisputeCount($userId);

            return array_merge($stats, $metrics, $siteQueues, [
                'needs_you' => $needsYou,
                'waiting_on_advertiser' => $this->safeInt(fn () => PublisherNeedsAction::waitingOnAdvertiserCount($userId)),
                'unread_chat' => $unreadChat,
                'open_disputes' => $openDisputes,
                'debt_balance' => $money['debtBalance'],
                'reserved_balance' => $money['reservedBalance'],
                'payout_ready' => $money['payoutReady'],
                'pending_withdrawal_count' => $money['pendingWithdrawalCount'],
                'in_progress_earnings' => (float) ($stats['in_progress_earnings'] ?? 0),
                'primary_action' => $this->resolvePrimaryAction(
                    $needsYou,
                    $unreadChat,
                    $openDisputes,
                    $siteQueues,
                    $money,
                ),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Publisher dashboard statistics payload failed', ['error' => $e->getMessage()]);

            return $this->emptyStatisticsPayload();
        }
    }

    /**
     * Leftover-safe JSON body when a mixed Hostinger schema still throws.
     *
     * @return array<string, mixed>
     */
    public function emptyStatisticsPayload(): array
    {
        $stats = $this->buildStatistics([]);
        $siteQueues = $this->emptySiteQueues();
        $money = $this->emptyMoneyStrip();

        return array_merge($stats, $this->buildPerformanceMetrics($stats), $siteQueues, [
            'needs_you' => 0,
            'waiting_on_advertiser' => 0,
            'unread_chat' => 0,
            'open_disputes' => 0,
            'debt_balance' => $money['debtBalance'],
            'reserved_balance' => $money['reservedBalance'],
            'payout_ready' => $money['payoutReady'],
            'pending_withdrawal_count' => $money['pendingWithdrawalCount'],
            'in_progress_earnings' => 0.0,
            'primary_action' => 'add_site',
        ]);
    }

    /**
     * @return array<int>
     */
    public function publisherSiteIds(int $userId): array
    {
        return $this->safeList(function () use ($userId) {
            if (! $this->schemaHasColumn('sites', 'publisher_id')) {
                return [];
            }

            return Site::where('publisher_id', $userId)->pluck('id')->all();
        });
    }

    /**
     * @param  array<int>  $siteIds
     * @return list<array<string, mixed>>
     */
    public function recentTasksPayload(array $siteIds, int $userId): array
    {
        return $this->buildRecentTasks($siteIds, $userId);
    }

    /**
     * @param  array<int>  $siteIds
     * @return array{labels: list<string>, values: list<float>}
     */
    public function weeklyEarningsPayload(array $siteIds): array
    {
        return $this->buildWeeklyEarnings($siteIds);
    }

    /**
     * @param  array<int>  $siteIds
     * @return array{labels: list<string>, values: list<float>}
     */
    public function monthlyEarningsPayload(array $siteIds): array
    {
        return $this->buildMonthlyEarnings($siteIds);
    }

    /**
     * @param  array<int>  $siteIds
     * @return array{labels: list<string>, values: list<int>}
     */
    public function orderStatusPayload(array $siteIds): array
    {
        return $this->buildOrderStatusDistribution($siteIds);
    }

    /**
     * @param  array<string, mixed>  $siteQueues
     * @param  array<string, mixed>  $money
     */
    public function resolvePrimaryAction(
        int $needsYou,
        int $unreadChat,
        int $openDisputes,
        array $siteQueues,
        array $money,
    ): string {
        if ($needsYou > 0) {
            return 'tasks';
        }
        if ($unreadChat > 0) {
            return 'chat';
        }
        if ($openDisputes > 0) {
            return 'disputes';
        }
        if ((float) ($money['debtBalance'] ?? 0) > 0) {
            return 'debt';
        }
        if ((int) ($siteQueues['awaitingDetailsCount'] ?? 0) > 0 || (bool) ($siteQueues['bulkBlocking'] ?? false)) {
            return 'site_details';
        }
        if ((int) ($siteQueues['inviteCount'] ?? 0) > 0) {
            return 'invites';
        }
        if ((int) ($siteQueues['waitingOnStaffCount'] ?? 0) > 0) {
            return 'verify_sites';
        }
        if ((int) ($siteQueues['siteCount'] ?? 0) === 0) {
            return 'add_site';
        }
        if (! (bool) ($money['payoutReady'] ?? false)
            && (float) ($money['withdrawableBalance'] ?? 0) >= (float) ($money['minWithdrawalAmount'] ?? 20)
        ) {
            return 'payout';
        }

        return 'grow';
    }

    /**
     * @param  array<int>  $siteIds
     * @return array<string, mixed>
     */
    private function siteQueues(int $userId, array $siteIds): array
    {
        $empty = $this->emptySiteQueues();
        $empty['siteCount'] = count($siteIds);
        if ($siteIds === [] && $userId < 1) {
            return $empty;
        }

        $siteCount = $this->safeInt(function () use ($userId) {
            if (! $this->schemaHasColumn('sites', 'publisher_id')) {
                return 0;
            }

            return Site::where('publisher_id', $userId)->count();
        });
        $unverified = $this->safeInt(function () use ($userId) {
            if (! $this->schemaHasColumn('sites', 'publisher_id') || ! Site::hasSitesColumn('verified')) {
                return 0;
            }

            return Site::query()
                ->where('publisher_id', $userId)
                ->where(function ($q) {
                    $q->where('verified', 0)->orWhereNull('verified');
                })
                ->count();
        });
        $awaitingDetails = $this->safeInt(function () use ($userId) {
            if (! $this->schemaHasColumn('sites', 'publisher_id') || ! Site::hasSitesColumn('onboarding_status')) {
                return 0;
            }

            $query = Site::query()
                ->where('publisher_id', $userId)
                ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS);
            if ($this->cancelledBulkFilterReady()) {
                $query->notFromCancelledBulk();
            }

            return $query->count();
        });
        $detailsComplete = $this->safeInt(function () use ($userId) {
            if (! $this->schemaHasColumn('sites', 'publisher_id') || ! Site::hasSitesColumn('onboarding_status')) {
                return 0;
            }

            $query = Site::query()
                ->where('publisher_id', $userId)
                ->whereIn('onboarding_status', [
                    Site::ONBOARDING_DETAILS_COMPLETE,
                    Site::ONBOARDING_READY_FOR_REVIEW,
                ]);
            if ($this->cancelledBulkFilterReady()) {
                $query->notFromCancelledBulk();
            }

            return $query->count();
        });
        $invites = $this->safeInt(function () use ($userId) {
            if (! $this->schemaHasColumn('sites', 'publisher_id')) {
                return 0;
            }

            return Site::query()
                ->where('publisher_id', $userId)
                ->pendingPublisherAcceptance()
                ->count();
        });
        $bulkBlocking = $this->safeInt(function () use ($userId) {
            if (! $this->schemaHasColumn('bulk_site_requests', 'publisher_id')
                || ! $this->schemaHasColumn('bulk_site_requests', 'status')
            ) {
                return 0;
            }
            if ($this->schemaHasTable('bulk_site_request_items')
                && ! $this->schemaHasColumn('bulk_site_request_items', 'site_id')
            ) {
                return 0;
            }

            return BulkSiteRequest::query()
                ->where('publisher_id', $userId)
                ->blockingPublisher()
                ->count();
        }) > 0;
        $liveSites = $this->liveSitesCount($userId);
        $waitingOnStaff = max(0, $unverified - $awaitingDetails - $invites);

        return [
            'siteCount' => $siteCount,
            'unverifiedSiteCount' => $unverified,
            'awaitingDetailsCount' => $awaitingDetails,
            'detailsCompleteCount' => $detailsComplete,
            'inviteCount' => $invites,
            'bulkBlocking' => $bulkBlocking,
            'liveSiteCount' => $liveSites,
            'waitingOnStaffCount' => $waitingOnStaff,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySiteQueues(): array
    {
        return [
            'siteCount' => 0,
            'unverifiedSiteCount' => 0,
            'awaitingDetailsCount' => 0,
            'detailsCompleteCount' => 0,
            'inviteCount' => 0,
            'bulkBlocking' => false,
            'liveSiteCount' => 0,
            'waitingOnStaffCount' => 0,
        ];
    }

    private function liveSitesCount(int $userId): int
    {
        if (! $this->schemaHasColumn('sites', 'publisher_id') || ! Site::hasSitesColumn('active')) {
            return 0;
        }

        try {
            return (int) Site::query()
                ->where('publisher_id', $userId)
                ->catalogVisible()
                ->count();
        } catch (\Throwable $e) {
            Log::warning('Publisher dashboard live sites count failed', ['error' => $e->getMessage()]);
        }

        try {
            return (int) Site::query()
                ->where('publisher_id', $userId)
                ->active()
                ->notArchived()
                ->count();
        } catch (\Throwable) {
            return $this->safeInt(fn () => Site::query()
                ->where('publisher_id', $userId)
                ->where('active', 1)
                ->count());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function moneyStrip(User $user): array
    {
        $empty = $this->emptyMoneyStrip();

        try {
            if (! Wallet::tableAvailable()) {
                return $empty;
            }
            $wallet = $user->activeWallet();
        } catch (\Throwable) {
            return $empty;
        }

        $available = 0.0;
        $withdrawable = 0.0;
        $reserved = 0.0;
        $debt = 0.0;
        try {
            if ($wallet && Wallet::hasTableColumn('balance')) {
                $available = (float) $wallet->balance;
            }
        } catch (\Throwable) {
            $available = 0.0;
        }
        try {
            $withdrawable = $wallet ? $wallet->withdrawableBalance() : 0.0;
        } catch (\Throwable) {
            $withdrawable = $available;
        }
        try {
            if ($wallet && Wallet::hasTableColumn('reserved_balance')) {
                $reserved = (float) $wallet->reserved_balance;
            }
        } catch (\Throwable) {
            $reserved = 0.0;
        }
        try {
            if ($wallet && Wallet::hasTableColumn('debt_balance')) {
                $debt = $wallet->debtBalance();
            }
        } catch (\Throwable) {
            $debt = 0.0;
        }

        $payoutReady = false;
        try {
            $payoutReady = $this->payouts->availableMethods($user) !== [];
        } catch (\Throwable) {
            $payoutReady = false;
        }

        $pendingCount = 0;
        $pendingAmount = 0.0;
        try {
            if (Withdrawal::tableAvailable()
                && Withdrawal::hasTableColumn('status')
                && Withdrawal::hasTableColumn('user_id')
            ) {
                $pending = Withdrawal::query()
                    ->where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'processing']);
                $pendingCount = (int) (clone $pending)->count();
                if (Withdrawal::hasTableColumn('amount')) {
                    $pendingAmount = round((float) (clone $pending)->sum('amount'), 2);
                }
            }
        } catch (\Throwable) {
            $pendingCount = 0;
            $pendingAmount = 0.0;
        }

        return [
            'availableBalance' => $available,
            'withdrawableBalance' => $withdrawable,
            'reservedBalance' => $reserved,
            'debtBalance' => $debt,
            'payoutReady' => $payoutReady,
            'pendingWithdrawalCount' => $pendingCount,
            'pendingWithdrawalAmount' => $pendingAmount,
            'minWithdrawalAmount' => max(0.01, round((float) config('billing.withdrawal_min_amount', 20), 2)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMoneyStrip(): array
    {
        return [
            'availableBalance' => 0.0,
            'withdrawableBalance' => 0.0,
            'reservedBalance' => 0.0,
            'debtBalance' => 0.0,
            'payoutReady' => false,
            'pendingWithdrawalCount' => 0,
            'pendingWithdrawalAmount' => 0.0,
            'minWithdrawalAmount' => max(0.01, round((float) config('billing.withdrawal_min_amount', 20), 2)),
        ];
    }

    private function unreadChatCount(int $userId): int
    {
        return $this->safeInt(function () use ($userId) {
            if (! $this->unreadChatReady()) {
                return 0;
            }

            return $this->unreadChatQuery($userId)->count();
        });
    }

    private function latestUnreadOrderId(int $userId): ?int
    {
        try {
            if (! $this->unreadChatReady()) {
                return null;
            }
            $row = $this->unreadChatQuery($userId)->orderByDesc('created_at')->first(['order_id']);

            return $row ? (int) $row->order_id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function unreadChatQuery(int $userId)
    {
        return OrderChatMessage::query()
            ->where('sender_type', 'advertiser')
            ->where('is_read', false)
            ->notBlocked()
            ->whereHas('order', function ($q) use ($userId) {
                $q->where('payment_status', 'paid')
                    ->where('status', '!=', 'cancelled')
                    ->whereHas('items.site', function ($sites) use ($userId) {
                        $sites->where('publisher_id', $userId);
                    });
            });
    }

    private function openDisputeCount(int $userId): int
    {
        return $this->safeInt(function () use ($userId) {
            if (! OrderItemDispute::tableAvailable()
                || ! $this->schemaHasColumn('order_item_disputes', 'status')
                || ! $this->orderItemsReady()
                || ! $this->schemaHasColumn('sites', 'publisher_id')
            ) {
                return 0;
            }

            return OrderItemDispute::query()
                ->where('status', OrderItemDispute::STATUS_OPEN)
                ->whereHas('orderItem.site', function ($q) use ($userId) {
                    $q->where('publisher_id', $userId);
                })
                ->count();
        });
    }

    /**
     * @param  array<string, mixed>  $siteQueues
     * @param  array<string, mixed>  $money
     * @return list<array<string, mixed>>
     */
    private function safeAttentionQueues(
        string $primaryAction,
        int $needsYou,
        int $unreadChat,
        ?int $latestUnreadOrderId,
        int $openDisputes,
        array $siteQueues,
        array $money,
    ): array {
        try {
            return $this->attentionQueues(
                $primaryAction,
                $needsYou,
                $unreadChat,
                $latestUnreadOrderId,
                $openDisputes,
                $siteQueues,
                $money,
            );
        } catch (\Throwable $e) {
            Log::warning('Publisher dashboard attention queues failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $siteQueues
     * @param  array<string, mixed>  $money
     * @return list<array<string, mixed>>
     */
    private function attentionQueues(
        string $primaryAction,
        int $needsYou,
        int $unreadChat,
        ?int $latestUnreadOrderId,
        int $openDisputes,
        array $siteQueues,
        array $money,
    ): array {
        $queues = [];

        if ($needsYou > 0) {
            $queues[] = [
                'key' => 'tasks',
                'count' => $needsYou,
                'href' => route('publisher.tasks', ['needs_action' => 1]),
                'label' => 'Needs you',
                'detail' => 'Accept, publish, or reply to a change',
                'icon' => 'fa-tasks',
            ];
        }
        if ($unreadChat > 0) {
            $chatParams = ['focus' => 'messages'];
            if ($latestUnreadOrderId) {
                $chatParams['order'] = $latestUnreadOrderId;
            }
            $queues[] = [
                'key' => 'chat',
                'count' => $unreadChat,
                'href' => route('publisher.tasks', $chatParams),
                'label' => 'Unread chat',
                'detail' => 'Advertiser messages waiting',
                'icon' => 'fa-comments',
            ];
        }
        if ($openDisputes > 0) {
            $queues[] = [
                'key' => 'disputes',
                'count' => $openDisputes,
                'href' => route('publisher.tasks'),
                'label' => 'Open disputes',
                'detail' => 'A placement was reported',
                'icon' => 'fa-gavel',
            ];
        }
        if ((float) ($money['debtBalance'] ?? 0) > 0) {
            $queues[] = [
                'key' => 'debt',
                'count' => 1,
                'href' => route('publisher.balance'),
                'label' => 'Clawback debt',
                'detail' => '€'.number_format((float) $money['debtBalance'], 2).' blocks withdrawals',
                'icon' => 'fa-ban',
            ];
        }
        $awaiting = (int) ($siteQueues['awaitingDetailsCount'] ?? 0);
        $bulk = (bool) ($siteQueues['bulkBlocking'] ?? false);
        if ($awaiting > 0 || $bulk) {
            $queues[] = [
                'key' => 'site_details',
                'count' => max($awaiting, $bulk ? 1 : 0),
                'href' => $bulk
                    ? route('publisher.bulk-sites.complete')
                    : route('publisher.websites', ['status' => 'pending']),
                'label' => $bulk ? 'Finish bulk listings' : 'Finish listing details',
                'detail' => $awaiting > 0
                    ? $awaiting.' site'.($awaiting === 1 ? '' : 's').' still need niche, language, or price'
                    : 'A bulk request is still waiting on you',
                'icon' => 'fa-pen',
            ];
        }
        $invites = (int) ($siteQueues['inviteCount'] ?? 0);
        if ($invites > 0) {
            $queues[] = [
                'key' => 'invites',
                'count' => $invites,
                'href' => route('publisher.websites', ['status' => 'invites']),
                'label' => 'Site invites',
                'detail' => 'Assigned listings waiting for you to accept',
                'icon' => 'fa-envelope-open',
            ];
        }
        $staff = (int) ($siteQueues['waitingOnStaffCount'] ?? 0);
        if ($staff > 0) {
            $queues[] = [
                'key' => 'verify_sites',
                'count' => $staff,
                'href' => route('publisher.websites', ['status' => 'pending']),
                'label' => 'Awaiting verification',
                'detail' => 'With staff — advertisers cannot rely on them yet',
                'icon' => 'fa-hourglass-half',
            ];
        }
        if (! (bool) ($money['payoutReady'] ?? false)
            && (float) ($money['withdrawableBalance'] ?? 0) >= (float) ($money['minWithdrawalAmount'] ?? 20)
        ) {
            $queues[] = [
                'key' => 'payout',
                'count' => 1,
                'href' => route('publisher.withdraw'),
                'label' => 'Set up payout',
                'detail' => '€'.number_format((float) $money['withdrawableBalance'], 2).' ready to withdraw',
                'icon' => 'fa-university',
            ];
        }
        if ((int) ($money['pendingWithdrawalCount'] ?? 0) > 0) {
            $queues[] = [
                'key' => 'withdrawal',
                'count' => (int) $money['pendingWithdrawalCount'],
                'href' => route('publisher.withdraw'),
                'label' => 'Withdrawal in flight',
                'detail' => '€'.number_format((float) $money['pendingWithdrawalAmount'], 2).' waiting to be paid',
                'icon' => 'fa-money-bill-wave',
            ];
        }

        return array_values(array_filter(
            $queues,
            fn (array $row) => ($row['key'] ?? '') !== $primaryAction
        ));
    }

    /**
     * @param  array<int>  $siteIds
     * @return array<int>
     */
    private function visibleOrderIds(array $siteIds): array
    {
        if ($siteIds === [] || ! $this->orderItemsReady() || ! $this->paidOrdersReady()) {
            return [];
        }

        return $this->safeList(function () use ($siteIds) {
            return OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid');
                })
                ->pluck('order_id')
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * @param  array<int>  $siteIds
     * @return array<string, float|int>
     */
    public function buildStatistics(array $siteIds): array
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

        $empty['total_sites'] = count($siteIds);

        if (! $this->orderItemsReady() || ! $this->paidOrdersReady()) {
            return $empty;
        }

        try {
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
                'success_rate' => $successRate,
                'total_earnings' => $this->sumPublisherPayout($siteIds, 'completed'),
                'pending_earnings' => $this->sumPublisherPayout($siteIds, 'review'),
                'in_progress_earnings' => $this->sumPublisherPayout($siteIds, 'processing'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Publisher dashboard statistics failed', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * @param  array<string, float|int>  $stats
     * @return array<string, float>
     */
    public function buildPerformanceMetrics(array $stats): array
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
     * Needs-you first, then other recent paid items. Deep-links Open to the task.
     *
     * @param  array<int>  $siteIds
     * @return list<array<string, mixed>>
     */
    public function buildRecentTasks(array $siteIds, int $userId = 0): array
    {
        if ($siteIds === [] || ! $this->orderItemsReady() || ! $this->paidOrdersReady()) {
            return [];
        }

        try {
            $needsYouIds = [];
            if ($userId > 0) {
                $needsYouIds = PublisherNeedsAction::needsYouQuery($userId)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->pluck('id')
                    ->all();
            }

            $picked = [];
            if ($needsYouIds !== []) {
                $needsItems = OrderItem::query()
                    ->whereIn('id', $needsYouIds)
                    ->whereHas('order', function ($q) {
                        $q->where('payment_status', 'paid');
                    })
                    ->with(['order', 'site'])
                    ->get()
                    ->sortBy(fn (OrderItem $item) => array_search($item->id, $needsYouIds, true));
                foreach ($needsItems as $item) {
                    $row = $this->mapTaskRow($item, true);
                    if ($row !== null) {
                        $picked[] = $row;
                    }
                }
            }

            $remaining = 5 - count($picked);
            if ($remaining > 0) {
                $fill = OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function ($q) {
                        $q->where('payment_status', 'paid');
                    })
                    ->when($needsYouIds !== [], fn ($q) => $q->whereNotIn('id', $needsYouIds))
                    ->with(['order', 'site'])
                    ->orderByDesc('created_at')
                    ->take($remaining)
                    ->get();
                foreach ($fill as $item) {
                    $row = $this->mapTaskRow($item, false);
                    if ($row !== null) {
                        $picked[] = $row;
                    }
                }
            }

            return $picked;
        } catch (\Throwable $e) {
            Log::warning('Publisher dashboard recent tasks failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapTaskRow(OrderItem $item, bool $needsYou): ?array
    {
        if (! $item->order) {
            return null;
        }

        $orderId = (int) $item->order->id;

        return [
            'order_id' => $orderId,
            'order_number' => $item->order->order_number,
            'status' => $item->order->isAwaitingScheduledRelease()
                ? 'scheduled'
                : $item->order->status,
            'payout' => $item->publisherPayoutAmount(),
            'created_at' => optional($item->created_at)?->toIso8601String(),
            'created_at_human' => optional($item->created_at)?->diffForHumans(),
            'site_name' => $item->site_name,
            'site_url' => $item->site_url,
            'needs_you' => $needsYou,
            'open_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $orderId]),
        ];
    }

    /**
     * @param  array<int>  $siteIds
     * @return array{labels: list<string>, values: list<float>}
     */
    public function buildWeeklyEarnings(array $siteIds): array
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
    public function buildMonthlyEarnings(array $siteIds): array
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

    private function completionTimestampSql(): string
    {
        try {
            if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'completed_at')) {
                return 'COALESCE(order_items.completed_at, orders.updated_at)';
            }
        } catch (\Throwable) {
            // Leftover Hostinger: inspect failed — use order.updated_at.
        }

        return 'orders.updated_at';
    }

    /**
     * @param  array<int>  $siteIds
     */
    private function completedEarningsQuery(array $siteIds)
    {
        $query = OrderItem::query()
            ->whereIn('order_items.site_id', $siteIds)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.payment_status', ['paid', 'refunded']);

        return $query->where(function ($q) {
            $q->where('orders.status', 'completed');
            if ($this->schemaHasColumn('orders', 'completed_at')) {
                $q->orWhereNotNull('orders.completed_at');
            }
        });
    }

    /**
     * @param  array<int>  $siteIds
     */
    private function netEarningsInWindow(array $siteIds, Carbon $start, Carbon $end): float
    {
        if (! $this->orderItemsReady() || ! $this->paidOrdersReady() || ! $this->schemaHasColumn('order_items', 'price')) {
            return 0.0;
        }

        try {
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
        } catch (\Throwable) {
            return 0.0;
        }
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
                        $order->where('status', 'completed');
                        if ($this->schemaHasColumn('orders', 'completed_at')) {
                            $order->orWhereNotNull('completed_at');
                        }
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
                        $order->where('status', 'completed');
                        if ($this->schemaHasColumn('orders', 'completed_at')) {
                            $order->orWhereNotNull('completed_at');
                        }
                    });
                $this->constrainOrderRefundedInWindow($q, $start, $end);
            })
            ->sum(OrderItem::publisherPayoutSqlExpression());
    }

    private function constrainOrderRefundedInWindow($query, Carbon $start, Carbon $end): void
    {
        if (! $this->schemaHasTable('wallet_transactions')
            || ! $this->schemaHasColumn('wallet_transactions', 'related_id')
            || ! $this->schemaHasColumn('wallet_transactions', 'related_type')
            || ! $this->schemaHasColumn('wallet_transactions', 'type')
            || ! $this->schemaHasColumn('wallet_transactions', 'direction')
            || ! $this->schemaHasColumn('wallet_transactions', 'created_at')
        ) {
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
    public function buildOrderStatusDistribution(array $siteIds): array
    {
        $labels = ['Pending', 'Processing', 'In Review', 'Scheduled', 'Completed', 'Cancelled'];

        if ($siteIds === []) {
            return [
                'labels' => $labels,
                'values' => [0, 0, 0, 0, 0, 0],
            ];
        }

        try {
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
        } catch (\Throwable) {
            return [
                'labels' => $labels,
                'values' => [0, 0, 0, 0, 0, 0],
            ];
        }
    }

    /**
     * @param  array<int>  $siteIds
     */
    private function sumPublisherPayout(array $siteIds, string $orderStatus): float
    {
        if (! $this->schemaHasColumn('order_items', 'price')) {
            return 0.0;
        }

        try {
            return round((float) OrderItem::whereIn('site_id', $siteIds)
                ->recognizedForFinance()
                ->whereHas('order', function ($q) use ($orderStatus) {
                    $q->where('status', $orderStatus)
                        ->where('payment_status', 'paid');
                })
                ->sum(OrderItem::publisherPayoutSqlExpression()), 2);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function unreadChatReady(): bool
    {
        return $this->schemaHasColumn('order_chat_messages', 'is_read')
            && $this->schemaHasColumn('order_chat_messages', 'sender_type')
            && $this->schemaHasColumn('order_chat_messages', 'order_id')
            && $this->orderItemsReady()
            && $this->paidOrdersReady()
            && $this->schemaHasColumn('sites', 'publisher_id');
    }

    private function orderItemsReady(): bool
    {
        return $this->schemaHasColumn('order_items', 'site_id')
            && $this->schemaHasColumn('order_items', 'order_id');
    }

    private function paidOrdersReady(): bool
    {
        return $this->schemaHasColumn('orders', 'payment_status')
            && $this->schemaHasColumn('orders', 'status');
    }

    private function cancelledBulkFilterReady(): bool
    {
        return $this->schemaHasTable('bulk_site_requests')
            && $this->schemaHasColumn('bulk_site_requests', 'status');
    }

    private function schemaHasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function schemaHasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeInt(callable $resolve): int
    {
        try {
            return (int) $resolve();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function safeList(callable $resolve): array
    {
        try {
            $value = $resolve();

            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
