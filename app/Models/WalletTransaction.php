<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    use ToleratesUnparseableDates;

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_BONUS_CREDIT = 'bonus_credit';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_REFUND = 'refund';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_ROLE_MOVE_OUT = 'role_move_out';

    public const TYPE_ROLE_MOVE_IN = 'role_move_in';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'type',
        'direction',
        'amount',
        'bonus_amount',
        'balance_after',
        'bonus_balance_after',
        'currency',
        'status',
        'description',
        'reference',
        'payment_method',
        'related_type',
        'related_id',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'bonus_balance_after' => 'decimal:2',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Ledger rows for the publisher wallet. Publisher-only leftover rows
     * with a null wallet_id stay visible; dual-role accounts exclude null
     * so advertiser deposits cannot land on Balance.
     */
    public static function queryForPublisherUser(User $user): Builder
    {
        $query = static::query()->where('user_id', $user->id);
        $wallet = Wallet::forPublisher((int) $user->id);
        if (! $wallet) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function ($inner) use ($user, $wallet) {
            $inner->where('wallet_id', $wallet->id);
            if (! $user->hasRole('advertiser')) {
                $inner->orWhereNull('wallet_id');
            }
        });
    }

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_DEPOSIT => 'Deposit',
            self::TYPE_BONUS_CREDIT => 'Bonus Credit',
            self::TYPE_PURCHASE => 'Purchase',
            self::TYPE_REFUND => 'Refund',
            self::TYPE_WITHDRAWAL => 'Withdrawal',
            self::TYPE_ADJUSTMENT => 'Adjustment',
            self::TYPE_TRANSFER_OUT => 'Transfer Out',
            self::TYPE_TRANSFER_IN => 'Transfer In',
            self::TYPE_ROLE_MOVE_OUT => 'Moved to Advertiser Wallet',
            self::TYPE_ROLE_MOVE_IN => 'Earnings Moved for Spending',
            default => ucfirst(str_replace('_', ' ', (string) $this->type)),
        };
    }

    public function publisherFacingLabel(): string
    {
        $description = trim((string) $this->description);
        if ($description !== '') {
            return $description;
        }

        return $this->typeLabel();
    }
}
