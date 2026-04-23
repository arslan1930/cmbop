<?php
// app/Models/DepositRequest.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositRequest extends Model
{
    protected $fillable = [
    'user_id', 'reference_code', 'stripe_session_id', 'stripe_payment_intent_id', 
    'stripe_response', 'amount', 'payment_method', 'status', 'admin_notes', 
    'approved_at', 'rejected_at', 'paid_at'
];

protected $casts = [
    'stripe_response' => 'array',
    'approved_at' => 'datetime',
    'rejected_at' => 'datetime',
    'paid_at' => 'datetime',
    'amount' => 'decimal:2'
];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isApproved()
    {
        return $this->status === 'approved';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isRejected()
    {
        return $this->status === 'rejected';
    }
}