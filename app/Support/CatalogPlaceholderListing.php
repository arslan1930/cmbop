<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * SQL approximation for the admin placeholder queue.
     * PHP {@see matches()} remains the source of truth for badges.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public static function constrainQuery(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('description', 'like', '%lorem ipsum%')
                ->orWhere('description', 'like', '%replace this placeholder with a real site description%');

            if (Site::hasSitesColumn('domain')) {
                $q->orWhere('domain', 'example.com')
                    ->orWhere('domain', 'localhost')
                    ->orWhere('domain', 'like', 'demo%.com');
            }

            foreach (['site_url', 'example_url'] as $column) {
                if (! Site::hasSitesColumn($column)) {
                    continue;
                }
                $q->orWhere($column, 'like', '%example.com%')
                    ->orWhere($column, 'like', '%://localhost%')
                    ->orWhere($column, 'like', '%demo%.com%');
            }
        });
    }
}
