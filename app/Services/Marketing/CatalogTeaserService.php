<?php

namespace App\Services\Marketing;

use App\Models\Site;
use App\Services\Catalog\CatalogCountryInventory;
use App\Services\PlatformFeeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CatalogTeaserService
{
    public function __construct(
        private PlatformFeeService $fees,
        private CatalogCountryInventory $countries,
    ) {}

    /**
     * Active + verified marketplace teasers with country diversity.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function teasers(int $limit = 8): Collection
    {
        $limit = max(1, min(24, $limit));

        try {
            $query = Site::query()
                ->catalogVisible()
                // Homepage/marketing teasers only promote quality-bar inventory.
                ->withGoodMetrics();

            if (Schema::hasColumn('sites', 'featured_until')) {
                $query->orderByRaw(
                    '(featured_until IS NOT NULL AND featured_until > ? AND featured_until <= ?) DESC',
                    [now(), Site::PLAUSIBLE_SQL_DATETIME_CEIL]
                );
            }

            $candidates = $query
                ->orderByDesc('dr')
                ->orderByDesc('da')
                ->orderByDesc('id')
                ->limit(40)
                ->get([
                    'id',
                    'site_name',
                    'site_url',
                    'domain',
                    'country',
                    'language',
                    'countries',
                    'languages',
                    'da',
                    'dr',
                    'traffic',
                    'price',
                    'category',
                    'link_type',
                    'publication_time',
                    'verified',
                    'site_image',
                    'screenshot_path',
                    'screenshot_thumb_path',
                    'favicon_path',
                    'featured_until',
                ]);

            if ($candidates->isEmpty()) {
                return collect();
            }

            $picked = collect();
            $seenCountries = [];
            $pickedIds = [];

            foreach ($candidates as $site) {
                if ($picked->count() >= $limit) {
                    break;
                }

                $code = strtolower((string) ($site->primaryCountryCode() ?: $site->country ?: 'xx'));
                if (isset($seenCountries[$code])) {
                    continue;
                }

                $seenCountries[$code] = true;
                $picked->push($site);
                $pickedIds[$site->id] = true;
            }

            foreach ($candidates as $site) {
                if ($picked->count() >= $limit) {
                    break;
                }
                if (isset($pickedIds[$site->id])) {
                    continue;
                }

                $picked->push($site);
                $pickedIds[$site->id] = true;
            }

            return $picked->values()->map(fn (Site $site) => $this->mapTeaser($site));
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Quality-bar teasers whose primary country is in $codes.
     *
     * @param  list<string>  $codes
     * @return Collection<int, array<string, mixed>>
     */
    public function teasersForCountries(array $codes, int $limit = 8): Collection
    {
        $limit = max(1, min(24, $limit));
        $codes = $this->normalizeCodes($codes);
        if ($codes === []) {
            return collect();
        }

        try {
            $query = Site::query()
                ->catalogVisible()
                ->withGoodMetrics();
            $this->countries->constrainQueryToPrimaryCountries($query, $codes);

            if (Schema::hasColumn('sites', 'featured_until')) {
                $query->orderByRaw(
                    '(featured_until IS NOT NULL AND featured_until > ? AND featured_until <= ?) DESC',
                    [now(), Site::PLAUSIBLE_SQL_DATETIME_CEIL]
                );
            }

            $sites = $query
                ->orderByDesc('dr')
                ->orderByDesc('da')
                ->orderByDesc('id')
                ->limit($limit)
                ->get([
                    'id',
                    'site_name',
                    'site_url',
                    'domain',
                    'country',
                    'language',
                    'countries',
                    'languages',
                    'da',
                    'dr',
                    'traffic',
                    'price',
                    'category',
                    'link_type',
                    'publication_time',
                    'verified',
                    'site_image',
                    'screenshot_path',
                    'screenshot_thumb_path',
                    'favicon_path',
                    'featured_until',
                ]);

            return $sites->values()->map(fn (Site $site) => $this->mapTeaser($site));
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Catalog-visible site count for primary countries. Null when zero or the query fails.
     *
     * @param  list<string>  $codes
     */
    public function countForCountries(array $codes): ?int
    {
        $codes = $this->normalizeCodes($codes);
        if ($codes === []) {
            return null;
        }

        try {
            $counts = $this->countries->counts();
            $total = 0;
            foreach ($codes as $code) {
                $total += (int) ($counts[$code] ?? 0);
            }

            return $total > 0 ? $total : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lowest advertiser checkout price for catalog-visible sites in $codes.
     *
     * @param  list<string>  $codes
     */
    public function priceFromForCountries(array $codes): ?float
    {
        $codes = $this->normalizeCodes($codes);
        if ($codes === []) {
            return null;
        }

        try {
            $query = Site::query()->catalogVisible();
            $this->countries->constrainQueryToPrimaryCountries($query, $codes);
            $min = $query->min('price');
            if ($min === null) {
                return null;
            }

            $advertiser = $this->fees->advertiserBase((float) $min);

            return $advertiser > 0 ? $advertiser : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function normalizeCodes(array $codes): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($code) => strtolower(trim((string) $code)),
            $codes
        ))));
    }

    public function maskDomain(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./i', '', $host) ?: $host;
        if ($host === '') {
            return 'site**.com';
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            $stem = $this->maskStem($host);

            return $stem.'**';
        }

        $tld = array_pop($parts);
        if (
            $parts !== []
            && in_array($tld, ['uk', 'au'], true)
            && strlen((string) end($parts)) <= 3
        ) {
            $tld = array_pop($parts).'.'.$tld;
        }

        $name = implode('.', $parts);

        return $this->maskStem($name).'**.'.$tld;
    }

    private function maskStem(string $name): string
    {
        $chunks = preg_split('/[^a-z0-9]+/i', $name, 2);
        $first = strtolower((string) (is_array($chunks) ? ($chunks[0] ?? '') : ''));
        if ($first === '') {
            $first = 'site';
        }

        $keep = min(8, max(3, strlen($first)));

        return substr($first, 0, $keep);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTeaser(Site $site): array
    {
        $host = $site->domain ?: (parse_url((string) $site->site_url, PHP_URL_HOST) ?: $site->site_url);
        $host = preg_replace('/^www\./i', '', (string) $host) ?: (string) $host;
        $country = $site->primaryCountryCode() ?: $site->country;
        $language = $site->primaryLanguageCode() ?: $site->language;
        $thumb = $site->screenshot_thumb_url ?: $site->logo_url;
        $categories = array_values(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            preg_split('/[,;|]+/', (string) ($site->category ?? '')) ?: []
        )));

        return [
            'name' => $site->site_name ?: $host,
            'domain_masked' => $this->maskDomain((string) $host),
            'country' => $country ? strtolower((string) $country) : null,
            'language' => $language ? strtolower((string) $language) : null,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $this->fees->advertiserBase((float) $site->price),
            'categories' => array_slice($categories, 0, 2),
            'more_cats' => max(0, count($categories) - 2),
            'link_type' => $site->link_type ?: 'DoFollow',
            'turnaround' => $site->publication_time ?: '7 days',
            'verified' => (bool) $site->verified,
            'thumb_url' => $thumb,
        ];
    }
}
