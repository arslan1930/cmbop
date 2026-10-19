<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerLedgerBalance extends Model
{
    protected $fillable = ['account_id', 'balance_minor', 'version'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PartnerLedgerAccount::class, 'account_id');
    }
}
