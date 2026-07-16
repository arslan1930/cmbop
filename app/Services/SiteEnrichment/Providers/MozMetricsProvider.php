<?php

namespace App\Services\SiteEnrichment\Providers;

use App\Contracts\SiteMetricsProvider;
use App\DTOs\SiteMetricsSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MozMetricsProvider implements SiteMetricsProvider
{
    public function key(): string
    {
        return 'moz';
    }

    public function isConfigured(): bool
    {
        return filled(config('site_enrichment.providers.moz.access_token'))
            || (filled(config('site_enrichment.providers.moz.access_id'))
                && filled(config('site_enrichment.providers.moz.secret_key')));
    }

    public function fetch(Site $site): SiteMetricsSnapshot
    {
        $token = (string) config('site_enrichment.providers.moz.access_token');
        $base = rtrim((string) config('site_enrichment.providers.moz.base_url'), '/');

        if (! $this->isConfigured()) {
            return SiteMetricsSnapshot::failure($this->key(), 'Moz access token is not configured.');
        }

        if ($token === '' && filled(config('site_enrichment.providers.moz.access_id'))) {
            // Basic auth fallback for Moz API credentials.
            $token = base64_encode(
                (string) config('site_enrichment.providers.moz.access_id').':'.
                (string) config('site_enrichment.providers.moz.secret_key')
            );
        }

        try {
            $target = $site->site_url ?: ('https://'.$site->domain);
            $request = Http::timeout(20)->acceptJson();

            if (filled(config('site_enrichment.providers.moz.access_token'))) {
                $request = $request->withToken((string) config('site_enrichment.providers.moz.access_token'));
            } else {
                $request = $request->withBasicAuth(
                    (string) config('site_enrichment.providers.moz.access_id'),
                    (string) config('site_enrichment.providers.moz.secret_key')
                );
            }

            $response = $request->post($base.'/url_metrics', [
                'targets' => [$target],
            ]);

            if (! $response->successful()) {
                Log::warning('Moz metrics fetch failed', [
                    'site_id' => $site->id,
                    'status' => $response->status(),
                ]);

                return SiteMetricsSnapshot::failure($this->key(), 'Moz API returned HTTP '.$response->status());
            }

            $data = $response->json();
            $row = data_get($data, 'results.0', $data);
            $da = data_get($row, 'domain_authority')
                ?? data_get($row, 'domainAuthority')
                ?? data_get($row, 'moz_rank_url');

            return new SiteMetricsSnapshot(
                domainRating: null,
                domainAuthority: $da !== null ? (int) round((float) $da) : null,
                monthlyOrganicTraffic: null,
                provider: $this->key(),
                raw: is_array($data) ? $data : [],
                success: true,
            );
        } catch (\Throwable $e) {
            Log::error('Moz metrics exception', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return SiteMetricsSnapshot::failure($this->key(), $e->getMessage());
        }
    }
}
