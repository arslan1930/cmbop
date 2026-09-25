<?php

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet and recent-payout context shared by the payout queue and the
 * signed email mark-paid page.
 */
class WithdrawalPayoutContext
{
    /**
     * @return array{
     *     currentBalance: float,
     *     priorPaid: Collection<int, Withdrawal>,
     *     possibleDuplicate: bool,
     *     duplicateMatches: Collection<int, Withdrawal>
     * }
     */
    public function payoutContext(Withdrawal $withdrawal, bool $canMarkPaid): array
    {
        $wallet = $this->payoutWallet((int) $withdrawal->user_id);
        $currentBalance = round((float) ($wallet?->balance ?? 0), 2);

        $priorPaid = Withdrawal::query()
            ->where('user_id', $withdrawal->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($withdrawal->id);
        if (Withdrawal::hasProcessedAtColumn()) {
            $priorPaid->orderByDesc('processed_at');
        }
        $priorPaid = $priorPaid
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $duplicateMatches = $canMarkPaid
            ? app(WithdrawalDuplicatePayoutWarning::class)->matches($withdrawal)
            : collect();

        return [
            'currentBalance' => $currentBalance,
            'priorPaid' => $priorPaid,
            'possibleDuplicate' => $duplicateMatches->isNotEmpty(),
            'duplicateMatches' => $duplicateMatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function modalPayload(Withdrawal $withdrawal, bool $canMarkPaid): array
    {
        $context = $this->payoutContext($withdrawal, $canMarkPaid);

        return [
            'current_balance' => $context['currentBalance'],
            'prior_paid' => $context['priorPaid']->map(function (Withdrawal $row) {
                return [
                    'id' => (int) $row->id,
                    'net_amount' => (float) $row->net_amount,
                    'processed_at' => optional($row->processed_at)?->toIso8601String(),
                    'created_at' => optional($row->created_at)?->toIso8601String(),
                ];
            })->values(),
            'possible_duplicate' => $context['possibleDuplicate'],
            'duplicate_matches' => $context['duplicateMatches']->map(fn (Withdrawal $row) => (int) $row->id)->values(),
        ];
    }

    public function payoutWallet(int $userId): ?Wallet
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            if (! Schema::hasTable('wallets')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $publisherRoleId = Wallet::publisherRoleId();
        if ($publisherRoleId) {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->where('role_id', $publisherRoleId)
                ->first();
            if ($wallet) {
                return $wallet;
            }
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if ($advertiserRoleId) {
            return Wallet::query()
                ->where('user_id', $userId)
                ->where('role_id', $advertiserRoleId)
                ->first();
        }

        return null;
    }
}
