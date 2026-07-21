<?php

namespace App\Services\ContentModeration;

/**
 * Contextual policy scorer — keywords + phrases + domains + intent co-occurrence.
 */
class ContentModerationEngine
{
    /**
     * @param  array<string, mixed>  $categories
     * @param  array<int, string|array{url?:string,anchor?:string}>  $links
     * @param  array<int, string>  $extraKeywords
     * @param  array<int, string>  $exceptions
     * @return array{
     *   scores: array<string,int>,
     *   max_confidence:int,
     *   detected_category:?string,
     *   signals:array,
     *   matched_terms: array<int, string>,
     *   blocked_urls: array<int, string>
     * }
     */
    public function score(
        string $title,
        string $text,
        array $links,
        array $categories,
        array $extraKeywords = [],
        array $exceptions = [],
    ): array {
        $haystack = mb_strtolower($title."\n".$text);
        $haystack = $this->applyExceptions($haystack, $exceptions);
        $urlStrings = $this->normalizeLinkList($links);
        $linkHosts = array_map(fn (string $u) => $this->hostForMatch($u), $urlStrings);
        $linkBlob = mb_strtolower(implode(' ', array_merge($urlStrings, $linkHosts)));

        $scores = [];
        $signals = ['hits' => []];
        $allMatched = [];
        $allBlockedUrls = [];

        foreach ($categories as $key => $cat) {
            if (empty($cat['enabled'])) {
                continue;
            }

            $points = 0.0;
            $hits = 0;
            $matched = [];
            $blockedUrls = [];
            $domainHit = false;

            $keywords = $this->mergedKeywords($cat);

            foreach ($keywords as $kw) {
                $kw = mb_strtolower(trim((string) $kw));
                if ($kw === '') {
                    continue;
                }
                $count = $this->countTerm($haystack, $kw);
                if ($count > 0) {
                    $points += min(35, 12 + ($count - 1) * 6);
                    $hits += $count;
                    $matched[] = $kw;
                }
            }

            foreach ($cat['intent_phrases'] ?? [] as $phrase) {
                $phrase = mb_strtolower(trim((string) $phrase));
                if ($phrase !== '' && str_contains($haystack, $phrase)) {
                    $points += 22;
                    $hits++;
                    $matched[] = $phrase;
                }
            }

            foreach ($cat['domains'] ?? [] as $domain) {
                $domain = mb_strtolower(trim((string) $domain));
                if ($domain === '') {
                    continue;
                }

                $urlsForDomain = $this->urlsMatchingDomain($urlStrings, $domain);
                if ($urlsForDomain !== []) {
                    // One blocked destination is enough to fail policy.
                    $points += 80;
                    $hits++;
                    $domainHit = true;
                    $matched[] = $domain;
                    $blockedUrls = array_merge($blockedUrls, $urlsForDomain);
                } elseif (str_contains($haystack, $domain) || str_contains($linkBlob, $domain)) {
                    $points += 28;
                    $hits++;
                    $matched[] = $domain;
                }
            }

            foreach ($extraKeywords as $extra) {
                $extra = mb_strtolower(trim((string) $extra));
                if ($extra !== '' && str_contains($haystack, $extra)) {
                    $points += 10;
                    $hits++;
                    $matched[] = $extra;
                }
            }

            if ($hits >= 3) {
                $points *= 1.25;
            } elseif ($hits >= 2) {
                $points *= 1.12;
            }

            $weight = (float) ($cat['weight'] ?? 1.0);
            $confidence = (int) min(99, round($points * $weight));

            // Soften weak single keyword hits, but never soft-pedal domain blocks.
            if (! $domainHit && $hits === 1 && $confidence < 40) {
                $confidence = (int) round($confidence * 0.55);
            }

            $scores[$key] = $confidence;
            $matched = array_values(array_unique($matched));
            $blockedUrls = array_values(array_unique($blockedUrls));
            if ($confidence > 0) {
                $signals['hits'][$key] = [
                    'term_hits' => $hits,
                    'confidence' => $confidence,
                    'matched_terms' => $matched,
                    'blocked_urls' => $blockedUrls,
                ];
                $allMatched = array_merge($allMatched, $matched);
                $allBlockedUrls = array_merge($allBlockedUrls, $blockedUrls);
            }
        }

        arsort($scores);
        $detected = null;
        $max = 0;
        foreach ($scores as $key => $conf) {
            if ($conf > $max) {
                $max = $conf;
                $detected = $key;
            }
        }

        $allBlockedUrls = array_values(array_unique($allBlockedUrls));
        $signals['blocked_urls'] = $allBlockedUrls;

        return [
            'scores' => $scores,
            'max_confidence' => $max,
            'detected_category' => $max > 0 ? $detected : null,
            'signals' => $signals,
            'matched_terms' => array_values(array_unique($allMatched)),
            'blocked_urls' => $allBlockedUrls,
        ];
    }

    /**
     * @param  array<int, string|array{url?:string,anchor?:string}|mixed>  $links
     * @return list<string>
     */
    public function normalizeLinkList(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (is_string($link)) {
                $url = trim($link);
            } elseif (is_array($link)) {
                $url = trim((string) ($link['url'] ?? ''));
            } else {
                continue;
            }
            if ($url === '') {
                continue;
            }
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }
            $out[] = $url;
        }

        return array_values(array_unique($out));
    }

    public function hostForMatch(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            // Bare domain / path fragments
            $host = preg_replace('#^(https?:)?//#i', '', $url) ?? $url;
            $host = explode('/', $host)[0] ?? $host;
        }

        $host = mb_strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    protected function urlsMatchingDomain(array $urls, string $domain): array
    {
        $domain = mb_strtolower(trim($domain));
        $matched = [];
        foreach ($urls as $url) {
            $host = $this->hostForMatch($url);
            $hay = mb_strtolower($url);
            if ($host !== '' && (str_contains($host, $domain) || str_ends_with($host, '.'.$domain))) {
                $matched[] = $url;

                continue;
            }
            if (str_contains($hay, $domain)) {
                $matched[] = $url;
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * @param  array<string, mixed>  $cat
     * @return list<string>
     */
    public function mergedKeywords(array $cat): array
    {
        $keywords = array_map('strval', $cat['keywords'] ?? []);
        $byLocale = $cat['keywords_by_locale'] ?? [];
        if (is_array($byLocale)) {
            foreach ($byLocale as $list) {
                if (! is_array($list)) {
                    continue;
                }
                foreach ($list as $kw) {
                    $keywords[] = (string) $kw;
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($k) => trim((string) $k),
            $keywords
        ), static fn ($k) => $k !== '')));
    }

    protected function countTerm(string $haystack, string $term): int
    {
        if (str_contains($term, ' ')) {
            return substr_count($haystack, $term);
        }

        return preg_match_all('/\b'.preg_quote($term, '/').'\b/u', $haystack) ?: 0;
    }

    /**
     * @param  array<int, string|array>  $exceptions
     */
    protected function applyExceptions(string $haystack, array $exceptions): string
    {
        foreach ($exceptions as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $haystack = str_ireplace($value, ' ', $haystack);
            } elseif (is_string($key) && is_array($value)) {
                foreach ($value as $phrase) {
                    $haystack = str_ireplace((string) $phrase, ' ', $haystack);
                }
            }
        }

        return $haystack;
    }
}
