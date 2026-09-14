<?php

namespace App\Services\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Groups catalog rows that share a normalised marketplace domain.
 *
 * Uniqueness should prevent this for new listings; leftover archived /
 * cancelled-bulk rows and URL/domain drift still show up here for staff.
 */
class DuplicateDomainReport
{
    /**
     * @return Collection<int, array{domain: string, count: int, sites: list<array<string, mixed>>}>
     */
    public function groups(): Collection
    {
        if (! Schema::hasTable('sites')) {
            return collect();
        }

        $select = ['id', 'publisher_id', 'site_name', 'site_url', 'domain', 'verified', 'active'];
        if (Site::hasSitesColumn('archived_at')) {
            $select[] = 'archived_at';
        }

        $grouped = [];
        foreach (Site::query()->select($select)->with('publisher:id,name,email')->cursor() as $site) {
            $host = trim((string) ($site->domain ?: ''));
            if ($host === '' && is_string($site->site_url)) {
                $parsed = parse_url($site->site_url, PHP_URL_HOST);
                $host = is_string($parsed) ? $parsed : '';
            }
            $normalized = Site::normalizeMarketplaceDomain($host);
            if ($normalized === '') {
                continue;
            }
            $grouped[$normalized][] = $this->row($site, $normalized);
        }

        return collect($grouped)
            ->filter(fn (array $sites) => count($sites) > 1)
            ->map(fn (array $sites, string $domain) => [
                'domain' => $domain,
                'count' => count($sites),
                'sites' => $sites,
            ])
            ->sortByDesc('count')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Site $site, string $normalized): array
    {
        $publisher = $site->publisher;

        return [
            'id' => (int) $site->id,
            'site_name' => (string) ($site->site_name ?: '—'),
            'site_url' => (string) ($site->site_url ?: ''),
            'domain' => (string) ($site->domain ?: ''),
            'normalized' => $normalized,
            'verified' => (bool) $site->verified,
            'active' => (bool) $site->active,
            'archived' => $site->isArchived(),
            'publisher_id' => $site->publisher_id,
            'publisher_name' => $publisher instanceof User ? (string) $publisher->name : 'Unknown',
            'publisher_email' => $publisher instanceof User ? (string) $publisher->email : '',
            'edit_url' => route('admin.sites.edit', $site->id),
            'publisher_url' => $publisher instanceof User ? $publisher->adminShowUrl() : null,
        ];
    }
}
