<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerIdempotencyKey extends Model
{
    protected $fillable = [
        'partner_id', 'key', 'request_hash', 'response_code', 'response_body',
    ];

    protected $casts = [
        'response_body' => 'array',
    ];
}
