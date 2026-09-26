<?php

namespace App\Mail;

use App\Models\Site;

/**
 * Plain copy of the listing fields a status email needs.
 *
 * The queued mail used to store the Site model. After a hard delete the worker
 * reloaded that row and failed with "No query results for model [Site]".
 */
class SiteStatusMailView
{
    public function __construct(
        public int $id,
        public string $site_name,
        public string $site_url,
        public mixed $category,
        public mixed $price,
        public mixed $da,
        public mixed $dr,
        public mixed $traffic,
        public bool $active,
        public bool $catalogVisible,
        public ?string $publisherName,
    ) {}

    public static function fromSite(Site $site): self
    {
        $catalogVisible = (bool) $site->active;
        try {
            $catalogVisible = $site->isCatalogVisible();
        } catch (\Throwable) {
            $catalogVisible = (bool) $site->active;
        }

        return new self(
            id: (int) $site->getKey(),
            site_name: (string) ($site->site_name ?? ''),
            site_url: (string) ($site->site_url ?? ''),
            category: $site->category,
            price: $site->price ?? 0,
            da: $site->da,
            dr: $site->dr,
            traffic: $site->traffic ?? 0,
            active: (bool) $site->active,
            catalogVisible: $catalogVisible,
            publisherName: $site->publisher?->name,
        );
    }

    public function isCatalogVisible(): bool
    {
        return $this->catalogVisible;
    }

    public function publisher(): ?object
    {
        if ($this->publisherName === null || $this->publisherName === '') {
            return null;
        }

        return (object) ['name' => $this->publisherName];
    }
}
