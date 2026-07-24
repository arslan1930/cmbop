<?php

namespace App\Services\ContentModeration;

/**
 * Contextual policy scorer — keywords + phrases + domains + intent co-occurrence.
 *
 * Policy stance: clear restricted keywords, intent phrases, and gambling/adult
 * domains (in hrefs or body text) must fail the confidence threshold. Soft scoring
 * previously let single prohibited terms through — that is intentionally strict now.
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
        $rawHaystack = mb_strtolower($title."\n".$text);
        $haystack = $this->applyExceptions($this->deobfuscate($rawHaystack), $exceptions);
        $urlStrings = $this->normalizeLinkList($links);
        $urlStrings = $this->enrichLinksFromContent($urlStrings, $haystack, $categories);
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
            $hardHit = false;

            $keywords = $this->mergedKeywords($cat);

            foreach ($keywords as $kw) {
                $kw = mb_strtolower(trim((string) $kw));
                if ($kw === '') {
                    continue;
                }
                $count = $this->countTerm($haystack, $kw);
                if ($count > 0) {
                    // One clear restricted keyword is enough to fail the default threshold (70).
                    $points += min(95, 78 + ($count - 1) * 6);
                    $hits += $count;
                    $matched[] = $kw;
                    $hardHit = true;
                }
            }

            foreach ($cat['intent_phrases'] ?? [] as $phrase) {
                $phrase = mb_strtolower(trim((string) $phrase));
                if ($phrase !== '' && str_contains($haystack, $phrase)) {
                    $points += 85;
                    $hits++;
                    $matched[] = $phrase;
                    $hardHit = true;
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
                    $points += 95;
                    $hits++;
                    $hardHit = true;
                    $matched[] = $domain;
                    $blockedUrls = array_merge($blockedUrls, $urlsForDomain);
                } elseif ($this->domainMentioned($haystack, $domain) || $this->domainMentioned($linkBlob, $domain)) {
                    $points += 90;
                    $hits++;
                    $hardHit = true;
                    $matched[] = $domain;
                    $blockedUrls[] = $this->syntheticUrlForDomain($domain);
                }
            }

            foreach ($extraKeywords as $extra) {
                $extra = mb_strtolower(trim((string) $extra));
                if ($extra !== '' && $this->countTerm($haystack, $extra) > 0) {
                    $points += 78;
                    $hits++;
                    $matched[] = $extra;
                    $hardHit = true;
                }
            }

            if ($hits >= 3) {
                $points *= 1.08;
            } elseif ($hits >= 2) {
                $points *= 1.04;
            }

            $weight = (float) ($cat['weight'] ?? 1.0);
            $confidence = (int) min(99, round($points * $weight));

            // Never soft-pedal hard policy hits (keywords, domains, intent).
            if ($hardHit) {
                $confidence = max($confidence, 78);
            }

            $scores[$key] = $confidence;
            $matched = array_values(array_unique($matched));
            $blockedUrls = array_values(array_unique(array_filter($blockedUrls)));
            if ($confidence > 0) {
                $signals['hits'][$key] = [
                    'term_hits' => $hits,
                    'confidence' => $confidence,
                    'matched_terms' => $matched,
                    'blocked_urls' => $blockedUrls,
                    'hard_hit' => $hardHit,
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
            // Promote bare www./domain mentions into absolute URLs for host matching.
            if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
                if (preg_match('#^(www\.)?[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $url)) {
                    $url = 'https://'.ltrim($url, '/');
                }
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
     * Pull absolute / www / bare restricted-looking URLs out of article body text.
     *
     * @return list<string>
     */
    public function extractUrlsFromText(string $text): array
    {
        $text = $this->deobfuscate(mb_strtolower($text));
        $found = [];

        if (preg_match_all('#https?://[^\s<>"\')\]]+#iu', $text, $m)) {
            foreach ($m[0] as $url) {
                $found[] = rtrim((string) $url, '.,);]');
            }
        }

        if (preg_match_all('#(?<![\w./])(?:www\.)[a-z0-9.-]+\.[a-z]{2,}(?:/[^\s<>"\')\]]*)?#iu', $text, $m2)) {
            foreach ($m2[0] as $url) {
                $found[] = 'https://'.ltrim((string) $url, '/');
            }
        }

        return $this->normalizeLinkList($found);
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, mixed>  $categories
     * @return list<string>
     */
    public function enrichLinksFromContent(array $urls, string $haystack, array $categories): array
    {
        $urls = array_values(array_unique(array_merge($urls, $this->extractUrlsFromText($haystack))));

        foreach ($categories as $cat) {
            if (empty($cat['enabled'])) {
                continue;
            }
            foreach ($cat['domains'] ?? [] as $domain) {
                $domain = mb_strtolower(trim((string) $domain));
                if ($domain === '' || ! str_contains($domain, '.')) {
                    continue;
                }
                if ($this->domainMentioned($haystack, $domain)) {
                    $urls[] = $this->syntheticUrlForDomain($domain);
                }
            }
        }

        return array_values(array_unique($urls));
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
            if ($host !== '' && $this->hostMatchesDomain($host, $domain)) {
                $matched[] = $url;

                continue;
            }
            if ($this->domainMentioned($hay, $domain)) {
                $matched[] = $url;
            }
        }

        return array_values(array_unique($matched));
    }

    protected function hostMatchesDomain(string $host, string $domain): bool
    {
        $host = mb_strtolower($host);
        $domain = mb_strtolower($domain);

        if ($domain === '') {
            return false;
        }

        // Brand tokens without a TLD (bet365, pornhub, pokerstars).
        if (! str_contains($domain, '.')) {
            return str_contains($host, $domain);
        }

        return $host === $domain
            || str_ends_with($host, '.'.$domain)
            || str_contains($host, $domain);
    }

    protected function domainMentioned(string $haystack, string $domain): bool
    {
        $domain = mb_strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        if (str_contains($haystack, $domain)) {
            return true;
        }

        // Obfuscations: stake[dot]com, stake (dot) com, stake . com
        if (str_contains($domain, '.')) {
            $parts = explode('.', $domain);
            $escaped = array_map(static fn (string $p) => preg_quote($p, '/'), $parts);
            $flex = implode('[\s\[\(\{]*(?:dot)?[\s\]\)\}]*\.?[\s\[\(\{]*', $escaped);

            return (bool) preg_match('/'.$flex.'/iu', $haystack);
        }

        return (bool) preg_match('/\b'.preg_quote($domain, '/').'\b/u', $haystack);
    }

    protected function syntheticUrlForDomain(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        if (! str_contains($domain, '.')) {
            return 'https://'.$domain.'.com/';
        }

        return 'https://'.$domain.'/';
    }

    /**
     * Normalize common link cloaking before keyword/domain scans.
     */
    public function deobfuscate(string $text): string
    {
        $text = preg_replace('/\[\s*dot\s*\]/iu', '.', $text) ?? $text;
        $text = preg_replace('/\(\s*dot\s*\)/iu', '.', $text) ?? $text;
        $text = preg_replace('/\{\s*dot\s*\}/iu', '.', $text) ?? $text;
        $text = preg_replace('/\s+dot\s+/iu', '.', $text) ?? $text;
        // "stake . com" / "bet365 . com"
        $text = preg_replace('/(\w)\s*\.\s*(\w)/u', '$1.$2', $text) ?? $text;
        $text = str_ireplace(['hxxps://', 'hxxp://', 'h**ps://'], ['https://', 'http://', 'https://'], $text);

        return $text;
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

        // Unicode-aware word boundaries; also catch glued variants like "casino!" already via \b.
        return preg_match_all('/(?<![\p{L}\p{N}_])'.preg_quote($term, '/').'(?![\p{L}\p{N}_])/u', $haystack) ?: 0;
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
