<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerStatement extends Model
{
    protected $fillable = ['partner_id', 'period', 'status', 'totals', 'lines', 'issued_at'];

    protected $casts = [
        'totals' => 'array',
        'lines' => 'array',
        'issued_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
