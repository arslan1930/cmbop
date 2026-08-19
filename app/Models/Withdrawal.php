<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Withdrawal extends Model
{
    use ToleratesMissingSchema;
    use ToleratesUnparseableDates;

    public const CANCELLED_BY_USER = 'user';

    public const CANCELLED_BY_ADMIN = 'admin';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'amount',
        'fee',
        'net_amount',
        'payment_method',
        'payment_details',
        'status',
        'cancelled_by',
        'cancelled_at',
        'admin_notes',
        'processed_at',
    ];

    protected $casts = [
        'payment_details' => 'array',
        'processed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    protected $appends = [
        'destination_snippet',
        'destination_copy_text',
        'waiting_days',
        'publisher_status_label',
    ];

    /**
     * Parseable processed_at in the Gregorian window. Leftover Hostinger
     * strings compare as recent on SQLite (`>= $since`) and as zero-dates
     * on MySQL, so they must not count as a paid clock.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function hasProcessedAtColumn(): bool
    {
        return static::hasTableColumn('processed_at');
    }

    public function scopeWhereProcessedAtIsRecorded($query)
    {
        return $query->whereNotNull('processed_at')
            ->where('processed_at', '>=', static::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('processed_at', '<=', static::PLAUSIBLE_SQL_DATETIME_CEIL);
    }

    /**
     * Missing or leftover processed_at (same as PHP null after cast).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereProcessedAtIsMissing($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('processed_at')
                ->orWhere('processed_at', '>', static::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->orWhere('processed_at', '<', static::PLAUSIBLE_SQL_DATETIME_FLOOR);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Attributes to persist the wallet that was debited. Empty when the column
     * is not migrated yet (Hostinger schema drift).
     *
     * @return array{wallet_id?: int}
     */
    public static function walletIdAttributes(?Wallet $wallet): array
    {
        if (! $wallet || ! static::hasTableColumn('wallet_id')) {
            return [];
        }

        return ['wallet_id' => (int) $wallet->id];
    }

    /**
     * Publisher-role withdrawals for a user. Dual-role advertiser payouts are
     * excluded. Publisher-only leftover rows with a null wallet_id stay visible.
     *
     * @return Builder<static>
     */
    public static function queryForPublisherUser(User $user): Builder
    {
        $query = static::query()->where('user_id', $user->id);

        if (! static::hasTableColumn('wallet_id')) {
            return $query;
        }

        $wallet = Wallet::forPublisher((int) $user->id);
        if (! $wallet) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $inner) use ($user, $wallet) {
            $inner->where('wallet_id', $wallet->id);
            if (! $user->hasRole('advertiser')) {
                $inner->orWhereNull('wallet_id');
            }
        });
    }

    /**
     * Wallet that was actually debited. Never prefers publisher vs advertiser.
     */
    public function resolveDebitedWallet(bool $lockForUpdate = false): ?Wallet
    {
        try {
            if (! Schema::hasTable('wallets')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $walletId = $this->resolveDebitedWalletId();
        if (! $walletId) {
            return null;
        }

        $query = Wallet::query()->whereKey($walletId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function resolveDebitedWalletId(): ?int
    {
        if (static::hasTableColumn('wallet_id') && $this->wallet_id) {
            return (int) $this->wallet_id;
        }

        $fromLedger = $this->ledgerDebitWalletId();
        if ($fromLedger) {
            return $fromLedger;
        }

        try {
            if (! Schema::hasTable('wallets')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $ids = Wallet::query()
            ->where('user_id', $this->user_id)
            ->pluck('id');

        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        if ($ids->count() > 1) {
            Log::error('Withdrawal source wallet is ambiguous', [
                'withdrawal_id' => $this->id,
                'user_id' => $this->user_id,
                'wallet_ids' => $ids->all(),
            ]);
        }

        return null;
    }

    private function ledgerDebitWalletId(): ?int
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return null;
        }

        $walletId = WalletTransaction::query()
            ->whereNotNull('wallet_id')
            ->where('type', WalletTransaction::TYPE_WITHDRAWAL)
            ->where('direction', 'debit')
            ->where(function ($query) {
                $query->where('reference', 'WD-'.$this->id)
                    ->orWhere(function ($query) {
                        $query->where('related_id', $this->id)
                            ->where('related_type', $this->getMorphClass());
                    });
            })
            ->orderByDesc('id')
            ->value('wallet_id');

        return $walletId ? (int) $walletId : null;
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(?string $notes = null): void
    {
        $payload = [
            'status' => 'completed',
            'processed_at' => now(),
        ];

        if ($notes !== null && $notes !== '') {
            $payload['admin_notes'] = $notes;
        }

        $this->update(static::attributesThatExist($payload));
    }

    public function markAsCancelled(?string $notes = null, string $cancelledBy = self::CANCELLED_BY_ADMIN): void
    {
        $payload = [
            'status' => 'cancelled',
            'cancelled_by' => $cancelledBy,
            'cancelled_at' => now(),
        ];

        if ($notes !== null && $notes !== '') {
            $payload['admin_notes'] = $notes;
        }

        $this->update(static::attributesThatExist($payload));
    }

    public function isActionable(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    public function isCancellableByPublisher(): bool
    {
        return $this->status === 'pending';
    }

    public function wasCancelledByUser(): bool
    {
        if ($this->status !== 'cancelled') {
            return false;
        }

        // Post-migration: cancelled_by is always present on the model attributes.
        if (array_key_exists('cancelled_by', $this->getAttributes())) {
            return ($this->cancelled_by ?? null) === self::CANCELLED_BY_USER;
        }

        // Pre-migration fallback: publisher cancel writes WD-{id}-cancel ledger credit.
        try {
            if (! Schema::hasTable('wallet_transactions')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return WalletTransaction::query()
            ->where('reference', 'WD-'.$this->id.'-cancel')
            ->exists();
    }

    /**
     * Short destination shown in the payout queue table.
     */
    public function getDestinationSnippetAttribute(): string
    {
        $details = is_array($this->payment_details)
            ? $this->payment_details
            : (json_decode((string) $this->payment_details, true) ?: []);

        return match ($this->payment_method) {
            'bank' => $this->bankSnippet($details),
            'paypal' => 'PayPal · '.$this->maskEmail((string) ($details['email'] ?? '')),
            'wise' => 'Wise · '.$this->maskEmail((string) ($details['email'] ?? '')),
            'crypto' => trim(($details['crypto_type'] ?? 'Crypto').' · '.$this->maskWallet((string) ($details['wallet_address'] ?? ''))),
            default => ucfirst((string) $this->payment_method),
        };
    }

    /**
     * Full text for clipboard paste into bank / Wise / PayPal.
     */
    public function getDestinationCopyTextAttribute(): string
    {
        $details = is_array($this->payment_details)
            ? $this->payment_details
            : (json_decode((string) $this->payment_details, true) ?: []);

        $ref = 'WD-'.$this->id;
        $net = number_format((float) $this->net_amount, 2, '.', '');

        return match ($this->payment_method) {
            'bank' => implode("\n", array_filter([
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Bank: '.($details['bank_name'] ?? ''),
                'Account holder: '.($details['account_holder'] ?? ''),
                'IBAN / Account: '.($details['account_number'] ?? ''),
                ! empty($details['swift_code']) ? 'SWIFT: '.$details['swift_code'] : null,
            ])),
            'paypal' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'PayPal: '.($details['email'] ?? ''),
            ]),
            'wise' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Wise: '.($details['email'] ?? ''),
            ]),
            'crypto' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Coin: '.($details['crypto_type'] ?? ''),
                'Wallet: '.($details['wallet_address'] ?? ''),
            ]),
            default => 'Amount: €'.$net."\nReference: ".$ref,
        };
    }

    public function getWaitingDaysAttribute(): ?int
    {
        if (! $this->isActionable() || ! $this->created_at) {
            return null;
        }

        return (int) $this->created_at->diffInDays(now());
    }

    /**
     * Publisher-facing status labels.
     */
    public function getPublisherStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Requested',
            'processing' => 'Processing',
            'completed' => 'Paid',
            'cancelled' => $this->wasCancelledByUser() ? 'Cancelled' : 'Rejected',
            default => ucfirst((string) $this->status),
        };
    }

    private function bankSnippet(array $details): string
    {
        $account = preg_replace('/\s+/', '', (string) ($details['account_number'] ?? ''));
        $last4 = $account !== '' ? substr($account, -4) : '????';
        $prefix = strlen($account) >= 2 ? strtoupper(substr($account, 0, 2)) : 'Bank';

        return $prefix.' · ···'.$last4;
    }

    private function maskEmail(string $email): string
    {
        if ($email === '' || ! str_contains($email, '@')) {
            return $email !== '' ? $email : '—';
        }

        [$local, $domain] = explode('@', $email, 2);
        $keep = max(1, min(2, strlen($local)));

        return substr($local, 0, $keep).'***@'.$domain;
    }

    private function maskWallet(string $wallet): string
    {
        if ($wallet === '') {
            return '—';
        }

        if (strlen($wallet) <= 10) {
            return $wallet;
        }

        return substr($wallet, 0, 6).'…'.substr($wallet, -4);
    }
}
