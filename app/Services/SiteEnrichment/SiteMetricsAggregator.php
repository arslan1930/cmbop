<?php

namespace App\Services\SiteEnrichment;

use App\Contracts\SiteMetricsProvider;
use App\DTOs\SiteMetricsSnapshot;
use App\Models\Site;
use App\Services\SiteEnrichment\Providers\AhrefsMetricsProvider;
use App\Services\SiteEnrichment\Providers\ManualMetricsProvider;
use App\Services\SiteEnrichment\Providers\MozMetricsProvider;
use App\Services\SiteEnrichment\Providers\SemrushMetricsProvider;
use Illuminate\Support\Facades\Log;

class SiteMetricsAggregator
{
    /** @var array<string, class-string<SiteMetricsProvider>> */
    private array $providers = [
        'manual' => ManualMetricsProvider::class,
        'ahrefs' => AhrefsMetricsProvider::class,
        'moz' => MozMetricsProvider::class,
        'semrush' => SemrushMetricsProvider::class,
    ];

    public function resolve(string $key): SiteMetricsProvider
    {
        $class = $this->providers[$key] ?? ManualMetricsProvider::class;

        return app($class);
    }

    /**
     * Fetch metrics using configured primary + fallbacks.
     * Never fabricates values — only returns what providers give.
     *
     * @return array{snapshot: SiteMetricsSnapshot, errors: list<string>, providers_used: list<string>}
     */
    public function fetch(Site $site, ?string $forceProvider = null): array
    {
        if ($site->metrics_manual) {
            $manual = $this->resolve('manual')->fetch($site);

            return [
                'snapshot' => $manual,
                'errors' => [],
                'providers_used' => ['manual'],
            ];
        }

        $primary = $forceProvider ?: (string) config('site_enrichment.default_provider', 'manual');
        $fallbacks = config('site_enrichment.fallback_providers', ['manual']);
        $order = array_values(array_unique(array_filter(array_merge([$primary], (array) $fallbacks))));

        $dr = null;
        $da = null;
        $traffic = null;
        $errors = [];
        $used = [];
        $raw = [];

        foreach ($order as $key) {
            if (! isset($this->providers[$key])) {
                continue;
            }

            $result = $this->resolve($key)->fetch($site);
            $used[] = $key;

            if (! $result->success) {
                $errors[] = $key.': '.($result->error ?: 'unknown error');
                Log::info('Site metrics provider failed', [
                    'site_id' => $site->id,
                    'provider' => $key,
                    'error' => $result->error,
                ]);
                continue;
            }

            if ($result->domainRating !== null && $dr === null) {
                $dr = $result->domainRating;
            }
            if ($result->domainAuthority !== null && $da === null) {
                $da = $result->domainAuthority;
            }
            if ($result->monthlyOrganicTraffic !== null && $traffic === null) {
                $traffic = $result->monthlyOrganicTraffic;
            }
            $raw[$key] = $result->raw;

            if ($dr !== null && $da !== null && $traffic !== null) {
                break;
            }
        }

        // Preserve existing DB values when providers omit a field (do not blank out good data).
        $dr ??= $site->dr !== null ? (int) $site->dr : null;
        $da ??= $site->da !== null ? (int) $site->da : null;
        $traffic ??= $site->traffic !== null ? (int) $site->traffic : null;

        $snapshot = new SiteMetricsSnapshot(
            domainRating: $dr,
            domainAuthority: $da,
            monthlyOrganicTraffic: $traffic,
            provider: $primary,
            raw: $raw,
            success: $dr !== null || $da !== null || $traffic !== null || empty($errors),
            error: $errors ? implode('; ', $errors) : null,
        );

        return [
            'snapshot' => $snapshot,
            'errors' => $errors,
            'providers_used' => $used,
        ];
    }

    public function availableProviders(): array
    {
        return array_keys($this->providers);
    }
}
