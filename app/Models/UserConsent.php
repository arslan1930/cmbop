<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserConsent extends Model
{
    protected $fillable = [
        'user_id',
        'terms_accepted',
        'marketing_consent',
        'newsletter_consent',
        'consented_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'terms_accepted'     => 'boolean',
        'marketing_consent'  => 'boolean',
        'newsletter_consent' => 'boolean',
        'consented_at'       => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}