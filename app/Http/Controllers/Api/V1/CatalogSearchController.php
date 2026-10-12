<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Advertiser\CatalogController;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Catalog\CatalogPromotedSiteMatch;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogSearchController extends Controller
{
    public function __construct(
        private readonly CatalogController $catalog,
        private readonly CatalogPromotedSiteMatch $siteMatch,
        private readonly SiteUrlVisibility $visibility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->mergeWebsiteGuess($request);

        $listing = $this->catalog->listingForExternalRequest($request);
        $sites = $listing['sites'];
        $user = $request->user();

        try {
            $this->visibility->ensureSchema();
            $this->visibility->warmFor($user, $sites->getCollection());
        } catch (\Throwable $e) {
            report($e);
        }

        $rows = [];
        foreach ($sites as $site) {
            $prices = $site->catalogPricesForViewer($user);

            $rows[] = [
                'name' => $this->visibility->nameFor($user, $site),
                'host' => $this->visibility->hostFor($user, $site),
                'price' => [
                    'currency' => 'EUR',
                    'list' => round((float) $prices['list'], 2),
                    'sale' => $prices['sale'] !== null ? round((float) $prices['sale'], 2) : null,
                ],
                'da' => (int) ($site->da ?? 0),
                'dr' => (int) ($site->dr ?? 0),
                'traffic' => (int) ($site->traffic ?? 0),
                'country' => (string) ($site->country ?? ''),
                'language' => (string) ($site->language ?? ''),
                'categories' => method_exists($site, 'nicheBadgeLabels')
                    ? $site->nicheBadgeLabels()
                    : [],
            ];
        }

        return response()->json([
            'status' => 'ok',
            'meta' => [
                'total' => $sites->total(),
                'page' => $sites->currentPage(),
                'per_page' => $sites->perPage(),
                'last_page' => $sites->lastPage(),
            ],
            'sites' => $rows,
        ]);
    }

    private function mergeWebsiteGuess(Request $request): void
    {
        $website = trim((string) $request->input('website', ''));
        if ($website === '') {
            return;
        }

        $guess = $this->siteMatch->fromUrl($website);
        if ($guess['host'] === '') {
            return;
        }

        if (trim((string) $request->input('category', '')) === '' && $guess['category'] !== '') {
            $request->merge([
                'category' => Category::canonicalizeCatalogCategoryParam($guess['category'])
                    ?: $guess['category'],
            ]);
        }
        if (trim((string) $request->input('country', '')) === '' && $guess['country'] !== '') {
            $request->merge(['country' => $guess['country']]);
        }
        if (trim((string) $request->input('language', '')) === '' && $guess['language'] !== '') {
            $request->merge(['language' => $guess['language']]);
        }
    }
}
