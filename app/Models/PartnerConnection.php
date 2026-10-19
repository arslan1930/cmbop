<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerConnection extends Model
{
    public const TYPE_FEED_URL = 'feed_url';

    public const TYPE_FILE_UPLOAD = 'file_upload';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'partner_id',
        'type',
        'feed_url',
        'schedule',
        'status',
        'last_sync_at',
        'last_sync_status',
        'last_job_id',
        'fail_count',
        'field_mapping',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'field_mapping' => 'array',
        'fail_count' => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PartnerImportJob::class, 'connection_id');
    }
}
