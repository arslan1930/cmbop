<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role_id',
        'balance',
        'reserved_balance',
        'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'reserved_balance' => 'decimal:2',
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

    public static function advertiserRoleId(): ?int
    {
        return Role::where('name', 'advertiser')->value('id');
    }

    public static function publisherRoleId(): ?int
    {
        return Role::where('name', 'publisher')->value('id');
    }

    /**
     * Lock an existing wallet row for update (must be called inside a DB transaction).
     */
    public static function lockForUserRole(int $userId, int $roleId): ?self
    {
        return static::where('user_id', $userId)
            ->where('role_id', $roleId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Lock or create a wallet row for a user+role (must be called inside a DB transaction).
     * Handles concurrent creates via the unique (user_id, role_id) constraint.
     */
    public static function lockOrCreateForRole(int $userId, int $roleId, string $currency = 'EUR'): self
    {
        $wallet = static::lockForUserRole($userId, $roleId);
        if ($wallet) {
            return $wallet;
        }

        try {
            return static::create([
                'user_id' => $userId,
                'role_id' => $roleId,
                'balance' => 0,
                'reserved_balance' => 0,
                'currency' => $currency,
            ]);
        } catch (QueryException $e) {
            return static::where('user_id', $userId)
                ->where('role_id', $roleId)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /**
     * Add amount to available balance.
     */
    public function credit(float $amount): self
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Credit amount must be non-negative');
        }

        $this->balance = round((float) $this->balance + $amount, 2);
        $this->save();

        return $this;
    }

    /**
     * Deduct amount from available balance.
     */
    public function debit(float $amount): self
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Debit amount must be non-negative');
        }

        if (round((float) $this->balance, 2) < round($amount, 2)) {
            throw new \RuntimeException('Insufficient balance');
        }

        $this->balance = round((float) $this->balance - $amount, 2);
        $this->save();

        return $this;
    }

    /**
     * Add amount to balance (legacy alias).
     */
    public function addBalance(float $amount)
    {
        return $this->credit($amount);
    }

    /**
     * Deduct amount from balance (legacy alias).
     */
    public function deductBalance(float $amount)
    {
        return $this->debit($amount);
    }

    /**
     * Move funds from available balance to reserved balance.
     */
    public function reserveAmount(float $amount)
    {
        if (round((float) $this->balance, 2) < round($amount, 2)) {
            throw new \RuntimeException('Insufficient balance to reserve');
        }

        $this->balance = round((float) $this->balance - $amount, 2);
        $this->reserved_balance = round((float) $this->reserved_balance + $amount, 2);

        return $this->save();
    }

    /**
     * Release reserved amount back to available balance.
     */
    public function releaseReserved(float $amount)
    {
        if (round((float) $this->reserved_balance, 2) < round($amount, 2)) {
            throw new \RuntimeException('Reserved balance too low');
        }

        $this->reserved_balance = round((float) $this->reserved_balance - $amount, 2);
        $this->balance = round((float) $this->balance + $amount, 2);

        return $this->save();
    }

    /**
     * Consume reserved funds after a successful payout (does not return to balance).
     */
    public function consumeReserved(float $amount): self
    {
        if (round((float) $this->reserved_balance, 2) < round($amount, 2)) {
            throw new \RuntimeException('Reserved balance too low');
        }

        $this->reserved_balance = round((float) $this->reserved_balance - $amount, 2);
        $this->save();

        return $this;
    }
}
