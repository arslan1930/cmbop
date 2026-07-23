<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'role',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        if (! $this->subject_type || ! $this->subject_id) {
            return null;
        }

        if (! class_exists($this->subject_type)) {
            return null;
        }

        return $this->subject_type::find($this->subject_id);
    }

    /**
     * Append-only history for a guided bulk onboarding request.
     *
     * @return Collection<int, self>
     */
    public static function forBulkSiteRequest(int $bulkSiteRequestId, int $limit = 100)
    {
        return static::query()
            ->where(function ($q) use ($bulkSiteRequestId) {
                $q->where(function ($inner) use ($bulkSiteRequestId) {
                    $inner->where('subject_type', BulkSiteRequest::class)
                        ->where('subject_id', $bulkSiteRequestId);
                })->orWhere('properties->bulk_site_request_id', $bulkSiteRequestId);
            })
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
