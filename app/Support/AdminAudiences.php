<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Audience Inventory filters shared with Campaigns send / count.
 */
class AdminAudiences
{
    /**
     * @return array{
     *     verified: string,
     *     registered_from: string,
     *     registered_to: string,
     *     country: string,
     *     marketing: string,
     *     exclude_dual_role: bool,
     *     sort: string,
     *     dir: string
     * }
     */
    public static function filtersFromRequest(Request $request): array
    {
        $verified = search_text($request->input('verified'));
        if (! in_array($verified, ['all', 'yes', 'no'], true)) {
            $verified = 'all';
        }

        $marketing = search_text($request->input('marketing'));
        if (! in_array($marketing, ['all', 'opted_in', 'opted_out'], true)) {
            $marketing = 'all';
        }

        $sort = search_text($request->input('sort'));
        if (! in_array($sort, ['name', 'registered'], true)) {
            $sort = 'name';
        }

        $dir = search_text($request->input('dir'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        $from = search_text($request->input('registered_from'));
        $to = search_text($request->input('registered_to'));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            $from = '';
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            $to = '';
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'verified' => $verified,
            'registered_from' => $from,
            'registered_to' => $to,
            'country' => mb_substr(search_text($request->input('country')), 0, 64),
            'marketing' => $marketing,
            'exclude_dual_role' => $request->boolean('exclude_dual_role'),
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    public static function searchFromRequest(Request $request): string
    {
        return search_text($request->input('q'));
    }

    /**
     * Query string for inventory tabs / CSV / campaign handoff (no tab).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function filterQuery(string $search, array $filters, bool $includeSort = true): array
    {
        $query = [
            'q' => $search !== '' ? $search : null,
            'verified' => ($filters['verified'] ?? 'all') !== 'all' ? $filters['verified'] : null,
            'registered_from' => ($filters['registered_from'] ?? '') !== '' ? $filters['registered_from'] : null,
            'registered_to' => ($filters['registered_to'] ?? '') !== '' ? $filters['registered_to'] : null,
            'country' => ($filters['country'] ?? '') !== '' ? $filters['country'] : null,
            'marketing' => ($filters['marketing'] ?? 'all') !== 'all' ? $filters['marketing'] : null,
            'exclude_dual_role' => ! empty($filters['exclude_dual_role']) ? 1 : null,
        ];

        if ($includeSort) {
            $query['sort'] = ($filters['sort'] ?? 'name') !== 'name' ? $filters['sort'] : null;
            $query['dir'] = ($filters['dir'] ?? 'asc') !== 'asc' ? $filters['dir'] : null;
        }

        return array_filter($query, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function hasListFilters(string $search, array $filters): bool
    {
        return self::filterQuery($search, $filters, includeSort: true) !== [];
    }

    /**
     * Filters that change who is emailed (not table sort).
     *
     * @param  array<string, mixed>  $filters
     */
    public static function hasSendFilters(string $search, array $filters): bool
    {
        return self::filterQuery($search, $filters, includeSort: false) !== [];
    }

    /**
     * @return array{search: string, filters: array<string, mixed>}
     */
    public static function snapshotFromRequest(Request $request): array
    {
        $search = self::searchFromRequest($request);
        $filters = self::filtersFromRequest($request);

        return self::normalizeSnapshot([
            'search' => $search,
            'filters' => $filters,
        ]);
    }

    /**
     * @return array{search: string, filters: array<string, mixed>}
     */
    public static function normalizeSnapshot(mixed $raw): array
    {
        $search = '';
        $filters = [];
        if (is_array($raw)) {
            $search = search_text($raw['search'] ?? $raw['q'] ?? '');
            $filters = is_array($raw['filters'] ?? null) ? $raw['filters'] : $raw;
        }

        $request = Request::create('/', 'GET', array_merge(
            is_array($filters) ? $filters : [],
            ['q' => $search]
        ));
        $normalized = self::filtersFromRequest($request);
        unset($normalized['sort'], $normalized['dir']);

        if (! self::hasSendFilters($search, $normalized)) {
            return ['search' => '', 'filters' => []];
        }

        return [
            'search' => $search,
            'filters' => $normalized,
        ];
    }

    /**
     * Hidden compose fields (no sort).
     *
     * @param  array{search?: string, filters?: array<string, mixed>}  $snapshot
     * @return array<string, string|int>
     */
    public static function handoffFields(array $snapshot): array
    {
        $snapshot = self::normalizeSnapshot($snapshot);
        $search = $snapshot['search'] ?? '';
        $filters = $snapshot['filters'] ?? [];

        return self::filterQuery($search, $filters, includeSort: false);
    }

    /**
     * @param  array{search?: string, filters?: array<string, mixed>}  $snapshot
     */
    public static function summary(array $snapshot): string
    {
        $snapshot = self::normalizeSnapshot($snapshot);
        $search = $snapshot['search'] ?? '';
        $filters = $snapshot['filters'] ?? [];
        $parts = [];

        if ($search !== '') {
            $parts[] = 'search “'.$search.'”';
        }
        if (($filters['verified'] ?? 'all') === 'yes') {
            $parts[] = 'verified';
        } elseif (($filters['verified'] ?? 'all') === 'no') {
            $parts[] = 'unverified';
        }
        if (($filters['registered_from'] ?? '') !== '') {
            $parts[] = 'from '.$filters['registered_from'];
        }
        if (($filters['registered_to'] ?? '') !== '') {
            $parts[] = 'to '.$filters['registered_to'];
        }
        if (($filters['country'] ?? '') !== '') {
            $parts[] = (string) $filters['country'];
        }
        if (($filters['marketing'] ?? 'all') === 'opted_in') {
            $parts[] = 'not opted out';
        } elseif (($filters['marketing'] ?? 'all') === 'opted_out') {
            $parts[] = 'opted out';
        }
        if (! empty($filters['exclude_dual_role'])) {
            $parts[] = 'no dual-role';
        }

        return implode(' · ', $parts);
    }

    public static function requestHasComposeHandoff(Request $request): bool
    {
        return self::hasSendFilters(self::searchFromRequest($request), self::filtersFromRequest($request));
    }
}
