<?php

namespace App\Support;

use App\Models\Site;

/**
 * Detect demo / lorem catalog rows so Site Details can warn buyers.
 * Does not hide the listing.
 */
class CatalogPlaceholderListing
{
    public static function matches(Site $site): bool
    {
        return self::descriptionLooksPlaceholder($site->description)
            || self::hostLooksPlaceholder($site->site_url)
            || self::hostLooksPlaceholder($site->example_url)
            || self::hostLooksPlaceholder($site->domain);
    }

    public static function descriptionLooksPlaceholder(mixed $description): bool
    {
        $haystack = strtolower(trim(strip_tags((string) $description)));
        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, 'lorem ipsum')
            || str_contains($haystack, 'replace this placeholder with a real site description');
    }

    public static function hostLooksPlaceholder(mixed $urlOrHost): bool
    {
        $raw = strtolower(trim((string) $urlOrHost));
        if ($raw === '') {
            return false;
        }

        $host = parse_url(str_contains($raw, '://') ? $raw : 'https://'.$raw, PHP_URL_HOST);
        $host = strtolower((string) ($host ?: $raw));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === 'example.com' || $host === 'localhost') {
            return true;
        }

        return (bool) preg_match('/^demo\d*\.com$/', $host);
    }
}
