<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class LinkCheck extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_MISSING = 'missing';

    public const STATUS_ERROR = 'error';

    public const STATUS_RESTORE_REQUESTED = 'restore_requested';

    public const STATUS_RESTORED = 'restored';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'checked_at',
        'status',
        'http_code',
        'href_found',
        'notes',
        'restore_due_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'restore_due_at' => 'datetime',
        'href_found' => 'boolean',
        'http_code' => 'integer',
    ];

    public static function tableAvailable(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
