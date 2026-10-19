<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerImportRow extends Model
{
    protected $fillable = [
        'job_id',
        'row_number',
        'domain',
        'result',
        'reason_code',
        'reason',
        'raw',
    ];

    protected $casts = [
        'raw' => 'array',
        'row_number' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(PartnerImportJob::class, 'job_id');
    }
}
