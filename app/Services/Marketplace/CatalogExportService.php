<?php

namespace App\Services\Marketplace;

use App\Http\Controllers\Advertiser\CatalogListingController;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogExportService
{
    public const MAX_ROWS = 200;

    /**
     * @return list<string>
     */
    public function headers(bool $includeUrl): array
    {
        $cols = ['site', 'country', 'language', 'category', 'da', 'dr', 'traffic', 'price_eur', 'link_type', 'rating', 'verified', 'publication_time'];
        if ($includeUrl) {
            array_splice($cols, 1, 0, ['url']);
        }

        return $cols;
    }

    public function download(Request $request, User $user): StreamedResponse
    {
        $visibility = app(SiteUrlVisibility::class);
        $sites = $this->collect($request, $user);
        $visibility->warmFor($user, $sites);
        $hideMode = $visibility->inHideMode($user);

        $filename = 'catalog-export-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($sites, $user, $visibility, $hideMode) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $this->headers(! $hideMode));
            foreach ($sites as $site) {
                fputcsv($out, $this->row($site, $user, $visibility, $hideMode));
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Collection<int, Site>
     */
    public function collect(Request $request, User $user): Collection
    {
        $params = CatalogUrlQuery::fromRequest($request);
        unset($params['page']);
        $params['per_page'] = '50';

        $collected = collect();
        for ($page = 1; $page <= 4 && $collected->count() < self::MAX_ROWS; $page++) {
            $params['page'] = (string) $page;
            $pageRequest = Request::create($request->url(), 'GET', $params);
            $pageRequest->setUserResolver(fn () => $user);
            $listing = app(CatalogListingController::class)->listingForExternalRequest($pageRequest);
            $sites = $listing['sites'] ?? collect();
            $items = collect($sites instanceof Collection ? $sites->all() : $sites->items());
            if ($items->isEmpty()) {
                break;
            }
            foreach ($items as $site) {
                if ($site instanceof Site) {
                    $collected->push($site);
                }
                if ($collected->count() >= self::MAX_ROWS) {
                    break 2;
                }
            }
            if ($items->count() < 50) {
                break;
            }
        }

        return $collected->unique('id')->values();
    }

    /**
     * @return list<string>
     */
    public function row(Site $site, User $user, SiteUrlVisibility $visibility, bool $hideMode): array
    {
        $shows = $visibility->showsFullIdentity($user, $site);
        $name = $shows
            ? (string) ($visibility->nameFor($user, $site) ?: $site->site_name)
            : (string) $visibility->hostFor($user, $site);
        $categories = method_exists($site, 'nicheBadgeLabels')
            ? implode(' | ', $site->nicheBadgeLabels())
            : (string) $site->category;
        $price = (float) $site->price;
        try {
            if (method_exists($site, 'catalogPricesForViewer')) {
                $prices = $site->catalogPricesForViewer($user);
                $price = (float) ($prices['sale'] ?? $prices['list'] ?? $site->price);
            }
        } catch (\Throwable) {
            $price = (float) $site->price;
        }
        $rating = ((int) ($site->rating_count ?? 0) > 0 && (int) ($site->completed_orders_count ?? 0) > 0)
            ? number_format((float) $site->rating_avg, 1)
            : '';

        $row = [
            $name,
            strtoupper((string) $site->country),
            strtolower((string) $site->language),
            $categories,
            (string) (int) $site->da,
            (string) (int) $site->dr,
            (string) (int) $site->traffic,
            number_format($price, 2, '.', ''),
            (string) $site->link_type,
            $rating,
            $site->verified ? 'yes' : 'no',
            (string) $site->publication_time,
        ];

        if (! $hideMode) {
            $url = $shows ? (string) ($visibility->rootedUrlFor($user, $site) ?: $site->site_url) : '';
            array_splice($row, 1, 0, [$url]);
        }

        return $row;
    }
}
