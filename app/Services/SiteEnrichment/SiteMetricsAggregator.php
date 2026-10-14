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
     * True when at least one live SEO vendor has credentials.
     */
    public function anyApiProviderConfigured(): bool
    {
        foreach (['ahrefs', 'moz', 'semrush'] as $key) {
            if ($this->resolve($key)->isConfigured()) {
                return true;
            }
        }

        return false;
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
        $contributors = [];
        $raw = [];

        foreach ($order as $key) {
            if (! isset($this->providers[$key])) {
                continue;
            }

            $provider = $this->resolve($key);
            if (! $provider->isConfigured()) {
                if ($key !== 'manual') {
                    $errors[] = $key.': not configured (missing API key)';
                    Log::warning('Site metrics provider is not configured', [
                        'site_id' => $site->id,
                        'provider' => $key,
                    ]);
                }

                continue;
            }

            $result = $provider->fetch($site);
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

            $contributed = false;
            if ($result->domainRating !== null && $dr === null) {
                $dr = $result->domainRating;
                $contributed = true;
            }
            if ($result->domainAuthority !== null && $da === null) {
                $da = $result->domainAuthority;
                $contributed = true;
            }
            if ($result->monthlyOrganicTraffic !== null && $traffic === null) {
                $traffic = $result->monthlyOrganicTraffic;
                $contributed = true;
            }
            $raw[$key] = $result->raw;
            if ($contributed) {
                $contributors[] = $key;
            }

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
            provider: $contributors !== []
                ? implode(',', array_values(array_unique($contributors)))
                : 'manual',
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
