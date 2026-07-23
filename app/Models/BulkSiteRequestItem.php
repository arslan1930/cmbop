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
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function bulkRequest(): BelongsTo
    {
        return $this->belongsTo(BulkSiteRequest::class, 'bulk_site_request_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
