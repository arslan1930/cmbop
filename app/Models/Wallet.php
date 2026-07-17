<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Wallet extends Model
{
    use HasFactory;

    public const PROMOTIONAL_BONUS_MESSAGE = 'This promotional bonus can only be used for purchases within our marketplace and cannot be withdrawn.';

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

    public static function advertiserRoleId(): ?int
    {
        return Role::where('name', 'advertiser')->value('id');
    }

    public static function publisherRoleId(): ?int
    {
        return Role::where('name', 'publisher')->value('id');
    }

    /**
     * Create advertiser + publisher wallets for a newly registered user.
     * Tolerates production DBs that have not yet migrated bonus_* columns.
     */
    public static function insertRegistrationPair(
        int $userId,
        int $advertiserRoleId,
        int $publisherRoleId,
        float $advertiserWelcomeBonus = 0.0,
        string $currency = 'EUR'
    ): void {
        $now = now();
        $bonus = round(max(0, $advertiserWelcomeBonus), 2);
        $hasBonusColumns = Schema::hasColumn('wallets', 'bonus_balance');

        $advertiser = [
            'user_id' => $userId,
            'role_id' => $advertiserRoleId,
            'balance' => $bonus,
            'reserved_balance' => 0.00,
            'currency' => $currency,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $publisher = [
            'user_id' => $userId,
            'role_id' => $publisherRoleId,
            'balance' => 0.00,
            'reserved_balance' => 0.00,
            'currency' => $currency,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($hasBonusColumns) {
            $advertiser['bonus_balance'] = $bonus;
            $advertiser['bonus_reserved'] = 0.00;
            $publisher['bonus_balance'] = 0.00;
            $publisher['bonus_reserved'] = 0.00;
        }

        DB::table('wallets')->insert([$advertiser, $publisher]);
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
     * Bonus / promotional credit can never be deducted here.
     */
    public function deductWithdrawable(float $amount): void
    {
        $amount = round($amount, 2);
        $withdrawable = $this->withdrawableBalance();

        if ($amount > $withdrawable) {
            if ($this->lockedBonusBalance() > 0 && $withdrawable <= 0) {
                throw new \RuntimeException(self::PROMOTIONAL_BONUS_MESSAGE);
            }

            throw new \RuntimeException('Insufficient withdrawable balance');
        }

        $this->balance = round((float) $this->balance - $amount, 2);
        $this->save();
    }

    /**
     * Whether an amount can be withdrawn/transferred (excludes bonus).
     */
    public function canWithdraw(float $amount): bool
    {
        return round($amount, 2) > 0 && round($amount, 2) <= $this->withdrawableBalance();
    }
}
