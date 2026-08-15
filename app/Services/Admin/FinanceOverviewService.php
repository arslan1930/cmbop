<?php

namespace App\Services\Admin;

use App\Models\DepositRequest;
use App\Models\Invoice;
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
    public const HUB_TABLE_LIMIT = 8;

    public const HUB_EXPANDED_LIMIT = 200;

    /**
     * @return array{start: ?Carbon, end: Carbon, label: string, key: string}
     */
    public function resolvePeriod(?string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $end = now()->endOfDay();

        if ($dateFrom || $dateTo) {
            $start = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : null;
            $end = $dateTo ? Carbon::parse($dateTo)->endOfDay() : $end;

            return [
                'start' => $start,
                'end' => $end,
                'label' => trim(($dateFrom ?: '…').' → '.($dateTo ?: 'today')),
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
    public function overview(array $period, ?string $list = null): array
    {
        $start = $period['start'];
        $end = $period['end'];
        $list = in_array($list, ['debt', 'wallets'], true) ? $list : null;

        $ops = $this->opsQueues($list);
        $liability = $this->walletLiability($list);
        $moneyIn = $this->moneyIn($start, $end);
        $moneyOut = $this->moneyOut($start, $end);
        $platform = $this->platform($start, $end);
        $cashSplit = $this->cashVsInternal($start, $end);

        $platform['margin'] = round(
            $platform['order_fees']
            + $platform['withdrawal_fees']
            - $platform['refunds']
            - $platform['bonuses_issued'],
            2
        );

        $cardVolume = round(
            (float) ($moneyIn['orders_paid']['stripe_card'] ?? 0)
            + (float) ($moneyIn['deposits_completed']['stripe'] ?? 0),
            2
        );
        $stripePercent = (float) config('billing.stripe_fee_percent', 1.5);
        $estimatedStripe = round($cardVolume * $stripePercent / 100, 2);
        $platform['stripe_fee_percent'] = $stripePercent;
        $platform['estimated_stripe_base'] = $cardVolume;
        $platform['estimated_stripe_fees'] = $estimatedStripe;
        $platform['margin_after_estimated_stripe'] = round($platform['margin'] - $estimatedStripe, 2);

        return [
            'period' => $period,
            'list' => $list,
            'ops' => $ops,
            'liability' => $liability,
            'money_in' => $moneyIn,
            'money_out' => $moneyOut,
            'platform' => $platform,
            'cash_split' => $cashSplit,
            'invoices' => $this->missingTaxInvoices(),
            'comparison' => $this->periodComparison($period, $platform),
            'reconciliation' => $this->depositReconciliation($start, $end, (float) ($moneyIn['deposits_completed']['amount'] ?? 0)),
            'payable_now' => $liability['total_publisher_liability'],
            'due_to_pay_now' => $liability['due_to_pay_now'],
            'in_publisher_wallets' => $liability['in_publisher_wallets'],
            'total_publisher_liability' => $liability['total_publisher_liability'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function opsQueues(?string $list = null): array
    {
        $pendingDeposits = DepositRequest::where('status', 'pending');
        $userMarked = (clone $pendingDeposits)->whereNotNull('user_marked_paid_at');
        $openWithdrawals = Withdrawal::whereIn('status', ['pending', 'processing']);
        $pendingPayments = Order::query()->unpaidOps();
        $oldestOpenWithdrawal = (clone $openWithdrawals)->orderBy('created_at')->first();
        $oldestUnpaid = (clone $pendingPayments)->orderBy('created_at')->first();

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
                'oldest_days' => $this->ageInDays($oldestOpenWithdrawal?->created_at),
                'url' => route('admin.withdrawals', ['queue' => 'open']),
            ],
            'unpaid_orders' => [
                'count' => (clone $pendingPayments)->count(),
                'amount' => (float) (clone $pendingPayments)->sum('total_amount'),
                'oldest_days' => $this->ageInDays($oldestUnpaid?->created_at),
                'url' => route('admin.payments', ['payment_status' => 'unpaid']),
            ],
            'publisher_debt' => $this->publisherDebt($list),
        ];
    }

    /**
     * Outstanding publisher clawback debt (blocks their withdrawals).
     *
     * @return array<string, mixed>
     */
    public function publisherDebt(?string $list = null): array
    {
        $empty = [
            'count' => 0,
            'amount' => 0.0,
            'rows' => [],
            'truncated' => false,
            'limit' => self::HUB_TABLE_LIMIT,
            'url' => route('admin.finance').'#finance-debt',
            'view_all_url' => route('admin.finance', ['list' => 'debt']).'#finance-debt-table',
        ];

        if (! Schema::hasColumn('wallets', 'debt_balance')) {
            return $empty;
        }

        $query = Wallet::query()->where('debt_balance', '>', 0);
        $publisherRoleId = Wallet::publisherRoleId();
        if ($publisherRoleId) {
            $query->where('role_id', $publisherRoleId);
        }

        $limit = $list === 'debt' ? self::HUB_EXPANDED_LIMIT : self::HUB_TABLE_LIMIT;
        $count = (clone $query)->count();
        $rows = (clone $query)
            ->with('user:id,name,email')
            ->orderByDesc('debt_balance')
            ->limit($limit)
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
            'count' => $count,
            'amount' => round((float) (clone $query)->sum('debt_balance'), 2),
            'rows' => $rows,
            'truncated' => $count > count($rows),
            'limit' => $limit,
            'url' => route('admin.finance').'#finance-debt',
            'view_all_url' => route('admin.finance', ['list' => 'debt']).'#finance-debt-table',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function walletLiability(?string $list = null): array
    {
        $advertiserRoleId = Wallet::advertiserRoleId();
        $publisherRoleId = Wallet::publisherRoleId();
        $withdrawableSql = $this->perWalletWithdrawableSql();

        // Per-wallet cash/withdrawable in SQL. Do NOT use
        // SUM(balance) - min(SUM(bonus), SUM(balance)) — that under/over-counts
        // when bonus is uneven across wallets.
        $adv = $this->sumRoleWallets($advertiserRoleId, $withdrawableSql, 'cash');
        $pub = $this->sumRoleWallets($publisherRoleId, $withdrawableSql, 'withdrawable');

        $walletLimit = $list === 'wallets' ? self::HUB_EXPANDED_LIMIT : self::HUB_TABLE_LIMIT;
        $topPublisherCount = 0;
        $topPublishers = [];
        if ($publisherRoleId) {
            $positiveWithdrawable = Wallet::query()
                ->where('role_id', $publisherRoleId)
                ->whereRaw("({$withdrawableSql}) > 0");
            $topPublisherCount = (clone $positiveWithdrawable)->count();
            $topPublishers = (clone $positiveWithdrawable)
                ->with('user:id,name,email')
                ->select('wallets.*')
                ->selectRaw("({$withdrawableSql}) as withdrawable_cash")
                ->orderByDesc('withdrawable_cash')
                ->limit($walletLimit)
                ->get()
                ->map(fn (Wallet $wallet) => [
                    'user_id' => $wallet->user_id,
                    'name' => $wallet->user?->name ?? 'User #'.$wallet->user_id,
                    'email' => $wallet->user?->email,
                    'withdrawable' => round((float) ($wallet->withdrawable_cash ?? 0), 2),
                    'url' => route('admin.finance.user', $wallet->user_id),
                ])
                ->all();
        }

        $openQuery = Withdrawal::query()->whereIn('status', ['pending', 'processing']);
        $openWithdrawalCount = (clone $openQuery)->count();
        $openWithdrawalNets = round((float) (clone $openQuery)->sum('net_amount'), 2);
        $openWithdrawalRows = (clone $openQuery)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->limit(self::HUB_TABLE_LIMIT)
            ->get()
            ->map(fn (Withdrawal $w) => [
                'id' => $w->id,
                'user_id' => $w->user_id,
                'name' => $w->user?->name ?? 'User #'.$w->user_id,
                'email' => $w->user?->email,
                'net_amount' => (float) $w->net_amount,
                'status' => $w->status,
                'url' => route('admin.withdrawals', ['search' => (string) $w->id, 'queue' => 'open']),
            ])
            ->all();

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
            'top_publisher_wallet_count' => $topPublisherCount,
            'top_publisher_wallets_truncated' => $topPublisherCount > count($topPublishers),
            'top_publisher_wallets_limit' => $walletLimit,
            'open_withdrawal_rows' => $openWithdrawalRows,
            'open_withdrawal_count' => $openWithdrawalCount,
            'open_withdrawals_truncated' => $openWithdrawalCount > count($openWithdrawalRows),
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
                if ($start) {
                    $q->whereBetween('updated_at', [$start, $end]);
                } else {
                    $q->where('updated_at', '<=', $end);
                }
            });

        $earnings = (float) (clone $earningsQuery)->sum(OrderItem::publisherPayoutSqlExpression());
        $earningsCount = (clone $earningsQuery)->count();

        $ledgerEarnings = WalletTransaction::where('type', WalletTransaction::TYPE_TRANSFER_IN);
        $this->applyCreatedWindow($ledgerEarnings, $start, $end);

        $paidWithdrawals = Withdrawal::where('status', 'completed');
        if ($start) {
            $paidWithdrawals->where(function ($q) use ($start, $end) {
                $q->whereBetween('processed_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('processed_at')->whereBetween('updated_at', [$start, $end]);
                    });
            });
        }

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
                if ($start) {
                    $q->whereBetween('updated_at', [$start, $end]);
                } else {
                    $q->where('updated_at', '<=', $end);
                }
            });

        $orderFees = (float) (clone $feeItems)->sum(OrderItem::platformFeeSqlExpression());
        $gmvCompleted = (float) Order::where('status', 'completed')
            ->where('payment_status', 'paid')
            ->when($start, fn ($q) => $q->whereBetween('updated_at', [$start, $end]))
            ->when(! $start, fn ($q) => $q->where('updated_at', '<=', $end))
            ->sum('total_amount');

        $withdrawalFees = Withdrawal::where('status', 'completed');
        if ($start) {
            $withdrawalFees->where(function ($q) use ($start, $end) {
                $q->whereBetween('processed_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('processed_at')->whereBetween('updated_at', [$start, $end]);
                    });
            });
        }
        $withdrawalFeeSum = (float) (clone $withdrawalFees)->sum('fee');

        $refundOrders = Order::where('payment_status', 'refunded');
        $this->applyPaidWindow($refundOrders, $start, $end, 'updated_at');
        $refundOrderSum = (float) (clone $refundOrders)->sum('total_amount');

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

        $cashOut = (float) Withdrawal::where('status', 'completed')
            ->when($start, function ($q) use ($start, $end) {
                $q->where(function ($q2) use ($start, $end) {
                    $q2->whereBetween('processed_at', [$start, $end])
                        ->orWhere(function ($q3) use ($start, $end) {
                            $q3->whereNull('processed_at')->whereBetween('updated_at', [$start, $end]);
                        });
                });
            })
            ->sum('net_amount');

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
            ['section' => 'liability', 'metric' => 'publisher_reserved', 'value' => $data['liability']['publisher']['reserved']],
            ['section' => 'invoices', 'metric' => 'missing_tax', 'value' => $data['invoices']['count']],
            ['section' => 'platform', 'metric' => 'estimated_stripe_fees', 'value' => $data['platform']['estimated_stripe_fees']],
            ['section' => 'reconciliation', 'metric' => 'deposit_ledger', 'value' => $data['reconciliation']['ledger_deposits']],
            ['section' => 'reconciliation', 'metric' => 'deposit_delta', 'value' => $data['reconciliation']['delta']],
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

    /**
     * Paid orders with no non-cancelled tax invoice.
     *
     * @return array{count: int, url: string}
     */
    public function missingTaxInvoices(): array
    {
        $url = route('admin.invoices.index');
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'type')) {
            return ['count' => 0, 'url' => $url];
        }

        $count = (int) Order::query()
            ->where('payment_status', 'paid')
            ->whereDoesntHave('invoices', function ($query) {
                $query->where('type', Invoice::TYPE_TAX_INVOICE)
                    ->where('status', '!=', Invoice::STATUS_CANCELLED);
            })
            ->count();

        return [
            'count' => $count,
            'url' => $url,
        ];
    }

    /**
     * Week/month GMV and fees vs the previous window of equal length.
     *
     * @param  array{start: ?Carbon, end: Carbon, label: string, key: string}  $period
     * @param  array<string, mixed>  $platform
     * @return array<string, mixed>|null
     */
    public function periodComparison(array $period, array $platform): ?array
    {
        $previous = $this->previousPeriodWindow($period);
        if (! $previous) {
            return null;
        }

        $prevPlatform = $this->platform($previous['start'], $previous['end']);

        return [
            'label' => 'Previous equal window',
            'from' => $previous['start']->toDateString(),
            'to' => $previous['end']->toDateString(),
            'gmv_completed' => $prevPlatform['gmv_completed'],
            'order_fees' => $prevPlatform['order_fees'],
            'gmv_delta' => round((float) $platform['gmv_completed'] - $prevPlatform['gmv_completed'], 2),
            'fees_delta' => round((float) $platform['order_fees'] - $prevPlatform['order_fees'], 2),
        ];
    }

    /**
     * Completed deposit payments vs ledger TYPE_DEPOSIT in the same period.
     *
     * @return array{deposits_completed: float, ledger_deposits: float, delta: float, matched: bool}
     */
    public function depositReconciliation(?Carbon $start, Carbon $end, float $completedDeposits): array
    {
        $ledgerSum = 0.0;
        if (Schema::hasTable('wallet_transactions')) {
            $ledger = WalletTransaction::query()->where('type', WalletTransaction::TYPE_DEPOSIT);
            $this->applyCreatedWindow($ledger, $start, $end);
            $ledgerSum = round((float) $ledger->sum('amount'), 2);
        }

        $completed = round($completedDeposits, 2);
        $delta = round($ledgerSum - $completed, 2);

        return [
            'deposits_completed' => $completed,
            'ledger_deposits' => $ledgerSum,
            'delta' => $delta,
            'matched' => abs($delta) < 0.01,
        ];
    }

    private function hasBonusColumns(): bool
    {
        return Schema::hasColumn('wallets', 'bonus_balance');
    }

    /**
     * Per-wallet withdrawable/cash: max(0, balance − min(bonus, balance)).
     * Portable CASE so SQLite tests and MySQL production match.
     */
    private function perWalletWithdrawableSql(string $table = 'wallets'): string
    {
        $balance = "{$table}.balance";
        if (! $this->hasBonusColumns()) {
            return "ROUND(CASE WHEN {$balance} > 0 THEN {$balance} ELSE 0 END, 2)";
        }

        $bonus = "COALESCE({$table}.bonus_balance, 0)";

        return "ROUND(CASE WHEN {$balance} <= 0 THEN 0 WHEN {$bonus} >= {$balance} THEN 0 ELSE {$balance} - {$bonus} END, 2)";
    }

    /**
     * @return array{balance: float, bonus: float, reserved: float, cash?: float, withdrawable?: float}
     */
    private function sumRoleWallets(?int $roleId, string $withdrawableSql, string $cashKey): array
    {
        $empty = [
            'balance' => 0.0,
            'bonus' => 0.0,
            'reserved' => 0.0,
            $cashKey => 0.0,
        ];
        if (! $roleId) {
            return $empty;
        }

        $hasBonus = $this->hasBonusColumns();
        $row = Wallet::query()
            ->where('role_id', $roleId)
            ->selectRaw('COALESCE(SUM(balance), 0) as balance')
            ->selectRaw($hasBonus ? 'COALESCE(SUM(bonus_balance), 0) as bonus' : '0 as bonus')
            ->selectRaw('COALESCE(SUM(reserved_balance), 0) as reserved')
            ->selectRaw("COALESCE(SUM({$withdrawableSql}), 0) as cash_or_wd")
            ->first();

        return [
            'balance' => round((float) ($row?->balance ?? 0), 2),
            'bonus' => round((float) ($row?->bonus ?? 0), 2),
            'reserved' => round((float) ($row?->reserved ?? 0), 2),
            $cashKey => round((float) ($row?->cash_or_wd ?? 0), 2),
        ];
    }

    /**
     * @param  array{start: ?Carbon, end: Carbon, key: string}  $period
     * @return array{start: Carbon, end: Carbon}|null
     */
    private function previousPeriodWindow(array $period): ?array
    {
        if (! in_array($period['key'] ?? '', ['week', 'month'], true) || ! ($period['start'] instanceof Carbon)) {
            return null;
        }

        $start = $period['start']->copy();
        $end = $period['end'] instanceof Carbon ? $period['end']->copy() : now()->endOfDay();
        $length = max(1, $end->getTimestamp() - $start->getTimestamp());
        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subSeconds($length);

        return [
            'start' => $prevStart,
            'end' => $prevEnd,
        ];
    }

    private function ageInDays(?Carbon $at): ?int
    {
        if (! $at) {
            return null;
        }

        return (int) $at->diffInDays(now());
    }

    private function applyCreatedWindow($query, ?Carbon $start, Carbon $end): void
    {
        if ($start) {
            $query->whereBetween('created_at', [$start, $end]);
        } else {
            $query->where('created_at', '<=', $end);
        }
    }

    private function applyPaidWindow($query, ?Carbon $start, Carbon $end, string $fallback = 'paid_at'): void
    {
        $column = Schema::hasColumn('orders', 'paid_at') ? 'paid_at' : $fallback;
        if ($start) {
            $query->where(function ($q) use ($start, $end, $column, $fallback) {
                $q->whereBetween($column, [$start, $end]);
                if ($column === 'paid_at') {
                    $q->orWhere(function ($q2) use ($start, $end, $fallback) {
                        $q2->whereNull('paid_at')->whereBetween($fallback === 'paid_at' ? 'created_at' : $fallback, [$start, $end]);
                    });
                }
            });
        } else {
            $query->where(function ($q) use ($end, $column) {
                $q->where($column, '<=', $end)->orWhereNull($column);
            });
        }
    }

    private function applyCreatedOrPaidWindow($query, ?Carbon $start, Carbon $end, string $preferred): void
    {
        if ($start) {
            $query->where(function ($q) use ($start, $end, $preferred) {
                $q->whereBetween($preferred, [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end, $preferred) {
                        $q2->whereNull($preferred)->whereBetween('created_at', [$start, $end]);
                    });
            });
        } else {
            $query->where(function ($q) use ($end, $preferred) {
                $q->where($preferred, '<=', $end)
                    ->orWhere(function ($q2) use ($end, $preferred) {
                        $q2->whereNull($preferred)->where('created_at', '<=', $end);
                    });
            });
        }
    }
}
