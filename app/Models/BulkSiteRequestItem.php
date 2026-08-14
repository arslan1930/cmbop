<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkSiteRequestItem extends Model
{
    protected $fillable = [
        'bulk_site_request_id',
        'site_url',
        'domain',
        'price',
        'site_id',
        'rejected_at',
        'rejected_by',
        'reject_reason',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'rejected_at' => 'datetime',
    ];

    /**
     * URL+price rows that still need Done (not added, not rejected).
     */
    public function scopePending($query)
    {
        return $query->whereNull('site_id')->whereNull('rejected_at');
    }

    public function scopeRejected($query)
    {
        return $query->whereNotNull('rejected_at');
    }

    public function isPending(): bool
    {
        return $this->site_id === null && $this->rejected_at === null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    public function bulkRequest(): BelongsTo
    {
        return $this->belongsTo(BulkSiteRequest::class, 'bulk_site_request_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
