<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PromotionListQuery
{
    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return ['all', 'live', 'scheduled', 'expired', 'paused', 'trashed'];
    }

    public static function apply(Builder $query, Request $request, string $searchColumn, ?string $secondSearch = null): Builder
    {
        $status = search_text($request->query('status')) ?: 'all';
        if ($status === 'trashed') {
            $query->onlyTrashed();
        } elseif (in_array($status, ['live', 'scheduled', 'expired', 'paused'], true)) {
            $query->scheduleState($status);
        }

        $type = search_text($request->query('type'));
        if ($type !== '') {
            $query->where('type', $type);
        }

        $placement = search_text($request->query('placement'));
        if ($placement !== '') {
            $query->where('placement', $placement);
        }

        $audience = search_text($request->query('audience'));
        if ($audience !== '') {
            $query->where('audience', $audience);
        }

        $q = search_text($request->query('q'));
        if ($q !== '') {
            $like = like_contains($q);
            $query->where(function (Builder $inner) use ($like, $searchColumn, $secondSearch) {
                $inner->where($searchColumn, 'like', $like);
                if ($secondSearch) {
                    $inner->orWhere($secondSearch, 'like', $like);
                }
            });
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    public static function statusCounts(Builder $base): array
    {
        $counts = [];
        foreach (['live', 'scheduled', 'expired', 'paused'] as $status) {
            try {
                $counts[$status] = (clone $base)->scheduleState($status)->count();
            } catch (\Throwable) {
                $counts[$status] = 0;
            }
        }

        try {
            $counts['trashed'] = (clone $base)->onlyTrashed()->count();
        } catch (\Throwable) {
            $counts['trashed'] = 0;
        }

        $counts['all'] = ($counts['live'] ?? 0) + ($counts['scheduled'] ?? 0)
            + ($counts['expired'] ?? 0) + ($counts['paused'] ?? 0);

        return $counts;
    }
}
