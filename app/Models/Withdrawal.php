<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'fee',
        'net_amount',
        'payment_method',
        'payment_details',
        'status',
        'admin_notes',
        'processed_at'
    ];
    
    protected $casts = [
        'payment_details' => 'array',
        'processed_at' => 'datetime',
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2'
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function markAsProcessing()
    {
        $this->update(['status' => 'processing']);
    }
    
    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now()
        ]);
    }
    
    public function markAsCancelled($notes = null)
    {
        $this->update([
            'status' => 'cancelled',
            'admin_notes' => $notes
        ]);
    }
}