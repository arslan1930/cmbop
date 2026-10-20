<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerLedgerEntry extends Model
{
    protected $fillable = ['transaction_id', 'account_id', 'direction', 'amount_minor', 'currency'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PartnerLedgerTransaction::class, 'transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PartnerLedgerAccount::class, 'account_id');
    }
}
