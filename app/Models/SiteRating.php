<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteRating extends Model
{
    public const STATUS_APPROVED = 'approved';
    public const STATUS_HIDDEN = 'hidden';
    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'site_id',
        'user_id',
        'order_id',
        'order_item_id',
        'rating',
        'comment',
        'status',
        'is_admin',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_admin' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

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

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public static function refreshSiteAggregate(int $siteId): void
    {
        $agg = static::query()
            ->where('site_id', $siteId)
            ->approved()
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
            ->first();

        Site::query()->where('id', $siteId)->update([
            'rating_avg' => round((float) ($agg->avg_rating ?? 0), 2),
            'rating_count' => (int) ($agg->total ?? 0),
        ]);
    }
}
