<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'email',
        'locale',
        'ip_address',
        'consented_at',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
    ];
}
