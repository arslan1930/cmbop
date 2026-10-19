<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerImportJob extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_HELD = 'held_for_confirmation';

    protected $fillable = [
        'partner_id',
        'connection_id',
        'source',
        'status',
        'started_at',
        'finished_at',
        'counts',
        'raw_payload_path',
        'error',
        'pending_removals',
        'actor_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'counts' => 'array',
        'pending_removals' => 'array',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PartnerConnection::class, 'connection_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PartnerImportRow::class, 'job_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
