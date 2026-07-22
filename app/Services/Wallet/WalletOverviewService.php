<?php

namespace App\Services\Wallet;

use App\Models\BalanceTransfer;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WalletOverviewService
{
    public function __construct(
        protected WalletLedgerService $ledger
    ) {
    }

    public function summary(int $userId, Wallet $wallet): array
    {
        $available = $wallet->withdrawableBalance();
        $bonus = $wallet->lockedBonusBalance();
        $pendingReserved = round((float) $wallet->reserved_balance, 2);
        $pendingDeposits = (float) DepositRequest::where('user_id', $userId)
            ->whereIn('status', ['pending'])
            ->sum('amount');
        $pendingBalance = round($pendingReserved + $pendingDeposits, 2);

        $lifetimeDeposits = (float) DepositRequest::where('user_id', $userId)
            ->whereIn('status', ['approved', 'completed'])
            ->sum('amount');

        $lifetimeSpending = (float) Order::where('user_id', $userId)
            ->where('payment_method', 'wallet')
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereNotIn('status', ['cancelled', 'rejected', 'failed'])
            ->sum('total_amount');

        // Fallback: ledger purchases if orders don't cover older data
        $ledgerPurchases = (float) WalletTransaction::where('user_id', $userId)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where('status', 'completed')
            ->sum('amount');
        $lifetimeSpending = max($lifetimeSpending, $ledgerPurchases);

        $lifetimeWithdrawals = (float) Withdrawal::where('user_id', $userId)
            ->whereIn('status', ['completed', 'processing', 'pending'])
            ->sum('amount');

        $pendingWithdrawals = (float) Withdrawal::where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $bonusReceived = (float) WalletTransaction::where('user_id', $userId)
            ->where('type', WalletTransaction::TYPE_BONUS_CREDIT)
            ->sum('bonus_amount');
        if ($bonusReceived <= 0) {
            $bonusReceived = (float) WalletTransaction::where('user_id', $userId)
                ->where('type', WalletTransaction::TYPE_BONUS_CREDIT)
                ->sum('amount');
        }
        if ($bonusReceived <= 0 && $bonus > 0) {
            $bonusReceived = $bonus;
        }

        $bonusUsed = max(0, round($bonusReceived - $bonus - (float) $wallet->bonus_reserved, 2));

        return [
            'available_balance' => $available,
            'bonus_balance' => $bonus,
            'pending_balance' => $pendingBalance,
            'spendable_balance' => round((float) $wallet->balance, 2),
            'reserved_balance' => $pendingReserved,
            'pending_deposits' => $pendingDeposits,
            'lifetime_deposits' => round($lifetimeDeposits, 2),
            'lifetime_spending' => round($lifetimeSpending, 2),
            'lifetime_withdrawals' => round($lifetimeWithdrawals, 2),
            'pending_withdrawals' => round($pendingWithdrawals, 2),
            'bonus_received' => round($bonusReceived, 2),
            'bonus_used' => $bonusUsed,
            'bonus_remaining' => $bonus,
            'bonus_reserved' => round((float) $wallet->bonus_reserved, 2),
            'bonus_expires_at' => null,
            'currency' => $wallet->currency ?? 'EUR',
        ];
    }

    public function analytics(int $userId, string $range = 'month', ?string $fromDate = null, ?string $toDate = null): array
    {
        [$from, $to, $bucket] = $this->rangeBounds($range, $fromDate, $toDate);

        $labels = [];
        $cursor = $from->copy();
        while ($cursor <= $to) {
            $key = $bucket === 'day' ? $cursor->format('Y-m-d') : $cursor->format('Y-m');
            $labels[$key] = [
                'key' => $key,
                'label' => $bucket === 'day' ? $cursor->format('M j') : $cursor->format('M Y'),
                'deposits' => 0.0,
                'orders' => 0.0,
                'withdrawals' => 0.0,
                'bonus_usage' => 0.0,
                'order_count' => 0,
                'largest_order' => 0.0,
                'order_ids' => [],
            ];
            $cursor = $bucket === 'day' ? $cursor->addDay() : $cursor->addMonth();
        }

        $depositRows = DepositRequest::where('user_id', $userId)
            ->whereIn('status', ['approved', 'completed'])
            ->whereBetween(DB::raw('COALESCE(paid_at, approved_at, created_at)'), [$from, $to])
            ->get(['amount', 'paid_at', 'approved_at', 'created_at']);

        foreach ($depositRows as $row) {
            $at = $row->paid_at ?? $row->approved_at ?? $row->created_at;
            $key = $bucket === 'day' ? $at->format('Y-m-d') : $at->format('Y-m');
            if (isset($labels[$key])) {
                $labels[$key]['deposits'] += (float) $row->amount;
            }
        }

        $orderRows = Order::with(['items.site'])
            ->where('user_id', $userId)
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereNotIn('status', ['cancelled', 'rejected', 'failed'])
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $pointOrders = [];

        foreach ($orderRows as $row) {
            $key = $bucket === 'day' ? $row->created_at->format('Y-m-d') : $row->created_at->format('Y-m');
            $amount = (float) $row->total_amount;
            if (isset($labels[$key])) {
                $labels[$key]['orders'] += $amount;
                $labels[$key]['order_count']++;
                $labels[$key]['largest_order'] = max($labels[$key]['largest_order'], $amount);
                $labels[$key]['order_ids'][] = $row->id;
            }

            $site = $row->items->first()?->site;
            $invoice = Invoice::where('user_id', $userId)
                ->where(function ($q) use ($row) {
                    $q->where('order_id', $row->id)
                        ->orWhere('order_number', $row->order_number)
                        ->orWhere('reference_code', $row->reference_code);
                })
                ->first();

            $pointOrders[] = [
                'id' => $row->id,
                'order_number' => $row->order_number,
                'bucket' => $key,
                'amount' => $amount,
                'status' => $row->status,
                'payment_status' => $row->payment_status,
                'date' => $row->created_at?->toIso8601String(),
                'completed_at' => $row->status === 'completed' ? ($row->updated_at?->toIso8601String()) : null,
                'site_name' => $site?->site_name,
                'site_url' => $site?->site_url ?? $site?->domain,
                'invoice_number' => $invoice?->invoice_number,
                'order_url' => url('/advertiser/orders?focus=order&order='.$row->id),
            ];
        }

        $withdrawalRows = Withdrawal::where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->whereBetween('created_at', [$from, $to])
            ->get(['amount', 'created_at']);

        foreach ($withdrawalRows as $row) {
            $key = $bucket === 'day' ? $row->created_at->format('Y-m-d') : $row->created_at->format('Y-m');
            if (isset($labels[$key])) {
                $labels[$key]['withdrawals'] += (float) $row->amount;
            }
        }

        $bonusRows = WalletTransaction::where('user_id', $userId)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where('bonus_amount', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->get(['bonus_amount', 'created_at']);

        foreach ($bonusRows as $row) {
            $key = $bucket === 'day' ? $row->created_at->format('Y-m-d') : $row->created_at->format('Y-m');
            if (isset($labels[$key])) {
                $labels[$key]['bonus_usage'] += (float) $row->bonus_amount;
            }
        }

        $series = array_values($labels);
        $hasSpend = collect($series)->sum('orders') > 0;

        return [
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'has_spend' => $hasSpend,
            'labels' => array_column($series, 'label'),
            'keys' => array_column($series, 'key'),
            'deposits' => array_map(fn ($r) => round($r['deposits'], 2), $series),
            'orders' => array_map(fn ($r) => round($r['orders'], 2), $series),
            'withdrawals' => array_map(fn ($r) => round($r['withdrawals'], 2), $series),
            'bonus_usage' => array_map(fn ($r) => round($r['bonus_usage'], 2), $series),
            'points' => array_map(function ($r) {
                $count = (int) $r['order_count'];
                $spend = round((float) $r['orders'], 2);

                return [
                    'key' => $r['key'],
                    'label' => $r['label'],
                    'total_spend' => $spend,
                    'order_count' => $count,
                    'avg_order' => $count > 0 ? round($spend / $count, 2) : 0,
                    'largest_order' => round((float) $r['largest_order'], 2),
                    'order_ids' => $r['order_ids'],
                ];
            }, $series),
            'order_details' => $pointOrders,
        ];
    }

    /**
     * Unified activity feed from ledger + legacy tables.
     */
    public function activity(int $userId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $items = $this->collectActivity($userId, $filters);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = $items->count();
        $slice = $items->forPage($page, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function exportRows(int $userId, array $filters = []): Collection
    {
        return $this->collectActivity($userId, $filters);
    }

    public function findActivity(int $userId, string $source, $id): ?array
    {
        return $this->collectActivity($userId, [])->first(function ($row) use ($source, $id) {
            return ($row['source'] ?? null) === $source && (string) ($row['id'] ?? '') === (string) $id;
        });
    }

    protected function collectActivity(int $userId, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;

        $rows = collect();

        // Prefer ledger entries
        $ledger = WalletTransaction::where('user_id', $userId)->orderByDesc('created_at')->get();
        foreach ($ledger as $tx) {
            $invoice = null;
            if ($tx->related_type && $tx->related_id) {
                if ($tx->related_type === Invoice::class || str_contains((string) $tx->related_type, 'Invoice')) {
                    $invoice = Invoice::find($tx->related_id);
                } elseif ($tx->type === WalletTransaction::TYPE_DEPOSIT) {
                    $deposit = DepositRequest::find($tx->related_id);
                    if ($deposit) {
                        $invoice = Invoice::where('user_id', $userId)
                            ->where(function ($q) use ($deposit) {
                                $q->where('reference_code', $deposit->reference_code)
                                    ->orWhere('transaction_id', $deposit->stripe_payment_intent_id);
                            })
                            ->first();
                    }
                }
            }

            $rows->push([
                'id' => $tx->id,
                'source' => 'ledger',
                'date' => $tx->created_at?->toIso8601String(),
                'timestamp' => $tx->created_at?->timestamp ?? 0,
                'type' => $tx->type,
                'type_label' => $tx->typeLabel(),
                'description' => $tx->description,
                'reference' => $tx->reference,
                'amount' => (float) $tx->amount,
                'direction' => $tx->direction,
                'signed_amount' => $tx->direction === 'credit' ? (float) $tx->amount : -(float) $tx->amount,
                'status' => $tx->status,
                'balance_after' => $tx->balance_after !== null ? (float) $tx->balance_after : null,
                'bonus_amount' => (float) $tx->bonus_amount,
                'payment_method' => $tx->payment_method,
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'order_reference' => $tx->meta['order_reference'] ?? null,
                'icon' => $this->iconForType($tx->type),
            ]);
        }

        // Legacy deposits not yet in ledger
        $ledgerDepositRefs = $ledger->where('type', WalletTransaction::TYPE_DEPOSIT)->pluck('reference')->filter()->all();
        DepositRequest::where('user_id', $userId)->orderByDesc('created_at')->get()->each(function ($d) use ($rows, $ledgerDepositRefs, $userId) {
            if (in_array($d->reference_code, $ledgerDepositRefs, true)) {
                return;
            }
            $invoice = Invoice::where('user_id', $userId)
                ->where('reference_code', $d->reference_code)
                ->first();
            $status = $d->status === 'approved' ? 'completed' : $d->status;
            $rows->push([
                'id' => $d->id,
                'source' => 'deposit',
                'date' => ($d->paid_at ?? $d->created_at)?->toIso8601String(),
                'timestamp' => ($d->paid_at ?? $d->created_at)?->timestamp ?? 0,
                'type' => WalletTransaction::TYPE_DEPOSIT,
                'type_label' => 'Deposit',
                'description' => 'Wallet deposit via '.ucfirst((string) $d->payment_method),
                'reference' => $d->reference_code,
                'amount' => (float) $d->amount,
                'direction' => 'credit',
                'signed_amount' => (float) $d->amount,
                'status' => $status,
                'balance_after' => null,
                'bonus_amount' => 0,
                'payment_method' => $d->payment_method,
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'order_reference' => null,
                'icon' => $this->iconForType(WalletTransaction::TYPE_DEPOSIT),
            ]);
        });

        // Legacy withdrawals
        $ledgerWithdrawalIds = $ledger->where('type', WalletTransaction::TYPE_WITHDRAWAL)
            ->pluck('related_id')->filter()->all();
        Withdrawal::where('user_id', $userId)->orderByDesc('created_at')->get()->each(function ($w) use ($rows, $ledgerWithdrawalIds) {
            if (in_array($w->id, $ledgerWithdrawalIds, true)) {
                return;
            }
            $rows->push([
                'id' => $w->id,
                'source' => 'withdrawal',
                'date' => $w->created_at?->toIso8601String(),
                'timestamp' => $w->created_at?->timestamp ?? 0,
                'type' => WalletTransaction::TYPE_WITHDRAWAL,
                'type_label' => 'Withdrawal',
                'description' => 'Withdrawal via '.ucfirst((string) $w->payment_method),
                'reference' => 'WD-'.$w->id,
                'amount' => (float) $w->amount,
                'direction' => 'debit',
                'signed_amount' => -(float) $w->amount,
                'status' => $w->status,
                'balance_after' => null,
                'bonus_amount' => 0,
                'payment_method' => $w->payment_method,
                'invoice_id' => null,
                'invoice_number' => null,
                'order_reference' => null,
                'icon' => $this->iconForType(WalletTransaction::TYPE_WITHDRAWAL),
            ]);
        });

        // Transfers out from advertiser
        BalanceTransfer::where('user_id', $userId)
            ->where('from_role', 'advertiser')
            ->orderByDesc('created_at')
            ->get()
            ->each(function ($t) use ($rows, $ledger) {
                if ($ledger->where('reference', $t->reference_code)->isNotEmpty()) {
                    return;
                }
                $rows->push([
                    'id' => $t->id,
                    'source' => 'transfer',
                    'date' => $t->created_at?->toIso8601String(),
                    'timestamp' => $t->created_at?->timestamp ?? 0,
                    'type' => WalletTransaction::TYPE_TRANSFER_OUT,
                    'type_label' => 'Transfer Out',
                    'description' => 'Transfer to Publisher wallet',
                    'reference' => $t->reference_code,
                    'amount' => (float) $t->amount,
                    'direction' => 'debit',
                    'signed_amount' => -(float) $t->amount,
                    'status' => $t->status,
                    'balance_after' => null,
                    'bonus_amount' => 0,
                    'payment_method' => null,
                    'invoice_id' => null,
                    'invoice_number' => null,
                    'order_reference' => null,
                    'icon' => $this->iconForType(WalletTransaction::TYPE_TRANSFER_OUT),
                ]);
            });

        // Wallet purchases from orders
        Order::where('user_id', $userId)
            ->where('payment_method', 'wallet')
            ->orderByDesc('created_at')
            ->get()
            ->each(function ($o) use ($rows, $ledger) {
                $ref = $o->reference_code ?? $o->order_number ?? ('ORD-'.$o->id);
                if ($ledger->where('reference', $ref)->isNotEmpty()) {
                    return;
                }
                $isRefundish = in_array($o->status, ['cancelled', 'rejected', 'refunded'], true);
                $rows->push([
                    'id' => $o->id,
                    'source' => 'order',
                    'date' => $o->created_at?->toIso8601String(),
                    'timestamp' => $o->created_at?->timestamp ?? 0,
                    'type' => $isRefundish ? WalletTransaction::TYPE_REFUND : WalletTransaction::TYPE_PURCHASE,
                    'type_label' => $isRefundish ? 'Refund' : 'Purchase',
                    'description' => $isRefundish ? 'Order cancelled / refunded' : 'Marketplace order purchase',
                    'reference' => $ref,
                    'amount' => (float) $o->total_amount,
                    'direction' => $isRefundish ? 'credit' : 'debit',
                    'signed_amount' => $isRefundish ? (float) $o->total_amount : -(float) $o->total_amount,
                    'status' => $o->payment_status ?: $o->status,
                    'balance_after' => null,
                    'bonus_amount' => 0,
                    'payment_method' => 'wallet',
                    'invoice_id' => null,
                    'invoice_number' => null,
                    'order_reference' => $ref,
                    'icon' => $this->iconForType($isRefundish ? WalletTransaction::TYPE_REFUND : WalletTransaction::TYPE_PURCHASE),
                ]);
            });

        // Synthetic bonus if present and no ledger bonus
        if ($ledger->where('type', WalletTransaction::TYPE_BONUS_CREDIT)->isEmpty()) {
            $wallet = Wallet::where('user_id', $userId)
                ->where('role_id', Wallet::advertiserRoleId())
                ->first();
            if ($wallet && ((float) $wallet->bonus_balance > 0 || (float) $wallet->bonus_reserved > 0)) {
                // Remaining promo only — never invent a larger “welcome” than what is on the wallet.
                $bonusAmt = round((float) $wallet->bonus_balance + (float) $wallet->bonus_reserved, 2);
                $rows->push([
                    'id' => 'welcome-bonus',
                    'source' => 'bonus',
                    'date' => $wallet->created_at?->toIso8601String(),
                    'timestamp' => $wallet->created_at?->timestamp ?? 0,
                    'type' => WalletTransaction::TYPE_BONUS_CREDIT,
                    'type_label' => 'Bonus Credit',
                    'description' => 'Welcome promotional bonus',
                    'reference' => 'BONUS-WELCOME',
                    'amount' => $bonusAmt,
                    'direction' => 'credit',
                    'signed_amount' => $bonusAmt,
                    'status' => 'completed',
                    'balance_after' => null,
                    'bonus_amount' => $bonusAmt,
                    'payment_method' => null,
                    'invoice_id' => null,
                    'invoice_number' => null,
                    'order_reference' => null,
                    'icon' => $this->iconForType(WalletTransaction::TYPE_BONUS_CREDIT),
                ]);
            }
        }

        $filtered = $rows
            ->when($type, fn ($c) => $c->where('type', $type))
            ->when($status, fn ($c) => $c->where('status', $status))
            ->when($from, fn ($c) => $c->filter(fn ($r) => ($r['timestamp'] ?? 0) >= $from->timestamp))
            ->when($to, fn ($c) => $c->filter(fn ($r) => ($r['timestamp'] ?? 0) <= $to->timestamp))
            ->when($search !== '', function ($c) use ($search) {
                $q = mb_strtolower($search);
                return $c->filter(function ($r) use ($q) {
                    return str_contains(mb_strtolower((string) ($r['description'] ?? '')), $q)
                        || str_contains(mb_strtolower((string) ($r['reference'] ?? '')), $q)
                        || str_contains(mb_strtolower((string) ($r['type_label'] ?? '')), $q)
                        || str_contains(mb_strtolower((string) ($r['order_reference'] ?? '')), $q);
                });
            })
            ->sortByDesc('timestamp')
            ->values();

        return $filtered;
    }

    protected function iconForType(string $type): string
    {
        return match ($type) {
            WalletTransaction::TYPE_DEPOSIT => 'fa-arrow-down',
            WalletTransaction::TYPE_BONUS_CREDIT => 'fa-gift',
            WalletTransaction::TYPE_PURCHASE => 'fa-shopping-cart',
            WalletTransaction::TYPE_REFUND => 'fa-undo',
            WalletTransaction::TYPE_WITHDRAWAL => 'fa-arrow-up',
            WalletTransaction::TYPE_ADJUSTMENT => 'fa-sliders-h',
            WalletTransaction::TYPE_TRANSFER_OUT => 'fa-exchange-alt',
            WalletTransaction::TYPE_TRANSFER_IN => 'fa-exchange-alt',
            default => 'fa-circle',
        };
    }

    protected function rangeBounds(string $range, ?string $fromDate = null, ?string $toDate = null): array
    {
        if ($range === 'custom' && $fromDate && $toDate) {
            $from = Carbon::parse($fromDate)->startOfDay();
            $to = Carbon::parse($toDate)->endOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
            $days = $from->diffInDays($to);
            $bucket = $days > 90 ? 'month' : 'day';

            return [$from, $to, $bucket];
        }

        $to = now()->endOfDay();

        return match ($range) {
            'week', '7d' => [now()->subDays(6)->startOfDay(), $to, 'day'],
            '90d', 'quarter' => [now()->subDays(89)->startOfDay(), $to, 'day'],
            'year' => [now()->startOfYear()->startOfDay(), $to, 'month'],
            'lifetime' => [now()->subYears(5)->startOfMonth(), $to, 'month'],
            default => [now()->subDays(29)->startOfDay(), $to, 'day'], // 30d / month
        };
    }
}
