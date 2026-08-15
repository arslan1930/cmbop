<?php

namespace App\Services\Wallet;

use App\Models\DepositRequest;
use App\Models\Wallet;
use Illuminate\Support\Collection;

/**
 * Wallet / duplicate snapshot shared by the email confirm page and the
 * admin Deposits modal so both approve paths show the same numbers.
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
    public function for(DepositRequest $deposit, bool $canApprove): array
    {
        $wallet = $this->advertiserWallet((int) $deposit->user_id);
        $currentBalance = round((float) ($wallet?->balance ?? 0), 2);
        $bonusBalance = round((float) ($wallet?->bonus_balance ?? 0), 2);
        $incomingAmount = round((float) $deposit->amount, 2);

        $priorDeposits = DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id)
            ->orderByDesc('approved_at')
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
     * JSON payload for the admin Deposits modal.
     *
     * @return array{
     *     can_approve: bool,
     *     is_card: bool,
     *     current_balance: float,
     *     incoming_amount: float,
     *     projected_balance: float|null,
     *     bonus_balance: float,
     *     possible_duplicate: bool,
     *     duplicate_matches: list<array{id: int, reference_code: string, amount: float, payment_method: string, date_label: string|null}>,
     *     prior_deposits: list<array{id: int, reference_code: string, amount: float, payment_method: string, date_label: string|null}>
     * }
     */
    public function forJson(DepositRequest $deposit): array
    {
        $canApprove = $deposit->canManuallyApprove();
        $context = $this->for($deposit, $canApprove);

        return [
            'can_approve' => $canApprove,
            'is_card' => $deposit->isCardPayment(),
            'current_balance' => $context['currentBalance'],
            'incoming_amount' => $context['incomingAmount'],
            'projected_balance' => $context['projectedBalance'],
            'bonus_balance' => $context['bonusBalance'],
            'possible_duplicate' => $context['possibleDuplicate'],
            'duplicate_matches' => $context['duplicateMatches']
                ->map(fn (DepositRequest $row) => $this->summarizeDeposit($row))
                ->values()
                ->all(),
            'prior_deposits' => $context['priorDeposits']
                ->map(fn (DepositRequest $row) => $this->summarizeDeposit($row))
                ->values()
                ->all(),
        ];
    }

    public function advertiserBalance(int $userId): ?float
    {
        $wallet = $this->advertiserWallet($userId);

        return $wallet ? round((float) $wallet->balance, 2) : null;
    }

    /**
     * @return array{id: int, reference_code: string, amount: float, payment_method: string, date_label: string|null}
     */
    private function summarizeDeposit(DepositRequest $deposit): array
    {
        $when = $deposit->approved_at ?? $deposit->created_at;

        return [
            'id' => (int) $deposit->id,
            'reference_code' => (string) $deposit->reference_code,
            'amount' => round((float) $deposit->amount, 2),
            'payment_method' => (string) $deposit->payment_method,
            'date_label' => $when?->format('M d, Y'),
        ];
    }

    /**
     * @return Collection<int, DepositRequest>
     */
    private function duplicateAmountMatches(DepositRequest $deposit, float $incomingAmount): Collection
    {
        $lookbackDays = max(1, (int) config('billing.deposit_approve_duplicate_lookback_days', 30));
        $since = now()->subDays($lookbackDays);

        return DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id)
            ->where('amount', $incomingAmount)
            ->where(function ($q) use ($since) {
                $q->where('approved_at', '>=', $since)
                    ->orWhere(function ($inner) use ($since) {
                        $inner->whereNull('approved_at')
                            ->where('created_at', '>=', $since);
                    });
            })
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    private function advertiserWallet(int $userId): ?Wallet
    {
        $roleId = Wallet::advertiserRoleId();
        if (! $roleId || $userId <= 0) {
            return null;
        }

        return Wallet::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();
    }
}
