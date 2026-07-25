<?php

namespace App\Services\Marketing;

use App\Models\Site;
use App\Services\PlatformFeeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CatalogTeaserService
{
    /** Prefer at least this many distinct countries in the homepage hero. */
    private const MIN_COUNTRIES = 5;

    public function __construct(
        private PlatformFeeService $fees,
    ) {}

    /**
     * Homepage catalog showcase: live diversified inventory, topped up with
     * curated multi-country demo rows when the marketplace is sparse.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function showcase(int $limit = 7): Collection
    {
        $limit = max(1, min(24, $limit));
        $live = $this->teasers(max($limit, 12));

        $picked = collect();
        $seenCountries = [];
        $seenDomains = [];

        $push = function (array $row) use (&$picked, &$seenCountries, &$seenDomains, $limit): bool {
            if ($picked->count() >= $limit) {
                return false;
            }

            $domain = (string) ($row['domain_masked'] ?? '');
            if ($domain !== '' && isset($seenDomains[$domain])) {
                return true;
            }

            $picked->push($row);
            $code = strtolower((string) ($row['country'] ?? ''));
            if ($code !== '') {
                $seenCountries[$code] = true;
            }
            if ($domain !== '') {
                $seenDomains[$domain] = true;
            }

            return true;
        };

        // 1) One live site per country (already diversified by teasers()).
        foreach ($live as $row) {
            $code = strtolower((string) ($row['country'] ?? ''));
            if ($code !== '' && isset($seenCountries[$code])) {
                continue;
            }
            if (! $push($row)) {
                break;
            }
        }

        // 2) Fill missing markets from curated multi-country demos.
        foreach ($this->demoInventory() as $demo) {
            if (count($seenCountries) >= self::MIN_COUNTRIES && $picked->count() >= $limit) {
                break;
            }
            $code = strtolower((string) ($demo['country'] ?? ''));
            if ($code !== '' && isset($seenCountries[$code])) {
                continue;
            }
            if (! $push($demo + ['is_demo' => true])) {
                break;
            }
        }

        // 3) Top up remaining slots with leftover live rows, then demos.
        if ($picked->count() < $limit) {
            foreach ($live as $row) {
                if (! $push($row)) {
                    break;
                }
            }
        }

        if ($picked->count() < $limit) {
            foreach ($this->demoInventory() as $demo) {
                if (! $push($demo + ['is_demo' => true])) {
                    break;
                }
            }
        }

        return $picked->values();
    }

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
                ->where('active', true)
                ->where(function ($q) {
                    $q->where('verified', true)->orWhere('verified', 1);
                });

            if (Schema::hasColumn('sites', 'featured_until')) {
                $query->orderByRaw('(featured_until IS NOT NULL AND featured_until > ?) DESC', [now()]);
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
                    'category',
                    'categories',
                    'da',
                    'dr',
                    'traffic',
                    'price',
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
     * Distinct country codes represented in a teaser collection (for UI chips).
     *
     * @param  Collection<int, array<string, mixed>>  $teasers
     * @return list<string>
     */
    public function countryCodes(Collection $teasers): array
    {
        return $teasers
            ->pluck('country')
            ->filter()
            ->map(fn ($c) => strtolower((string) $c))
            ->unique()
            ->values()
            ->all();
    }

    public function maskDomain(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '••••••.com';
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return substr($host, 0, 1).str_repeat('*', max(3, strlen($host) - 1));
        }

        $tld = array_pop($parts);
        $name = implode('.', $parts);
        $visible = substr($name, 0, 1);

        return $visible.str_repeat('*', max(3, min(8, strlen($name) - 1))).'.'.$tld;
    }

    public function formatTraffic(int|float|null $traffic): string
    {
        $n = (float) ($traffic ?? 0);
        if ($n <= 0) {
            return '—';
        }
        if ($n >= 1000000) {
            $v = $n / 1000000;

            return rtrim(rtrim(number_format($v, $v >= 10 ? 0 : 1, '.', ''), '0'), '.').'M';
        }
        if ($n >= 1000) {
            $v = $n / 1000;

            return rtrim(rtrim(number_format($v, $v >= 10 ? 0 : 1, '.', ''), '0'), '.').'K';
        }

        return (string) (int) $n;
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
        $categories = method_exists($site, 'getCategoriesArrayAttribute')
            ? $site->getCategoriesArrayAttribute()
            : (array) ($site->categories ?? [$site->category]);
        $niche = collect($categories)->filter()->map(fn ($c) => trim((string) $c))->first() ?: 'General';

        return [
            'name' => $site->site_name ?: $host,
            'domain_masked' => $this->maskDomain((string) $host),
            'country' => $country ? strtolower((string) $country) : null,
            'language' => $language ? strtolower((string) $language) : null,
            'niche' => $niche,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => (int) ($site->traffic ?? 0),
            'traffic_label' => $this->formatTraffic($site->traffic ?? 0),
            'price' => $this->fees->advertiserBase((float) $site->price),
            'thumb_url' => $thumb,
            'is_demo' => false,
        ];
    }

    /**
     * Curated multi-market rows mirroring catalog presentation when live
     * inventory cannot yet show global coverage.
     *
     * @return list<array<string, mixed>>
     */
    private function demoInventory(): array
    {
        $rows = [
            ['name' => 'Nordic Business Daily', 'domain' => 'nordicbiz.se', 'country' => 'se', 'language' => 'sv', 'niche' => 'Business & Finance', 'dr' => 64, 'da' => 58, 'traffic' => 182000, 'price' => 320],
            ['name' => 'Paris Lifestyle Review', 'domain' => 'parisreview.fr', 'country' => 'fr', 'language' => 'fr', 'niche' => 'Lifestyle & Fashion', 'dr' => 71, 'da' => 63, 'traffic' => 410000, 'price' => 450],
            ['name' => 'Berlin Tech Pulse', 'domain' => 'berlintech.de', 'country' => 'de', 'language' => 'de', 'niche' => 'Technology & Gadgets', 'dr' => 69, 'da' => 57, 'traffic' => 427000, 'price' => 420],
            ['name' => 'Madrid Travel Atlas', 'domain' => 'madridatlas.es', 'country' => 'es', 'language' => 'es', 'niche' => 'Travel & Tourism', 'dr' => 62, 'da' => 54, 'traffic' => 156000, 'price' => 280],
            ['name' => 'US Growth Journal', 'domain' => 'usgrowth.com', 'country' => 'us', 'language' => 'en', 'niche' => 'Marketing & PR', 'dr' => 74, 'da' => 66, 'traffic' => 890000, 'price' => 520],
            ['name' => 'London Media Desk', 'domain' => 'londonmedia.co.uk', 'country' => 'uk', 'language' => 'en', 'niche' => 'News & Media', 'dr' => 68, 'da' => 61, 'traffic' => 305000, 'price' => 390],
            ['name' => 'Roma Wellness Hub', 'domain' => 'romawell.it', 'country' => 'it', 'language' => 'it', 'niche' => 'Health & Wellness', 'dr' => 58, 'da' => 51, 'traffic' => 98000, 'price' => 240],
            ['name' => 'Amsterdam Startup Wire', 'domain' => 'amsstartup.nl', 'country' => 'nl', 'language' => 'nl', 'niche' => 'Startups & Tech', 'dr' => 60, 'da' => 55, 'traffic' => 121000, 'price' => 260],
            ['name' => 'Warsaw Finance Brief', 'domain' => 'wawfinance.pl', 'country' => 'pl', 'language' => 'pl', 'niche' => 'Finance', 'dr' => 55, 'da' => 49, 'traffic' => 87000, 'price' => 210],
            ['name' => 'Lisbon Food Stories', 'domain' => 'lisbonfood.pt', 'country' => 'pt', 'language' => 'pt', 'niche' => 'Food & Drink', 'dr' => 52, 'da' => 47, 'traffic' => 64000, 'price' => 190],
        ];

        return array_map(function (array $row) {
            return [
                'name' => $row['name'],
                'domain_masked' => $this->maskDomain($row['domain']),
                'country' => $row['country'],
                'language' => $row['language'],
                'niche' => $row['niche'],
                'da' => $row['da'],
                'dr' => $row['dr'],
                'traffic' => $row['traffic'],
                'traffic_label' => $this->formatTraffic($row['traffic']),
                'price' => $this->fees->advertiserBase((float) $row['price']),
                'thumb_url' => null,
                'is_demo' => true,
            ];
        }, $rows);
    }
}
