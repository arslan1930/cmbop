<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPayoutModeChange extends Model
{
    protected $fillable = [
        'partner_id', 'from_mode', 'to_mode', 'reason', 'requested_by',
        'approved_by', 'second_approver', 'effective_from',
    ];

    protected $casts = ['effective_from' => 'datetime'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
