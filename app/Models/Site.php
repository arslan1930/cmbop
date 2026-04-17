<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $fillable = [
        'publisher_id',
        'site_name',
        'site_url',
        'domain', // NEW
        'example_url',
        'da',
        'dr',
        'traffic',
        'country',
        'language',
        'category',
        'price',
        'publication_time',
        'link_type',
        'sponsored',
        'partner_material',
        'as_you_prefer',
        'description',
        'sensitive_prices',
        'verified',
        'active',
        'owner_id'
    ];

    public function publisher()
{
    return $this->belongsTo(\App\Models\User::class, 'publisher_id');
}

}