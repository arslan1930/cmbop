<?php

namespace App\Services\Admin;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceOverviewService
{
    /**
     * @return array{start: ?Carbon, end: Carbon, label: string, key: string}
     */
    public function resolvePeriod(?string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $end = now()->endOfDay();
        $from = $this->parseDay($dateFrom, false);
        $to = $this->parseDay($dateTo, true);

        if ($from || $to) {
            $start = $from;
            $end = $to ?? $end;

            return [
                'start' => $start,
                'end' => $end,
                'label' => trim(($from?->toDateString() ?: '…').' → '.($to?->toDateString() ?: 'today')),
                'key' => 'custom',
            ];
        }

        return match ($period) {
            'week' => [
                'start' => now()->startOfWeek(),
                'end' => $end,
                'label' => 'This week',
                'key' => 'week',
            ],
            'all' => [
                'start' => null,
                'end' => $end,
                'label' => 'All time',
                'key' => 'all',
            ],
            default => [
                'start' => now()->startOfMonth(),
                'end' => $end,
                'label' => 'This month',
                'key' => 'month',
            ],
        };
    }

    /**
     * Full finance hub payload.
     *
     * @return array<string, mixed>
     */
    public function overview(array $period): array
    {
        $start = $period['start'];
        $end = $period['end'];

        $ops = $this->opsQueues();
        $liability = $this->walletLiability();
        $moneyIn = $this->moneyIn($start, $end);
        $moneyOut = $this->moneyOut($start, $end);
        $platform = $this->platform($start, $end);
        $cashSplit = $this->cashVsInternal($start, $end);

        $platform['margin'] = round(
            $platform['order_fees']
            + $platform['withdrawal_fees']
            - $platform['refunded_order_fees']
            - $platform['bonuses_issued'],
            2
        );

        return [
            'period' => $period,
            'ops' => $ops,
            'liability' => $liability,
            'money_in' => $moneyIn,
            'money_out' => $moneyOut,
            'platform' => $platform,
            'cash_split' => $cashSplit,
            'payable_now' => $liability['total_publisher_liability'],
            'due_to_pay_now' => $liability['due_to_pay_now'],
            'in_publisher_wallets' => $liability['in_publisher_wallets'],
            'total_publisher_liability' => $liability['total_publisher_liability'],
            'clocks' => [
                'deposits' => 'approved_at',
                'orders_paid' => 'paid_at',
                'completed' => 'completed_at',
                'refunds' => 'updated_at',
                'withdrawals_paid' => 'processed_at',
                'ledger' => 'created_at',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function opsQueues(): array
    {
        $pendingDeposits = DepositRequest::where('status', 'pending');
        $userMarked = (clone $pendingDeposits)->whereNotNull('user_marked_paid_at');
        $openWithdrawals = Withdrawal::whereIn('status', ['pending', 'processing']);
        $pendingPayments = Order::query()->unpaidOps();

        return [
            'pending_deposits' => [
                'count' => (clone $pendingDeposits)->count(),
                'amount' => (float) (clone $pendingDeposits)->sum('amount'),
                'user_marked_paid_count' => (clone $userMarked)->count(),
                'user_marked_paid_amount' => (float) (clone $userMarked)->sum('amount'),
                'url' => route('admin.deposits', ['status' => 'pending']),
            ],
            'open_withdrawals' => [
                'count' => (clone $openWithdrawals)->count(),
                'amount' => (float) (clone $openWithdrawals)->sum('net_amount'),
                'url' => route('admin.withdrawals', ['queue' => 'open']),
            ],
            'unpaid_orders' => [
                'count' => (clone $pendingPayments)->count(),
                'amount' => (float) (clone $pendingPayments)->sum('total_amount'),
                'url' => route('admin.payments', ['payment_status' => 'unpaid']),
            ],
            'publisher_debt' => $this->publisherDebt(),
        ];
    }

    /**
     * Outstanding publisher clawback debt (blocks their withdrawals).
     *
     * @return array{count: int, amount: float, rows: list<array<string, mixed>>, url: string}
     */
    public function publisherDebt(): array
    {
        $empty = [
            'count' => 0,
            'amount' => 0.0,
            'rows' => [],
            'url' => route('admin.finance').'#finance-debt',
        ];

        if (! Schema::hasColumn('wallets', 'debt_balance')) {
            return $empty;
        }

        $query = Wallet::query()->where('debt_balance', '>', 0);
        $publisherRoleId = Wallet::publisherRoleId();
        if ($publisherRoleId) {
            $query->where('role_id', $publisherRoleId);
        }

        $rows = (clone $query)
            ->with('user:id,name,email')
            ->orderByDesc('debt_balance')
            ->limit(8)
            ->get()
            ->map(fn (Wallet $wallet) => [
                'user_id' => $wallet->user_id,
                'name' => $wallet->user?->name ?? 'User #'.$wallet->user_id,
                'email' => $wallet->user?->email,
                'debt' => round((float) $wallet->debt_balance, 2),
                'url' => route('admin.finance.user', $wallet->user_id),
            ])
            ->all();

        return [
            'count' => (clone $query)->count(),
            'amount' => round((float) (clone $query)->sum('debt_balance'), 2),
            'rows' => $rows,
            'url' => route('admin.finance').'#finance-debt',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function walletLiability(): array
    {
        $advertiserRoleId = Wallet::advertiserRoleId();
        $publisherRoleId = Wallet::publisherRoleId();
        $hasBonus = $this->hasBonusColumns();

        $adv = [
            'balance' => 0.0,
            'bonus' => 0.0,
            'reserved' => 0.0,
            'cash' => 0.0,
        ];
        $pub = [
            'balance' => 0.0,
            'bonus' => 0.0,
            'reserved' => 0.0,
            'withdrawable' => 0.0,
        ];

        // Sum per-wallet withdrawable/cash. Do NOT use
        // SUM(balance) - min(SUM(bonus), SUM(balance)) — that under/over-counts
        // when bonus is uneven across wallets.
        if ($advertiserRoleId) {
            $columns = ['balance', 'reserved_balance'];
            if ($hasBonus) {
                $columns[] = 'bonus_balance';
            }
            $wallets = Wallet::where('role_id', $advertiserRoleId)->get($columns);
            foreach ($wallets as $wallet) {
                $balance = (float) $wallet->balance;
                $bonus = $hasBonus ? (float) ($wallet->bonus_balance ?? 0) : 0.0;
                $lockedBonus = min($bonus, $balance);
                $adv['balance'] += $balance;
                $adv['bonus'] += $bonus;
                $adv['reserved'] += (float) $wallet->reserved_balance;
                $adv['cash'] += max(0, round($balance - $lockedBonus, 2));
            }
            $adv['balance'] = round($adv['balance'], 2);
            $adv['bonus'] = round($adv['bonus'], 2);
            $adv['reserved'] = round($adv['reserved'], 2);
            $adv['cash'] = round($adv['cash'], 2);
        }

        $topPublishers = [];
        if ($publisherRoleId) {
            $wallets = Wallet::where('role_id', $publisherRoleId)
                ->with('user:id,name,email')
                ->get();
            foreach ($wallets as $wallet) {
                $balance = (float) $wallet->balance;
                $bonus = $hasBonus ? (float) $wallet->bonus_balance : 0.0;
                $lockedBonus = min($bonus, $balance);
                $withdrawable = max(0, round($balance - $lockedBonus, 2));
                $pub['balance'] += $balance;
                $pub['bonus'] += $bonus;
                $pub['reserved'] += (float) $wallet->reserved_balance;
                $pub['withdrawable'] += $withdrawable;

                if ($withdrawable > 0) {
                    $topPublishers[] = [
                        'user_id' => $wallet->user_id,
                        'name' => $wallet->user?->name ?? 'User #'.$wallet->user_id,
                        'email' => $wallet->user?->email,
                        'withdrawable' => $withdrawable,
                        'url' => route('admin.finance.user', $wallet->user_id),
                    ];
                }
            }
            $pub['balance'] = round($pub['balance'], 2);
            $pub['bonus'] = round($pub['bonus'], 2);
            $pub['reserved'] = round($pub['reserved'], 2);
            $pub['withdrawable'] = round($pub['withdrawable'], 2);

            usort($topPublishers, fn ($a, $b) => $b['withdrawable'] <=> $a['withdrawable']);
            $topPublishers = array_slice($topPublishers, 0, 8);
        }

        $openWithdrawals = Withdrawal::with('user:id,name,email')
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $openWithdrawalNets = round((float) $openWithdrawals->sum('net_amount'), 2);

        $openWithdrawalRows = $openWithdrawals->take(8)->map(fn (Withdrawal $w) => [
            'id' => $w->id,
            'user_id' => $w->user_id,
            'name' => $w->user?->name ?? 'User #'.$w->user_id,
            'email' => $w->user?->email,
            'net_amount' => (float) $w->net_amount,
            'status' => $w->status,
            'url' => route('admin.withdrawals.show', $w->id),
        ])->all();

        // What admin must send outside the app today (payout queue).
        $dueToPayNow = $openWithdrawalNets;
        // Earnings still sitting in publisher wallets (not requested yet).
        $inPublisherWallets = $pub['withdrawable'];
        // Total you owe publishers eventually.
        $totalPublisherLiability = round($dueToPayNow + $inPublisherWallets, 2);

        return [
            'advertiser' => $adv,
            'publisher' => $pub,
            'open_withdrawal_nets' => $openWithdrawalNets,
            'due_to_pay_now' => $dueToPayNow,
            'in_publisher_wallets' => $inPublisherWallets,
            'total_publisher_liability' => $totalPublisherLiability,
            // Back-compat: old "payable_now" mixed both buckets and confused ops.
            // Keep key but point at total liability; UI now labels buckets clearly.
            'payable_now' => $totalPublisherLiability,
            'open_reserved_total' => round($adv['reserved'] + $pub['reserved'], 2),
            'top_publisher_wallets' => $topPublishers,
            'open_withdrawal_rows' => $openWithdrawalRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function moneyIn(?Carbon $start, Carbon $end): array
    {
        $depositsCompleted = DepositRequest::where('status', 'completed');
        $this->applyCreatedOrPaidWindow($depositsCompleted, $start, $end, 'approved_at');

        $depositsByMethod = (clone $depositsCompleted)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(fn ($r) => [
                (string) ($r->payment_method ?: 'unknown') => [
                    'count' => (int) $r->count,
                    'amount' => (float) $r->total,
                ],
            ])
            ->all();

        $paidOrders = Order::where('payment_status', 'paid');
        $this->applyPaidWindow($paidOrders, $start, $end);

        $ordersByMethod = (clone $paidOrders)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(fn ($r) => [
                (string) ($r->payment_method ?: 'unknown') => [
                    'count' => (int) $r->count,
                    'amount' => (float) $r->total,
                ],
            ])
            ->all();

        $gmv = (float) (clone $paidOrders)->sum('total_amount');
        $stripeOrders = (float) (clone $paidOrders)->where('payment_method', 'card')->sum('total_amount');
        $walletOrders = (float) (clone $paidOrders)->where('payment_method', 'wallet')->sum('total_amount');
        $manualOrders = (float) (clone $paidOrders)
            ->whereIn('payment_method', ['wise', 'bank', 'crypto'])
            ->sum('total_amount');

        $depositsTotal = (float) (clone $depositsCompleted)->sum('amount');
        $stripeDeposits = (float) (clone $depositsCompleted)
            ->where(function ($q) {
                $q->where('payment_method', 'card')
                    ->orWhere('payment_method', 'stripe')
                    ->orWhereNotNull('stripe_session_id');
            })
            ->sum('amount');
        // Avoid double-counting stripe: prefer method card/stripe; if using stripe_session_id only, still ok
        $manualDeposits = (float) (clone $depositsCompleted)
            ->whereIn('payment_method', ['wise', 'bank', 'crypto'])
            ->sum('amount');

        $bonuses = WalletTransaction::where('type', WalletTransaction::TYPE_BONUS_CREDIT);
        $this->applyCreatedWindow($bonuses, $start, $end);

        return [
            'deposits_completed' => [
                'count' => (clone $depositsCompleted)->count(),
                'amount' => $depositsTotal,
                'by_method' => $depositsByMethod,
                'stripe' => $stripeDeposits,
                'manual' => $manualDeposits,
            ],
            'orders_paid' => [
                'count' => (clone $paidOrders)->count(),
                'gmv' => $gmv,
                'by_method' => $ordersByMethod,
                'stripe_card' => $stripeOrders,
                'wallet' => $walletOrders,
                'manual' => $manualOrders,
            ],
            'bonuses_issued' => [
                'count' => (clone $bonuses)->count(),
                'amount' => (float) (clone $bonuses)->sum('amount'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function moneyOut(?Carbon $start, Carbon $end): array
    {
        $earningsQuery = OrderItem::query()
            ->whereHas('order', function ($q) use ($start, $end) {
                $q->where('status', 'completed')->where('payment_status', 'paid');
                $this->applyCompletedWindow($q, $start, $end);
            });

        $earnings = (float) (clone $earningsQuery)->sum(OrderItem::publisherPayoutSqlExpression());
        $earningsCount = (clone $earningsQuery)->count();

        $ledgerEarnings = WalletTransaction::where('type', WalletTransaction::TYPE_TRANSFER_IN);
        $this->applyCreatedWindow($ledgerEarnings, $start, $end);

        $paidWithdrawals = Withdrawal::where('status', 'completed');
        $this->applyCoalesceWindow($paidWithdrawals, $start, $end, 'withdrawals.processed_at', 'withdrawals.updated_at');

        $openWithdrawals = Withdrawal::whereIn('status', ['pending', 'processing']);

        return [
            'earnings_credited' => [
                'count' => $earningsCount,
                'amount' => round($earnings, 2),
                'ledger_transfer_in' => (float) (clone $ledgerEarnings)->sum('amount'),
            ],
            'withdrawals_paid' => [
                'count' => (clone $paidWithdrawals)->count(),
                'gross' => (float) (clone $paidWithdrawals)->sum('amount'),
                'net' => (float) (clone $paidWithdrawals)->sum('net_amount'),
                'fees' => (float) (clone $paidWithdrawals)->sum('fee'),
            ],
            'withdrawals_open' => [
                'count' => (clone $openWithdrawals)->count(),
                'net' => (float) (clone $openWithdrawals)->sum('net_amount'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function platform(?Carbon $start, Carbon $end): array
    {
        $feeItems = OrderItem::query()
            ->whereHas('order', function ($q) use ($start, $end) {
                $q->where('status', 'completed')->where('payment_status', 'paid');
                $this->applyCompletedWindow($q, $start, $end);
            });

        $orderFees = (float) (clone $feeItems)->sum(OrderItem::platformFeeSqlExpression());
        $completedPaid = Order::where('status', 'completed')->where('payment_status', 'paid');
        $this->applyCompletedWindow($completedPaid, $start, $end);
        $gmvCompleted = (float) $completedPaid->sum('total_amount');

        $withdrawalFees = Withdrawal::where('status', 'completed');
        $this->applyCoalesceWindow($withdrawalFees, $start, $end, 'withdrawals.processed_at', 'withdrawals.updated_at');
        $withdrawalFeeSum = (float) (clone $withdrawalFees)->sum('fee');

        $refundOrders = Order::where('payment_status', 'refunded');
        $this->applyRefundWindow($refundOrders, $start, $end);
        $refundOrderSum = (float) (clone $refundOrders)->sum('total_amount');
        $refundedFeeItems = OrderItem::query()
            ->whereHas('order', function ($q) use ($start, $end) {
                $q->where('payment_status', 'refunded');
                $this->applyRefundWindow($q, $start, $end);
            });
        $refundedOrderFees = (float) (clone $refundedFeeItems)->sum(OrderItem::platformFeeSqlExpression());

        $walletRefunds = WalletTransaction::where('type', WalletTransaction::TYPE_REFUND);
        $this->applyCreatedWindow($walletRefunds, $start, $end);

        $bonuses = WalletTransaction::where('type', WalletTransaction::TYPE_BONUS_CREDIT);
        $this->applyCreatedWindow($bonuses, $start, $end);

        return [
            'gmv_completed' => round($gmvCompleted, 2),
            'order_fees' => round($orderFees, 2),
            'withdrawal_fees' => round($withdrawalFeeSum, 2),
            'withdrawal_fee_percent' => (float) config('billing.withdrawal_fee_percent', 0),
            'refunds' => round($refundOrderSum, 2),
            'refunded_order_fees' => round($refundedOrderFees, 2),
            'refund_orders_count' => (clone $refundOrders)->count(),
            'wallet_refunds' => (float) (clone $walletRefunds)->sum('amount'),
            'bonuses_issued' => (float) (clone $bonuses)->sum('amount'),
            'payment_processor_costs_tracked' => false,
            'margin' => 0.0, // filled by overview()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cashVsInternal(?Carbon $start, Carbon $end): array
    {
        $in = $this->moneyIn($start, $end);

        $cashIn = round(
            ($in['deposits_completed']['stripe'] ?? 0)
            + ($in['deposits_completed']['manual'] ?? 0)
            + ($in['orders_paid']['stripe_card'] ?? 0)
            + ($in['orders_paid']['manual'] ?? 0),
            2
        );

        $internal = round(
            ($in['orders_paid']['wallet'] ?? 0)
            + ($in['bonuses_issued']['amount'] ?? 0),
            2
        );

        $cashOutQuery = Withdrawal::where('status', 'completed');
        $this->applyCoalesceWindow($cashOutQuery, $start, $end, 'withdrawals.processed_at', 'withdrawals.updated_at');
        $cashOut = (float) $cashOutQuery->sum('net_amount');

        return [
            'cash_in_bank' => $cashIn,
            'internal_only' => $internal,
            'cash_out_payouts' => round($cashOut, 2),
            'note' => 'Cash in = Stripe/card + approved bank/Wise/crypto deposits & manual order payments. Internal = wallet checkouts + welcome bonuses (not bank deposits).',
        ];
    }

    /**
     * Per-user finance dossier.
     *
     * @return array<string, mixed>
     */
    public function userDossier(User $user): array
    {
        $user->load('roles');
        $advertiserRoleId = Wallet::advertiserRoleId();
        $publisherRoleId = Wallet::publisherRoleId();

        $advWallet = $advertiserRoleId
            ? Wallet::where('user_id', $user->id)->where('role_id', $advertiserRoleId)->first()
            : null;
        $pubWallet = $publisherRoleId
            ? Wallet::where('user_id', $user->id)->where('role_id', $publisherRoleId)->first()
            : null;

        $deposits = DepositRequest::where('user_id', $user->id)->latest()->limit(20)->get();
        $orders = Order::where('user_id', $user->id)->latest()->limit(20)->get();
        $withdrawals = Withdrawal::where('user_id', $user->id)->latest()->limit(20)->get();
        $ledger = WalletTransaction::where('user_id', $user->id)->latest()->limit(50)->get();

        $siteIds = DB::table('sites')->where('publisher_id', $user->id)->pluck('id');
        $earnings = $siteIds->isEmpty() ? 0.0 : (float) OrderItem::whereIn('site_id', $siteIds)
            ->whereHas('order', fn ($q) => $q->where('status', 'completed')->where('payment_status', 'paid'))
            ->sum(OrderItem::publisherPayoutSqlExpression());
        $feesOnTheirSales = $siteIds->isEmpty() ? 0.0 : (float) OrderItem::whereIn('site_id', $siteIds)
            ->whereHas('order', fn ($q) => $q->where('status', 'completed')->where('payment_status', 'paid'))
            ->sum(OrderItem::platformFeeSqlExpression());

        $gmvAsAdvertiser = (float) Order::where('user_id', $user->id)
            ->where('payment_status', 'paid')
            ->sum('total_amount');
        $paidOrdersCount = (int) Order::where('user_id', $user->id)
            ->where('payment_status', 'paid')
            ->count();

        return [
            'user' => $user,
            'roles' => $user->roles->pluck('name')->all(),
            'payout_profile' => $user->payoutProfile(),
            'payout_locked' => $user->payoutProfileLocked(),
            'advertiser_wallet' => $advWallet,
            'publisher_wallet' => $pubWallet,
            'deposits' => $deposits,
            'orders' => $orders,
            'withdrawals' => $withdrawals,
            'ledger' => $ledger,
            'totals' => [
                'deposits_completed' => (float) DepositRequest::where('user_id', $user->id)->where('status', 'completed')->sum('amount'),
                'gmv_as_advertiser' => $gmvAsAdvertiser,
                'paid_orders_count' => $paidOrdersCount,
                'earnings_as_publisher' => round($earnings, 2),
                'platform_fees_on_their_sites' => round($feesOnTheirSales, 2),
                'withdrawals_paid_net' => (float) Withdrawal::where('user_id', $user->id)->where('status', 'completed')->sum('net_amount'),
                'withdrawals_open_net' => (float) Withdrawal::where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->sum('net_amount'),
            ],
        ];
    }

    /**
     * Flat rows for CSV period export.
     *
     * @return array<int, array<string, scalar|null>>
     */
    public function exportRows(array $period): array
    {
        $data = $this->overview($period);
        $p = $data['period']['label'];

        return [
            ['section' => 'period', 'metric' => 'label', 'value' => $p],
            ['section' => 'payable_now', 'metric' => 'amount', 'value' => $data['payable_now']],
            ['section' => 'due_to_pay_now', 'metric' => 'open_withdrawal_nets', 'value' => $data['due_to_pay_now']],
            ['section' => 'in_publisher_wallets', 'metric' => 'withdrawable', 'value' => $data['in_publisher_wallets']],
            ['section' => 'total_publisher_liability', 'metric' => 'amount', 'value' => $data['total_publisher_liability']],
            ['section' => 'liability', 'metric' => 'publisher_withdrawable', 'value' => $data['liability']['publisher']['withdrawable']],
            ['section' => 'liability', 'metric' => 'open_withdrawal_nets', 'value' => $data['liability']['open_withdrawal_nets']],
            ['section' => 'liability', 'metric' => 'advertiser_cash', 'value' => $data['liability']['advertiser']['cash']],
            ['section' => 'liability', 'metric' => 'advertiser_bonus', 'value' => $data['liability']['advertiser']['bonus']],
            ['section' => 'liability', 'metric' => 'advertiser_reserved', 'value' => $data['liability']['advertiser']['reserved']],
            ['section' => 'money_in', 'metric' => 'deposits_completed', 'value' => $data['money_in']['deposits_completed']['amount']],
            ['section' => 'money_in', 'metric' => 'orders_gmv', 'value' => $data['money_in']['orders_paid']['gmv']],
            ['section' => 'money_in', 'metric' => 'orders_stripe', 'value' => $data['money_in']['orders_paid']['stripe_card']],
            ['section' => 'money_in', 'metric' => 'orders_wallet', 'value' => $data['money_in']['orders_paid']['wallet']],
            ['section' => 'money_in', 'metric' => 'orders_manual', 'value' => $data['money_in']['orders_paid']['manual']],
            ['section' => 'money_in', 'metric' => 'bonuses_issued', 'value' => $data['money_in']['bonuses_issued']['amount']],
            ['section' => 'money_out', 'metric' => 'earnings_credited', 'value' => $data['money_out']['earnings_credited']['amount']],
            ['section' => 'money_out', 'metric' => 'withdrawals_paid_net', 'value' => $data['money_out']['withdrawals_paid']['net']],
            ['section' => 'money_out', 'metric' => 'withdrawals_open_net', 'value' => $data['money_out']['withdrawals_open']['net']],
            ['section' => 'platform', 'metric' => 'gmv_completed', 'value' => $data['platform']['gmv_completed']],
            ['section' => 'platform', 'metric' => 'order_fees', 'value' => $data['platform']['order_fees']],
            ['section' => 'platform', 'metric' => 'withdrawal_fees', 'value' => $data['platform']['withdrawal_fees']],
            ['section' => 'platform', 'metric' => 'refunds', 'value' => $data['platform']['refunds']],
            ['section' => 'platform', 'metric' => 'refunded_order_fees', 'value' => $data['platform']['refunded_order_fees']],
            ['section' => 'platform', 'metric' => 'bonuses_issued', 'value' => $data['platform']['bonuses_issued']],
            ['section' => 'platform', 'metric' => 'margin', 'value' => $data['platform']['margin']],
            ['section' => 'cash_split', 'metric' => 'cash_in_bank', 'value' => $data['cash_split']['cash_in_bank']],
            ['section' => 'cash_split', 'metric' => 'internal_only', 'value' => $data['cash_split']['internal_only']],
            ['section' => 'cash_split', 'metric' => 'cash_out_payouts', 'value' => $data['cash_split']['cash_out_payouts']],
            ['section' => 'ops', 'metric' => 'pending_deposits', 'value' => $data['ops']['pending_deposits']['amount']],
            ['section' => 'ops', 'metric' => 'user_marked_paid_deposits', 'value' => $data['ops']['pending_deposits']['user_marked_paid_amount']],
            ['section' => 'ops', 'metric' => 'open_withdrawals', 'value' => $data['ops']['open_withdrawals']['amount']],
            ['section' => 'ops', 'metric' => 'unpaid_orders', 'value' => $data['ops']['unpaid_orders']['amount']],
            ['section' => 'ops', 'metric' => 'publisher_debt', 'value' => $data['ops']['publisher_debt']['amount']],
        ];
    }

    private function hasBonusColumns(): bool
    {
        return Schema::hasColumn('wallets', 'bonus_balance');
    }

    private function applyCreatedWindow($query, ?Carbon $start, Carbon $end): void
    {
        if ($start) {
            $query->whereBetween('created_at', [$start, $end]);
        } else {
            $query->where('created_at', '<=', $end);
        }
    }

    private function applyPaidWindow($query, ?Carbon $start, Carbon $end): void
    {
        if ($this->ordersHaveColumn('paid_at')) {
            $this->applyCoalesceWindow($query, $start, $end, 'orders.paid_at', 'orders.created_at');
        } else {
            $this->applyCoalesceWindow($query, $start, $end, 'orders.created_at', 'orders.created_at');
        }
    }

    private function applyCompletedWindow($query, ?Carbon $start, Carbon $end): void
    {
        if ($this->ordersHaveColumn('completed_at')) {
            $this->applyCoalesceWindow($query, $start, $end, 'orders.completed_at', 'orders.updated_at');
        } else {
            $this->applyCoalesceWindow($query, $start, $end, 'orders.updated_at', 'orders.updated_at');
        }
    }

    private function applyRefundWindow($query, ?Carbon $start, Carbon $end): void
    {
        $this->applyCoalesceWindow($query, $start, $end, 'orders.updated_at', 'orders.updated_at');
    }

    private function applyCreatedOrPaidWindow($query, ?Carbon $start, Carbon $end, string $preferred): void
    {
        $table = $query->getModel()->getTable();
        $this->applyCoalesceWindow($query, $start, $end, $table.'.'.$preferred, $table.'.created_at');
    }

    /**
     * Bound a timestamp with COALESCE(preferred, fallback) so a null preferred
     * date does not pull the row into every period.
     */
    private function applyCoalesceWindow($query, ?Carbon $start, Carbon $end, string $preferred, string $fallback): void
    {
        $expr = $preferred === $fallback
            ? $preferred
            : 'COALESCE('.$preferred.', '.$fallback.')';

        if ($start) {
            $query->whereRaw($expr.' BETWEEN ? AND ?', [$start, $end]);
        } else {
            $query->whereRaw($expr.' <= ?', [$end]);
        }
    }

    private function parseDay(?string $value, bool $endOfDay): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $day = Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $day->endOfDay() : $day->startOfDay();
    }

    private function ordersHaveColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('orders', $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
