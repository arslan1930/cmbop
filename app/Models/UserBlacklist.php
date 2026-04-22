<?php
// app/Models/UserBlacklist.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBlacklist extends Model
{
    protected $table = 'user_blacklist'; // Specify the correct table name
    
    protected $fillable = ['user_id', 'site_id'];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}