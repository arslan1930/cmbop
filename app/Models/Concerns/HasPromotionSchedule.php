<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

trait HasPromotionSchedule
{
    public function scheduleState(): string
    {
        try {
            if ($this->isCurrentlyLive()) {
                return 'live';
            }

            if (! $this->is_active) {
                return 'paused';
            }

            $starts = $this->safeStartsAt();
            if ($starts && $starts->isFuture()) {
                return 'scheduled';
            }

            $ends = $this->safeEndsAt();
            if ($ends && $ends->isPast()) {
                return 'expired';
            }
        } catch (\Throwable) {
            return 'paused';
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
        try {
            if (! $this->is_active) {
                return false;
            }

            $now = now();
            $starts = $this->safeStartsAt();
            if ($starts && $starts->gt($now)) {
                return false;
            }
            $ends = $this->safeEndsAt();
            if ($ends && $ends->lt($now)) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function safeStartsAt(): ?DateTimeInterface
    {
        return $this->safeScheduleDate('starts_at');
    }

    public function safeEndsAt(): ?DateTimeInterface
    {
        return $this->safeScheduleDate('ends_at');
    }

    private function safeScheduleDate(string $attribute): ?DateTimeInterface
    {
        try {
            $value = $this->{$attribute};

            return $value instanceof DateTimeInterface ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
