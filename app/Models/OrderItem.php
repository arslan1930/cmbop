<?php
// app/Models/OrderItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 
        'site_id', 
        'site_name', 
        'site_url', 
        'price', 
        'content_link', 
        'live_url',
        'sensitive_type',
        'additional_price',
        'publisher_status',
        'accepted_at',
        'rejected_at',
        'completed_at',
        'rejection_reason',
        'completion_notes'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'additional_price' => 'decimal:2',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
    
    /**
     * Get the publisher (site owner) for this order item
     */
    public function getPublisherAttribute()
    {
        if ($this->site) {
            return User::find($this->site->publisher_id);
        }
        return null;
    }
    
    /**
     * Get the publisher ID for this order item
     */
    public function getPublisherIdAttribute()
    {
        return $this->site?->publisher_id;
    }
    
    /**
     * Get the publisher name for this order item
     */
    public function getPublisherNameAttribute()
    {
        $publisher = $this->publisher;
        return $publisher ? $publisher->name : 'Unknown Publisher';
    }
    
    /**
     * Get the publisher email for this order item
     */
    public function getPublisherEmailAttribute()
    {
        $publisher = $this->publisher;
        return $publisher ? $publisher->email : null;
    }
    
    // Helper method to get base price (price - additional_price)
    public function getBasePriceAttribute()
    {
        return $this->price - $this->additional_price;
    }
    
    // Helper method to check if item has sensitive pricing
    public function hasSensitivePricing()
    {
        return !is_null($this->sensitive_type) && $this->additional_price > 0;
    }
    
    // Helper method to get formatted price breakdown
    public function getPriceBreakdownAttribute()
    {
        if ($this->hasSensitivePricing()) {
            return [
                'base_price' => $this->base_price,
                'additional_price' => $this->additional_price,
                'sensitive_type' => $this->sensitive_type,
                'total_price' => $this->price
            ];
        }
        
        return [
            'base_price' => $this->price,
            'additional_price' => 0,
            'sensitive_type' => null,
            'total_price' => $this->price
        ];
    }
    
    /**
     * Check if live URL has been submitted
     */
    public function hasLiveUrl()
    {
        return !is_null($this->live_url) && $this->live_url !== '';
    }
    
    /**
     * Get status badge class for display
     */
    public function getStatusBadgeClassAttribute()
    {
        switch ($this->publisher_status) {
            case 'pending':
                return 'bg-warning text-dark';
            case 'accepted':
                return 'bg-info text-white';
            case 'rejected':
                return 'bg-danger text-white';
            case 'completed':
                return 'bg-success text-white';
            default:
                return 'bg-secondary text-white';
        }
    }
    
    /**
     * Get status text for display
     */
    public function getStatusTextAttribute()
    {
        switch ($this->publisher_status) {
            case 'pending':
                return 'Pending';
            case 'accepted':
                return 'Accepted';
            case 'rejected':
                return 'Rejected';
            case 'completed':
                return 'Completed';
            default:
                return ucfirst($this->publisher_status);
        }
    }
}