<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasPromotionSchedule
{
    public function scheduleState(): string
    {
        if ($this->isCurrentlyLive()) {
            return 'live';
        }

        if (! $this->is_active) {
            return 'paused';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'expired';
        }

        return 'paused';
    }

    public function scopeScheduleState(Builder $query, string $state): Builder
    {
        $now = now();

        return match ($state) {
            'live' => $query->active(),
            'paused' => $query->where('is_active', false),
            'scheduled' => $query->where('is_active', true)
                ->whereNotNull('starts_at')
                ->where('starts_at', '>', $now),
            'expired' => $query->where('is_active', true)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', $now),
            default => $query,
        };
    }

    public function isCurrentlyLive(): bool
    {
        if (! $this->is_active) {
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
}
