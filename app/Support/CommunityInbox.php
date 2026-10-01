<?php

namespace App\Support;

use App\Models\Site;
use App\Models\WebsiteSuggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    public static function normalizeKind(mixed $kind): string
    {
        $kind = search_text($kind);

        return in_array($kind, ['catalog', 'other'], true) ? $kind : '';
    }

    public static function normalizeSort(mixed $sort): string
    {
        return search_text($sort) === 'oldest' ? 'oldest' : 'newest';
    }

    public static function normalizeOccupying(mixed $value): string
    {
        $value = search_text($value);

        return in_array($value, ['yes', 'no'], true) ? $value : '';
    }

    public static function normalizeClaimView(mixed $value): string
    {
        $value = search_text($value);

        return in_array($value, ['mismatch', 'blocked', 'unverified'], true) ? $value : '';
    }

    /**
     * Checkbox / query flag. Arrays must not reach Request::boolean()
     * (filter_var TypeError on ?stale[]=1).
     */
    public static function normalizeStale(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(search_text($value)), ['1', 'true', 'on', 'yes'], true);
    }

    public static function applyListSort($query, string $sort): void
    {
        if ($sort === 'oldest') {
            $query->orderBy('id');

            return;
        }

        $query->latest('id');
    }

    public static function constrainStalePending($query): void
    {
        $query->where('status', 'pending')->where('created_at', '<=', now()->subHours(48));
    }

    public static function constrainProblemKind($query, string $kind): void
    {
        if ($kind === 'catalog') {
            $query->where(function ($outer) {
                $outer->where('subject', 'like', 'Catalog site:%')
                    ->orWhereRaw('TRIM(message) LIKE ?', ['Catalog listing%']);
            });

            return;
        }

        if ($kind === 'other') {
            $query->where(function ($outer) {
                $outer->where(function ($subject) {
                    $subject->whereNull('subject')
                        ->orWhere('subject', 'not like', 'Catalog site:%');
                })->where(function ($message) {
                    $message->whereNull('message')
                        ->orWhereRaw('TRIM(message) NOT LIKE ?', ['Catalog listing%']);
                });
            });
        }
    }

    public static function constrainWebsiteOccupying($query, string $occupying, string $table = 'website_suggestions'): void
    {
        if ($occupying === '' || ! self::columnExists('sites', 'domain')) {
            return;
        }

        $exists = function ($outer) use ($table) {
            $outer->selectRaw('1')
                ->from('sites')
                ->whereNotNull('sites.domain')
                ->where('sites.domain', '!=', '')
                ->whereColumn('sites.domain', $table.'.domain');
        };

        if ($occupying === 'yes') {
            $query->whereExists($exists);

            return;
        }

        $query->whereNotExists($exists);
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

    public const PAGE_URL_MAX = 255;

    /**
     * Single-line text for subjects, bells, and mail headers.
     * Newlines in user-supplied names used to split SMTP subjects.
     */
    public static function plainLine(mixed $value, string $fallback = ''): string
    {
        $text = trim(preg_replace('/[\r\n]+/', ' ', search_text($value)) ?? '');

        return $text !== '' ? $text : $fallback;
    }

    public static function validEmail(mixed $email): ?string
    {
        $email = search_text($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    public static function safeHttpUrl(mixed $url): ?string
    {
        $url = search_text($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f\s]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * Persistable http(s) page URL, or null. The reports table is varchar(255);
     * a longer Referer used to 500 the guest submit path.
     */
    public static function storedPageUrl(mixed $url): ?string
    {
        $safe = self::safeHttpUrl($url);
        if ($safe === null || strlen($safe) > self::PAGE_URL_MAX) {
            return null;
        }

        return $safe;
    }

    /**
     * AND-group of LIKE ? ESCAPE '\\' matches across the given columns.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     * @param  list<string>  $columns
     */
    public static function constrainSearch($query, array $columns, string $q, ?string $table = null): void
    {
        if ($table) {
            $columns = array_values(array_filter($columns, fn ($column) => self::columnExists($table, $column)));
        }

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

    public static function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function emptyPage(Request $request, string $pageName): LengthAwarePaginator
    {
        return (new LengthAwarePaginator([], 0, 25, 1, [
            'path' => $request->url(),
            'pageName' => $pageName,
        ]))->withQueryString();
    }

    /**
     * Positive suggestion id from a query/body value. Arrays and booleans
     * must not coerce to 1 the way `(int) ['12']` / `(int) true` do.
     */
    public static function suggestionIdFrom(mixed $raw): int
    {
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw !== '' && ctype_digit($raw)) {
                return (int) $raw;
            }
        }

        return 0;
    }

    /**
     * Query string to prefill staff site-create from a website suggestion.
     *
     * @return array{site_name?: string, site_url?: string, example_url?: string, country?: string, language?: string, suggestion_id: int}
     */
    public static function createListingQuery(WebsiteSuggestion $suggestion): array
    {
        $params = ['suggestion_id' => (int) $suggestion->id];
        $name = self::plainLine($suggestion->website_name);
        if ($name !== '') {
            $params['site_name'] = $name;
        }
        $url = self::safeHttpUrl($suggestion->website_url);
        if ($url) {
            $parts = parse_url($url);
            $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '';
            $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
            $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
            if ($scheme !== '' && $host !== '') {
                $params['site_url'] = $scheme.'://'.$host;
                if ($path !== '' && $path !== '/') {
                    $params['example_url'] = $url;
                }
            } else {
                $params['site_url'] = $url;
            }
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
     * Host used to see if a suggestion already occupies the catalog.
     * `domain` is nullable, so fall back to the URL host — a raw
     * `https://…` string is not a marketplace domain.
     */
    public static function suggestionLookupDomain(WebsiteSuggestion $suggestion): string
    {
        $raw = search_text($suggestion->domain);
        if ($raw === '' || preg_match('#^https?://#i', $raw)) {
            $url = self::safeHttpUrl($raw !== '' ? $raw : $suggestion->website_url);
            if ($url) {
                $host = parse_url($url, PHP_URL_HOST);
                $raw = is_string($host) ? $host : '';
            } elseif (preg_match('#^https?://#i', $raw)) {
                $raw = '';
            }
        }

        return $raw !== '' ? Site::normalizeMarketplaceDomain($raw) : '';
    }

    /**
     * Host of a staff-created listing. The in-memory Site after create can
     * omit `domain` until refresh; fall back to site_url.
     */
    public static function listingLookupDomain(Site $site): string
    {
        $raw = search_text($site->domain);
        if ($raw === '' || preg_match('#^https?://#i', $raw)) {
            $url = self::safeHttpUrl($raw !== '' ? $raw : $site->site_url);
            if ($url) {
                $host = parse_url($url, PHP_URL_HOST);
                $raw = is_string($host) ? $host : '';
            } elseif (preg_match('#^https?://#i', $raw)) {
                $raw = '';
            }
        }

        return $raw !== '' ? Site::normalizeMarketplaceDomain($raw) : '';
    }

    /**
     * @param  iterable<int, WebsiteSuggestion>  $suggestions
     * @return array<int, Site>
     */
    public static function occupyingSitesFor(iterable $suggestions): array
    {
        try {
            if (! Schema::hasTable('sites')) {
                return [];
            }
            DB::table('sites')->limit(1)->exists();
        } catch (\Throwable) {
            return [];
        }

        $found = [];
        $seen = [];
        foreach ($suggestions as $suggestion) {
            $domain = self::suggestionLookupDomain($suggestion);
            if ($domain === '' || array_key_exists($domain, $seen)) {
                if ($domain !== '' && ($seen[$domain] ?? null) instanceof Site) {
                    $found[$suggestion->id] = $seen[$domain];
                }

                continue;
            }
            try {
                $site = Site::findOccupyingDomain($domain);
            } catch (\Throwable) {
                $site = null;
            }
            $seen[$domain] = $site;
            if ($site) {
                $found[$suggestion->id] = $site;
            }
        }

        return $found;
    }
}
