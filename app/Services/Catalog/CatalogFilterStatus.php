<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Catalog results status copy + empty-state recovery links.
 */
class CatalogFilterStatus
{
    /** Listing query keys preserved when building recovery URLs. */
    public const QUERY_KEYS = [
        'search', 'category', 'country', 'language',
        'price_min', 'price_max', 'da_min', 'da_max', 'dr_min', 'dr_max',
        'traffic_min', 'traffic_max', 'sponsored', 'favorites_filter',
        'blacklist_filter', 'new_badge', 'verified', 'bulk_deals', 'on_sale',
        'quality', 'rating_min', 'has_completions', 'site', 'sort', 'per_page', 'wizard',
    ];

    public function __construct(
        private readonly CatalogCountryBuckets $buckets = new CatalogCountryBuckets,
        private readonly CatalogCountryInventory $inventory = new CatalogCountryInventory,
    ) {}

    /**
     * @return array{text: string, announce: string}
     */
    public function summarize(Request $request, int $total, ?int $firstItem = null, ?int $lastItem = null): array
    {
        $search = trim((string) $request->input('search', ''));
        $countries = $this->selectedCountryCodes($request);
        $countryLabel = $this->countryLabels($countries);
        $niches = $this->selectedNiches($request);
        $nicheLabel = $this->nicheLabels($niches);

        if ($total < 1) {
            if ($search !== '' && $countries !== []) {
                $text = 'No sites matching “'.$search.'” in '.$countryLabel;

                return ['text' => $text, 'announce' => $text];
            }
            if ($search !== '' && $niches !== []) {
                $text = 'No sites matching “'.$search.'” in '.$nicheLabel;

                return ['text' => $text, 'announce' => $text];
            }
            if ($countries !== [] && $niches !== []) {
                $text = 'No sites in '.$nicheLabel.' for '.$countryLabel;

                return ['text' => $text, 'announce' => $text];
            }
            if ($countries !== []) {
                $text = 'No sites in '.$countryLabel;

                return ['text' => $text, 'announce' => $text];
            }
            if ($niches !== []) {
                $text = 'No sites in '.$nicheLabel;

                return ['text' => $text, 'announce' => $text];
            }
            if ($search !== '') {
                $text = 'No sites matching “'.$search.'”';

                return ['text' => $text, 'announce' => $text];
            }

            return [
                'text' => 'No sites match your filters',
                'announce' => 'No sites match your filters',
            ];
        }

        $range = ($firstItem !== null && $lastItem !== null)
            ? $firstItem.'–'.$lastItem
            : null;
        $countLabel = number_format($total).' '.($total === 1 ? 'site' : 'sites');

        if ($search !== '' && $countries !== []) {
            $text = 'Matching “'.$search.'” in '.$countryLabel.' · '.$countLabel;
            if ($range) {
                $text .= ' (showing '.$range.')';
            }

            return ['text' => $text, 'announce' => $text];
        }

        if ($niches !== [] && $countries === [] && $search === '') {
            $text = $nicheLabel.' · '.$countLabel;
            if ($range) {
                $text .= ' (showing '.$range.')';
            }

            return ['text' => $text, 'announce' => $text];
        }

        if ($countries !== []) {
            $text = $countryLabel.' · '.$countLabel;
            if ($range) {
                $text .= ' (showing '.$range.')';
            }

            return ['text' => $text, 'announce' => $text];
        }

        if ($range) {
            $text = 'Showing '.$range.' of '.$countLabel;

            return ['text' => $text, 'announce' => $text];
        }

        return [
            'text' => $countLabel,
            'announce' => $countLabel,
        ];
    }

    /**
     * @return array{
     *     clear_country_url: ?string,
     *     clear_category_url: ?string,
     *     clear_all_url: string,
     *     try_language: ?array{code: string, name: string, url: string},
     *     neighbors: list<array{code: string, name: string, count: int, url: string}>,
     *     related_niches: list<array{name: string, count: int, url: string}>,
     *     body: string
     * }
     */
    public function emptyRecovery(Request $request): array
    {
        $search = trim((string) $request->input('search', ''));
        $countries = $this->selectedCountryCodes($request);
        $hasCountry = $countries !== [];
        $niches = $this->selectedNiches($request);
        $hasCategory = $niches !== [];
        $nicheLabel = $this->nicheLabels($niches);

        $clearCountryUrl = $hasCountry
            ? route('advertiser.catalog', $this->catalogQuery($request, except: ['country', 'page']))
            : null;

        $clearCategoryUrl = $hasCategory
            ? route('advertiser.catalog', $this->catalogQuery($request, except: ['category', 'page']))
            : null;

        $tryLanguage = null;
        if ($hasCountry && ! $request->filled('language')) {
            $primary = $this->primaryLanguageFor($countries[0]);
            if ($primary !== null) {
                $tryLanguage = [
                    'code' => $primary['code'],
                    'name' => $primary['name'],
                    'url' => route('advertiser.catalog', $this->catalogQuery(
                        $request,
                        except: ['country', 'page'],
                        merge: ['language' => $primary['code']]
                    )),
                ];
            }
        }

        $neighbors = $hasCountry
            ? $this->neighborMarkets($request, $countries)
            : [];

        $relatedNiches = $hasCategory
            ? $this->relatedNiches($request, $niches)
            : [];

        $countryLabel = $hasCountry ? $this->countryLabels($countries) : '';
        if ($search !== '' && $hasCountry) {
            $body = 'No listings match “'.$search.'” in '.$countryLabel.'. Clear the country filter or try a nearby market.';
        } elseif ($search !== '' && $hasCategory) {
            $body = 'No listings match “'.$search.'” in '.$nicheLabel.'. Clear this category or try a related niche.';
        } elseif ($hasCountry && $hasCategory) {
            $body = 'No listings in '.$nicheLabel.' for '.$countryLabel.' right now. Clear the category or country filter, or try a related niche.';
        } elseif ($hasCountry) {
            $body = 'No listings in '.$countryLabel.' right now. Clear the country filter or try a nearby market.';
        } elseif ($hasCategory) {
            $body = 'No listings in '.$nicheLabel.' right now. Clear this category or try a related niche.';
        } elseif ($search !== '') {
            $body = 'No listings match “'.$search.'”. Try broader filters or suggest a website.';
        } else {
            $body = 'Try broader filters — clear a category, widen price, or remove DA/DR limits.';
        }

        return [
            'clear_country_url' => $clearCountryUrl,
            'clear_category_url' => $clearCategoryUrl,
            'clear_all_url' => route('advertiser.catalog'),
            'try_language' => $tryLanguage,
            'neighbors' => $neighbors,
            'related_niches' => $relatedNiches,
            'body' => $body,
        ];
    }

    /**
     * Selected catalog niches from category= (pipe wire + legacy comma compat).
     *
     * @return list<string>
     */
    public function selectedNiches(Request $request): array
    {
        $raw = trim((string) $request->input('category', ''));
        if ($raw === '') {
            return [];
        }

        return Category::parseCatalogCategoryParam(
            Category::canonicalizeCatalogCategoryParam($raw)
        );
    }

    /**
     * @return list<string>
     */
    public function selectedCountryCodes(Request $request): array
    {
        $raw = (string) $request->input('country', '');
        $codes = [];
        foreach (explode(',', $raw) as $code) {
            $normalized = strtolower(trim($code));
            if ($normalized !== '' && ! in_array($normalized, $codes, true)) {
                $codes[] = $normalized;
            }
        }

        return $codes;
    }

    /**
     * @param  list<string>  $except
     * @param  array<string, mixed>  $merge
     * @return array<string, mixed>
     */
    public function catalogQuery(Request $request, array $except = [], array $merge = []): array
    {
        $exceptLookup = array_fill_keys($except, true);
        $query = [];

        foreach (self::QUERY_KEYS as $key) {
            if (isset($exceptLookup[$key])) {
                continue;
            }
            if (! $request->filled($key)) {
                continue;
            }
            $query[$key] = $request->input($key);
        }

        foreach ($merge as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);

                continue;
            }
            $query[$key] = $value;
        }

        return $query;
    }

    /**
     * @param  list<string>  $niches
     */
    private function nicheLabels(array $niches): string
    {
        if ($niches === []) {
            return '';
        }

        if (count($niches) === 1) {
            return $niches[0];
        }

        $labels = $niches;
        $last = array_pop($labels);

        return implode(', ', $labels).' or '.$last;
    }

    /**
     * @param  list<string>  $codes
     */
    private function countryLabels(array $codes): string
    {
        if ($codes === []) {
            return '';
        }

        $names = Country::query()
            ->whereIn('code', $codes)
            ->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtolower((string) $code) => (string) $name])
            ->all();

        $labels = [];
        foreach ($codes as $code) {
            $labels[] = $names[$code] ?? strtoupper($code);
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }

    /**
     * @return array{code: string, name: string}|null
     */
    private function primaryLanguageFor(string $countryCode): ?array
    {
        $country = Country::query()->where('code', $countryCode)->first();
        if (! $country) {
            return null;
        }

        $language = $country->primaryLanguages()->first()
            ?: $country->languages()->first();

        if (! $language instanceof Language) {
            return null;
        }

        return [
            'code' => strtolower((string) $language->code),
            'name' => (string) $language->name,
        ];
    }

    /**
     * Sibling niches in the same group(s) that still have active inventory.
     *
     * @param  list<string>  $selected
     * @return list<array{name: string, count: int, url: string}>
     */
    private function relatedNiches(Request $request, array $selected): array
    {
        if ($selected === []) {
            return [];
        }

        $selectedLookup = [];
        foreach ($selected as $name) {
            $selectedLookup[strtolower($name)] = true;
        }

        $groups = Category::query()
            ->whereIn('name', $selected)
            ->pluck('group')
            ->map(static fn ($g) => trim((string) $g))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($groups === []) {
            return [];
        }

        $candidates = Category::query()
            ->whereIn('group', $groups)
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($n) => (string) $n)
            ->reject(static fn ($n) => isset($selectedLookup[strtolower($n)]))
            ->values()
            ->all();

        if ($candidates === []) {
            return [];
        }

        $counts = $this->nicheInventoryCounts($candidates);
        arsort($counts);

        $related = [];
        foreach ($counts as $name => $count) {
            if ((int) $count < 1) {
                continue;
            }
            $related[] = [
                'name' => $name,
                'count' => (int) $count,
                'url' => route('advertiser.catalog', $this->catalogQuery(
                    $request,
                    except: ['page'],
                    merge: ['category' => Category::encodeCatalogCategoryParam([(string) $name])]
                )),
            ];
            if (count($related) >= 3) {
                break;
            }
        }

        return $related;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function nicheInventoryCounts(array $names): array
    {
        $counts = array_fill_keys($names, 0);
        if ($names === []) {
            return $counts;
        }

        $lookupLower = [];
        foreach ($names as $name) {
            $lookupLower[strtolower($name)] = $name;
        }

        $sites = Site::query()
            ->catalogVisible()
            ->tap(static fn ($q) => Category::constrainQueryToNicheNames($q, $names))
            ->get(['category', 'categories']);

        foreach ($sites as $site) {
            $hit = [];
            foreach ($site->nicheBadgeLabels() as $label) {
                $key = strtolower((string) $label);
                if (isset($lookupLower[$key])) {
                    $hit[$lookupLower[$key]] = true;
                }
            }
            // nicheBadgeLabels prefers JSON; still credit the legacy category column.
            $legacy = trim((string) ($site->category ?? ''));
            if ($legacy !== '') {
                $key = strtolower($legacy);
                if (isset($lookupLower[$key])) {
                    $hit[$lookupLower[$key]] = true;
                }
            }
            foreach ($hit as $name => $_) {
                $counts[$name]++;
            }
        }

        return $counts;
    }

    /**
     * Nearby markets from DACH+ / Nordics groups that still have inventory.
     *
     * @param  list<string>  $selected
     * @return list<array{code: string, name: string, count: int, url: string}>
     */
    private function neighborMarkets(Request $request, array $selected): array
    {
        $counts = $this->inventory->counts();
        $selectedLookup = array_fill_keys($selected, true);
        $candidateCodes = [];

        foreach ($this->buckets->groups() as $codes) {
            $intersects = false;
            foreach ($selected as $code) {
                if (in_array($code, $codes, true)) {
                    $intersects = true;
                    break;
                }
            }
            if (! $intersects) {
                continue;
            }
            foreach ($codes as $code) {
                if (isset($selectedLookup[$code])) {
                    continue;
                }
                if ((int) ($counts[$code] ?? 0) < 1) {
                    continue;
                }
                $candidateCodes[$code] = (int) $counts[$code];
            }
        }

        if ($candidateCodes === []) {
            return [];
        }

        arsort($candidateCodes);
        $names = Country::query()
            ->whereIn('code', array_keys($candidateCodes))
            ->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtolower((string) $code) => (string) $name])
            ->all();

        $neighbors = [];
        foreach ($candidateCodes as $code => $count) {
            $neighbors[] = [
                'code' => $code,
                'name' => $names[$code] ?? strtoupper($code),
                'count' => $count,
                'url' => route('advertiser.catalog', $this->catalogQuery(
                    $request,
                    except: ['page'],
                    merge: ['country' => $code]
                )),
            ];
            if (count($neighbors) >= 3) {
                break;
            }
        }

        return $neighbors;
    }
}
