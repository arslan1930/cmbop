<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Content Library list filters and return URLs.
 */
class AdminContentLibrary
{
    public const SESSION_KEY = 'admin_content_library_return';

    public const EXPORT_LIMIT = 2000;

    public const EXPIRING_DAYS = 14;

    /**
     * @return list<string>
     */
    public static function availabilities(): array
    {
        return ['all', 'available', 'evaluating', 'in_progress', 'needs_fix', 'completed', 'expired', 'archived'];
    }

    /**
     * @return list<string>
     */
    public static function sorts(): array
    {
        return ['latest', 'title', 'expires', 'uniqueness', 'quality'];
    }

    /**
     * @return list<string>
     */
    public static function attachments(): array
    {
        return ['order', 'none'];
    }

    /**
     * @return array<string, string|int>
     */
    public static function indexQuery(Request $request): array
    {
        $availability = strtolower(search_text($request->input('availability')));
        if ($availability === '' && search_text($request->input('status')) !== '') {
            $availability = self::availabilityFromLegacyStatus(strtolower(search_text($request->input('status'))));
        }
        if (! in_array($availability, self::availabilities(), true) || $availability === 'all') {
            $availability = '';
        }

        $language = strtolower(search_text($request->input('language')));
        if ($language === 'all') {
            $language = '';
        }
        $country = strtolower(search_text($request->input('country')));
        if ($country === 'all') {
            $country = '';
        }

        $sort = strtolower(search_text($request->input('sort')));
        if (! in_array($sort, self::sorts(), true) || $sort === 'latest') {
            $sort = '';
        }

        $attachment = strtolower(search_text($request->input('attachment')));
        if (! in_array($attachment, self::attachments(), true)) {
            $attachment = '';
        }

        $expiring = strtolower(search_text($request->input('expiring')));
        if ($expiring !== 'soon') {
            $expiring = '';
        }

        $from = search_text($request->input('from'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '';
        }
        $to = search_text($request->input('to'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }

        $userId = (int) (filter_number($request->input('user_id')) ?? 0);

        $query = array_filter([
            'availability' => $availability !== '' ? $availability : null,
            'q' => search_text($request->input('q')) ?: null,
            'advertiser' => search_text($request->input('advertiser')) ?: null,
            'user_id' => $userId > 0 ? $userId : null,
            'country' => $country !== '' ? $country : null,
            'language' => $language !== '' ? $language : null,
            'sort' => $sort !== '' ? $sort : null,
            'attachment' => $attachment !== '' ? $attachment : null,
            'expiring' => $expiring !== '' ? $expiring : null,
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $page = (int) (filter_number($request->input('page')) ?? 0);
        if ($page > 1) {
            $query['page'] = min($page, 10000);
        }

        return $query;
    }

    public static function availabilityFromLegacyStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'available',
            'pending', 'processing' => 'evaluating',
            'rejected', 'error', 'needs_improvement' => 'needs_fix',
            'expired' => 'expired',
            'archived' => 'archived',
            default => '',
        };
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

        return route('admin.content-library.index', $query);
    }
}
