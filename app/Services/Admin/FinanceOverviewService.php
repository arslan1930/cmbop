<?php

namespace App\Services\Admin;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\SiteFeaturePurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\OrderPaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceOverviewService
{
    /**
     * Same completed-deposit window the overview total uses.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<DepositRequest>  $query
     */
    public function applyDepositCompletedWindow($query, ?string $from, ?string $to): void
    {
        $query->where('status', 'completed');
        $this->applyCreatedOrPaidWindow($query, $this->parseDay($from, false), $this->windowEnd($to), 'approved_at');
    }

    /**
     * Same paid-withdrawal window the overview total uses.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Withdrawal>  $query
     */
    public function applyWithdrawalPaidWindow($query, ?string $from, ?string $to): void
    {
        $query->where('status', 'completed');
        $this->applyWithdrawalProcessedWindow($query, $this->parseDay($from, false), $this->windowEnd($to));
    }

    /**
     * Same completed-GMV window the overview total uses, including later refunds.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Order>  $query
     */
    public function applyGmvWindow($query, ?string $from, ?string $to): void
    {
        $this->constrainRecognizedCompleted($query);
        $this->applyCompletedWindow($query, $this->parseDay($from, false), $this->windowEnd($to));
    }

    /**
     * Same created_at window the overview uses for bonuses issued.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\WalletTransaction>  $query
     */
    public function applyLedgerCreatedWindow($query, ?string $from, ?string $to): void
    {
        $this->applyCreatedWindow($query, $this->parseDay($from, false), $this->windowEnd($to));
    }

    private function windowEnd(?string $to): Carbon
    {
        return $this->parseDay($to, true) ?? now()->endOfDay();
    }

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
    public function overview(array $period, bool $allWallets = false, bool $allDebt = false, float $minWallet = 0): array
    {
        try {
            $start = $period['start'];
            $end = $period['end'];

            $ops = $this->opsQueues($allDebt);
            $liability = $this->walletLiability($allWallets, $minWallet);
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
                    'failed_cash_in' => 'order_created_at',
                    'refunds' => 'refund_ledger_or_updated_at',
                    'withdrawals_paid' => 'processed_at',
                    'ledger' => 'created_at',
                ],
            ];
        } catch (\Throwable $e) {
            report($e);

            return $this->emptyOverview($period);
        }
    }

    /**
     * Zeroed hub payload when leftover schema makes a section unreadable.
     *
     * @return array<string, mixed>
     */
    public function emptyOverview(array $period): array
    {
        return [
            'period' => $period,
            'ops' => [
                'pending_deposits' => [
                    'count' => 0,
                    'amount' => 0.0,
                    'user_marked_paid_count' => 0,
                    'user_marked_paid_amount' => 0.0,
                    'charges' => [],
                    'url' => route('admin.deposits', ['status' => 'pending']),
                ],
                'open_withdrawals' => [
                    'count' => 0,
                    'amount' => 0.0,
                    'url' => route('admin.withdrawals', ['queue' => 'open']),
                ],
                'unpaid_orders' => [
                    'count' => 0,
                    'amount' => 0.0,
                    'url' => route('admin.payments', ['payment_status' => 'unpaid']),
                ],
                'publisher_debt' => [
                    'count' => 0,
                    'amount' => 0.0,
                    'rows' => [],
                    'url' => route('admin.finance').'#finance-debt',
                ],
            ],
            'liability' => [
                'advertiser' => [
                    'balance' => 0.0,
                    'bonus' => 0.0,
                    'reserved' => 0.0,
                    'cash' => 0.0,
                ],
                'publisher' => [
                    'balance' => 0.0,
                    'bonus' => 0.0,
                    'reserved' => 0.0,
                    'withdrawable' => 0.0,
                ],
                'open_withdrawal_nets' => 0.0,
                'due_to_pay_now' => 0.0,
                'in_publisher_wallets' => 0.0,
                'total_publisher_liability' => 0.0,
                'payable_now' => 0.0,
                'open_reserved_total' => 0.0,
                'top_publisher_wallets' => [],
                'publisher_wallets_total' => 0,
                'open_withdrawal_rows' => [],
                'open_withdrawals_total' => 0,
                'other_currencies' => [],
            ],
            'money_in' => [
                'deposits_completed' => [
                    'count' => 0,
                    'amount' => 0.0,
                    'by_method' => [],
                    'stripe' => 0.0,
                    'manual' => 0.0,
                ],
                'orders_paid' => [
                    'count' => 0,
                    'gmv' => 0.0,
                    'by_method' => [],
                    'stripe_card' => 0.0,
                    'wallet' => 0.0,
                    'manual' => 0.0,
                ],
                'bonuses_issued' => [
                    'count' => 0,
                    'amount' => 0.0,
                ],
                'unfulfilled_card_credits' => 0.0,
                'stripe_card_collected' => 0.0,
                'manual_collected' => 0.0,
                'failed_external_collected' => 0.0,
                'site_feature_stripe' => 0.0,
                'collected' => [
                    'by_currency' => [],
                    'deposits' => [],
                    'orders_not_recorded' => 0,
                    'features_not_recorded' => 0,
                ],
            ],
            'money_out' => [
                'earnings_credited' => [
                    'count' => 0,
                    'amount' => 0.0,
                    'ledger_transfer_in' => 0.0,
                ],
                'withdrawals_paid' => [
                    'count' => 0,
                    'gross' => 0.0,
                    'net' => 0.0,
                    'fees' => 0.0,
                ],
                'withdrawals_open' => [
                    'count' => 0,
                    'net' => 0.0,
                ],
            ],
            'platform' => [
                'gmv_completed' => 0.0,
                'order_fees' => 0.0,
                'withdrawal_fees' => 0.0,
                'withdrawal_fee_percent' => (float) config('billing.withdrawal_fee_percent', 0),
                'refunds' => 0.0,
                'refunded_order_fees' => 0.0,
                'refund_orders_count' => 0,
                'wallet_refunds' => 0.0,
                'bonuses_issued' => 0.0,
                'payment_processor_costs_tracked' => false,
                'margin' => 0.0,
            ],
            'cash_split' => [
                'cash_in_bank' => 0.0,
                'internal_only' => 0.0,
                'cash_out_payouts' => 0.0,
                'note' => 'Cash in = Stripe/card + PayPal checkout/deposits + approved bank/Wise/crypto deposits & manual order payments + leftover card credits + featured-site Stripe + paid→failed captures returned to wallet. Wallet refunds do not remove collected card/manual cash (no Stripe refund). PayPal checkout refunds return on PayPal, not as a second wallet credit. A PayPal-dashboard refund of an Add Funds deposit is removed from cash in and debited from the wallet. Internal = wallet checkouts + welcome bonuses.',
            ],
            'payable_now' => 0.0,
            'due_to_pay_now' => 0.0,
            'in_publisher_wallets' => 0.0,
            'total_publisher_liability' => 0.0,
            'clocks' => [
                'deposits' => 'approved_at',
                'orders_paid' => 'paid_at',
                'completed' => 'completed_at',
                'failed_cash_in' => 'order_created_at',
                'refunds' => 'refund_ledger_or_updated_at',
                'withdrawals_paid' => 'processed_at',
                'ledger' => 'created_at',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function opsQueues(bool $allDebt = false): array
    {
        $pendingCount = 0;
        $pendingAmount = 0.0;
        $userMarkedPaidCount = 0;
        $userMarkedPaidAmount = 0.0;
        if (DepositRequest::tableAvailable()) {
            $pendingDeposits = DepositRequest::where('status', 'pending');
            $pendingCount = (clone $pendingDeposits)->count();
            $pendingAmount = (float) (clone $pendingDeposits)->sum('amount');
            if ($this->depositsHaveColumn('user_marked_paid_at')) {
                $userMarked = (clone $pendingDeposits)->whereUserMarkedPaidAtIsRecorded();
                $userMarkedPaidCount = (clone $userMarked)->count();
                $userMarkedPaidAmount = (float) (clone $userMarked)->sum('amount');
            }
        }

        $openCount = 0;
        $openAmount = 0.0;
        if (Withdrawal::tableAvailable()) {
            $openWithdrawals = Withdrawal::whereIn('status', ['pending', 'processing']);
            $openCount = (clone $openWithdrawals)->count();
            $openAmount = (float) (clone $openWithdrawals)->sum('net_amount');
        }
        $unpaidCount = 0;
        $unpaidAmount = 0.0;
        try {
            $pendingPayments = Order::query()->unpaidOps();
            $unpaidCount = (clone $pendingPayments)->count();
            $unpaidAmount = (float) (clone $pendingPayments)->sum('total_amount');
        } catch (\Throwable) {
            $unpaidCount = 0;
            $unpaidAmount = 0.0;
        }

        return [
            'pending_deposits' => [
                'count' => $pendingCount,
                'amount' => $pendingAmount,
                'user_marked_paid_count' => $userMarkedPaidCount,
                'user_marked_paid_amount' => $userMarkedPaidAmount,
                'charges' => $this->pendingDepositCharges(),
                'url' => route('admin.deposits', ['status' => 'pending']),
            ],
            'open_withdrawals' => [
                'count' => $openCount,
                'amount' => $openAmount,
                'url' => route('admin.withdrawals', ['queue' => 'open']),
            ],
            'unpaid_orders' => [
                'count' => $unpaidCount,
                'amount' => $unpaidAmount,
                'url' => route('admin.payments', ['payment_status' => 'unpaid']),
            ],
            'publisher_debt' => $this->publisherDebt($allDebt ? 500 : 8),
        ];
    }

    /**
     * Outstanding publisher clawback debt (blocks their withdrawals).
     *
     * @return array{count: int, amount: float, rows: list<array<string, mixed>>, url: string}
     */
    public function publisherDebt(int $limit = 8): array
    {
        $empty = [
            'count' => 0,
            'amount' => 0.0,
            'rows' => [],
            'url' => route('admin.finance').'#finance-debt',
        ];

        $hasDebtColumn = false;
        try {
            $hasDebtColumn = $this->walletsAvailable() && Schema::hasColumn('wallets', 'debt_balance');
        } catch (\Throwable) {
            $hasDebtColumn = false;
        }
        if (! $hasDebtColumn) {
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
            ->limit(max(1, $limit))
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
    public function walletLiability(bool $allWallets = false, float $minWallet = 0): array
    {
        $advertiserRoleId = $this->walletsAvailable() ? Wallet::advertiserRoleId() : null;
        $publisherRoleId = $this->walletsAvailable() ? Wallet::publisherRoleId() : null;
        $hasBonus = $this->walletsAvailable() && $this->hasBonusColumns();

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
        $otherCurrencies = [];
        $hasWalletCurrency = $this->walletsAvailable() && Schema::hasColumn('wallets', 'currency');
        if ($advertiserRoleId) {
            $columns = ['balance', 'reserved_balance'];
            if ($hasBonus) {
                $columns[] = 'bonus_balance';
            }
            if ($hasWalletCurrency) {
                $columns[] = 'currency';
            }
            $wallets = Wallet::where('role_id', $advertiserRoleId)->get($columns);
            foreach ($wallets as $wallet) {
                $walletCurrency = $hasWalletCurrency ? strtoupper(trim((string) ($wallet->currency ?: 'EUR'))) : 'EUR';
                if ($walletCurrency !== '' && $walletCurrency !== 'EUR') {
                    $otherCurrencies[$walletCurrency] ??= ['currency' => $walletCurrency, 'balance' => 0.0, 'count' => 0];
                    $otherCurrencies[$walletCurrency]['balance'] = round($otherCurrencies[$walletCurrency]['balance'] + (float) $wallet->balance, 2);
                    $otherCurrencies[$walletCurrency]['count']++;

                    continue;
                }
                $balance = (float) $wallet->balance;
                $bonus = $hasBonus ? (float) ($wallet->bonus_balance ?? 0) : 0.0;
                $adv['balance'] += $balance;
                $adv['bonus'] += $bonus;
                $adv['reserved'] += (float) $wallet->reserved_balance;
                $adv['cash'] += $wallet->withdrawableBalance();
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
                $walletCurrency = $hasWalletCurrency ? strtoupper(trim((string) ($wallet->currency ?: 'EUR'))) : 'EUR';
                if ($walletCurrency !== '' && $walletCurrency !== 'EUR') {
                    $otherCurrencies[$walletCurrency] ??= ['currency' => $walletCurrency, 'balance' => 0.0, 'count' => 0];
                    $otherCurrencies[$walletCurrency]['balance'] = round($otherCurrencies[$walletCurrency]['balance'] + (float) $wallet->balance, 2);
                    $otherCurrencies[$walletCurrency]['count']++;

                    continue;
                }
                $balance = (float) $wallet->balance;
                $bonus = $hasBonus ? (float) $wallet->bonus_balance : 0.0;
                $withdrawable = $wallet->withdrawableBalance();
                $pub['balance'] += $balance;
                $pub['bonus'] += $bonus;
                $pub['reserved'] += (float) $wallet->reserved_balance;
                $pub['withdrawable'] += $withdrawable;

                if ($withdrawable > 0 && $withdrawable + 0.001 >= $minWallet) {
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
            $publisherWalletTotal = count($topPublishers);
            if (! $allWallets) {
                $topPublishers = array_slice($topPublishers, 0, 8);
            }
        }

        $openWithdrawals = Withdrawal::tableAvailable()
            ? Withdrawal::with('user:id,name,email')
                ->whereIn('status', ['pending', 'processing'])
                ->orderBy('created_at')
                ->get()
            : collect();
        $openWithdrawalNets = round((float) $openWithdrawals->sum('net_amount'), 2);

        $openWithdrawalTotal = $openWithdrawals->count();
        $openWithdrawalRows = $openWithdrawals->take(8)->map(fn (Withdrawal $w) => [
            'id' => $w->id,
            'user_id' => $w->user_id,
            'name' => $w->user?->name ?? 'User #'.$w->user_id,
            'email' => $w->user?->email,
            'net_amount' => (float) $w->net_amount,
            'status' => $w->status,
            'url' => route('admin.withdrawals', ['search' => (string) $w->id, 'queue' => 'open']),
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
            'publisher_wallets_total' => $publisherWalletTotal ?? 0,
            'open_withdrawal_rows' => $openWithdrawalRows,
            'open_withdrawals_total' => $openWithdrawalTotal,
            'other_currencies' => array_values($otherCurrencies),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function moneyIn(?Carbon $start, Carbon $end): array
    {
        $depositsCompleted = DepositRequest::tableAvailable()
            ? DepositRequest::where('status', 'completed')
            : null;
        if ($depositsCompleted) {
            $this->applyCreatedOrPaidWindow($depositsCompleted, $start, $end, 'approved_at');
        }

        $depositsByMethod = $depositsCompleted
            ? (clone $depositsCompleted)
                ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get()
                ->mapWithKeys(fn ($r) => [
                    (string) ($r->payment_method ?: 'unknown') => [
                        'count' => (int) $r->count,
                        'amount' => (float) $r->total,
                    ],
                ])
                ->all()
            : [];

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
        // Live checkout writes `card`; older / promotion rows used `stripe`.
        $stripeOrders = (float) (clone $paidOrders)
            ->whereIn('payment_method', $this->cardOrderMethods())
            ->sum('total_amount');
        $walletOrders = (float) (clone $paidOrders)->where('payment_method', 'wallet')->sum('total_amount');
        $manualOrders = (float) (clone $paidOrders)
            ->whereIn('payment_method', $this->manualOrderMethods())
            ->sum('total_amount');

        $depositsTotal = $depositsCompleted ? (float) (clone $depositsCompleted)->sum('amount') : 0.0;
        $manualMethods = ['wise', 'bank', 'crypto'];
        $manualDeposits = $depositsCompleted
            ? (float) (clone $depositsCompleted)
                ->whereIn('payment_method', $manualMethods)
                ->sum('amount')
            : 0.0;
        // Session id alone must not pull a bank/Wise/crypto row into Stripe —
        // cash_in_bank sums stripe + manual and would double-count the deposit.
        $stripeDeposits = 0.0;
        if ($depositsCompleted) {
            $stripeDeposits = (float) (clone $depositsCompleted)
                ->where(function ($q) use ($manualMethods) {
                    $q->whereIn('payment_method', ['card', 'stripe']);
                    if ($this->depositsHaveColumn('stripe_session_id')) {
                        $q->orWhere(function ($q) use ($manualMethods) {
                            $q->whereNotNull('stripe_session_id')
                                ->where('stripe_session_id', '!=', '')
                                ->where(function ($q) use ($manualMethods) {
                                    $q->whereNull('payment_method')
                                        ->orWhereNotIn('payment_method', $manualMethods);
                                });
                        });
                    }
                })
                ->sum('amount');
        }

        return [
            'deposits_completed' => [
                'count' => $depositsCompleted ? (clone $depositsCompleted)->count() : 0,
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
                'count' => $this->ledgerTypeCount(WalletTransaction::TYPE_BONUS_CREDIT, $start, $end),
                'amount' => $this->ledgerTypeAmount(WalletTransaction::TYPE_BONUS_CREDIT, $start, $end),
            ],
            'unfulfilled_card_credits' => $this->unfulfilledCardCredits($start, $end),
            // Bank still has this cash after a wallet refund (no Stripe refund).
            'stripe_card_collected' => $this->sumExternalOrdersCollected($start, $end, $this->cardOrderMethods()),
            'manual_collected' => $this->sumExternalOrdersCollected($start, $end, $this->manualOrderMethods()),
            'failed_external_collected' => $this->sumFailedExternalCollected($start, $end),
            'site_feature_stripe' => $this->siteFeatureStripeCash($start, $end),
            'collected' => $this->collectedByCurrency($depositsCompleted, $paidOrders, $start, $end),
        ];
    }

    /**
     * Money actually charged, in the currency Stripe or PayPal took. Not converted to euros.
     * Deposits with no charge currency count as euros. Orders and featured placements
     * with no stored charge are counted as not recorded.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<DepositRequest>|null  $depositsCompleted
     * @param  \Illuminate\Database\Eloquent\Builder<Order>  $paidOrders
     * @return array{by_currency: array<string, array{card: float, paypal: float, other: float}>, orders_not_recorded: int, features_not_recorded: int}
     */
    private function collectedByCurrency($depositsCompleted, $paidOrders, ?Carbon $start, Carbon $end): array
    {
        $by = [];
        $add = function (string $currency, string $bucket, float $amount) use (&$by): void {
            $code = strtoupper(trim($currency));
            if ($code === '' || $amount == 0.0) {
                return;
            }
            $by[$code] ??= ['card' => 0.0, 'paypal' => 0.0, 'other' => 0.0];
            $by[$code][$bucket] = round($by[$code][$bucket] + $amount, 2);
        };

        $depositsBy = [];
        if ($depositsCompleted && $this->depositsHaveColumn('charge_currency') && $this->depositsHaveColumn('charge_amount')) {
            $rows = (clone $depositsCompleted)->get(['payment_method', 'amount', 'charge_currency', 'charge_amount']);
            foreach ($rows as $row) {
                $code = strtoupper(trim((string) ($row->charge_currency ?: 'EUR')));
                $charged = $row->charge_amount !== null ? (float) $row->charge_amount : (float) $row->amount;
                $method = strtolower((string) $row->payment_method);
                $bucket = in_array($method, ['card', 'stripe'], true) ? 'card' : ($method === 'paypal' ? 'paypal' : 'other');
                $add($code, $bucket, $charged);
                $depositsBy[$code] = round(($depositsBy[$code] ?? 0) + $charged, 2);
            }
        } elseif ($depositsCompleted) {
            $groups = (clone $depositsCompleted)
                ->selectRaw('payment_method, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get();
            foreach ($groups as $row) {
                $method = strtolower((string) $row->payment_method);
                $bucket = in_array($method, ['card', 'stripe'], true) ? 'card' : ($method === 'paypal' ? 'paypal' : 'other');
                $sum = (float) $row->total;
                $add('EUR', $bucket, $sum);
                $depositsBy['EUR'] = round(($depositsBy['EUR'] ?? 0) + $sum, 2);
            }
        }

        $ordersNotRecorded = 0;
        $externalOrders = (clone $paidOrders)->whereIn('payment_method', array_merge($this->cardOrderMethods(), ['paypal']));
        if ($this->ordersHaveColumn('charge_currency') && $this->ordersHaveColumn('charge_amount')) {
            $ordersNotRecorded = (clone $externalOrders)->where(function ($q) {
                $q->whereNull('charge_currency')->orWhere('charge_currency', '');
            })->count();
            $recorded = (clone $externalOrders)->whereNotNull('charge_currency')->where('charge_currency', '!=', '')->get(['payment_method', 'charge_amount']);
            foreach ($recorded as $row) {
                $method = strtolower((string) $row->payment_method);
                $bucket = $method === 'paypal' ? 'paypal' : 'card';
                $add((string) $row->charge_currency, $bucket, (float) $row->charge_amount);
            }
        } else {
            $ordersNotRecorded = (clone $externalOrders)->count();
        }

        $featuresNotRecorded = 0;
        try {
            if (Schema::hasTable('site_feature_purchases')) {
                $features = SiteFeaturePurchase::query()->where('payment_method', 'stripe');
                $this->applyCreatedWindow($features, $start, $end);
                if (Schema::hasColumn('site_feature_purchases', 'charge_currency') && Schema::hasColumn('site_feature_purchases', 'charge_amount')) {
                    $featuresNotRecorded = (clone $features)->where(function ($q) {
                        $q->whereNull('charge_currency')->orWhere('charge_currency', '');
                    })->count();
                    foreach ((clone $features)->whereNotNull('charge_currency')->where('charge_currency', '!=', '')->get(['charge_currency', 'charge_amount']) as $row) {
                        $add((string) $row->charge_currency, 'card', (float) $row->charge_amount);
                    }
                } else {
                    $featuresNotRecorded = (clone $features)->count();
                }
            }
        } catch (\Throwable) {
            $featuresNotRecorded = 0;
        }

        ksort($by);
        ksort($depositsBy);

        return [
            'by_currency' => $by,
            'deposits' => $depositsBy,
            'orders_not_recorded' => $ordersNotRecorded,
            'features_not_recorded' => $featuresNotRecorded,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function pendingDepositCharges(): array
    {
        if (! DepositRequest::tableAvailable() || ! $this->depositsHaveColumn('charge_currency') || ! $this->depositsHaveColumn('charge_amount')) {
            return [];
        }

        $totals = [];
        foreach (DepositRequest::where('status', 'pending')->get(['amount', 'charge_currency', 'charge_amount']) as $row) {
            $code = strtoupper(trim((string) ($row->charge_currency ?: 'EUR')));
            $charged = $row->charge_amount !== null ? (float) $row->charge_amount : (float) $row->amount;
            $totals[$code] = round(($totals[$code] ?? 0) + $charged, 2);
        }
        ksort($totals);

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public function moneyOut(?Carbon $start, Carbon $end): array
    {
        // Recognize payouts on completed sales, then reverse clawed / later-
        // refunded lines in their own window. Filtering to currently-paid
        // recognizedForFinance() rows would erase a July credit after an
        // August full clawback (order flips to refunded).
        $earningsQuery = OrderItem::query()
            ->whereHas('order', function ($q) use ($start, $end) {
                $this->constrainRecognizedCompleted($q);
                $this->applyCompletedWindow($q, $start, $end);
            });

        $earnings = (float) (clone $earningsQuery)->sum(OrderItem::publisherPayoutSqlExpression())
            - $this->clawedPublisherPayouts($start, $end)
            - $this->refundedNonClawedPublisherPayouts($start, $end);
        $earningsCount = (clone $earningsQuery)->count();
        // A reversal-only window (August clawback of a July sale) has no
        // completions, so count would read "0 line items" next to −€100.
        if ($earningsCount === 0 && abs($earnings) > 0.009) {
            $earningsCount = $this->reversedPublisherPayoutCount($start, $end);
        }

        $paidWithdrawals = Withdrawal::tableAvailable()
            ? Withdrawal::where('status', 'completed')
            : null;
        if ($paidWithdrawals) {
            $this->applyWithdrawalProcessedWindow($paidWithdrawals, $start, $end);
        }

        $openWithdrawals = Withdrawal::tableAvailable()
            ? Withdrawal::whereIn('status', ['pending', 'processing'])
            : null;

        return [
            'earnings_credited' => [
                'count' => $earningsCount,
                'amount' => round($earnings, 2),
                'ledger_transfer_in' => round(
                    $this->ledgerTypeAmount(WalletTransaction::TYPE_TRANSFER_IN, $start, $end)
                    - $this->ledgerTypeAmount(WalletTransaction::TYPE_TRANSFER_OUT, $start, $end),
                    2
                ),
            ],
            'withdrawals_paid' => [
                'count' => $paidWithdrawals ? (clone $paidWithdrawals)->count() : 0,
                'gross' => $paidWithdrawals ? (float) (clone $paidWithdrawals)->sum('amount') : 0.0,
                'net' => $paidWithdrawals ? (float) (clone $paidWithdrawals)->sum('net_amount') : 0.0,
                'fees' => $paidWithdrawals ? (float) (clone $paidWithdrawals)->sum('fee') : 0.0,
            ],
            'withdrawals_open' => [
                'count' => $openWithdrawals ? (clone $openWithdrawals)->count() : 0,
                'net' => $openWithdrawals ? (float) (clone $openWithdrawals)->sum('net_amount') : 0.0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function platform(?Carbon $start, Carbon $end): array
    {
        // Completed lines keep their recognized fee even after a later partial
        // clawback; the clawed slice is reversed in refunded_order_fees (same
        // recognize-then-reverse as a completed-then-refunded sale). Using
        // recognizedForFinance() here would drop the fee twice and also pull a
        // later clawback out of the completion month.
        $feeItems = OrderItem::query()
            ->whereHas('order', function ($q) use ($start, $end) {
                $this->constrainRecognizedCompleted($q);
                $this->applyCompletedWindow($q, $start, $end);
            });

        $orderFees = (float) (clone $feeItems)->sum(OrderItem::platformFeeSqlExpression());
        $completedPaid = Order::query();
        $this->constrainRecognizedCompleted($completedPaid);
        $this->applyCompletedWindow($completedPaid, $start, $end);
        $gmvCompleted = (float) $completedPaid->sum('total_amount');

        $withdrawalFeeSum = 0.0;
        if (Withdrawal::tableAvailable()) {
            $withdrawalFees = Withdrawal::where('status', 'completed');
            $this->applyWithdrawalProcessedWindow($withdrawalFees, $start, $end);
            $withdrawalFeeSum = (float) (clone $withdrawalFees)->sum('fee');
        }

        $refundOrders = Order::where('payment_status', 'refunded');
        $this->applyRefundWindow($refundOrders, $start, $end);
        $failedRefundOrders = $this->failedExternalOrdersWithWalletReturn($start, $end);
        // When the last line is clawed the order flips to refunded and the
        // refund clock becomes MAX(ledger). Subtract those line credits here
        // and add them back by resolved_at so July's clawback does not jump
        // into August — and so all-time does not count 230 + 230.
        $refundOrderSum = (float) (clone $refundOrders)->sum('total_amount')
            - $this->clawbackCreditsOnOrders($refundOrders)
            + (float) (clone $failedRefundOrders)->sum('total_amount')
            + $this->partialClawbackAdvertiserCredits($start, $end);
        $refundedFeeItems = OrderItem::query()
            ->when(OrderItemDispute::tableAvailable(), function ($items) {
                $items->whereDoesntHave('disputes', function ($disputes) {
                    $disputes->where('status', OrderItemDispute::STATUS_UPHELD);
                });
            })
            ->whereHas('order', function ($q) use ($start, $end) {
                // Only reverse fees that were recognized on a completed sale.
                // In-progress cancel/refunds never earned a platform fee.
                // Clawed lines reverse on the dispute date, not this clock.
                $this->constrainRecognizedCompleted($q);
                $q->where('payment_status', 'refunded');
                $this->applyRefundWindow($q, $start, $end);
            });
        $refundedOrderFees = (float) (clone $refundedFeeItems)->sum(OrderItem::platformFeeSqlExpression())
            + $this->partialClawbackRecognizedFees($start, $end);

        // Featured-site leftovers also write TYPE_REFUND (related Site).
        // This subtitle sits next to "Refunds (order totals)" — order only.
        return [
            'gmv_completed' => round($gmvCompleted, 2),
            'order_fees' => round($orderFees, 2),
            'withdrawal_fees' => round($withdrawalFeeSum, 2),
            'withdrawal_fee_percent' => (float) config('billing.withdrawal_fee_percent', 0),
            'refunds' => round($refundOrderSum, 2),
            'refunded_order_fees' => round($refundedOrderFees, 2),
            'refund_orders_count' => $this->refundOrdersCount($refundOrders, $failedRefundOrders, $start, $end),
            'wallet_refunds' => $this->ledgerTypeAmount(
                WalletTransaction::TYPE_REFUND,
                $start,
                $end,
                (new Order)->getMorphClass()
            ),
            'bonuses_issued' => $this->ledgerTypeAmount(WalletTransaction::TYPE_BONUS_CREDIT, $start, $end),
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
            + ($in['stripe_card_collected'] ?? $in['orders_paid']['stripe_card'] ?? 0)
            + ($in['manual_collected'] ?? $in['orders_paid']['manual'] ?? 0)
            + ($in['unfulfilled_card_credits'] ?? 0)
            + ($in['failed_external_collected'] ?? 0)
            + ($in['site_feature_stripe'] ?? 0),
            2
        );

        $internal = round(
            ($in['orders_paid']['wallet'] ?? 0)
            + ($in['bonuses_issued']['amount'] ?? 0),
            2
        );

        $cashOut = 0.0;
        if (Withdrawal::tableAvailable()) {
            $cashOutQuery = Withdrawal::where('status', 'completed');
            $this->applyWithdrawalProcessedWindow($cashOutQuery, $start, $end);
            $cashOut = (float) $cashOutQuery->sum('net_amount');
        }

        return [
            'cash_in_bank' => $cashIn,
            'internal_only' => $internal,
            'cash_out_payouts' => round($cashOut, 2),
            'note' => 'Cash in = Stripe/card + PayPal checkout/deposits + approved bank/Wise/crypto deposits & manual order payments + leftover card credits + featured-site Stripe + paid→failed captures returned to wallet. Wallet refunds do not remove collected card/manual cash (no Stripe refund). PayPal checkout refunds return on PayPal, not as a second wallet credit. A PayPal-dashboard refund of an Add Funds deposit is removed from cash in and debited from the wallet. Internal = wallet checkouts + welcome bonuses.',
        ];
    }

    /**
     * Per-user finance dossier.
     *
     * @return array<string, mixed>
     */
    public function userDossier(User $user): array
    {
        try {
            $user->load('roles');
        } catch (\Throwable) {
            $user->setRelation('roles', collect());
        }
        $advertiserRoleId = $this->walletsAvailable() ? Wallet::advertiserRoleId() : null;
        $publisherRoleId = $this->walletsAvailable() ? Wallet::publisherRoleId() : null;

        $advWallet = $advertiserRoleId
            ? Wallet::where('user_id', $user->id)->where('role_id', $advertiserRoleId)->first()
            : null;
        $pubWallet = $publisherRoleId
            ? Wallet::where('user_id', $user->id)->where('role_id', $publisherRoleId)->first()
            : null;

        $deposits = DepositRequest::tableAvailable()
            ? DepositRequest::where('user_id', $user->id)->latest()->limit(20)->get()
            : collect();
        $orders = collect();
        try {
            $orders = Order::where('user_id', $user->id)->latest()->limit(20)->get();
        } catch (\Throwable) {
            $orders = collect();
        }
        $withdrawals = Withdrawal::tableAvailable()
            ? Withdrawal::where('user_id', $user->id)->latest()->limit(20)->get()
            : collect();
        $ledger = $this->walletTransactionsAvailable()
            ? WalletTransaction::where('user_id', $user->id)->latest()->limit(50)->get()
            : collect();

        $siteIds = collect();
        try {
            if (Schema::hasTable('sites')) {
                $siteIds = DB::table('sites')->where('publisher_id', $user->id)->pluck('id');
            }
        } catch (\Throwable) {
            $siteIds = collect();
        }
        $earnings = 0.0;
        $feesOnTheirSales = 0.0;
        if ($siteIds->isNotEmpty()) {
            try {
                $earnings = (float) OrderItem::whereIn('site_id', $siteIds)
                    ->recognizedForFinance()
                    ->whereHas('order', fn ($q) => $q->where('status', 'completed')->where('payment_status', 'paid'))
                    ->sum(OrderItem::publisherPayoutSqlExpression());
                $feesOnTheirSales = (float) OrderItem::whereIn('site_id', $siteIds)
                    ->recognizedForFinance()
                    ->whereHas('order', fn ($q) => $q->where('status', 'completed')->where('payment_status', 'paid'))
                    ->sum(OrderItem::platformFeeSqlExpression());
            } catch (\Throwable) {
                $earnings = 0.0;
                $feesOnTheirSales = 0.0;
            }
        }

        $currentPaidGmv = 0.0;
        $gmvAsAdvertiser = 0.0;
        $refundsAsAdvertiser = 0.0;
        $paidOrdersCount = 0;
        try {
            $currentPaidGmv = (float) Order::where('user_id', $user->id)
                ->where('payment_status', 'paid')
                ->sum('total_amount');
            $gmvAsAdvertiser = (float) Order::where('user_id', $user->id)
                ->whereIn('payment_status', ['paid', 'refunded'])
                ->sum('total_amount');
            $refundsAsAdvertiser = $this->userAdvertiserRefunds($user);
            $paidOrdersCount = (int) Order::where('user_id', $user->id)
                ->where('payment_status', 'paid')
                ->count();
        } catch (\Throwable) {
            $currentPaidGmv = 0.0;
            $gmvAsAdvertiser = 0.0;
            $refundsAsAdvertiser = 0.0;
            $paidOrdersCount = 0;
        }

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
                'deposits_completed' => DepositRequest::tableAvailable()
                    ? (float) DepositRequest::where('user_id', $user->id)->where('status', 'completed')->sum('amount')
                    : 0.0,
                'gmv_as_advertiser' => round($gmvAsAdvertiser, 2),
                'current_paid_gmv' => round($currentPaidGmv, 2),
                'refunds_as_advertiser' => $refundsAsAdvertiser,
                'net_gmv_as_advertiser' => round(max(0.0, $gmvAsAdvertiser - $refundsAsAdvertiser), 2),
                'paid_orders_count' => $paidOrdersCount,
                'earnings_as_publisher' => round($earnings, 2),
                'platform_fees_on_their_sites' => round($feesOnTheirSales, 2),
                'withdrawals_paid_net' => Withdrawal::tableAvailable()
                    ? (float) Withdrawal::where('user_id', $user->id)->where('status', 'completed')->sum('net_amount')
                    : 0.0,
                'withdrawals_open_net' => Withdrawal::tableAvailable()
                    ? (float) Withdrawal::where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->sum('net_amount')
                    : 0.0,
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

        $rows = [
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
            ['section' => 'money_in', 'metric' => 'unfulfilled_card_credits', 'value' => $data['money_in']['unfulfilled_card_credits']],
            ['section' => 'money_in', 'metric' => 'stripe_card_collected', 'value' => $data['money_in']['stripe_card_collected']],
            ['section' => 'money_in', 'metric' => 'manual_collected', 'value' => $data['money_in']['manual_collected']],
            ['section' => 'money_in', 'metric' => 'site_feature_stripe', 'value' => $data['money_in']['site_feature_stripe']],
            ['section' => 'money_in', 'metric' => 'failed_external_collected', 'value' => $data['money_in']['failed_external_collected']],
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

        foreach ($data['money_in']['collected']['by_currency'] ?? [] as $code => $parts) {
            foreach (['card', 'paypal', 'other'] as $bucket) {
                if (($parts[$bucket] ?? 0) == 0.0) {
                    continue;
                }
                $rows[] = ['section' => 'collected', 'metric' => $code.'_'.$bucket, 'value' => $parts[$bucket]];
            }
        }
        $rows[] = ['section' => 'collected', 'metric' => 'orders_not_recorded', 'value' => $data['money_in']['collected']['orders_not_recorded'] ?? 0];
        $rows[] = ['section' => 'collected', 'metric' => 'features_not_recorded', 'value' => $data['money_in']['collected']['features_not_recorded'] ?? 0];

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function cardOrderMethods(): array
    {
        return ['card', 'stripe'];
    }

    /**
     * @return list<string>
     */
    private function manualOrderMethods(): array
    {
        return ['wise', 'bank', 'bank_transfer', 'crypto'];
    }

    /**
     * Completed sales that recognized a platform fee — still paid, or later
     * wallet-refunded. In-progress cancels never completed and are excluded.
     */
    private function constrainRecognizedCompleted($query): void
    {
        $query->whereIn('payment_status', ['paid', 'refunded'])
            ->where(function ($q) {
                $q->where('status', 'completed');
                if ($this->ordersHaveColumn('completed_at')) {
                    $q->orWhereNotNull('completed_at');
                }
            });
    }

    /**
     * Card / manual cash that hit the bank. Wallet refunds keep payment_status
     * refunded but do not return the Stripe/bank capture, so those rows stay.
     *
     * @param  list<string>  $methods
     */
    private function sumExternalOrdersCollected(?Carbon $start, Carbon $end, array $methods): float
    {
        $query = Order::query()
            ->whereIn('payment_method', $methods)
            ->whereIn('payment_status', ['paid', 'refunded']);
        $this->applyPaidWindow($query, $start, $end);

        return round((float) $query->sum('total_amount'), 2);
    }

    /**
     * Admin paid→failed clears paid_at but credits the wallet (capture stays
     * in the bank). Count those orders once when a refund ledger row exists.
     * Cash-in is dated by checkout (created_at) — paid_at is gone, and the
     * refund write is the wallet return, not the capture.
     */
    private function sumFailedExternalCollected(?Carbon $start, Carbon $end): float
    {
        $query = $this->failedExternalOrdersBase();
        $this->applyCreatedWindow($query, $start, $end);

        return round((float) $query->sum('total_amount'), 2);
    }

    private function failedExternalOrdersBase()
    {
        if (! $this->walletTransactionsAvailable()) {
            return Order::query()->whereRaw('0 = 1');
        }

        $methods = array_merge($this->cardOrderMethods(), $this->manualOrderMethods());
        $morph = (new Order)->getMorphClass();

        return Order::query()
            ->where('payment_status', 'failed')
            ->whereIn('payment_method', $methods)
            ->whereExists(function ($exists) use ($morph) {
                $exists->select(DB::raw('1'))
                    ->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.related_id', 'orders.id')
                    ->where('wallet_transactions.related_type', $morph)
                    ->where('wallet_transactions.type', WalletTransaction::TYPE_REFUND)
                    ->where('wallet_transactions.direction', 'credit');
            });
    }

    private function failedExternalOrdersWithWalletReturn(?Carbon $start, Carbon $end)
    {
        // failedExternalOrdersBase() already returns `0 = 1` when the ledger
        // table is gone, but SQLite still evaluates a trailing EXISTS against
        // the missing table and 500s the finance hub.
        if (! $this->walletTransactionsAvailable()) {
            return Order::query()->whereRaw('0 = 1');
        }

        $morph = (new Order)->getMorphClass();

        return $this->failedExternalOrdersBase()
            ->whereExists(function ($exists) use ($morph, $start, $end) {
                $exists->select(DB::raw('1'))
                    ->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.related_id', 'orders.id')
                    ->where('wallet_transactions.related_type', $morph)
                    ->where('wallet_transactions.type', WalletTransaction::TYPE_REFUND)
                    ->where('wallet_transactions.direction', 'credit');
                if ($start) {
                    $exists->whereBetween('wallet_transactions.created_at', [$start, $end]);
                } else {
                    $exists->where('wallet_transactions.created_at', '<=', $end);
                }
            });
    }

    /**
     * All-time advertiser refunds for a dossier: refunded order totals minus
     * clawed lines already in those totals, plus each clawback credit, plus
     * paid→failed wallet returns.
     */
    private function userAdvertiserRefunds(User $user): float
    {
        $end = now()->endOfDay();
        $refundOrders = Order::where('payment_status', 'refunded')->where('user_id', $user->id);
        $this->applyRefundWindow($refundOrders, null, $end);
        $failedRefundOrders = $this->failedExternalOrdersWithWalletReturn(null, $end)
            ->where('user_id', $user->id);

        return round(
            (float) (clone $refundOrders)->sum('total_amount')
            - $this->clawbackCreditsOnOrders($refundOrders)
            + (float) (clone $failedRefundOrders)->sum('total_amount')
            + $this->partialClawbackAdvertiserCredits(null, $end, $user->id),
            2
        );
    }

    /**
     * Advertiser credits from upheld disputes, including after the last line
     * flips the order to refunded. Dated by dispute resolution.
     */
    private function partialClawbackAdvertiserCredits(?Carbon $start, Carbon $end, ?int $userId = null): float
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0.0;
        }

        $query = OrderItemDispute::query()
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->where('advertiser_credited', '>', 0)
            ->whereHas('order', function ($order) use ($userId) {
                $this->constrainRecognizedCompleted($order);
                if ($userId !== null) {
                    $order->where('user_id', $userId);
                }
            });
        $this->applyDisputeResolvedWindow($query, $start, $end);

        return round((float) $query->sum('advertiser_credited'), 2);
    }

    /**
     * Clawback credits already sitting on refunded orders in this window.
     * Those orders' totals still include the clawed lines; subtract here so
     * the same euros are not counted again via partialClawbackAdvertiserCredits.
     */
    private function clawbackCreditsOnOrders($ordersQuery): float
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0.0;
        }

        return round((float) OrderItemDispute::query()
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->whereIn('order_id', (clone $ordersQuery)->select('orders.id'))
            ->sum('advertiser_credited'), 2);
    }

    /**
     * Distinct orders that returned advertiser credit this window.
     */
    private function partialClawbackRefundOrderCount(?Carbon $start, Carbon $end): int
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0;
        }

        $query = OrderItemDispute::query()
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->where('advertiser_credited', '>', 0)
            ->whereHas('order', function ($order) {
                $this->constrainRecognizedCompleted($order);
            });
        $this->applyDisputeResolvedWindow($query, $start, $end);

        return $query->pluck('order_id')->unique()->count();
    }

    /**
     * Refunded-order count plus clawbacks, without double-counting a sale
     * that flipped to refunded only because every line was already clawed.
     */
    private function refundOrdersCount($refundOrders, $failedRefundOrders, ?Carbon $start, Carbon $end): int
    {
        $classic = 0;
        if (OrderItemDispute::tableAvailable()) {
            $orders = (clone $refundOrders)->get(['id', 'total_amount']);
            $credits = OrderItemDispute::query()
                ->where('status', OrderItemDispute::STATUS_UPHELD)
                ->whereIn('order_id', $orders->pluck('id'))
                ->selectRaw('order_id, SUM(advertiser_credited) as credited')
                ->groupBy('order_id')
                ->pluck('credited', 'order_id');
            foreach ($orders as $order) {
                $remaining = (float) $order->total_amount - (float) ($credits[$order->id] ?? 0);
                if ($remaining > 0.009) {
                    $classic++;
                }
            }
        } else {
            $classic = (clone $refundOrders)->count();
        }

        return $classic
            + (clone $failedRefundOrders)->count()
            + $this->partialClawbackRefundOrderCount($start, $end);
    }

    /**
     * Platform fees on clawed lines (paid or later fully refunded).
     * Dated by dispute resolution, not the original completion date.
     */
    private function partialClawbackRecognizedFees(?Carbon $start, Carbon $end): float
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0.0;
        }

        $query = OrderItem::query()
            ->clawedBack()
            ->whereHas('order', function ($q) {
                $this->constrainRecognizedCompleted($q);
            })
            ->whereHas('disputes', function ($disputes) use ($start, $end) {
                $disputes->where('status', OrderItemDispute::STATUS_UPHELD);
                $this->applyDisputeResolvedWindow($disputes, $start, $end);
            });

        return round((float) $query->sum(OrderItem::platformFeeSqlExpression()), 2);
    }

    /**
     * Publisher payout on clawed lines, dated by dispute resolution.
     */
    private function clawedPublisherPayouts(?Carbon $start, Carbon $end): float
    {
        return round((float) $this->clawedPublisherPayoutQuery($start, $end)
            ->sum(OrderItem::publisherPayoutSqlExpression()), 2);
    }

    /**
     * Remaining (non-clawed) payouts reversed when a completed sale is later
     * marked refunded. Clawed lines reverse via clawedPublisherPayouts.
     */
    private function refundedNonClawedPublisherPayouts(?Carbon $start, Carbon $end): float
    {
        return round((float) $this->refundedNonClawedPublisherPayoutQuery($start, $end)
            ->sum(OrderItem::publisherPayoutSqlExpression()), 2);
    }

    /**
     * Lines reversed in this window (clawback or later full-order refund).
     */
    private function reversedPublisherPayoutCount(?Carbon $start, Carbon $end): int
    {
        return $this->clawedPublisherPayoutQuery($start, $end)->count()
            + $this->refundedNonClawedPublisherPayoutQuery($start, $end)->count();
    }

    private function clawedPublisherPayoutQuery(?Carbon $start, Carbon $end)
    {
        if (! OrderItemDispute::tableAvailable()) {
            return OrderItem::query()->whereRaw('0 = 1');
        }

        return OrderItem::query()
            ->clawedBack()
            ->whereHas('order', function ($q) {
                $this->constrainRecognizedCompleted($q);
            })
            ->whereHas('disputes', function ($disputes) use ($start, $end) {
                $disputes->where('status', OrderItemDispute::STATUS_UPHELD);
                $this->applyCreatedOrPaidWindow($disputes, $start, $end, 'resolved_at');
            });
    }

    private function refundedNonClawedPublisherPayoutQuery(?Carbon $start, Carbon $end)
    {
        return OrderItem::query()
            ->when(OrderItemDispute::tableAvailable(), function ($items) {
                $items->whereDoesntHave('disputes', function ($disputes) {
                    $disputes->where('status', OrderItemDispute::STATUS_UPHELD);
                });
            })
            ->whereHas('order', function ($q) use ($start, $end) {
                $this->constrainRecognizedCompleted($q);
                $q->where('payment_status', 'refunded');
                $this->applyRefundWindow($q, $start, $end);
            });
    }

    /**
     * Publisher featured-site Stripe charges (including leftover stripe_credit
     * when the listing could not be featured after capture).
     */
    private function siteFeatureStripeCash(?Carbon $start, Carbon $end): float
    {
        try {
            if (! Schema::hasTable('site_feature_purchases')) {
                return 0.0;
            }

            $query = SiteFeaturePurchase::query()
                ->whereIn('payment_method', ['stripe', 'stripe_credit']);
            $this->applyCreatedWindow($query, $start, $end);

            return round((float) $query->sum('amount'), 2);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Stripe captured cash credited to the advertiser wallet when paid lines
     * left the catalog (no paid order row, so it is not in orders GMV).
     */
    private function unfulfilledCardCredits(?Carbon $start, Carbon $end): float
    {
        if (! $this->walletTransactionsAvailable()) {
            return 0.0;
        }

        $prefix = OrderPaymentService::unfulfilledCardCreditReference('');
        $query = WalletTransaction::query()
            ->where('direction', 'credit')
            ->where('reference', 'like', $prefix.'%');
        $this->applyCreatedWindow($query, $start, $end);

        return round((float) $query->sum('amount'), 2);
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

    /**
     * Prefer the last wallet-refund write for this order so a later admin
     * note / save does not move the refund into another period.
     */
    private function applyRefundWindow($query, ?Carbon $start, Carbon $end): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->applyCoalesceWindow($query, $start, $end, 'orders.updated_at', 'orders.updated_at');

            return;
        }

        $refundAt = '(SELECT MAX(wallet_transactions.created_at) FROM wallet_transactions'
            .' WHERE wallet_transactions.related_id = orders.id'
            .' AND wallet_transactions.related_type = ?'
            .' AND wallet_transactions.type = ?'
            .' AND wallet_transactions.direction = ?)';
        $expr = 'COALESCE('.$refundAt.', orders.updated_at)';
        $bindings = [
            (new Order)->getMorphClass(),
            WalletTransaction::TYPE_REFUND,
            'credit',
        ];

        if ($start) {
            $query->whereRaw($expr.' BETWEEN ? AND ?', [...$bindings, $start, $end]);
        } else {
            $query->whereRaw($expr.' <= ?', [...$bindings, $end]);
        }
    }

    private function applyCreatedOrPaidWindow($query, ?Carbon $start, Carbon $end, string $preferred): void
    {
        $table = $query->getModel()->getTable();
        $preferredColumn = $table.'.'.$preferred;
        if ($table === 'deposit_requests' && ! $this->depositsHaveColumn($preferred)) {
            $this->applyCoalesceWindow($query, $start, $end, $table.'.created_at', $table.'.created_at');

            return;
        }
        $this->applyCoalesceWindow($query, $start, $end, $preferredColumn, $table.'.created_at');
    }

    /**
     * Resolution-clock window. Leftover Hostinger resolved_at strings are
     * not a clawback date — treat them as null and fall back to a parseable
     * created_at so refunds stay in the period of the last real write.
     */
    private function applyDisputeResolvedWindow($query, ?Carbon $start, Carbon $end): void
    {
        $table = $query->getModel()->getTable();
        $floor = OrderItemDispute::PLAUSIBLE_SQL_DATETIME_FLOOR;
        $ceil = OrderItemDispute::PLAUSIBLE_SQL_DATETIME_CEIL;
        $expr = 'COALESCE('
            .'CASE WHEN '.$table.'.resolved_at >= ? AND '.$table.'.resolved_at <= ? THEN '.$table.'.resolved_at END, '
            .'CASE WHEN '.$table.'.created_at >= ? AND '.$table.'.created_at <= ? THEN '.$table.'.created_at END)';

        if ($start) {
            $query->whereRaw($expr.' BETWEEN ? AND ?', [$floor, $ceil, $floor, $ceil, $start, $end]);
        } else {
            $query->whereRaw($expr.' <= ?', [$floor, $ceil, $floor, $ceil, $end]);
        }
    }

    /**
     * Paid-clock window. Leftover Hostinger processed_at strings are not a
     * payout date — treat them as null and fall back to updated_at so cash-out
     * stays in the period of the last real write (same as a missing stamp).
     */
    private function applyWithdrawalProcessedWindow($query, ?Carbon $start, Carbon $end): void
    {
        if (! Withdrawal::hasProcessedAtColumn()) {
            $this->applyCoalesceWindow($query, $start, $end, 'withdrawals.updated_at', 'withdrawals.created_at');

            return;
        }

        $floor = Withdrawal::PLAUSIBLE_SQL_DATETIME_FLOOR;
        $ceil = Withdrawal::PLAUSIBLE_SQL_DATETIME_CEIL;
        $expr = 'COALESCE(CASE WHEN withdrawals.processed_at >= ? AND withdrawals.processed_at <= ?'
            .' THEN withdrawals.processed_at END, withdrawals.updated_at)';

        if ($start) {
            $query->whereRaw($expr.' BETWEEN ? AND ?', [$floor, $ceil, $start, $end]);
        } else {
            $query->whereRaw($expr.' <= ?', [$floor, $ceil, $end]);
        }
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

    private function depositsHaveColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('deposit_requests', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function walletsAvailable(): bool
    {
        return $this->schemaTableAvailable('wallets');
    }

    private function walletTransactionsAvailable(): bool
    {
        return $this->schemaTableAvailable('wallet_transactions');
    }

    private function schemaTableAvailable(string $table): bool
    {
        try {
            if (! Schema::hasTable($table)) {
                return false;
            }
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ledgerTypeAmount(string $type, ?Carbon $start, Carbon $end, ?string $relatedType = null): float
    {
        $query = $this->ledgerTypeQuery($type, $start, $end, $relatedType);

        return $query ? (float) $query->sum('amount') : 0.0;
    }

    private function ledgerTypeCount(string $type, ?Carbon $start, Carbon $end, ?string $relatedType = null): int
    {
        $query = $this->ledgerTypeQuery($type, $start, $end, $relatedType);

        return $query ? (int) $query->count() : 0;
    }

    private function ledgerTypeQuery(string $type, ?Carbon $start, Carbon $end, ?string $relatedType = null)
    {
        if (! $this->walletTransactionsAvailable()) {
            return null;
        }

        $query = WalletTransaction::where('type', $type);
        if ($relatedType !== null) {
            $query->where('related_type', $relatedType);
        }
        $this->applyCreatedWindow($query, $start, $end);

        return $query;
    }
}
