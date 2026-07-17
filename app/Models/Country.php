<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = ['code', 'name', 'region'];

    public function languages()
    {
        return $this->belongsToMany(Language::class, 'country_language')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    public function primaryLanguages()
    {
        return $this->belongsToMany(Language::class, 'country_language')
                    ->wherePivot('is_primary', true);
    }

    public function sites()
    {
        return $this->hasMany(Site::class, 'country', 'code');
    }

    /**
     * Marketplace countries: Europe + English regions + Latin America + Chinese markets.
     */
    public function scopeMarketplace(Builder $query): Builder
    {
        $codes = config('markets.allowed_country_codes', []);
        if (!empty($codes)) {
            return $query->whereIn('code', $codes);
        }

        $regions = config('markets.allowed_country_regions', ['Europe']);

        return $query->whereIn('region', $regions);
    }

    /**
     * @deprecated Use marketplace()
     */
    public function scopeEuropean(Builder $query): Builder
    {
        return $query->marketplace();
    }
}
