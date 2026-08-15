<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Withdrawal extends Model
{
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
        if (! $wallet || ! Schema::hasColumn((new static)->getTable(), 'wallet_id')) {
            return [];
        }

        return ['wallet_id' => (int) $wallet->id];
    }

    /**
     * Wallet that was actually debited. Never prefers publisher vs advertiser.
     */
    public function resolveDebitedWallet(bool $lockForUpdate = false): ?Wallet
    {
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
        if (Schema::hasColumn($this->getTable(), 'wallet_id') && $this->wallet_id) {
            return (int) $this->wallet_id;
        }

        $fromLedger = $this->ledgerDebitWalletId();
        if ($fromLedger) {
            return $fromLedger;
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

        $this->update($payload);
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

        $this->update($payload);
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
        return WalletTransaction::query()
            ->where('reference', 'WD-'.$this->id.'-cancel')
            ->exists();
    }

    /**
     * Short destination shown in the payout queue table.
     */
    public function getDestinationSnippetAttribute(): string
    {
        $details = self::detailsArray($this->payment_details);

        return match ($this->payment_method) {
            'bank' => $this->bankSnippet($details),
            'paypal' => 'PayPal · '.$this->maskEmail(self::destinationText($details, 'email')),
            'wise' => 'Wise · '.$this->maskEmail(self::destinationText($details, 'email')),
            'crypto' => trim((self::destinationText($details, 'crypto_type') ?: 'Crypto').' · '.$this->maskWallet(self::destinationText($details, 'wallet_address'))),
            default => ucfirst((string) $this->payment_method),
        };
    }

    /**
     * Full text for clipboard paste into bank / Wise / PayPal.
     */
    public function getDestinationCopyTextAttribute(): string
    {
        $details = self::detailsArray($this->payment_details);

        $ref = 'WD-'.$this->id;
        $net = number_format((float) $this->net_amount, 2, '.', '');

        return match ($this->payment_method) {
            'bank' => implode("\n", array_filter([
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Bank: '.self::destinationText($details, 'bank_name'),
                'Account holder: '.self::destinationText($details, 'account_holder'),
                'IBAN / Account: '.self::destinationText($details, 'account_number'),
                self::destinationText($details, 'swift_code') !== '' ? 'SWIFT: '.self::destinationText($details, 'swift_code') : null,
            ])),
            'paypal' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'PayPal: '.self::destinationText($details, 'email'),
            ]),
            'wise' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Wise: '.self::destinationText($details, 'email'),
            ]),
            'crypto' => implode("\n", [
                'Amount: €'.$net,
                'Reference: '.$ref,
                'Coin: '.self::destinationText($details, 'crypto_type'),
                'Wallet: '.self::destinationText($details, 'wallet_address'),
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

    /**
     * payment_details as an array. JSON scalars / invalid payloads become [].
     *
     * @return array<string, mixed>
     */
    public static function detailsArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Scalar payout-detail field, or empty when the value is nested/invalid.
     */
    public static function detailText(array $details, string $key): string
    {
        $value = $details[$key] ?? null;
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * First non-empty scalar among the given payout-detail keys.
     */
    public static function firstDetailText(array $details, string ...$keys): string
    {
        foreach ($keys as $key) {
            $text = self::detailText($details, $key);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Canonical dest field plus leftover aliases used on older WD rows
     * (paypal_email / iban / crypto_wallet). Same keys as Invoice::maskedPayoutDestination.
     *
     * @return list<string>
     */
    public static function destinationAliases(string $field): array
    {
        return match ($field) {
            'email' => ['email', 'paypal_email', 'wise_email'],
            'account_number' => ['account_number', 'iban', 'bank_account'],
            'wallet_address' => ['wallet_address', 'crypto_wallet'],
            default => [$field],
        };
    }

    public static function destinationText(array $details, string $field): string
    {
        return self::firstDetailText($details, ...self::destinationAliases($field));
    }

    private function bankSnippet(array $details): string
    {
        $account = preg_replace('/\s+/', '', self::destinationText($details, 'account_number')) ?? '';
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
