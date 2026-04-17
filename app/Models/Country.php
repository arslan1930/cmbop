<?php

namespace App\Models;

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
}