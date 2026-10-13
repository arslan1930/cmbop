<?php

namespace App\Services\Marketing;

use App\Models\Country;
use App\Models\Site;
use App\Services\Catalog\CatalogCountryInventory;
use App\Services\PlatformFeeService;
use App\Support\CountryLander;
use Illuminate\Support\Facades\Cache;
use Locale;
use Throwable;

class GuestPostPriceIndex
{
    public const CACHE_KEY = 'marketing.guest_post_price_index';

    public const CACHE_TTL_SECONDS = 600;

    public const MIN_COUNTRY_SAMPLE = 3;

    public const MIN_EUROPE_SAMPLE = 3;

    public const SLUG = 'guest-post-prices-europe';

    public function __construct(
        private PlatformFeeService $fees,
        private CatalogCountryInventory $countries,
    ) {}

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Live catalog medians. Never invent a number when the sample is too small.
     *
     * @return array{
     *     generated_at: ?string,
     *     europe: array{median: ?float, listings: int},
     *     countries: list<array{code: string, name: string, median: float, from: float, listings: int, lander_url: ?string}>,
     *     has_index: bool
     * }
     */
    public function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->compute());
    }

    /**
     * @return array{
     *     generated_at: ?string,
     *     europe: array{median: ?float, listings: int},
     *     countries: list<array{code: string, name: string, median: float, from: float, listings: int, lander_url: ?string}>,
     *     has_index: bool
     * }
     */
    public function compute(): array
    {
        $europeCodes = $this->europeCodeSet();
        $pricesByCountry = [];
        $europePrices = [];

        try {
            $this->collectEuropePrices($pricesByCountry, $europePrices, $europeCodes);
        } catch (Throwable) {
            return $this->emptySnapshot();
        }

        $names = $this->countryNames(array_keys($pricesByCountry));
        $landers = $this->landerUrlByCode();

        $countryRows = [];
        foreach ($pricesByCountry as $code => $prices) {
            if (count($prices) < self::MIN_COUNTRY_SAMPLE) {
                continue;
            }

            $countryRows[] = [
                'code' => $code,
                'name' => $names[$code] ?? $this->displayName($code),
                'median' => $this->median($prices),
                'from' => round((float) min($prices), 2),
                'listings' => count($prices),
                'lander_url' => $landers[$code] ?? null,
            ];
        }

        usort($countryRows, function (array $a, array $b): int {
            if ($a['listings'] !== $b['listings']) {
                return $b['listings'] <=> $a['listings'];
            }

            return strcasecmp($a['name'], $b['name']);
        });

        $europeCount = count($europePrices);
        $europeMedian = $europeCount >= self::MIN_EUROPE_SAMPLE
            ? $this->median($europePrices)
            : null;

        return [
            'generated_at' => now()->toIso8601String(),
            'europe' => [
                'median' => $europeMedian,
                'listings' => $europeCount,
            ],
            'countries' => array_values($countryRows),
            'has_index' => $europeMedian !== null || $countryRows !== [],
        ];
    }

    /**
     * @return array{
     *     generated_at: ?string,
     *     europe: array{median: ?float, listings: int},
     *     countries: list<array{code: string, name: string, median: float, from: float, listings: int, lander_url: ?string}>,
     *     has_index: bool
     * }
     */
    private function emptySnapshot(): array
    {
        return [
            'generated_at' => null,
            'europe' => [
                'median' => null,
                'listings' => 0,
            ],
            'countries' => [],
            'has_index' => false,
        ];
    }

    /**
     * @param  array<string, list<float>>  $pricesByCountry
     * @param  list<float>  $europePrices
     * @param  array<string, true>  $europeCodes
     */
    private function collectEuropePrices(array &$pricesByCountry, array &$europePrices, array $europeCodes): void
    {
        $tally = function (array $columns) use (&$pricesByCountry, &$europePrices, $europeCodes): void {
            Site::query()
                ->catalogVisible()
                ->select($columns)
                ->orderBy('id')
                ->chunkById(500, function ($sites) use (&$pricesByCountry, &$europePrices, $europeCodes) {
                    foreach ($sites as $site) {
                        $code = $this->normalizeEuropeCode(
                            $this->countries->primaryCountryCode($site->country, $site->countries ?? null)
                        );
                        if ($code === null || ! isset($europeCodes[$code])) {
                            continue;
                        }

                        $publisher = (float) $site->price;
                        if ($publisher <= 0) {
                            continue;
                        }

                        $advertiser = $this->fees->advertiserBase($publisher);
                        $pricesByCountry[$code][] = $advertiser;
                        $europePrices[] = $advertiser;
                    }
                });
        };

        if (Site::hasSitesColumn('countries')) {
            try {
                $tally(['id', 'country', 'countries', 'price']);

                return;
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $missingJson = str_contains($message, 'Unknown column')
                    || str_contains($message, 'no such column')
                    || str_contains($message, '42S22');
                if (! $missingJson || ! str_contains($message, 'countries')) {
                    throw $e;
                }
            }
        }

        $tally(['id', 'country', 'price']);
    }

    /**
     * @return array<string, true>
     */
    private function europeCodeSet(): array
    {
        $set = [];
        foreach (config('markets.europe_country_codes', []) as $code) {
            $normalized = $this->normalizeEuropeCode((string) $code);
            if ($normalized !== null) {
                $set[$normalized] = true;
            }
        }

        return $set;
    }

    private function normalizeEuropeCode(?string $code): ?string
    {
        $code = strtolower(trim((string) $code));
        if ($code === '') {
            return null;
        }

        // Catalog and landers store the United Kingdom as uk, not gb.
        return $code === 'gb' ? 'uk' : $code;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    private function countryNames(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        try {
            return Country::query()
                ->whereIn('code', $codes)
                ->pluck('name', 'code')
                ->mapWithKeys(fn ($name, $code) => [strtolower((string) $code) => (string) $name])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function landerUrlByCode(): array
    {
        if (! class_exists(CountryLander::class)) {
            return [];
        }

        $map = [];
        foreach (CountryLander::all() as $lander) {
            $slug = trim((string) ($lander['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            foreach ($lander['codes'] ?? [] as $code) {
                $normalized = $this->normalizeEuropeCode((string) $code);
                if ($normalized !== null && ! isset($map[$normalized])) {
                    $map[$normalized] = url('/'.$slug);
                }
            }
        }

        return $map;
    }

    private function displayName(string $code): string
    {
        $iso = $code === 'uk' ? 'GB' : strtoupper($code);
        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('und_'.$iso, 'en');
            if (is_string($name) && $name !== '' && strcasecmp($name, $iso) !== 0) {
                return $name;
            }
        }

        return match ($code) {
            'de' => 'Germany',
            'uk' => 'United Kingdom',
            'fr' => 'France',
            'it' => 'Italy',
            'es' => 'Spain',
            'nl' => 'Netherlands',
            default => strtoupper($code),
        };
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return round((float) $values[$mid], 2);
        }

        return round(((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 2);
    }
}
