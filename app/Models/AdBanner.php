<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AdBanner extends Model
{
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
            // Root-relative path so admin/public previews work on any host:port
            return '/storage/' . ltrim($this->image_path, '/');
        }

        return $this->image_url ?: null;
    }

    public function sizeLabel(): string
    {
        $meta = config("promotions.banner_sizes.{$this->size_key}");
        $label = $meta['label'] ?? ucfirst(str_replace('_', ' ', $this->size_key));

        return "{$label} ({$this->width}×{$this->height})";
    }

    public function placementLabel(): string
    {
        return config("promotions.banner_placements.{$this->placement}", $this->placement);
    }

    public function isCurrentlyLive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();
        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
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
