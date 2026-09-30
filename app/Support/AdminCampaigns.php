<?php

namespace App\Support;

use App\Models\EmailCampaign;
use Illuminate\Http\Request;

/**
 * Admin Campaigns list filters and return URL.
 */
class AdminCampaigns
{
    public const SESSION_KEY = 'admin_campaigns_return';

    /**
     * @return list<string>
     */
    public static function listStatuses(): array
    {
        return [
            'attention',
            EmailCampaign::STATUS_DRAFT,
            EmailCampaign::STATUS_QUEUED,
            EmailCampaign::STATUS_SENDING,
            EmailCampaign::STATUS_FAILED,
            EmailCampaign::STATUS_SENT,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    public static function indexQuery(Request $request): array
    {
        $status = search_text($request->input('status'));
        if (! in_array($status, self::listStatuses(), true)) {
            $status = '';
        }

        $query = array_filter([
            'status' => $status !== '' ? $status : null,
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
        if (self::isComposeOnly($request)) {
            return self::storedReturnQuery($request);
        }

        $query = self::indexQuery($request);
        try {
            $request->session()->put(self::SESSION_KEY, $query);
        } catch (\Throwable) {
        }

        return $query;
    }

    /**
     * ?draft= / ?audience= only must not wipe list Back (status + page).
     */
    public static function isComposeOnly(Request $request): bool
    {
        if (self::indexQuery($request) !== []) {
            return false;
        }

        $draft = (int) (filter_number($request->input('draft')) ?? 0);
        $audience = search_text($request->input('audience'));
        $template = search_text($request->input('template'));
        $subject = search_text($request->input('subject'));
        $body = search_text($request->input('body_html'));

        return $draft > 0
            || $audience !== ''
            || $template !== ''
            || $subject !== ''
            || $body !== ''
            || AdminAudiences::requestHasComposeHandoff($request);
    }

    /**
     * @return array<string, string|int>
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

        return self::sessionReturnQuery($request);
    }

    /**
     * Show uses ?status= / ?page= for recipients — never treat those as the list.
     *
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
        $query = is_array($query) ? self::indexQuery(Request::create('/', 'GET', $query)) : [];

        return route('admin.campaigns.index', $query);
    }
}
