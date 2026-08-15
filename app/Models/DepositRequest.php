<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositRequest extends Model
{
    public const MANUAL_METHODS = ['wise', 'bank', 'crypto'];

    public const CARD_METHODS = ['card', 'stripe'];

    protected $fillable = [
        'user_id',
        'reference_code',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_response',
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
            && $this->isManualPayment();
    }

    public function isManualPayment(): bool
    {
        return in_array($this->payment_method, self::MANUAL_METHODS, true);
    }

    public function hasStripeSettlement(): bool
    {
        return filled($this->stripe_session_id) || filled($this->stripe_payment_intent_id);
    }

    public function isCardPayment(): bool
    {
        return in_array(strtolower((string) $this->payment_method), self::CARD_METHODS, true)
            || $this->hasStripeSettlement();
    }

    /**
     * Admin / email confirm may credit only pending bank / Wise / crypto rows
     * that are not already tied to a Stripe session or PaymentIntent.
     */
    public function canManuallyApprove(): bool
    {
        return $this->isPending()
            && $this->isManualPayment()
            && ! $this->hasStripeSettlement();
    }
}
