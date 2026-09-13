<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DepositRequest extends Model
{
    use ToleratesMissingSchema;
    use ToleratesUnparseableDates;

    protected $fillable = [
        'user_id',
        'reference_code',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_response',
        'paypal_order_id',
        'paypal_capture_id',
        'paypal_response',
        'amount',
        'payment_method',
        'status',
        'admin_notes',
        'approved_at',
        'rejected_at',
        'paid_at',
        'user_marked_paid_at',
        'user_payment_note',
    ];

    protected $casts = [
        'stripe_response' => 'array',
        'paypal_response' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
        'user_marked_paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Real Gregorian user_marked_paid_at. Leftover Hostinger strings are not
     * a report — PHP casts them to null via ToleratesUnparseableDates.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function hasUserMarkedPaidAtColumn(): bool
    {
        return static::hasTableColumn('user_marked_paid_at');
    }

    public function scopeWhereUserMarkedPaidAtIsRecorded($query)
    {
        return $query->whereNotNull('user_marked_paid_at')
            ->where('user_marked_paid_at', '>=', static::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('user_marked_paid_at', '<=', static::PLAUSIBLE_SQL_DATETIME_CEIL);
    }

    /**
     * Missing or leftover user_marked_paid_at (same as PHP null after cast).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereUserMarkedPaidAtIsMissing($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('user_marked_paid_at')
                ->orWhere('user_marked_paid_at', '>', static::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->orWhere('user_marked_paid_at', '<', static::PLAUSIBLE_SQL_DATETIME_FLOOR);
        });
    }

    /**
     * Advertiser reported that they sent the bank/Wise/crypto transfer.
     * Does not change status — wallet credit still requires admin approval.
     */
    public function userHasMarkedPaid(): bool
    {
        return $this->user_marked_paid_at !== null;
    }

    public function canUserMarkPaid(): bool
    {
        return $this->isPending()
            && ! $this->userHasMarkedPaid()
            && in_array($this->payment_method, ['wise', 'bank', 'crypto'], true);
    }

    /**
     * Advertiser can drop an unused Bank/Wise/crypto invoice.
     * Mark-paid invoices stay pending for admin — they are not cancellable here.
     */
    public function canUserCancel(): bool
    {
        return $this->isPending()
            && ! $this->userHasMarkedPaid()
            && in_array($this->payment_method, ['wise', 'bank', 'crypto'], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function paymentMethodLabel(): string
    {
        return Invoice::paymentMethodLabel($this->payment_method);
    }

    /**
     * Six-digit unique REF for a new invoice. Never accept a client-supplied code.
     */
    public static function generateUniqueReferenceCode(): string
    {
        for ($i = 0; $i < 24; $i++) {
            $code = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            try {
                if (! static::query()->where('reference_code', $code)->exists()) {
                    return $code;
                }
            } catch (\Throwable) {
                return $code;
            }
        }

        return str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Latest completed/approved wallet top-up rail for this advertiser, or null.
     *
     * @return 'card'|'paypal'|'bank'|'wise'|'crypto'|null
     */
    public static function lastUsedMethodForUser(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $method = static::query()
                ->where('user_id', $userId)
                ->whereIn('status', ['completed', 'approved'])
                ->whereIn('payment_method', ['card', 'paypal', 'bank', 'wise', 'crypto'])
                ->latest('id')
                ->value('payment_method');
        } catch (\Throwable) {
            return null;
        }

        return is_string($method) && $method !== '' ? $method : null;
    }

    public function isPaypalRefundable(): bool
    {
        return strtolower((string) $this->payment_method) === 'paypal'
            && in_array((string) $this->status, ['completed', 'approved'], true)
            && filled($this->paypal_capture_id);
    }
}
