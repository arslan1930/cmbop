<?php

namespace App\Models;

use App\Models\Concerns\HasPromotionSchedule;
use App\Support\PromotionUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdBanner extends Model
{
    use HasPromotionSchedule;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'title',
        'alt_text',
        'size_key',
        'width',
        'height',
        'image_path',
        'image_url',
        'link_url',
        'placement',
        'audience',
        'is_active',
        'open_in_new_tab',
        'priority',
        'impressions',
        'clicks',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'open_in_new_tab' => 'boolean',
        'priority' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where(function (Builder $q) use ($audience) {
            $q->where('audience', 'all')->orWhere('audience', $audience);
        });
    }

    public function scopeForPlacement(Builder $query, string $placement): Builder
    {
        return $query->where('placement', $placement);
    }

    public function imageSrc(): ?string
    {
        if (filled($this->image_path)) {
            return '/storage/'.ltrim($this->image_path, '/');
        }

        return PromotionUrl::href($this->image_url);
    }

    public function sizeLabel(): string
    {
        $sizeKey = scalar_text($this->size_key);
        $meta = config("promotions.banner_sizes.{$sizeKey}");
        $label = scalar_text($meta['label'] ?? ucfirst(str_replace('_', ' ', $sizeKey)));

        return "{$label} ({$this->width}×{$this->height})";
    }

    public function placementLabel(): string
    {
        $placement = scalar_text($this->placement);

        return scalar_text(config("promotions.banner_placements.{$placement}", $placement));
    }

    public function recordImpression(): void
    {
        $this->increment('impressions');
    }

    public function recordClick(): void
    {
        $this->increment('clicks');
    }
}
