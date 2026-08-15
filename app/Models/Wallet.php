<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        'debt_balance',
        'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'reserved_balance' => 'decimal:2',
        'bonus_balance' => 'decimal:2',
        'bonus_reserved' => 'decimal:2',
        'debt_balance' => 'decimal:2',
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
     * Welcome credit is applied only when bonus_* columns exist so it cannot
     * become withdrawable cash on an unmigrated Hostinger wallet table.
     * Returns the amount actually credited (0 when the claim is missing).
     */
    public static function insertRegistrationPair(
        int $userId,
        int $advertiserRoleId,
        int $publisherRoleId,
        float $advertiserWelcomeBonus = 0.0,
        string $currency = 'EUR'
    ): float {
        $now = now();
        $hasBonusColumns = Schema::hasColumn('wallets', 'bonus_balance');
        // Never put welcome credit in plain balance — that makes it withdrawable.
        $bonus = $hasBonusColumns ? round(max(0, $advertiserWelcomeBonus), 2) : 0.0;
        $bonus = static::welcomeBonusBackedByClaim($userId, $bonus);

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

        return $bonus;
    }

    /**
     * Welcome credit is only valid when this user has a claim row, and never
     * more than the recorded claim or the configured amount.
     */
    private static function welcomeBonusBackedByClaim(int $userId, float $bonus): float
    {
        if ($bonus <= 0) {
            return 0.0;
        }

        $max = WelcomeBonusSetting::configuredAmount();
        $bonus = min($bonus, $max);
        if ($bonus <= 0 || ! Schema::hasTable('welcome_bonus_claims')) {
            return 0.0;
        }

        try {
            $claimed = DB::table('welcome_bonus_claims')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->value('amount');
        } catch (\Throwable) {
            return 0.0;
        }

        $claimed = is_numeric($claimed) ? round((float) $claimed, 2) : 0.0;

        return $claimed > 0 ? min($bonus, $claimed) : 0.0;
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

        $payload = [
            'user_id' => $userId,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'currency' => $currency,
        ];
        // Hostinger may have bonus/debt columns as NOT NULL without relying on DB defaults.
        if (Schema::hasColumn('wallets', 'bonus_balance')) {
            $payload['bonus_balance'] = 0;
        }
        if (Schema::hasColumn('wallets', 'bonus_reserved')) {
            $payload['bonus_reserved'] = 0;
        }
        if (Schema::hasColumn('wallets', 'debt_balance')) {
            $payload['debt_balance'] = 0;
        }

        try {
            return static::create($payload);
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
     * Cash that may be withdrawn. Bonus is excluded; reserved funds already left balance.
     */
    public function withdrawableBalance(): float
    {
        return max(0, round((float) $this->balance - $this->lockedBonusBalance(), 2));
    }

    /**
     * Role-wallet figures for Balance / Add Funds overviews.
     *
     * @return array{spendable: float, withdrawable: float, bonus: float, reserved: float, debt: float}
     */
    public function roleSnapshot(): array
    {
        return [
            'spendable' => round((float) $this->balance, 2),
            'withdrawable' => $this->withdrawableBalance(),
            'bonus' => $this->lockedBonusBalance(),
            'reserved' => round((float) $this->reserved_balance, 2),
            'debt' => $this->debtBalance(),
        ];
    }

    /**
     * @return array{spendable: float, withdrawable: float, bonus: float, reserved: float, debt: float}
     */
    public static function emptyRoleSnapshot(): array
    {
        return [
            'spendable' => 0.0,
            'withdrawable' => 0.0,
            'bonus' => 0.0,
            'reserved' => 0.0,
            'debt' => 0.0,
        ];
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
     * Move funds to reserved balance for a wallet checkout.
     * When $useBonus is true, promotional credit is consumed first.
     * When false, only withdrawable cash may be reserved (bonus stays untouched).
     *
     * @return float Bonus amount reserved
     */
    public function reserveForOrder(float $amount, bool $useBonus = true): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return 0.0;
        }

        if ($amount > round((float) $this->balance, 2)) {
            throw new \Exception('Insufficient balance to reserve');
        }

        $fromBonus = $useBonus ? min($amount, (float) $this->bonus_balance) : 0.0;
        $fromCash = round($amount - $fromBonus, 2);

        if ($fromCash > $this->withdrawableBalance() + 0.00001) {
            throw new \Exception(
                $useBonus
                    ? 'Insufficient wallet balance to reserve'
                    : 'Insufficient available (cash) balance. Enable “Use bonus balance” to apply your promotional credit.'
            );
        }

        $this->balance = round((float) $this->balance - $amount, 2);
        $this->reserved_balance = round((float) $this->reserved_balance + $amount, 2);
        $this->bonus_balance = round((float) $this->bonus_balance - $fromBonus, 2);
        $this->bonus_reserved = round((float) $this->bonus_reserved + $fromBonus, 2);
        $this->save();

        return round($fromBonus, 2);
    }

    /**
     * Reserve promotional credit only (partial payment toward an order paid by card/manual).
     *
     * @return float Amount reserved from bonus
     */
    public function reserveBonusOnly(float $amount): float
    {
        $amount = round(min(max(0, $amount), $this->lockedBonusBalance()), 2);
        if ($amount <= 0) {
            return 0.0;
        }

        $this->balance = round((float) $this->balance - $amount, 2);
        $this->reserved_balance = round((float) $this->reserved_balance + $amount, 2);
        $this->bonus_balance = round((float) $this->bonus_balance - $amount, 2);
        $this->bonus_reserved = round((float) $this->bonus_reserved + $amount, 2);
        $this->save();

        return $amount;
    }

    /**
     * Repair wallets where welcome credit landed in balance but bonus_balance was left at 0
     * (e.g. bonus columns added after registration without a backfill).
     *
     * Uses remaining promotional credit (received − spent − reserved), not lifetime
     * bonus_credit. Lifetime credits re-tagged deposited cash after the welcome
     * bonus had already been spent.
     */
    public function repairOrphanedWelcomeBonus(): bool
    {
        if (! Schema::hasColumn('wallets', 'bonus_balance')) {
            return false;
        }

        $balance = round((float) $this->balance, 2);
        $bonus = round((float) $this->bonus_balance, 2);
        if ($bonus > 0 || $balance <= 0) {
            return false;
        }

        $remaining = $this->remainingPromotionalCredit();
        if ($remaining === null || $remaining <= 0) {
            return false;
        }

        $this->bonus_balance = round(min($balance, $remaining), 2);
        $this->save();

        return true;
    }

    /**
     * Clamp bonus_balance when it exceeds ledger promotional credits
     * (e.g. deposits wrongly counted as bonus so Spendable looks like “€45 bonus”).
     */
    public function reconcileInflatedBonusBalance(): bool
    {
        if (! Schema::hasColumn('wallets', 'bonus_balance')) {
            return false;
        }

        $remaining = $this->remainingPromotionalCredit();
        if ($remaining === null) {
            return false;
        }

        $balance = round((float) $this->balance, 2);
        $current = round((float) $this->bonus_balance, 2);
        $target = round(min($balance, $remaining), 2);

        if ($current <= $target + 0.001) {
            return false;
        }

        $this->bonus_balance = $target;
        $this->save();

        return true;
    }

    /**
     * Promotional credit still available to tag as bonus_balance.
     * received bonus_credit − spent debit bonus_amount − currently reserved promo.
     *
     * @return float|null null when the ledger has no promotional credits (or no table)
     */
    public function remainingPromotionalCredit(): ?float
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return null;
        }

        $received = (float) DB::table('wallet_transactions')
            ->where('wallet_id', $this->id)
            ->where('type', 'bonus_credit')
            ->sum('bonus_amount');
        if ($received <= 0) {
            $received = (float) DB::table('wallet_transactions')
                ->where('wallet_id', $this->id)
                ->where('type', 'bonus_credit')
                ->sum('amount');
        }
        if ($received <= 0) {
            return null;
        }

        $spent = (float) DB::table('wallet_transactions')
            ->where('wallet_id', $this->id)
            ->where('direction', 'debit')
            ->sum('bonus_amount');

        $reserved = round((float) $this->bonus_reserved, 2);

        return max(0, round($received - $spent - $reserved, 2));
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
     * Never drive reserved_balance / bonus_reserved negative — clamp and log.
     *
     * $bonusLimit caps how much of the consume comes from bonus_reserved so a
     * shared checkout promo is not burned on the first sibling approve.
     */
    public function consumeReserved(float $amount, ?float $bonusLimit = null): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $available = max(0, round((float) $this->reserved_balance, 2));
        if ($available <= 0) {
            Log::warning('Wallet consumeReserved skipped: reserved_balance is empty', [
                'wallet_id' => $this->id,
                'user_id' => $this->user_id,
                'requested' => $amount,
            ]);

            return;
        }

        $consume = min($amount, $available);
        if ($consume < $amount) {
            Log::warning('Wallet consumeReserved clamped to reserved_balance', [
                'wallet_id' => $this->id,
                'user_id' => $this->user_id,
                'requested' => $amount,
                'consumed' => $consume,
                'reserved_balance' => $available,
            ]);
        }

        $this->reserved_balance = round($available - $consume, 2);

        if (Schema::hasColumn('wallets', 'bonus_reserved')) {
            $bonusReserved = max(0, round((float) ($this->bonus_reserved ?? 0), 2));
            $fromBonus = min($consume, $bonusReserved);
            if ($bonusLimit !== null) {
                $fromBonus = min($fromBonus, max(0, round($bonusLimit, 2)));
            }
            $this->bonus_reserved = round($bonusReserved - $fromBonus, 2);
        }

        $this->save();
    }

    /**
     * Order rejected / cancelled: return reserved funds; restore any promo portion as spend-only.
     * Only moves money that is actually reserved — never invents withdrawable cash.
     *
     * $bonusLimit caps how much of the refund comes from bonus_reserved so a
     * shared checkout promo is not restored on the first sibling reject.
     */
    public function refundReserved(float $amount, ?float $bonusLimit = null): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $available = max(0, round((float) $this->reserved_balance, 2));
        if ($available <= 0) {
            Log::warning('Wallet refundReserved skipped: reserved_balance is empty', [
                'wallet_id' => $this->id,
                'user_id' => $this->user_id,
                'requested' => $amount,
            ]);

            return;
        }

        $refund = min($amount, $available);
        if ($refund < $amount) {
            Log::warning('Wallet refundReserved clamped to reserved_balance', [
                'wallet_id' => $this->id,
                'user_id' => $this->user_id,
                'requested' => $amount,
                'refunded' => $refund,
                'reserved_balance' => $available,
            ]);
        }

        $bonusReserved = max(0, round((float) ($this->bonus_reserved ?? 0), 2));
        $fromBonus = min($refund, $bonusReserved);
        if ($bonusLimit !== null) {
            $fromBonus = min($fromBonus, max(0, round($bonusLimit, 2)));
        }

        $this->reserved_balance = round($available - $refund, 2);
        $this->balance = round((float) $this->balance + $refund, 2);
        $this->bonus_reserved = round($bonusReserved - $fromBonus, 2);
        $this->bonus_balance = round((float) $this->bonus_balance + $fromBonus, 2);
        // Never leave bonus_reserved larger than the hold — but do not burn
        // the excess. A 0 bonusLimit used to zero the leftover without
        // restoring spend-only promo.
        if ($this->bonus_reserved > $this->reserved_balance) {
            $excess = round($this->bonus_reserved - (float) $this->reserved_balance, 2);
            $this->bonus_reserved = (float) $this->reserved_balance;
            if ($excess > 0) {
                $this->bonus_balance = round((float) $this->bonus_balance + $excess, 2);
            }
        }
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
        return round($amount, 2) > 0
            && round($amount, 2) <= $this->withdrawableBalance()
            && ! $this->hasDebt();
    }

    /**
     * Outstanding clawback / platform debt (blocks withdrawals while &gt; 0).
     */
    public function debtBalance(): float
    {
        return max(0, round((float) ($this->debt_balance ?? 0), 2));
    }

    public function hasDebt(): bool
    {
        return $this->debtBalance() > 0;
    }

    /**
     * Increase outstanding debt (partial clawback shortfall).
     */
    public function increaseDebt(float $amount): self
    {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            return $this;
        }

        $this->debt_balance = round($this->debtBalance() + $amount, 2);
        $this->save();

        return $this;
    }

    /**
     * Zero outstanding debt (admin clear).
     */
    public function clearDebt(): float
    {
        $cleared = $this->debtBalance();
        if ($cleared <= 0) {
            return 0.0;
        }

        $this->debt_balance = 0;
        $this->save();

        return $cleared;
    }
}
