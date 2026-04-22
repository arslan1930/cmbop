<?php
// app/Models/OrderItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'site_id', 'site_name', 'site_url', 'price', 'content_link'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}