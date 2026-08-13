<?php

namespace App\Services\Wallet;

use App\Models\BalanceTransfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Moves publisher withdrawable cash into the advertiser Money wallet so dual-role
 * publishers can spend earnings on the catalog. Bonus never moves. Fee is 0.
 * Ledger types are role_move_* so finance earnings stay on transfer_in only.
 */
class WalletRoleMoveService
{
    public function __construct(
        private WalletLedgerService $ledger,
    ) {}

    /**
     * @return array{
     *     reference: string,
     *     amount: float,
     *     fee: float,
     *     net_amount: float,
     *     publisher: array{spendable: float, withdrawable: float, bonus: float, reserved: float, debt: float},
     *     advertiser: array{spendable: float, withdrawable: float, bonus: float, reserved: float, debt: float}
     * }
     *
     * @throws WalletRoleMoveException
     */
    public function publisherToAdvertiser(User $user, float $amount): array
    {
        $amount = round($amount, 2);
        $min = round((float) config('billing.role_move.min_amount', 0.01), 2);
        $max = round((float) config('billing.role_move.max_amount', 999999.99), 2);
        $feePercent = (float) config('billing.role_move.fee_percent', 0);

        if ($amount < $min || $amount > $max) {
            throw WalletRoleMoveException::invalidAmount();
        }

        if (! $user->hasRole('advertiser')) {
            throw WalletRoleMoveException::advertiserRoleRequired();
        }

        $publisherRoleId = Wallet::publisherRoleId();
        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $publisherRoleId || ! $advertiserRoleId) {
            throw WalletRoleMoveException::rolesNotConfigured();
        }

        $fee = round(($amount * $feePercent) / 100, 2);
        $netAmount = round($amount - $fee, 2);
        if ($netAmount <= 0) {
            throw WalletRoleMoveException::invalidAmount();
        }

        return DB::transaction(function () use ($user, $amount, $fee, $netAmount, $publisherRoleId, $advertiserRoleId) {
            // Lock publisher first, then advertiser, to keep lock order stable.
            $publisherWallet = Wallet::lockForUserRole($user->id, $publisherRoleId);
            if (! $publisherWallet) {
                throw WalletRoleMoveException::insufficientWithdrawable();
            }

            if ($publisherWallet->hasDebt()) {
                throw WalletRoleMoveException::walletDebt();
            }

            if (! $publisherWallet->canWithdraw($amount)) {
                throw WalletRoleMoveException::insufficientWithdrawable();
            }

            $advertiserWallet = Wallet::lockOrCreateForRole($user->id, $advertiserRoleId);

            $publisherWallet->deductWithdrawable($amount);
            $advertiserWallet->addBalance($netAmount);

            $transfer = BalanceTransfer::create([
                'user_id' => $user->id,
                'from_role' => 'publisher',
                'to_role' => 'advertiser',
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'reference_code' => BalanceTransfer::generateReferenceCode(),
                'status' => 'completed',
                'notes' => 'Publisher earnings moved to advertiser wallet for spending',
            ]);

            $meta = [
                'from_role' => 'publisher',
                'to_role' => 'advertiser',
                'fee' => $fee,
            ];

            $this->ledger->recordRoleMoveOut(
                $publisherWallet,
                $amount,
                $transfer,
                $transfer->reference_code,
                'Moved to advertiser wallet for spending',
                $meta
            );
            $this->ledger->recordRoleMoveIn(
                $advertiserWallet,
                $netAmount,
                $transfer,
                $transfer->reference_code,
                'Publisher earnings moved for spending',
                $meta
            );

            return [
                'reference' => $transfer->reference_code,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'publisher' => $publisherWallet->fresh()->roleSnapshot(),
                'advertiser' => $advertiserWallet->fresh()->roleSnapshot(),
            ];
        });
    }
}
