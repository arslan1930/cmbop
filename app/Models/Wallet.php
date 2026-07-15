<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role_id',
        'balance',
        'reserved_balance',
        'bonus_balance',
        'bonus_reserved',
        'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'reserved_balance' => 'decimal:2',
        'bonus_balance' => 'decimal:2',
        'bonus_reserved' => 'decimal:2',
    ];

    /**
     * Owner of the wallet
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Role associated with this wallet
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Portion of available balance that is promotional (spend-only).
     */
    public function lockedBonusBalance(): float
    {
        return min((float) $this->bonus_balance, (float) $this->balance);
    }

    /**
     * Funds that may be withdrawn or transferred to another role wallet.
     */
    public function withdrawableBalance(): float
    {
        return max(0, round((float) $this->balance - $this->lockedBonusBalance(), 2));
    }

    /**
     * Credit a spend-only welcome / promo amount (also increases balance).
     */
    public function creditBonus(float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $this->balance = round((float) $this->balance + $amount, 2);
        $this->bonus_balance = round((float) $this->bonus_balance + $amount, 2);
        $this->save();
    }

    /**
     * Add withdrawable funds (deposits, earnings). Does not touch bonus.
     */
    public function addBalance(float $amount)
    {
        $this->balance = round((float) $this->balance + $amount, 2);
        return $this->save();
    }

    /**
     * Deduct from available balance (legacy helper). Prefer reserveForOrder / withdrawable checks.
     */
    public function deductBalance(float $amount)
    {
        if ($amount > (float) $this->balance) {
            throw new \Exception('Insufficient balance');
        }
        $this->balance = round((float) $this->balance - $amount, 2);
        if ((float) $this->bonus_balance > (float) $this->balance) {
            $this->bonus_balance = (float) $this->balance;
        }
        return $this->save();
    }

    /**
     * Move funds to reserved balance for a wallet checkout (consumes bonus first).
     */
    public function reserveForOrder(float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount > (float) $this->balance) {
            throw new \Exception('Insufficient balance to reserve');
        }

        $fromBonus = min($amount, (float) $this->bonus_balance);

        $this->balance = round((float) $this->balance - $amount, 2);
        $this->reserved_balance = round((float) $this->reserved_balance + $amount, 2);
        $this->bonus_balance = round((float) $this->bonus_balance - $fromBonus, 2);
        $this->bonus_reserved = round((float) $this->bonus_reserved + $fromBonus, 2);
        $this->save();
    }

    /**
     * Legacy alias used by older call sites.
     */
    public function reserveAmount(float $amount)
    {
        $this->reserveForOrder($amount);
        return true;
    }

    /**
     * Order completed: drop reserved funds (bonus portion is permanently spent).
     */
    public function consumeReserved(float $amount): void
    {
        $amount = round($amount, 2);
        $fromBonus = min($amount, (float) $this->bonus_reserved);

        $this->reserved_balance = round((float) $this->reserved_balance - $amount, 2);
        $this->bonus_reserved = round((float) $this->bonus_reserved - $fromBonus, 2);
        $this->save();
    }

    /**
     * Order rejected / cancelled: return reserved funds; restore any promo portion as spend-only.
     */
    public function refundReserved(float $amount): void
    {
        $amount = round($amount, 2);
        $fromBonus = min($amount, (float) $this->bonus_reserved);

        $this->reserved_balance = round((float) $this->reserved_balance - $amount, 2);
        $this->balance = round((float) $this->balance + $amount, 2);
        $this->bonus_reserved = round((float) $this->bonus_reserved - $fromBonus, 2);
        $this->bonus_balance = round((float) $this->bonus_balance + $fromBonus, 2);
        $this->save();
    }

    /**
     * Release reserved amount back to balance (legacy helper — restores bonus when present).
     */
    public function releaseReserved(float $amount)
    {
        if ($amount > (float) $this->reserved_balance) {
            throw new \Exception('Reserved balance too low');
        }
        $this->refundReserved($amount);
        return true;
    }

    /**
     * Deduct withdrawable funds only (withdrawals / role transfers out).
     */
    public function deductWithdrawable(float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount > $this->withdrawableBalance()) {
            throw new \Exception('Insufficient withdrawable balance');
        }

        $this->balance = round((float) $this->balance - $amount, 2);
        $this->save();
    }
}
