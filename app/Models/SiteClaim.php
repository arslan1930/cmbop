<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteClaim extends Model
{
    protected $fillable = [
        'site_id',
        'claimer_id',
        'website_name',
        'website_url',
        'domain',
        'name_matches',
        'proof_message',
        'contact_email',
        'status',
        'admin_notes',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'name_matches' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
