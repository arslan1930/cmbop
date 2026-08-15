<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
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

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }

    public function walletRoleLabel(): string
    {
        $name = strtolower((string) ($this->wallet?->role?->name ?? ''));

        return match ($name) {
            'advertiser' => 'Advertiser',
            'publisher' => 'Publisher',
            '' => '—',
            default => ucfirst($name),
        };
    }

    public function statusLabel(): string
    {
        $status = trim((string) ($this->status ?? ''));

        return $status === '' ? '—' : ucfirst(str_replace('_', ' ', $status));
    }

    public function paymentMethodKey(): string
    {
        $key = strtolower(trim((string) ($this->payment_method ?? '')));
        if ($key === '' && $this->relatedClassExists()) {
            $related = $this->related;
            if (is_object($related) && isset($related->payment_method)) {
                $key = strtolower(trim((string) $related->payment_method));
            }
        }

        return $key;
    }

    public function paymentMethodLabel(): string
    {
        $key = $this->paymentMethodKey();

        return match ($key) {
            'bank', 'bank_transfer' => 'Bank Transfer',
            'card', 'stripe' => 'Card',
            'paypal' => 'PayPal',
            'wise' => 'Wise',
            'crypto' => 'Cryptocurrency',
            'wallet' => 'Wallet',
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    /**
     * Admin page for the related deposit / withdrawal / order, if we can route it.
     */
    public function adminRelatedUrl(): ?string
    {
        $id = (int) ($this->related_id ?? 0);
        if ($id < 1) {
            return null;
        }

        $type = (string) ($this->related_type ?? '');
        if ($type === '') {
            return null;
        }

        try {
            if (! $this->relatedClassExists()) {
                return null;
            }

            if ($this->relatedTypeIs(DepositRequest::class)) {
                $search = $this->related instanceof DepositRequest
                    ? trim((string) ($this->related->reference_code ?: ''))
                    : '';
                if ($search === '') {
                    $search = trim((string) ($this->reference ?: ''));
                }

                return $search !== ''
                    ? route('admin.deposits', ['search' => $search])
                    : route('admin.deposits');
            }

            if ($this->relatedTypeIs(Withdrawal::class)) {
                // Ledger withdrawal rows stay "pending" after payout; the
                // Withdrawal status is what the queue actually filters on.
                $status = $this->related instanceof Withdrawal
                    ? (string) $this->related->status
                    : '';
                $queue = in_array($status, ['completed', 'cancelled'], true)
                    ? 'history'
                    : 'open';

                return route('admin.withdrawals', [
                    'search' => (string) $id,
                    'queue' => $queue,
                ]);
            }

            if ($this->relatedTypeIs(Order::class)) {
                return route('admin.orders.show', $id);
            }

            if ($this->relatedTypeIs(OrderItem::class)) {
                $orderId = (int) ($this->related?->order_id ?? data_get($this->meta, 'order_id', 0));

                return $orderId > 0 ? route('admin.orders.show', $orderId) : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  iterable<int, mixed>  $rows
     */
    public static function eagerLoadKnownRelated(iterable $rows): void
    {
        $known = collect($rows)->filter(
            fn ($tx) => $tx instanceof self && $tx->relatedClassExists()
        );
        if ($known->isEmpty()) {
            return;
        }

        (new \Illuminate\Database\Eloquent\Collection($known->values()->all()))->load('related');
    }

    public function relatedClassExists(): bool
    {
        return $this->relatedClass() !== null;
    }

    private function relatedClass(): ?string
    {
        $type = (string) ($this->related_type ?? '');
        if (trim($type) === '') {
            return null;
        }

        // MorphTo instantiates this exact string. Do not trim/ltrim before
        // class_exists — "App\Models\Withdrawal " would pass a normalized
        // check and then 500 on load('related').
        $class = Model::getActualClassNameForMorph($type);
        if (! is_string($class) || $class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return ltrim($class, '\\');
    }

    private function relatedTypeIs(string $class): bool
    {
        $actual = $this->relatedClass();
        $class = ltrim($class, '\\');

        return $actual !== null && ($actual === $class || str_ends_with($actual, '\\'.class_basename($class)));
    }

    public static function typeLabelFor(?string $type): string
    {
        return match ($type) {
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
            default => ucfirst(str_replace('_', ' ', (string) $type)),
        };
    }

    public function typeLabel(): string
    {
        return self::typeLabelFor($this->type);
    }
}
