<?php

namespace App\Support;

/**
 * Strict http(s) listing URL normaliser (admin / partner import).
 * Rejects whitespace, quotes, backticks, non-http schemes, and non-DNS hosts.
 */
final class MarketplaceHttpUrl
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || self::containsForbiddenCharacters($url)) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (preg_match('~^(?:https?|ftps?)://~i', $url) !== 1) {
            if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $url, $schemeMatch) === 1) {
                $scheme = strtolower($schemeMatch[1]);
                $hasAuthority = preg_match('~^'.preg_quote($schemeMatch[1], '~').'://~i', $url) === 1;
                if ($hasAuthority || in_array($scheme, ['javascript', 'data', 'mailto', 'vbscript', 'file', 'about', 'blob'], true)) {
                    return '';
                }
                if (! str_contains($scheme, '.')) {
                    return '';
                }
            }
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = rtrim($parts['host'], '.');
        if ($host === '' || ! self::isDnsHostname($host)) {
            return '';
        }
        if (function_exists('idn_to_ascii') && ! filter_var($host, FILTER_VALIDATE_IP) && ! str_starts_with($host, '[')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
            $host = strtolower($host);
        }
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        $authority = $host;
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            if ($port < 1 || $port > 65535) {
                return '';
            }
            if (! in_array($port, [80, 443], true)) {
                $authority .= ':'.$port;
            }
        }

        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$authority.$path.$query;
    }

    public static function containsForbiddenCharacters(string $url): bool
    {
        return preg_match('/[\x00-\x1f\x7f\s`"\'<>\\\\]/u', $url) === 1;
    }

    public static function isDnsHostname(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (function_exists('idn_to_ascii') && ! filter_var($host, FILTER_VALIDATE_IP) && ! str_starts_with($host, '[')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (! is_string($ascii) || $ascii === '') {
                return false;
            }
            $host = strtolower($ascii);
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public static function isMarketplaceHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_starts_with($host, '[')) {
            return false;
        }
        if (str_contains($host, ':') && preg_match('/^(.+):(\d+)$/', $host, $m) === 1) {
            $host = $m[1];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }
        if (! self::isDnsHostname($host)) {
            return false;
        }

        $labels = explode('.', $host);
        $tld = (string) end($labels);
        if (in_array($tld, ['localhost', 'local', 'internal', 'invalid'], true)) {
            return false;
        }
        $allNumeric = true;
        foreach ($labels as $label) {
            if ($label === '') {
                return false;
            }
            if (! ctype_digit($label)) {
                $allNumeric = false;
            }
        }

        return ! $allNumeric;
    }
}
