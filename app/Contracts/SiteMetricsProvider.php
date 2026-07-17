<?php

namespace App\Contracts;

use App\DTOs\SiteMetricsSnapshot;
use App\Models\Site;

interface SiteMetricsProvider
{
    public function key(): string;

    public function isConfigured(): bool;

    /**
     * Fetch SEO metrics for a site.
     * Must not invent values — return null metrics when unavailable.
     */
    public function fetch(Site $site): SiteMetricsSnapshot;
}
