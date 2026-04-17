<?php

namespace App\Models;

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
}