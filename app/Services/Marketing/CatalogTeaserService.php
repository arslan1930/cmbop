<?php

namespace App\Services\Marketing;

use App\Models\Site;
use App\Services\PlatformFeeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CatalogTeaserService
{
    public function __construct(
        private PlatformFeeService $fees,
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
                    'da',
                    'dr',
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

        return [
            'name' => $site->site_name ?: $host,
            'domain_masked' => $this->maskDomain((string) $host),
            'country' => $country ? strtolower((string) $country) : null,
            'language' => $language ? strtolower((string) $language) : null,
            'da' => $site->da,
            'dr' => $site->dr,
            'price' => $this->fees->advertiserBase((float) $site->price),
            'thumb_url' => $thumb,
        ];
    }
}
