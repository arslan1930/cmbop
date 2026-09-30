<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Admin Sites list query, including the page staff were on.
 */
class AdminSites
{
    public const SESSION_KEY = 'admin_sites_return';

    /**
     * @return array<string, string|int|float>
     */
    public static function indexQuery(Request $request): array
    {
        $listMode = self::flag($request, 'all')
            || self::flag($request, 'flat')
            || self::flag($request, 'needs_review')
            || self::flag($request, 'waiting_on_publisher');

        $query = array_filter([
            'q' => ($q = search_text($request->input('q'))) !== '' ? $q : null,
            'all' => self::flag($request, 'all') ? 1 : null,
            'flat' => self::flag($request, 'flat') ? 1 : null,
            'needs_review' => self::flag($request, 'needs_review') ? 1 : null,
            'waiting_on_publisher' => self::flag($request, 'waiting_on_publisher') ? 1 : null,
            'waiting_stage' => ($stage = search_text($request->input('waiting_stage'))) !== '' ? $stage : null,
            'tag' => ($tag = search_text($request->input('tag'))) !== '' ? $tag : null,
            'country' => self::code($request->input('country')),
            'language' => self::code($request->input('language')),
            'niche' => ($niche = search_text($request->input('niche'))) !== '' ? $niche : null,
            'listing_active' => self::zeroOne($request->input('listing_active')),
            'listing_verified' => self::zeroOne($request->input('listing_verified')),
            'below_quality' => self::flag($request, 'below_quality') ? 1 : null,
            'ready_to_activate' => self::flag($request, 'ready_to_activate') ? 1 : null,
            'missing_market' => self::flag($request, 'missing_market') ? 1 : null,
            'placeholder' => self::flag($request, 'placeholder') ? 1 : null,
            'missing_cover' => self::flag($request, 'missing_cover') ? 1 : null,
            'bulk_request' => self::flag($request, 'bulk_request') ? 1 : null,
            'scan_failed' => self::flag($request, 'scan_failed') ? 1 : null,
            'copy_strike' => self::flag($request, 'copy_strike') ? 1 : null,
            'has_orders' => self::flag($request, 'has_orders') ? 1 : null,
            'featured' => self::flag($request, 'featured') ? 1 : null,
            'bulk_discount' => self::flag($request, 'bulk_discount') ? 1 : null,
            'csv_metrics' => self::flag($request, 'csv_metrics') ? 1 : null,
            'price_min' => self::decimal($request->input('price_min')),
            'price_max' => self::decimal($request->input('price_max')),
            'traffic_min' => self::uint($request->input('traffic_min')),
            'da_min' => self::uint($request->input('da_min')),
            'metrics_age' => self::metricsAge($request->input('metrics_age')),
            'archived' => self::flag($request, 'archived') ? 1 : null,
            'sort' => self::sort($request->input('sort')),
        ], static fn ($value) => $value !== null && $value !== '');

        $page = (int) (filter_number($request->input('page')) ?? 0);
        if ($page > 1) {
            $query['page'] = $page;
        }

        $perPage = (int) (filter_number($request->input('per_page')) ?? 0);
        if (in_array($perPage, [20, 50, 100], true)) {
            $query['per_page'] = $perPage;
        }

        if (! $listMode) {
            $publisher = (int) (filter_number($request->input('publisher')) ?? 0);
            if ($publisher > 0) {
                $query['publisher'] = $publisher;
                unset($query['page']);
                $sitesPage = (int) (filter_number($request->input('sites_page')) ?? 0);
                if ($sitesPage > 1) {
                    $query['sites_page'] = $sitesPage;
                }
            }
        }

        return $query;
    }

    /**
     * @return array<string, string|int|float>
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
     * @return array<string, string|int|float>
     */
    public static function storedReturnQuery(Request $request): array
    {
        $fromRequest = self::indexQuery($request);
        if ($fromRequest !== []) {
            try {
                $request->session()->put(self::SESSION_KEY, $fromRequest);
            } catch (\Throwable) {
            }

            return $fromRequest;
        }

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
        $query = is_array($query) ? self::indexQuery(Request::create('/', 'GET', $query)) : [];

        return staff_route('sites.index', $query);
    }

    private static function flag(Request $request, string $key): bool
    {
        $value = $request->input($key);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(search_text($value)), ['1', 'true', 'on', 'yes'], true);
    }

    private static function zeroOne(mixed $value): ?string
    {
        $text = search_text($value);

        return in_array($text, ['0', '1'], true) ? $text : null;
    }

    private static function code(mixed $value): ?string
    {
        $text = strtolower(search_text($value));
        if ($text === '' || $text === 'all' || strlen($text) !== 2) {
            return null;
        }

        return $text;
    }

    private static function metricsAge(mixed $value): ?string
    {
        $text = search_text($value);

        return in_array($text, ['30', '90', 'never'], true) ? $text : null;
    }

    private static function sort(mixed $value): ?string
    {
        $text = search_text($value);

        return in_array($text, ['newest', 'oldest', 'price', 'traffic', 'da'], true) ? $text : null;
    }

    private static function decimal(mixed $value): ?float
    {
        $text = trim(scalar_text($value));
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        return round((float) $text, 2);
    }

    private static function uint(mixed $value): ?int
    {
        $text = trim(scalar_text($value));
        if ($text === '' || ! ctype_digit($text)) {
            return null;
        }

        return (int) $text;
    }
}
