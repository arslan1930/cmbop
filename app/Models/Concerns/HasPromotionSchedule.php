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

            // A leftover non-null date that Carbon cannot parse is not "no
            // schedule" — SQL active() already excludes many of these, and
            // treating them as unrestricted made admin/click disagree.
            if ($this->scheduleDateUnparseable('starts_at') || $this->scheduleDateUnparseable('ends_at')) {
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

    public function visibleToAudience(string $audience): bool
    {
        $mine = scalar_text($this->audience ?: 'all');

        return $mine === 'all' || $mine === $audience;
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

    private function scheduleDateUnparseable(string $attribute): bool
    {
        $raw = $this->getAttributes()[$attribute] ?? null;
        if ($raw === null || $raw === '') {
            return false;
        }

        return $this->safeScheduleDate($attribute) === null;
    }
}
