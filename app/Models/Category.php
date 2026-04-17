<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'group'];
    
    public function sites()
    {
        return $this->hasMany(Site::class, 'category', 'name');
    }
}