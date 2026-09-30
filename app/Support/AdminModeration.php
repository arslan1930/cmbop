<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Moderation log filters and return URLs.
 */
class AdminModeration
{
    public const SESSION_KEY = 'admin_moderation_return';

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return ['all', 'needs', 'approved', 'rejected', 'error', 'skipped', 'overridden'];
    }

    /**
     * @return array<string, string|int>
     */
    public static function indexQuery(Request $request): array
    {
        $status = strtolower(search_text($request->input('status')));
        if (! in_array($status, self::statuses(), true) || $status === 'all') {
            $status = '';
        }

        $category = strtolower(search_text($request->input('category')));
        $known = array_merge(array_keys(config('content_moderation.categories', [])), ['custom']);
        if ($category === 'all' || ! in_array($category, $known, true)) {
            $category = '';
        }

        $from = search_text($request->input('from'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '';
        }
        $to = search_text($request->input('to'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }

        $query = array_filter([
            'status' => $status !== '' ? $status : null,
            'category' => $category !== '' ? $category : null,
            'q' => search_text($request->input('q')) ?: null,
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $page = (int) (filter_number($request->input('page')) ?? 0);
        if ($page > 1) {
            $query['page'] = min($page, 10000);
        }

        return $query;
    }

    /**
     * @return array<string, string|int>
     */
    public static function rememberReturnQuery(Request $request): array
    {
        $query = self::indexQuery($request);
        try {
            $request->session()->put(self::SESSION_KEY, $query);
        } catch (\Throwable) {
        }

        return $query;
    }

    /**
     * @return array<string, string|int>
     */
    public static function sessionReturnQuery(Request $request): array
    {
        try {
            $stored = $request->session()->get(self::SESSION_KEY);
        } catch (\Throwable) {
            return [];
        }
        if (! is_array($stored)) {
            return [];
        }

        return self::indexQuery(Request::create('/', 'GET', $stored));
    }

    public static function listUrl(mixed $query = []): string
    {
        $query = is_array($query)
            ? self::indexQuery(Request::create('/', 'GET', $query))
            : [];

        return route('admin.moderation.index', $query);
    }
}
