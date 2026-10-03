<?php

namespace App\Services\Catalog;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Advertiser catalog free-text search: metric tokens → range filters,
 * word-AND name/category/domain matching, and relevance ordering.
 */
class CatalogSearchQuery
{
    /** Below this, only prefix-match site names (no category substring noise). */
    public const MIN_CONTAINS_LENGTH = 3;

    /** Shared-hosting cap: one request cannot AND dozens of LIKE groups. */
    public const MAX_SEARCH_CHARS = 120;

    public const MAX_TOKENS = 8;

    /**
     * Split raw search into leftover text + DA/DR/traffic/price ranges.
     *
     * Supported metric tokens (case-insensitive):
     * - da>40, dr>=50, traffic<10k, price<=100, da:30
     * - da 40+, traffic 5k+
     * - price 50-200, da 30–60
     *
     * Explicit range inputs on the form always win over parsed tokens.
     *
     * @return array{text: string, ranges: array<string, int>}
     */
    public function parse(string $raw): array
    {
        $text = $this->limitSearchText($raw);
        $ranges = [];

        if ($text === '') {
            return ['text' => '', 'ranges' => []];
        }

        // metric 30-60 / metric 5k-10k
        $text = preg_replace_callback(
            '/\b(da|dr|traffic|price)\s+(\d+(?:\.\d+)?)([km])?\s*[-–]\s*(\d+(?:\.\d+)?)([km])?\b/iu',
            function (array $m) use (&$ranges): string {
                $metric = strtolower($m[1]);
                $min = $this->metricNumber($m[2], $m[3] ?? null);
                $max = $this->metricNumber($m[4], $m[5] ?? null);
                if ($min !== null) {
                    $ranges[$metric.'_min'] = $min;
                }
                if ($max !== null) {
                    $ranges[$metric.'_max'] = $max;
                }

                return ' ';
            },
            $text
        ) ?? $text;

        // metric 40+ / metric 10k+
        $text = preg_replace_callback(
            '/\b(da|dr|traffic|price)\s+(\d+(?:\.\d+)?)([km])?\s*\+/iu',
            function (array $m) use (&$ranges): string {
                $metric = strtolower($m[1]);
                $min = $this->metricNumber($m[2], $m[3] ?? null);
                if ($min !== null) {
                    $ranges[$metric.'_min'] = $min;
                }

                return ' ';
            },
            $text
        ) ?? $text;

        // metric>40, metric>=40, metric:40, metric=40, …
        $text = preg_replace_callback(
            '/\b(da|dr|traffic|price)\s*(>=|<=|>|<|=|:)\s*(\d+(?:\.\d+)?)([km])?\b/iu',
            function (array $m) use (&$ranges): string {
                $metric = strtolower($m[1]);
                $op = $m[2] === ':' ? '=' : $m[2];
                $value = $this->metricNumber($m[3], $m[4] ?? null);
                if ($value === null) {
                    return ' ';
                }

                if ($op === '>' || $op === '>=') {
                    $ranges[$metric.'_min'] = $op === '>' ? $value + 1 : $value;
                } elseif ($op === '<' || $op === '<=') {
                    $ranges[$metric.'_max'] = $op === '<' ? max(0, $value - 1) : $value;
                } else { // = or :
                    $ranges[$metric.'_min'] = $value;
                    $ranges[$metric.'_max'] = $value;
                }

                return ' ';
            },
            $text
        ) ?? $text;

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return ['text' => $text, 'ranges' => $ranges];
    }

    /**
     * Merge parsed ranges into the request when the form fields are empty.
     *
     * @param  array<string, int>  $ranges
     * @return array<string, int|string>
     */
    public function mergeIntoRequestInput(string $originalSearch, string $text, array $ranges, array $input): array
    {
        $merge = [];

        if ($text !== $originalSearch) {
            $merge['search'] = $text;
        }

        foreach ($ranges as $key => $value) {
            $existing = $input[$key] ?? null;
            if ($existing === null || $existing === '') {
                // Strings so Blade search_text/scalar_text and query URLs stay honest.
                $merge[$key] = (string) $value;
            }
        }

        return $merge;
    }

    /**
     * Split leftover search text into match tokens (order-independent AND).
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $text = $this->limitSearchText($text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/[\s,;|]+/u', $text) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $part = trim($part);
            // Keep host-looking tokens intact; strip wrapping punctuation on words.
            if (str_contains($part, '.')) {
                $part = trim($part, " \t\"'`()");
            } else {
                $part = trim($part, " \t\"'`()[]{}");
            }

            if ($part === '' || $this->likeLiteral($part) === '') {
                continue;
            }

            $tokens[] = $part;
        }

        return array_slice(array_values(array_unique($tokens)), 0, self::MAX_TOKENS);
    }

    /**
     * Constrain the site query to name / category / domain matches.
     *
     * Multi-word queries are word-AND: every token must match name, category, or
     * domain (any field). Word order and exact spacing do not matter.
     *
     * Domain/URL matching is open for all advertisers (`$searchAllDomains`
     * true from the catalog). Display masking is separate: hide-mode rows
     * still paint masked name/URL until the eye.
     *
     * @param  Collection<int, int|string>  $searchableUrlIds  Legacy allow-list
     *                                                         when `$searchAllDomains` is false.
     * @param  bool  $searchAllDomains  When true (catalog default), domain/URL
     *                                  matches are not limited to revealed rows.
     */
    public function applyTextConstraints(
        Builder $query,
        string $text,
        Collection $searchableUrlIds,
        ?string $hostNeedle = null,
        bool $searchAllDomains = true,
    ): void {
        $text = $this->limitSearchText($text);
        if ($text === '') {
            return;
        }

        $tokens = $this->tokens($text);
        if ($tokens === []) {
            return;
        }

        // Single short token: prefix on the listing name only (+ host-like domain).
        if (count($tokens) === 1 && mb_strlen($tokens[0]) < self::MIN_CONTAINS_LENGTH) {
            $token = $tokens[0];
            $like = $this->likeLiteral($token);
            $query->where(function (Builder $q) use ($like, $token, $hostNeedle, $searchableUrlIds, $searchAllDomains) {
                $this->addLike($q, 'site_name', $like.'%');
                $this->constrainDomainNeedles(
                    $q,
                    array_values(array_unique(array_filter([$token, $hostNeedle]))),
                    $searchableUrlIds,
                    allowContains: str_contains($token, '.'),
                    searchAllDomains: $searchAllDomains,
                );
            });

            return;
        }

        $query->where(function (Builder $outer) use ($tokens, $text, $hostNeedle, $searchableUrlIds, $searchAllDomains) {
            // Every token must hit name OR category OR domain.
            foreach ($tokens as $token) {
                $outer->where(function (Builder $tokenQ) use ($token, $searchableUrlIds, $searchAllDomains) {
                    $this->constrainTokenAcrossFields($tokenQ, $token, $searchableUrlIds, $searchAllDomains);
                });
            }

            // Also accept a contiguous phrase / pasted URL on domain columns even
            // when word-AND would miss (e.g. hyphenated hosts).
            $phraseNeedles = array_values(array_unique(array_filter([$text, $hostNeedle])));
            if (count($tokens) > 1 || ($hostNeedle !== null && $hostNeedle !== '')) {
                $this->constrainDomainNeedles(
                    $outer,
                    $phraseNeedles,
                    $searchableUrlIds,
                    allowContains: true,
                    searchAllDomains: $searchAllDomains,
                    boolean: 'or',
                );
            }
        });
    }

    /**
     * Prefer exact / prefix name hits over loose category matches.
     */
    public function applyRelevanceOrder(Builder $query, string $text): void
    {
        $text = $this->limitSearchText($text);
        if ($text === '') {
            return;
        }

        $like = $this->likeLiteral($text);
        $lower = mb_strtolower($text);
        $tokens = $this->tokens($text);
        $esc = '\\';

        $bindings = [
            $lower,
            $like.'%', $esc,
            '% '.$like.'%', $esc,
            '%'.$like.'%', $esc,
        ];

        // All tokens appear in the name (order-independent) — between phrase and domain.
        $tokenNameSql = '0';
        if (count($tokens) >= 2) {
            $parts = [];
            foreach ($tokens as $token) {
                $parts[] = 'LOWER(site_name) LIKE ? ESCAPE ?';
                $bindings[] = '%'.$this->likeLiteral(mb_strtolower($token)).'%';
                $bindings[] = $esc;
            }
            $tokenNameSql = '('.implode(' AND ', $parts).')';
        }

        $bindings = array_merge($bindings, [
            '%'.$like.'%', $esc,
            '%'.$like.'%', $esc,
            $lower,
            $like.'%', $esc,
            '%,'.$like.'%', $esc,
        ]);

        $query->orderByRaw(
            "CASE
                WHEN LOWER(site_name) = ? THEN 0
                WHEN LOWER(site_name) LIKE ? ESCAPE ? THEN 1
                WHEN LOWER(site_name) LIKE ? ESCAPE ? THEN 2
                WHEN LOWER(site_name) LIKE ? ESCAPE ? THEN 3
                WHEN {$tokenNameSql} THEN 4
                WHEN LOWER(domain) LIKE ? ESCAPE ? OR LOWER(site_url) LIKE ? ESCAPE ? THEN 5
                WHEN LOWER(category) = ? OR LOWER(category) LIKE ? ESCAPE ? OR LOWER(category) LIKE ? ESCAPE ? THEN 6
                ELSE 7
            END ASC",
            $bindings
        );
    }

    /**
     * One token may match name, category niche, or domain/URL.
     */
    private function constrainTokenAcrossFields(
        Builder $q,
        string $token,
        Collection $searchableUrlIds,
        bool $searchAllDomains,
    ): void {
        $like = $this->likeLiteral($token);
        $allowContains = mb_strlen($token) >= self::MIN_CONTAINS_LENGTH || str_contains($token, '.');

        $q->where(function (Builder $inner) use ($like, $token, $allowContains, $searchableUrlIds, $searchAllDomains) {
            if ($allowContains) {
                $inner->where(function (Builder $nameQ) use ($like) {
                    $this->addLike($nameQ, 'site_name', $like.'%');
                    $this->addLike($nameQ, 'site_name', '% '.$like.'%', 'or');
                    $this->addLike($nameQ, 'site_name', '%'.$like.'%', 'or');
                });
                $inner->orWhere(function (Builder $catQ) use ($like) {
                    $this->constrainCategoryNeedle($catQ, $like);
                });
            } else {
                $this->addLike($inner, 'site_name', $like.'%');
            }

            $this->constrainDomainNeedles(
                $inner,
                [$token],
                $searchableUrlIds,
                allowContains: $allowContains,
                searchAllDomains: $searchAllDomains,
                boolean: 'or',
            );
        });
    }

    /**
     * Match legacy `category` and JSON `categories` without mid-token false hits.
     *
     * Patterns anchor on separators (",", " ", "&", quotes) so "art" does not
     * match "marketing", while "Crypto" still hits "Crypto & Web3".
     */
    private function constrainCategoryNeedle(Builder $catQ, string $like): void
    {
        $hasCategoriesJson = Site::hasSitesColumn('categories');

        $catQ->where(function (Builder $q) use ($like, $hasCategoriesJson) {
            $this->addLike($q, 'category', $like);
            $this->addLike($q, 'category', $like.'%', 'or');
            $this->addLike($q, 'category', '%,'.$like.'%', 'or');
            $this->addLike($q, 'category', '%, '.$like.'%', 'or');
            $this->addLike($q, 'category', '% '.$like.'%', 'or');
            $this->addLike($q, 'category', '%& '.$like.'%', 'or');
            $this->addLike($q, 'category', $like.' %', 'or');
            $this->addLike($q, 'category', $like.'&%', 'or');

            if (! $hasCategoriesJson) {
                return;
            }

            // JSON string values: "Niche Name" — require a boundary before/after.
            $this->addLike($q, 'categories', '%"'.$like.'"%', 'or');
            $this->addLike($q, 'categories', '%"'.$like.' %', 'or');
            $this->addLike($q, 'categories', '%"'.$like.'&%', 'or');
            $this->addLike($q, 'categories', '% '.$like.'"%', 'or');
            $this->addLike($q, 'categories', '%& '.$like.'"%', 'or');
            $this->addLike($q, 'categories', '% '.$like.' %', 'or');
            $this->addLike($q, 'categories', '% '.$like.'&%', 'or');
            $this->addLike($q, 'categories', '%& '.$like.' %', 'or');
            $this->addLike($q, 'categories', '%&'.$like.'"%', 'or');
        });
    }

    /**
     * @param  list<string>  $needles
     */
    private function constrainDomainNeedles(
        Builder $q,
        array $needles,
        Collection $searchableUrlIds,
        bool $allowContains,
        bool $searchAllDomains,
        string $boolean = 'or',
    ): void {
        $needles = array_values(array_unique(array_filter($needles)));
        if ($needles === []) {
            return;
        }

        if (! $searchAllDomains && $searchableUrlIds->isEmpty()) {
            return;
        }

        $method = $boolean === 'and' ? 'where' : 'orWhere';

        $q->{$method}(function (Builder $inner) use ($needles, $searchableUrlIds, $allowContains, $searchAllDomains) {
            if (! $searchAllDomains) {
                $inner->whereIn('id', $searchableUrlIds->all());
            }

            $inner->where(function (Builder $urlQ) use ($needles, $allowContains) {
                foreach ($needles as $needle) {
                    $escaped = $this->likeLiteral($needle);
                    if ($escaped === '') {
                        continue;
                    }
                    if ($allowContains || str_contains($needle, '.')) {
                        $this->addLike($urlQ, 'site_url', '%'.$escaped.'%', 'or');
                        $this->addLike($urlQ, 'domain', '%'.$escaped.'%', 'or');
                    } else {
                        $this->addLike($urlQ, 'domain', $escaped.'%', 'or');
                        $this->addLike($urlQ, 'site_url', '%'.$escaped.'%', 'or');
                    }
                }
            });
        });
    }

    private function metricNumber(string $number, ?string $suffix): ?int
    {
        if (! is_numeric($number)) {
            return null;
        }

        $value = (float) $number;
        $suffix = strtolower((string) $suffix);

        if ($suffix === 'k') {
            $value *= 1000;
        } elseif ($suffix === 'm') {
            $value *= 1000000;
        }

        if ($value < 0) {
            return null;
        }

        return (int) round($value);
    }

    /**
     * LIKE literal: %, _, and \ match themselves (pair with ESCAPE '\\').
     */
    private function likeLiteral(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function limitSearchText(string $raw): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > self::MAX_SEARCH_CHARS) {
            $text = mb_substr($text, 0, self::MAX_SEARCH_CHARS);
        }

        return $text;
    }

    private function addLike(Builder $q, string $column, string $pattern, string $boolean = 'and'): void
    {
        $sql = $column.' LIKE ? ESCAPE ?';
        if ($boolean === 'or') {
            $q->orWhereRaw($sql, [$pattern, '\\']);

            return;
        }

        $q->whereRaw($sql, [$pattern, '\\']);
    }
}
