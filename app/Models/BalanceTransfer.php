<?php
// app/Models/BalanceTransfer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalanceTransfer extends Model
{
    protected $fillable = [
        'user_id',
        'from_role',
        'to_role',
        'amount',
        'fee',
        'net_amount',
        'reference_code',
        'status',
        'notes'
    ];
    
    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2'
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public static function generateReferenceCode()
    {
        return 'INT-TRF-' . strtoupper(uniqid()) . '-' . mt_rand(1000, 9999);
    }
}