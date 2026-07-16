<?php

namespace App\Services\SiteEnrichment\Providers;

use App\Contracts\SiteMetricsProvider;
use App\DTOs\SiteMetricsSnapshot;
use App\Models\Site;

class ManualMetricsProvider implements SiteMetricsProvider
{
    public function key(): string
    {
        return 'manual';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fetch(Site $site): SiteMetricsSnapshot
    {
        return new SiteMetricsSnapshot(
            domainRating: $site->dr !== null ? (int) $site->dr : null,
            domainAuthority: $site->da !== null ? (int) $site->da : null,
            monthlyOrganicTraffic: $site->traffic !== null ? (int) $site->traffic : null,
            provider: $this->key(),
            raw: ['source' => 'manual_or_existing'],
            success: true,
        );
    }
}
