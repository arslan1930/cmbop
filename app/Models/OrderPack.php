<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class OrderPack extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PARTIALLY_COMPLETED = 'partially_completed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'site_id',
        'reference_code',
        'pack_size',
        'discount_tier',
        'discount_percent',
        'status',
        'payment_method',
        'total_amount',
        'published_count',
        'refunded_count',
        'completed_count',
        'completed_at',
    ];

    protected $casts = [
        'pack_size' => 'integer',
        'discount_tier' => 'integer',
        'discount_percent' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'published_count' => 'integer',
        'refunded_count' => 'integer',
        'completed_count' => 'integer',
        'completed_at' => 'datetime',
    ];

    public static function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('order_packs');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function ordersColumnAvailable(): bool
    {
        try {
            return Schema::hasTable('orders') && Schema::hasColumn('orders', 'order_pack_id');
        } catch (\Throwable) {
            return false;
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'order_pack_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'order_pack_id');
    }

    public function label(): string
    {
        $size = max(1, (int) $this->pack_size);

        return 'Pack of '.$size;
    }

    public function progressLabel(): string
    {
        $published = (int) $this->published_count;
        $size = max(1, (int) $this->pack_size);

        return $published.' of '.$size.' published';
    }

    public function statusLabel(): string
    {
        return match ((string) $this->status) {
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_PARTIALLY_COMPLETED => 'Partially completed',
            self::STATUS_PARTIALLY_REFUNDED => 'Partially refunded',
            self::STATUS_REFUNDED => 'Refunded',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'In progress',
        };
    }
}
