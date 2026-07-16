<?php

namespace App\Services\SiteEnrichment\Providers;

use App\Contracts\SiteMetricsProvider;
use App\DTOs\SiteMetricsSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemrushMetricsProvider implements SiteMetricsProvider
{
    public function key(): string
    {
        return 'semrush';
    }

    public function isConfigured(): bool
    {
        return filled(config('site_enrichment.providers.semrush.api_key'));
    }

    public function fetch(Site $site): SiteMetricsSnapshot
    {
        $apiKey = (string) config('site_enrichment.providers.semrush.api_key');
        $base = rtrim((string) config('site_enrichment.providers.semrush.base_url'), '/');

        if (! $this->isConfigured()) {
            return SiteMetricsSnapshot::failure($this->key(), 'SEMrush API key is not configured.');
        }

        try {
            $response = Http::timeout(20)->get($base.'/', [
                'type' => 'domain_ranks',
                'key' => $apiKey,
                'export_columns' => 'Dn,Rk,Or,Ot,Oc,Ad,At,Ac',
                'domain' => $site->domain,
                'database' => 'us',
            ]);

            if (! $response->successful()) {
                Log::warning('SEMrush metrics fetch failed', [
                    'site_id' => $site->id,
                    'status' => $response->status(),
                ]);

                return SiteMetricsSnapshot::failure($this->key(), 'SEMrush API returned HTTP '.$response->status());
            }

            $body = trim((string) $response->body());
            $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
            $traffic = null;

            if (count($lines) >= 2) {
                $cols = str_getcsv($lines[1], ';');
                // Ot = Organic Traffic typically at index 3 for this export set
                if (isset($cols[3]) && is_numeric($cols[3])) {
                    $traffic = (int) $cols[3];
                }
            }

            return new SiteMetricsSnapshot(
                domainRating: null,
                domainAuthority: null,
                monthlyOrganicTraffic: $traffic,
                provider: $this->key(),
                raw: ['body' => mb_substr($body, 0, 500)],
                success: true,
            );
        } catch (\Throwable $e) {
            Log::error('SEMrush metrics exception', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return SiteMetricsSnapshot::failure($this->key(), $e->getMessage());
        }
    }
}
