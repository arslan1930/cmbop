<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerFxRate extends Model
{
    protected $fillable = [
        'rate_date',
        'quote',
        'rate',
        'source',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:8',
    ];
}
