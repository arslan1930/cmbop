<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerLedgerTransaction extends Model
{
    protected $fillable = [
        'type', 'order_item_id', 'payout_id', 'idempotency_key', 'description', 'created_by',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(PartnerLedgerEntry::class, 'transaction_id');
    }
}
