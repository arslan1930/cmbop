<?php

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WalletLedgerService
{
    public function record(
        Wallet $wallet,
        string $type,
        string $direction,
        float $amount,
        array $options = []
    ): ?WalletTransaction {
        $amount = round(abs($amount), 2);
        if ($amount <= 0 && empty($options['allow_zero'])) {
            return null;
        }

        try {
            $wallet->refresh();

            return WalletTransaction::create([
                'user_id' => $wallet->user_id,
                'wallet_id' => $wallet->id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'bonus_amount' => round((float) ($options['bonus_amount'] ?? 0), 2),
                'balance_after' => round((float) $wallet->balance, 2),
                'bonus_balance_after' => round((float) $wallet->bonus_balance, 2),
                'currency' => $wallet->currency ?? 'EUR',
                'status' => $options['status'] ?? 'completed',
                'description' => $options['description'] ?? null,
                'reference' => $options['reference'] ?? $this->makeReference($type),
                'payment_method' => $options['payment_method'] ?? null,
                'related_type' => isset($options['related']) && $options['related'] instanceof Model
                    ? $options['related']->getMorphClass()
                    : ($options['related_type'] ?? null),
                'related_id' => isset($options['related']) && $options['related'] instanceof Model
                    ? $options['related']->getKey()
                    : ($options['related_id'] ?? null),
                'meta' => $options['meta'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write wallet ledger entry', [
                'wallet_id' => $wallet->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function recordBonusCredit(Wallet $wallet, float $amount, ?string $description = null, array $meta = []): ?WalletTransaction
    {
        return $this->record($wallet, WalletTransaction::TYPE_BONUS_CREDIT, 'credit', $amount, [
            'bonus_amount' => $amount,
            'description' => $description ?? 'Promotional bonus credit',
            'meta' => $meta,
        ]);
    }

    public function recordDeposit(Wallet $wallet, float $amount, $related = null, ?string $paymentMethod = null, ?string $reference = null): ?WalletTransaction
    {
        return $this->record($wallet, WalletTransaction::TYPE_DEPOSIT, 'credit', $amount, [
            'related' => $related,
            'payment_method' => $paymentMethod,
            'reference' => $reference,
            'description' => 'Wallet deposit',
        ]);
    }

    public function recordPurchase(Wallet $wallet, float $amount, float $bonusAmount = 0, $related = null, ?string $reference = null): ?WalletTransaction
    {
        return $this->record($wallet, WalletTransaction::TYPE_PURCHASE, 'debit', $amount, [
            'bonus_amount' => $bonusAmount,
            'related' => $related,
            'reference' => $reference,
            'description' => 'Marketplace purchase',
            'status' => 'completed',
        ]);
    }

    public function recordRefund(Wallet $wallet, float $amount, float $bonusAmount = 0, $related = null, ?string $reference = null): ?WalletTransaction
    {
        return $this->record($wallet, WalletTransaction::TYPE_REFUND, 'credit', $amount, [
            'bonus_amount' => $bonusAmount,
            'related' => $related,
            'reference' => $reference,
            'description' => 'Order refund to wallet',
        ]);
    }

    public function recordWithdrawal(Wallet $wallet, float $amount, $related = null, string $status = 'pending', ?string $reference = null): ?WalletTransaction
    {
        return $this->record($wallet, WalletTransaction::TYPE_WITHDRAWAL, 'debit', $amount, [
            'related' => $related,
            'reference' => $reference,
            'status' => $status,
            'description' => 'Withdrawal request',
        ]);
    }

    protected function makeReference(string $type): string
    {
        return strtoupper(Str::slug($type, '_')).'-'.strtoupper(Str::random(8));
    }
}
