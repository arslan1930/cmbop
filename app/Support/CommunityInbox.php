<?php

namespace App\Support;

use App\Models\Site;
use App\Models\WebsiteSuggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Per-tab status vocabulary for the admin Community inbox.
 *
 * Claims use approved/rejected; website suggestions use accepted;
 * problems and suggestions use resolved. A shared dropdown used to
 * mix these and made approved claims unfilterable.
 */
class CommunityInbox
{
    public const TAB_PROBLEMS = 'problems';

    public const TAB_SUGGESTIONS = 'suggestions';

    public const TAB_WEBSITES = 'websites';

    public const TAB_CLAIMS = 'claims';

    public const DEFAULT_TAB = self::TAB_PROBLEMS;

    /** @var array<string, string> */
    public const TABS = [
        self::TAB_PROBLEMS => 'Problem reports',
        self::TAB_SUGGESTIONS => 'Suggestion box',
        self::TAB_WEBSITES => 'Website suggestions',
        self::TAB_CLAIMS => 'Site claims',
    ];

    /** @var array<string, list<string>> */
    public const STATUSES = [
        self::TAB_PROBLEMS => ['pending', 'reviewed', 'resolved', 'rejected'],
        self::TAB_SUGGESTIONS => ['pending', 'reviewed', 'resolved', 'rejected'],
        self::TAB_WEBSITES => ['pending', 'reviewed', 'accepted', 'rejected'],
        self::TAB_CLAIMS => ['pending', 'approved', 'rejected'],
    ];

    public static function normalizeTab(mixed $tab): string
    {
        $tab = search_text($tab);

        return array_key_exists($tab, self::TABS) ? $tab : self::DEFAULT_TAB;
    }

    /**
     * @return list<string>
     */
    public static function statusesFor(string $tab): array
    {
        return self::STATUSES[self::normalizeTab($tab)] ?? self::STATUSES[self::DEFAULT_TAB];
    }

    public static function allowsStatus(string $tab, string $status): bool
    {
        return in_array($status, self::statusesFor($tab), true);
    }

    /**
     * Valid status for the tab, or null (meaning "all") when missing/invalid.
     */
    public static function normalizeStatus(string $tab, mixed $status): ?string
    {
        $status = search_text($status);
        if ($status === '' || ! self::allowsStatus($tab, $status)) {
            return null;
        }

        return $status;
    }

    /**
     * Query string for a tab link: keep search, keep status only when legal there.
     *
     * @return array{tab: string, q?: string, status?: string}
     */
    public static function tabQuery(string $targetTab, mixed $q = null, mixed $status = null): array
    {
        $tab = self::normalizeTab($targetTab);
        $params = ['tab' => $tab];

        $q = search_text($q);
        if ($q !== '') {
            $params['q'] = $q;
        }

        $normalized = self::normalizeStatus($tab, $status);
        if ($normalized !== null) {
            $params['status'] = $normalized;
        }

        return $params;
    }

    /**
     * First tab that still has pending items (problems → claims).
     *
     * @param  array<string, int>  $pendingCounts
     */
    public static function landingTab(array $pendingCounts): string
    {
        foreach (array_keys(self::TABS) as $tab) {
            if ((int) ($pendingCounts[$tab] ?? 0) > 0) {
                return $tab;
            }
        }

        return self::DEFAULT_TAB;
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'pending' => 'bg-warning text-dark',
            'reviewed' => 'bg-info text-dark',
            'resolved', 'accepted', 'approved' => 'bg-success',
            'rejected' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public static function safeHttpUrl(mixed $url): ?string
    {
        $url = search_text($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    /**
     * AND-group of LIKE ? ESCAPE '\\' matches across the given columns.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     * @param  list<string>  $columns
     */
    public static function constrainSearch($query, array $columns, string $q): void
    {
        if ($q === '' || $columns === []) {
            return;
        }

        $like = like_contains($q);
        $query->where(function ($inner) use ($columns, $like) {
            foreach ($columns as $i => $column) {
                $sql = $column.' LIKE ? ESCAPE ?';
                if ($i === 0) {
                    $inner->whereRaw($sql, [$like, '\\']);
                } else {
                    $inner->orWhereRaw($sql, [$like, '\\']);
                }
            }
        });
    }

    public static function emptyPage(Request $request, string $pageName): LengthAwarePaginator
    {
        return (new LengthAwarePaginator([], 0, 25, 1, [
            'path' => $request->url(),
            'pageName' => $pageName,
        ]))->withQueryString();
    }

    /**
     * Query string to prefill staff site-create from a website suggestion.
     *
     * @return array{site_name?: string, site_url?: string, country?: string, language?: string, suggestion_id: int}
     */
    public static function createListingQuery(WebsiteSuggestion $suggestion): array
    {
        $params = ['suggestion_id' => (int) $suggestion->id];
        $name = search_text($suggestion->website_name);
        if ($name !== '') {
            $params['site_name'] = $name;
        }
        $url = self::safeHttpUrl($suggestion->website_url);
        if ($url) {
            $params['site_url'] = $url;
        }
        $country = strtolower(search_text($suggestion->country));
        if (strlen($country) === 2) {
            $params['country'] = $country;
        }
        $language = strtolower(search_text($suggestion->language));
        if (strlen($language) === 2) {
            $params['language'] = $language;
        }

        return $params;
    }

    /**
     * @param  iterable<int, WebsiteSuggestion>  $suggestions
     * @return array<int, Site>
     */
    public static function occupyingSitesFor(iterable $suggestions): array
    {
        $found = [];
        $seen = [];
        foreach ($suggestions as $suggestion) {
            $domain = search_text($suggestion->domain ?: $suggestion->website_url);
            if ($domain === '' || isset($seen[$domain])) {
                if ($domain !== '' && isset($seen[$domain]) && $seen[$domain] instanceof Site) {
                    $found[$suggestion->id] = $seen[$domain];
                }

                continue;
            }
            $site = Site::findOccupyingDomain($domain);
            $seen[$domain] = $site;
            if ($site) {
                $found[$suggestion->id] = $site;
            }
        }

        return $found;
    }
}
