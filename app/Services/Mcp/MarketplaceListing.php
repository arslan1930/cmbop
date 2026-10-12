<?php

namespace App\Services\Mcp;

use App\Http\Controllers\Advertiser\CatalogController;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use App\Services\Catalog\SiteUrlVisibility;
use App\Support\SiteTag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketplaceListing
{
    public function __construct(
        private readonly CatalogController $catalog,
        private readonly SiteUrlVisibility $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{sites: list<array<string, mixed>>, meta: array<string, mixed>, filters: array<string, mixed>}
     */
    public function search(User $user, array $filters): array
    {
        $limit = $this->clampLimit($filters['limit'] ?? null);
        $request = $this->listingRequest($user, $filters, $limit);

        $listing = $this->catalog->listingForExternalRequest($request);
        $sites = $listing['sites'];

        try {
            $this->visibility->ensureSchema();
            $this->visibility->warmFor($user, $sites->getCollection());
        } catch (\Throwable $e) {
            report($e);
        }

        $rows = [];
        foreach ($sites as $site) {
            $rows[] = $this->present($user, $site);
            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'sites' => $rows,
            'meta' => [
                'total' => $sites->total(),
                'returned' => count($rows),
                'limit' => $limit,
                'page' => $sites->currentPage(),
                'currency' => 'EUR',
            ],
            'filters' => $this->appliedFilters($filters),
        ];
    }

    public function details(User $user, int|string|null $siteId = null, ?string $domain = null): ?array
    {
        $site = $this->findVisibleSite($siteId, $domain);
        if ($site === null) {
            return null;
        }

        try {
            $this->visibility->ensureSchema();
            $this->visibility->warmFor($user, collect([$site]));
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->present($user, $site, detailed: true);
    }

    /**
     * @return array{countries: list<array{code: string, name: string}>, languages: list<array{code: string, name: string}>, niches: list<string>}
     */
    public function vocab(): array
    {
        $countries = Country::query()->marketplace()->orderBy('name')->get(['code', 'name'])
            ->map(static fn (Country $row): array => [
                'code' => strtolower((string) $row->code),
                'name' => (string) $row->name,
            ])->values()->all();

        $languages = Language::query()->marketplace()->orderBy('name')->get(['code', 'name'])
            ->map(static fn (Language $row): array => [
                'code' => strtolower((string) $row->code),
                'name' => (string) $row->name,
            ])->values()->all();

        return [
            'countries' => $countries,
            'languages' => $languages,
            'niches' => Category::catalogPickerNames(),
        ];
    }

    public function findVisibleSite(int|string|null $siteId = null, ?string $domain = null): ?Site
    {
        $query = Site::query()->catalogVisible();

        $id = (int) $siteId;
        if ($id > 0) {
            return $query->whereKey($id)->first();
        }

        $host = $this->normalizeHost((string) $domain);
        if ($host === '') {
            return null;
        }

        return $query->where(function ($inner) use ($host) {
            $inner->where('domain', $host)
                ->orWhere('site_url', 'like', '%'.$host.'%');
        })->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $user, Site $site, bool $detailed = false): array
    {
        $prices = $site->catalogPricesForViewer($user);
        $sale = $prices['sale'] !== null ? round((float) $prices['sale'], 2) : null;
        $list = round((float) $prices['list'], 2);
        $row = [
            'id' => (int) $site->id,
            'name' => $this->visibility->nameFor($user, $site),
            'host' => $this->visibility->hostFor($user, $site),
            'price_eur' => $sale ?? $list,
            'price' => [
                'currency' => 'EUR',
                'list' => $list,
                'sale' => $sale,
            ],
            'da' => (int) ($site->da ?? 0),
            'dr' => (int) ($site->dr ?? 0),
            'traffic' => (int) ($site->traffic ?? 0),
            'country' => (string) ($site->country ?? ''),
            'language' => (string) ($site->language ?? ''),
            'niches' => method_exists($site, 'nicheBadgeLabels') ? $site->nicheBadgeLabels() : [],
            'dofollow' => strtolower((string) ($site->link_type ?? '')) !== 'nofollow',
            'sponsored_label' => $site->tagLabel(),
            'publication_time' => (string) ($site->publication_time ?? ''),
        ];

        if ($detailed) {
            $row['description'] = trim((string) ($site->description ?? ''));
            $row['example_url'] = $this->visibility->showsFullIdentity($user, $site)
                ? (string) ($site->example_url ?? '')
                : null;
            $row['catalog_url'] = url('/advertiser/catalog?site='.$site->id);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function listingRequest(User $user, array $filters, int $limit): Request
    {
        $perPage = $this->nearestPerPage($limit);
        $payload = [
            'per_page' => $perPage,
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'sort' => $this->sort($filters['sort'] ?? null),
        ];

        foreach ([
            'country' => 'country',
            'language' => 'language',
            'topic' => 'category',
            'niche' => 'category',
            'category' => 'category',
            'da_min' => 'da_min',
            'da_max' => 'da_max',
            'dr_min' => 'dr_min',
            'dr_max' => 'dr_max',
            'traffic_min' => 'traffic_min',
            'traffic_max' => 'traffic_max',
            'price_min' => 'price_min',
            'price_max' => 'price_max',
            'max_price' => 'price_max',
        ] as $from => $to) {
            if (isset($filters[$from]) && $filters[$from] !== '' && $filters[$from] !== null) {
                $payload[$to] = $filters[$from];
            }
        }

        if (! empty($filters['dofollow'])) {
            $payload['link_type'] = 'dofollow';
        } elseif (isset($filters['link_type']) && is_string($filters['link_type'])) {
            $payload['link_type'] = strtolower($filters['link_type']);
        }

        if (! empty($filters['sponsored']) || ($filters['sponsored_label'] ?? null) === true) {
            $payload['tag'] = SiteTag::SPONSORED;
        } elseif (! empty($filters['tag'])) {
            $payload['tag'] = $filters['tag'];
        }

        $request = Request::create('/advertiser/catalog', 'GET', $payload);
        $request->setUserResolver(static fn () => $user);
        try {
            $request->setLaravelSession(app('session')->driver());
        } catch (\Throwable) {
            // MCP calls are stateless; favorites still read via Auth::user().
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function appliedFilters(array $filters): array
    {
        return array_filter($filters, static fn ($value) => $value !== null && $value !== '');
    }

    private function clampLimit(mixed $limit): int
    {
        $value = (int) $limit;

        return max(1, min(50, $value > 0 ? $value : 10));
    }

    private function nearestPerPage(int $limit): int
    {
        foreach (CatalogUrlQuery::ALLOWED_PER_PAGE as $size) {
            if ($size >= $limit) {
                return $size;
            }
        }

        return 50;
    }

    private function sort(mixed $sort): string
    {
        $allowed = ['dr_desc', 'dr_asc', 'da_desc', 'da_asc', 'traffic_desc', 'price_asc', 'price_desc', 'newest', 'rating_desc'];
        $value = is_string($sort) ? strtolower(trim($sort)) : '';

        return in_array($value, $allowed, true) ? $value : 'dr_desc';
    }

    private function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }
}
