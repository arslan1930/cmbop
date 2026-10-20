<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PartnerLedgerAccount extends Model
{
    protected $fillable = ['code', 'owner_type', 'owner_id', 'currency', 'allow_negative'];

    protected $casts = ['allow_negative' => 'boolean'];

    public function balanceRow(): HasOne
    {
        return $this->hasOne(PartnerLedgerBalance::class, 'account_id');
    }
}
