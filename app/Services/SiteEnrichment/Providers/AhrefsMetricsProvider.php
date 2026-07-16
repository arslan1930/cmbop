<?php

namespace App\Services\SiteEnrichment\Providers;

use App\Contracts\SiteMetricsProvider;
use App\DTOs\SiteMetricsSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AhrefsMetricsProvider implements SiteMetricsProvider
{
    public function key(): string
    {
        return 'ahrefs';
    }

    public function isConfigured(): bool
    {
        return filled(config('site_enrichment.providers.ahrefs.api_token'));
    }

    public function fetch(Site $site): SiteMetricsSnapshot
    {
        $token = (string) config('site_enrichment.providers.ahrefs.api_token');
        $base = rtrim((string) config('site_enrichment.providers.ahrefs.base_url'), '/');

        if (! $this->isConfigured()) {
            return SiteMetricsSnapshot::failure($this->key(), 'Ahrefs API token is not configured.');
        }

        try {
            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->get($base.'/site-explorer/metrics', [
                    'target' => $site->domain,
                    'mode' => 'domain',
                ]);

            if (! $response->successful()) {
                Log::warning('Ahrefs metrics fetch failed', [
                    'site_id' => $site->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return SiteMetricsSnapshot::failure($this->key(), 'Ahrefs API returned HTTP '.$response->status());
            }

            $data = $response->json();
            $dr = data_get($data, 'domain_rating')
                ?? data_get($data, 'metrics.domain_rating')
                ?? data_get($data, 'domainRating');
            $traffic = data_get($data, 'org_traffic')
                ?? data_get($data, 'metrics.org_traffic')
                ?? data_get($data, 'organic_traffic');

            return new SiteMetricsSnapshot(
                domainRating: $dr !== null ? (int) round((float) $dr) : null,
                domainAuthority: null,
                monthlyOrganicTraffic: $traffic !== null ? (int) round((float) $traffic) : null,
                provider: $this->key(),
                raw: is_array($data) ? $data : [],
                success: true,
            );
        } catch (\Throwable $e) {
            Log::error('Ahrefs metrics exception', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return SiteMetricsSnapshot::failure($this->key(), $e->getMessage());
        }
    }
}
