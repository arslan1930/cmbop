<?php

namespace App\Services\Wallet;

use App\Models\DepositRequest;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet and duplicate context shared by the deposit list modal and the
 * signed email approve-confirm page.
 */
class DepositApproveContext
{
    /**
     * @return array{
     *     currentBalance: float,
     *     incomingAmount: float,
     *     projectedBalance: float|null,
     *     priorDeposits: Collection<int, DepositRequest>,
     *     bonusBalance: float,
     *     possibleDuplicate: bool,
     *     duplicateMatches: Collection<int, DepositRequest>
     * }
     */
    public function walletContext(DepositRequest $deposit, bool $canApprove): array
    {
        $wallet = $this->advertiserWallet((int) $deposit->user_id);
        $currentBalance = round((float) ($wallet?->balance ?? 0), 2);
        $bonusBalance = round((float) ($wallet?->bonus_balance ?? 0), 2);
        $incomingAmount = round((float) $deposit->amount, 2);

        $priorDeposits = DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id);
        if (DepositRequest::hasTableColumn('approved_at')) {
            $priorDeposits->orderByDesc('approved_at');
        }
        $priorDeposits = $priorDeposits
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $duplicateMatches = $canApprove
            ? $this->duplicateAmountMatches($deposit, $incomingAmount)
            : collect();

        return [
            'currentBalance' => $currentBalance,
            'incomingAmount' => $incomingAmount,
            'projectedBalance' => $canApprove ? round($currentBalance + $incomingAmount, 2) : null,
            'priorDeposits' => $priorDeposits,
            'bonusBalance' => $bonusBalance,
            'possibleDuplicate' => $duplicateMatches->isNotEmpty(),
            'duplicateMatches' => $duplicateMatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function modalPayload(DepositRequest $deposit, bool $canApprove): array
    {
        $context = $this->walletContext($deposit, $canApprove);

        $row = function (DepositRequest $row): array {
            return [
                'id' => (int) $row->id,
                'amount' => (float) $row->amount,
                'reference_code' => (string) ($row->reference_code ?? ''),
                'approved_at' => optional($row->approved_at)?->toIso8601String(),
                'created_at' => optional($row->created_at)?->toIso8601String(),
            ];
        };

        return [
            'current_balance' => $context['currentBalance'],
            'incoming_amount' => $context['incomingAmount'],
            'projected_balance' => $context['projectedBalance'],
            'bonus_balance' => $context['bonusBalance'],
            'possible_duplicate' => $context['possibleDuplicate'],
            'duplicate_matches' => $context['duplicateMatches']->map($row)->values(),
            'prior_deposits' => $context['priorDeposits']->map($row)->values(),
        ];
    }

    /**
     * @return Collection<int, DepositRequest>
     */
    public function duplicateAmountMatches(DepositRequest $deposit, float $incomingAmount): Collection
    {
        $lookbackDays = max(1, (int) config('billing.deposit_approve_duplicate_lookback_days', 30));
        $since = now()->subDays($lookbackDays);

        $matches = DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id)
            ->where('amount', $incomingAmount)
            ->where(function ($q) use ($since) {
                if (DepositRequest::hasTableColumn('approved_at')) {
                    $q->where('approved_at', '>=', $since)
                        ->orWhere(function ($inner) use ($since) {
                            $inner->whereNull('approved_at')
                                ->where('created_at', '>=', $since);
                        });
                } else {
                    $q->where('created_at', '>=', $since);
                }
            });
        if (DepositRequest::hasTableColumn('approved_at')) {
            $matches->orderByDesc('approved_at');
        }

        return $matches
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    public function advertiserWallet(int $userId): ?Wallet
    {
        $roleId = Wallet::advertiserRoleId();
        if (! $roleId || $userId <= 0) {
            return null;
        }

        try {
            if (! Schema::hasTable('wallets')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return Wallet::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();
    }

    public function advertiserBalance(int $userId): ?float
    {
        $wallet = $this->advertiserWallet($userId);

        return $wallet ? round((float) $wallet->balance, 2) : null;
    }
}
