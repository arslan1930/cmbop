<?php

namespace App\Services\Catalog;

use App\Models\Country;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Active-site inventory counts per marketplace country (one country per site).
 */
class CatalogCountryInventory
{
    public const CACHE_KEY = 'catalog.country_inventory';

    public const CACHE_TTL_SECONDS = 600;

    public const POPULAR_LIMIT = 10;

    public const SECTION_LABELS = [
        'popular' => 'Popular',
        'recent' => 'Recent',
        'big_europe' => 'Big Europe',
        'nordics' => 'Nordics',
        'small_europe' => 'Small Europe',
        'big_english' => 'Big English-speaking',
        'other_english' => 'Other English-speaking',
        'other_language_markets' => 'Other language markets',
        'all_other' => 'All other',
    ];

    public const GROUP_LABELS = [
        'dach_plus' => 'DACH+',
        'nordics' => 'Nordics',
    ];

    public function __construct(
        private readonly CatalogCountryBuckets $buckets = new CatalogCountryBuckets,
    ) {}

    /**
     * @return array<string, int> code => active site count
     */
    public function counts(): array
    {
        /** @var array<string, int> $counts */
        $counts = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return $this->computeCounts();
        });

        return $counts;
    }

    /**
     * Marketplace countries with inventory metadata for the catalog picker.
     *
     * @param  bool  $onlyWithInventory  When true, drop zero-count markets
     * @return list<array{code: string, name: string, count: int}>
     */
    public function options(bool $onlyWithInventory = true): array
    {
        $counts = $this->counts();
        $names = Country::marketplace()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtolower((string) $code) => (string) $name])
            ->all();

        $options = [];
        $seen = [];
        foreach ($this->buckets->orderedCodes() as $code) {
            $count = (int) ($counts[$code] ?? 0);
            if ($onlyWithInventory && $count < 1) {
                continue;
            }

            $options[] = [
                'code' => $code,
                'name' => $names[$code] ?? strtoupper($code),
                'count' => $count,
            ];
            $seen[$code] = true;
        }

        // Any allowlisted active count not already in the ordered list (should be rare).
        foreach ($counts as $code => $count) {
            if ($count < 1 || isset($seen[$code])) {
                continue;
            }

            $options[] = [
                'code' => $code,
                'name' => $names[$code] ?? strtoupper($code),
                'count' => (int) $count,
            ];
        }

        return $options;
    }

    /**
     * Sectioned country picker payload for the advertiser catalog dropdown.
     *
     * Checkbox uniqueness: Popular codes are omitted from buckets 1–7.
     * Recent is an empty shell filled client-side (moves nodes; no duplicates).
     *
     * @param  list<string>  $selectedCodes  URL-selected codes (keep even if count is 0)
     * @return array{
     *     sections: list<array{key: string, label: string, options: list<array{code: string, name: string, count: int}>}>,
     *     groups: list<array{key: string, label: string, codes: list<string>}>
     * }
     */
    public function pickerSections(array $selectedCodes = []): array
    {
        $selected = [];
        foreach ($selectedCodes as $code) {
            $normalized = strtolower(trim((string) $code));
            if ($normalized !== '') {
                $selected[$normalized] = true;
            }
        }

        $counts = $this->counts();
        $names = Country::marketplace()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtolower((string) $code) => (string) $name])
            ->all();

        $row = function (string $code) use ($counts, $names): array {
            return [
                'code' => $code,
                'name' => $names[$code] ?? strtoupper($code),
                'count' => (int) ($counts[$code] ?? 0),
            ];
        };

        $eligible = [];
        foreach ($this->buckets->orderedCodes() as $code) {
            $count = (int) ($counts[$code] ?? 0);
            if ($count > 0 || isset($selected[$code])) {
                $eligible[$code] = $row($code);
            }
        }
        foreach ($counts as $code => $count) {
            if ($count < 1 || isset($eligible[$code])) {
                continue;
            }
            $eligible[$code] = $row($code);
        }

        // Popular: top N by count (inventory only), fixed Big-Europe-style pin.
        $byCount = array_values($eligible);
        usort($byCount, function (array $a, array $b) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return strcasecmp($a['name'], $b['name']);
        });

        $popular = [];
        $placed = [];
        foreach ($byCount as $option) {
            if ($option['count'] < 1) {
                continue;
            }
            if (count($popular) >= self::POPULAR_LIMIT) {
                break;
            }
            $popular[] = $option;
            $placed[$option['code']] = true;
        }

        $sections = [];
        if ($popular !== []) {
            $sections[] = [
                'key' => 'popular',
                'label' => self::SECTION_LABELS['popular'],
                'options' => $popular,
            ];
        }

        // Recent shell — JS relocates option nodes here without duplicating inputs.
        $sections[] = [
            'key' => 'recent',
            'label' => self::SECTION_LABELS['recent'],
            'options' => [],
        ];

        $orderBuckets = $this->buckets->orderBuckets();
        foreach (CatalogCountryBuckets::ORDER_KEYS as $bucketKey) {
            $codes = $orderBuckets[$bucketKey] ?? [];
            $options = [];
            foreach ($codes as $code) {
                if (! isset($eligible[$code]) || isset($placed[$code])) {
                    continue;
                }
                $options[] = $eligible[$code];
                $placed[$code] = true;
            }

            if ($bucketKey === 'big_europe') {
                // Keep confirmed fixed order among remaining Big Europe codes.
            } else {
                usort($options, function (array $a, array $b) {
                    if ($a['count'] !== $b['count']) {
                        return $b['count'] <=> $a['count'];
                    }

                    return strcasecmp($a['name'], $b['name']);
                });
            }

            if ($options === []) {
                continue;
            }

            $sections[] = [
                'key' => $bucketKey,
                'label' => self::SECTION_LABELS[$bucketKey] ?? $bucketKey,
                'options' => $options,
            ];
        }

        // Orphans with inventory/selection not covered above.
        $orphans = [];
        foreach ($eligible as $code => $option) {
            if (isset($placed[$code])) {
                continue;
            }
            $orphans[] = $option;
            $placed[$code] = true;
        }
        if ($orphans !== []) {
            usort($orphans, function (array $a, array $b) {
                if ($a['count'] !== $b['count']) {
                    return $b['count'] <=> $a['count'];
                }

                return strcasecmp($a['name'], $b['name']);
            });
            $sections[] = [
                'key' => 'all_other',
                'label' => self::SECTION_LABELS['all_other'],
                'options' => $orphans,
            ];
        }

        $groups = [];
        foreach ($this->buckets->groups() as $key => $codes) {
            $visible = array_values(array_filter(
                $codes,
                fn (string $code) => isset($eligible[$code])
            ));
            if ($visible === []) {
                continue;
            }
            $groups[] = [
                'key' => $key,
                'label' => self::GROUP_LABELS[$key] ?? $key,
                'codes' => $visible,
            ];
        }

        return [
            'sections' => $sections,
            'groups' => $groups,
        ];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Primary country for inventory counting: single-country rule.
     * Scalar `sites.country` wins; otherwise first entry of `countries` JSON.
     */
    public function primaryCountryCode(?string $country, mixed $countries): ?string
    {
        $code = strtolower(trim((string) ($country ?? '')));
        if ($code !== '') {
            return $code;
        }

        $list = is_array($countries) ? $countries : [];
        foreach ($list as $item) {
            $normalized = strtolower(trim((string) $item));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Constrain a catalog/site query to primary country codes only.
     *
     * Matches scalar `sites.country` (case-insensitive). Does NOT use
     * JSON `countries` "contains" — that caused DE-primary multi-market
     * listings to appear under US (and show a German flag).
     *
     * @param  list<string>|string  $codes
     */
    public function constrainQueryToPrimaryCountries($query, array|string $codes)
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($c) => strtolower(trim((string) $c)),
            is_array($codes) ? $codes : [$codes]
        ))));

        if ($normalized === []) {
            return $query;
        }

        return $query->where(function ($q) use ($normalized) {
            foreach ($normalized as $code) {
                $q->orWhereRaw('LOWER(TRIM(country)) = ?', [$code]);
            }
        });
    }

    /**
     * @return array<string, int>
     */
    private function computeCounts(): array
    {
        $allow = array_fill_keys(
            array_map('strtolower', config('markets.allowed_country_codes', [])),
            true
        );

        $counts = [];

        Site::query()
            ->catalogVisible()
            ->select(['id', 'country', 'countries'])
            ->orderBy('id')
            ->chunkById(500, function ($sites) use (&$counts, $allow) {
                foreach ($sites as $site) {
                    $code = $this->primaryCountryCode($site->country, $site->countries);
                    if ($code === null || ! isset($allow[$code])) {
                        continue;
                    }
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            });

        return $counts;
    }
}
