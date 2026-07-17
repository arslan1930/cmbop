<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $fillable = ['code', 'name', 'native_name'];

    public function countries()
    {
        return $this->belongsToMany(Country::class, 'country_language');
    }

    public function sites()
    {
        return $this->hasMany(Site::class, 'language', 'code');
    }

    /**
     * Marketplace languages (Europe-focused set; covers NA via EN/ES/FR).
     */
    public function scopeMarketplace(Builder $query): Builder
    {
        return $query->whereIn('code', config('markets.allowed_language_codes', []));
    }

    /**
     * @deprecated Use marketplace()
     */
    public function scopeEuropean(Builder $query): Builder
    {
        return $query->marketplace();
    }
}
