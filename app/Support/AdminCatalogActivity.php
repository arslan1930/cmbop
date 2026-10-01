<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Catalog activity queue filters and return URLs.
 */
class AdminCatalogActivity
{
    public const SESSION_KEY = 'admin_catalog_activity_return';

    public const UNLOCK_LIMIT = 50;

    public const COPY_LIMIT = 100;

    public const COPY_ATTENTION_DAYS = 14;

    public const DAYS_DEFAULT = 7;

    public const DAYS_MIN = 1;

    public const DAYS_MAX = 90;

    /** @var list<int> */
    public const DAY_CHIPS = [1, 7, 14, 30];

    /**
     * @return array<string, string|int>
     */
    public static function indexQuery(Request $request): array
    {
        $q = search_text($request->input('q'));

        $daysRaw = filter_number($request->input('days'));
        $days = $daysRaw === null ? self::DAYS_DEFAULT : (int) $daysRaw;
        $days = max(self::DAYS_MIN, min(self::DAYS_MAX, $days));

        $copy = search_text($request->input('copy')) === 'all' ? 'all' : '';

        $userId = 0;
        if ($q === '') {
            $userId = (int) (filter_number($request->input('user')) ?? 0);
        }

        return array_filter([
            'days' => $days !== self::DAYS_DEFAULT ? $days : null,
            'copy' => $copy !== '' ? $copy : null,
            'q' => $q !== '' ? $q : null,
            'user' => $userId > 0 ? $userId : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    public static function days(Request $request): int
    {
        $daysRaw = filter_number($request->input('days'));
        $days = $daysRaw === null ? self::DAYS_DEFAULT : (int) $daysRaw;

        return max(self::DAYS_MIN, min(self::DAYS_MAX, $days));
    }

    public static function copyFilter(Request $request): string
    {
        return search_text($request->input('copy')) === 'all' ? 'all' : 'attention';
    }

    public static function search(Request $request): string
    {
        return search_text($request->input('q'));
    }

    public static function focusUserId(Request $request): int
    {
        if (self::search($request) !== '') {
            return 0;
        }

        return max(0, (int) (filter_number($request->input('user')) ?? 0));
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

        return route('admin.catalog-activity', $query);
    }

    public static function queueUrl(Request $request, int $fallbackUserId = 0): string
    {
        $stored = self::sessionReturnQuery($request);
        $storedUser = (int) ($stored['user'] ?? 0);
        $onlyADifferentPin = $stored !== []
            && array_keys($stored) === ['user']
            && $fallbackUserId > 0
            && $storedUser !== $fallbackUserId;

        if ($stored !== [] && ! $onlyADifferentPin) {
            return self::listUrl($stored);
        }

        if ($fallbackUserId > 0) {
            return self::listUrl(['user' => $fallbackUserId]);
        }

        return route('admin.catalog-activity');
    }
}
