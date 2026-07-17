<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentModerationLog extends Model
{
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'order_id',
        'order_item_id',
        'document_url',
        'document_id',
        'status',
        'passed',
        'max_confidence',
        'detected_category',
        'category_scores',
        'quality_report',
        'signals',
        'error_code',
        'error_message',
        'word_count',
        'scan_token',
        'admin_override',
        'overridden_by',
        'overridden_at',
        'admin_notes',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'admin_override' => 'boolean',
        'max_confidence' => 'integer',
        'word_count' => 'integer',
        'category_scores' => 'array',
        'quality_report' => 'array',
        'signals' => 'array',
        'overridden_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function isUsableApproval(int $withinSeconds = 900): bool
    {
        if ($this->admin_override && $this->passed) {
            return true;
        }

        return $this->passed
            && $this->status === self::STATUS_APPROVED
            && $this->created_at
            && $this->created_at->gte(now()->subSeconds($withinSeconds));
    }
}
