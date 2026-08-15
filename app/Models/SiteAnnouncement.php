<?php

namespace App\Models;

use App\Models\Concerns\HasPromotionSchedule;
use App\Models\Concerns\SoftDeletesWhenReady;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteAnnouncement extends Model
{
    use HasPromotionSchedule;
    use SoftDeletesWhenReady;

    protected $fillable = [
        'title',
        'message',
        'type',
        'style',
        'audience',
        'cta_label',
        'cta_url',
        'is_active',
        'is_dismissible',
        'priority',
        'version',
        'clicks',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_dismissible' => 'boolean',
        'priority' => 'integer',
        'version' => 'integer',
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

    public function typeLabel(): string
    {
        $type = $this->typeKey();

        return scalar_text(config("promotions.announcement_types.{$type}.label", ucfirst($type)));
    }

    public function typeIcon(): string
    {
        $type = $this->typeKey();

        return scalar_text(config("promotions.announcement_types.{$type}.icon", 'fa-bullhorn'));
    }

    public function typeKey(): string
    {
        $type = scalar_text($this->type);

        return array_key_exists($type, config('promotions.announcement_types', [])) ? $type : 'general';
    }

    public function styleKey(): string
    {
        $style = scalar_text($this->style);

        return array_key_exists($style, config('promotions.announcement_styles', [])) ? $style : 'info';
    }

    public function recordClick(): void
    {
        $this->increment('clicks');
    }

    public function offerEndsLabel(): ?string
    {
        if (! in_array($this->typeKey(), ['limited_offer', 'discount', 'black_friday', 'offer'], true)) {
            return null;
        }

        $ends = $this->safeEndsAt();

        return $ends ? $ends->format('M j') : null;
    }
}
