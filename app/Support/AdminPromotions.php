<?php

namespace App\Support;

use App\Services\PromotionListQuery;
use Illuminate\Http\Request;

/**
 * Promotions announcement/banner list filters and return URLs.
 */
class AdminPromotions
{
    public const SESSION_ANNOUNCEMENTS = 'admin_promotions_announcements_return';

    public const SESSION_BANNERS = 'admin_promotions_banners_return';

    /**
     * @return array<string, string|int>
     */
    public static function indexQuery(Request $request, string $kind): array
    {
        $status = search_text($request->input('status'));
        if (! in_array($status, PromotionListQuery::statuses(), true) || $status === 'all') {
            $status = '';
        }

        $audience = search_text($request->input('audience'));
        $audiences = array_keys(config('promotions.audiences', []));
        if (! in_array($audience, $audiences, true)) {
            $audience = '';
        }

        $query = array_filter([
            'status' => $status !== '' ? $status : null,
            'audience' => $audience !== '' ? $audience : null,
            'q' => search_text($request->input('q')) ?: null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($kind === 'announcements') {
            $type = search_text($request->input('type'));
            if ($type !== '' && array_key_exists($type, config('promotions.announcement_types', []))) {
                $query['type'] = $type;
            }
        }

        if ($kind === 'banners') {
            $placement = search_text($request->input('placement'));
            if ($placement !== '' && array_key_exists($placement, config('promotions.banner_placements', []))) {
                $query['placement'] = $placement;
            }
        }

        $page = (int) (filter_number($request->input('page')) ?? 0);
        if ($page > 1) {
            $query['page'] = min($page, 10000);
        }

        return $query;
    }

    /**
     * @return array<string, string|int>
     */
    public static function rememberReturnQuery(Request $request, string $kind): array
    {
        $query = self::indexQuery($request, $kind);
        try {
            $request->session()->put(self::sessionKey($kind), $query);
        } catch (\Throwable) {
        }

        return $query;
    }

    /**
     * @return array<string, string|int>
     */
    public static function sessionReturnQuery(Request $request, string $kind): array
    {
        try {
            $stored = $request->session()->get(self::sessionKey($kind));
        } catch (\Throwable) {
            return [];
        }
        if (! is_array($stored)) {
            return [];
        }

        return self::indexQuery(Request::create('/', 'GET', $stored), $kind);
    }

    public static function listUrl(string $kind, mixed $query = []): string
    {
        $query = is_array($query)
            ? self::indexQuery(Request::create('/', 'GET', $query), $kind)
            : [];

        $name = $kind === 'banners'
            ? staff_route_prefix().'promotions.banners.index'
            : staff_route_prefix().'promotions.announcements.index';

        return route($name, $query);
    }

    private static function sessionKey(string $kind): string
    {
        return $kind === 'banners' ? self::SESSION_BANNERS : self::SESSION_ANNOUNCEMENTS;
    }
}
