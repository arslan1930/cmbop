<?php

namespace App\Services\Catalog;

use App\Models\Country;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
        try {
            /** @var array<string, int> $counts */
            $counts = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
                return $this->computeCounts();
            });

            return $counts;
        } catch (\Throwable $e) {
            Log::warning('Catalog country inventory counts failed', ['error' => $e->getMessage()]);

            return [];
        }
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
        $names = $this->marketplaceCountryNames();

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

        try {
            $counts = $this->counts();
        } catch (\Throwable $e) {
            Log::warning('Catalog country inventory counts failed', ['error' => $e->getMessage()]);
            $counts = [];
        }
        $names = $this->marketplaceCountryNames();

        $row = function (string $code) use ($counts, $names): array {
            return [
                'code' => $code,
                'name' => $names[$code] ?? strtoupper($code),
                'count' => (int) ($counts[$code] ?? 0),
            ];
        };

        // Always list marketplace countries so the dropdown is never empty
        // when listings exist but inventory cache/counts miss (or count is 0).
        $eligible = [];
        foreach ($this->marketplacePickerCodes($counts, $selected) as $code) {
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

        $withJson = ['id', 'country', 'countries'];
        $scalarOnly = ['id', 'country'];

        // Hostinger leftovers skip the countries JSON migration. Schema::hasColumn
        // can still report true (cached listing) while MySQL 42S22's the SELECT.
        if (Site::hasSitesColumn('countries')) {
            try {
                return $this->tallyVisibleSites($withJson, $allow);
            } catch (\Throwable $e) {
                if (! $this->isUnknownColumn($e, 'countries')) {
                    throw $e;
                }
                Log::warning('Catalog country inventory skipped missing sites.countries column', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->tallyVisibleSites($scalarOnly, $allow);
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, true>  $allow
     * @return array<string, int>
     */
    private function tallyVisibleSites(array $columns, array $allow): array
    {
        $counts = [];

        try {
            $grouped = Site::query()
                ->catalogVisible()
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->selectRaw('LOWER(TRIM(country)) as country_code, COUNT(*) as site_count')
                ->groupByRaw('LOWER(TRIM(country))')
                ->pluck('site_count', 'country_code');

            foreach ($grouped as $code => $count) {
                $code = strtolower(trim((string) $code));
                if ($code === '' || ! isset($allow[$code])) {
                    continue;
                }
                $counts[$code] = (int) $count;
            }
        } catch (\Throwable $e) {
            Log::warning('Catalog country inventory group count failed', ['error' => $e->getMessage()]);
        }

        $includeJson = in_array('countries', $columns, true);
        if (! $includeJson) {
            return $counts;
        }

        // Scalar country is empty: fall back to first JSON country (rare leftovers).
        Site::query()
            ->catalogVisible()
            ->where(function ($q) {
                $q->whereNull('country')->orWhere('country', '');
            })
            ->select($columns)
            ->orderBy('id')
            ->chunkById(500, function ($sites) use (&$counts, $allow) {
                foreach ($sites as $site) {
                    $code = $this->primaryCountryCode($site->country, $site->countries ?? null);
                    if ($code === null || ! isset($allow[$code])) {
                        continue;
                    }
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            });

        return $counts;
    }

    private function isUnknownColumn(\Throwable $e, string $column): bool
    {
        $message = $e->getMessage();
        if ($message === '') {
            return false;
        }

        $looksMissing = str_contains($message, 'Unknown column')
            || str_contains($message, 'no such column')
            || str_contains($message, '42S22');

        if (! $looksMissing) {
            return false;
        }

        return str_contains($message, $column);
    }

    /**
     * Display names: helper map first, countries table overrides when present.
     *
     * @return array<string, string>
     */
    private function marketplaceCountryNames(): array
    {
        $names = [];
        if (function_exists('marketplace_countries')) {
            foreach (marketplace_countries() as $code => $name) {
                $names[strtolower((string) $code)] = (string) $name;
            }
        }

        try {
            $fromDb = Country::marketplace()
                ->orderBy('name')
                ->pluck('name', 'code')
                ->mapWithKeys(fn ($name, $code) => [strtolower((string) $code) => (string) $name])
                ->all();
            $names = array_merge($names, $fromDb);
        } catch (\Throwable $e) {
            Log::warning('Catalog country names fell back to helper map', [
                'error' => $e->getMessage(),
            ]);
        }

        return $names;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, true>  $selected
     * @return list<string>
     */
    private function marketplacePickerCodes(array $counts, array $selected): array
    {
        $codes = [];
        foreach ($this->buckets->orderedCodes() as $code) {
            $codes[$code] = true;
        }
        foreach (array_keys($this->marketplaceCountryNames()) as $code) {
            $codes[$code] = true;
        }
        foreach (array_keys($counts) as $code) {
            if ((int) $counts[$code] > 0) {
                $codes[$code] = true;
            }
        }
        foreach (array_keys($selected) as $code) {
            $codes[$code] = true;
        }

        return array_keys($codes);
    }
}
