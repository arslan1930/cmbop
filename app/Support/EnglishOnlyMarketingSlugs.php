<?php

namespace App\Support;

use App\Services\Marketing\GuestPostPriceIndex;

/**
 * Country landers, the Europe price index, and the trust FAQ stay on unprefixed English URLs.
 * Kept in a small class so leftover Hostinger PublicI18n.php (missing the
 * method, or truncated in File Manager) does not 500 public pages.
 */
class EnglishOnlyMarketingSlugs
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $slugs = ['guest-post-prices-europe', 'is-seolinkbuildings-safe'];

        try {
            if (
                class_exists(GuestPostPriceIndex::class)
                && defined(GuestPostPriceIndex::class.'::SLUG')
            ) {
                $indexSlug = trim((string) GuestPostPriceIndex::SLUG);
                if ($indexSlug !== '') {
                    $slugs = array_values(array_unique(array_merge([$indexSlug], $slugs)));
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (class_exists(CountryLander::class) && method_exists(CountryLander::class, 'slugs')) {
                foreach (CountryLander::slugs() as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug !== '') {
                        $slugs[] = $slug;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($slugs));
    }
}
