<?php

namespace App\DTOs;

class SiteMetricsSnapshot
{
    public function __construct(
        public readonly ?int $domainRating = null,
        public readonly ?int $domainAuthority = null,
        public readonly ?int $monthlyOrganicTraffic = null,
        public readonly ?string $provider = null,
        public readonly array $raw = [],
        public readonly bool $success = false,
        public readonly ?string $error = null,
    ) {
    }

    public function hasAnyMetric(): bool
    {
        return $this->domainRating !== null
            || $this->domainAuthority !== null
            || $this->monthlyOrganicTraffic !== null;
    }

    public static function failure(string $provider, string $error): self
    {
        return new self(provider: $provider, success: false, error: $error);
    }
}
