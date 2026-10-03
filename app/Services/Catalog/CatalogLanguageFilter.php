<?php

namespace App\Services\Catalog;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Advertiser catalog language filter (Option A).
 *
 * - Language-only → all sites that offer that language (any country).
 * - Language + country → AND (country constrained separately).
 * - Selecting a language never auto-sets country (client/server).
 * - Same rule for every language code (de, en, fr, …).
 */
class CatalogLanguageFilter
{
    /**
     * @param  list<string>|string  $codes
     * @return list<string>
     */
    public function normalizeCodes(array|string $codes): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($c) => strtolower(trim((string) $c)),
            is_array($codes) ? $codes : [$codes]
        ))));
    }

    /**
     * Match scalar `language` or JSON `languages` contains — every code equally.
     * Multi-language sites that offer German still appear for language=de.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     * @param  list<string>|string  $codes
     * @return Builder|\Illuminate\Database\Query\Builder
     */
    public function constrainQuery($query, array|string $codes)
    {
        $normalized = $this->normalizeCodes($codes);
        if ($normalized === []) {
            return $query;
        }

        $hasLanguagesJson = Site::hasSitesColumn('languages');

        return $query->where(function ($q) use ($normalized, $hasLanguagesJson) {
            foreach ($normalized as $code) {
                $q->orWhereRaw('LOWER(TRIM(language)) = ?', [$code]);
                if ($hasLanguagesJson) {
                    $q->orWhereJsonContains('languages', $code);
                }
            }
        });
    }
}
