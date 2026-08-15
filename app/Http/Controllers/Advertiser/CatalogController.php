<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Mail\ModificationRequested;
use App\Mail\OrderApprovedByAdvertiser;
use App\Mail\SiteOwnerOrderNotification;
use App\Models\Category;
use App\Models\ContentSubmission;
use App\Models\Country;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Models\UserBlacklist;
use App\Models\UserFavorite;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Advertiser\AdvertiserOrderSearchQuery;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\CartPricingService;
use App\Services\Catalog\CatalogCountryInventory;
use App\Services\Catalog\CatalogLanguageFilter;
use App\Services\Catalog\CatalogSearchQuery;
use App\Services\Catalog\CatalogUrlQuery;
use App\Services\Catalog\SiteUrlVisibility;
use App\Services\CheckoutIntentService;
use App\Services\CheckoutSchemaService;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use App\Services\LiveUrlHealthChecker;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\OrderChatContactGuard;
use App\Services\OrderPaymentService;
use App\Services\Orders\ContentRevisionService;
use App\Services\Orders\OrderClawbackService;
use App\Services\Orders\OrderRefundService;
use App\Services\PlatformFeeService;
use App\Services\StripeCustomerService;
use App\Services\StripePaymentService;
use App\Services\Wallet\WalletLedgerService;
use App\Services\WalletStripeDepositService;
use App\Support\AdvertiserOrderStatus;
use App\Support\UserFacingError;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class CatalogController extends Controller
{
    private function cartPricing(): CartPricingService
    {
        return app(CartPricingService::class);
    }

    /**
     * Advertiser-facing catalog list price (publisher base + hidden tiered portal fee).
     *
     * Used for other people's listings (bulk rail, etc.). Own listings are not
     * orderable and show the entered publisher price instead.
     */
    private function advertiserCatalogListPrice(float|int|string $publisherBase): float
    {
        return app(PlatformFeeService::class)
            ->advertiserBase((float) $publisherBase);
    }

    /**
     * Strip scheme / www / path so pasted URLs still match domain + site_url.
     */
    private function catalogSearchHostNeedle(string $search): ?string
    {
        $raw = trim($search);
        if ($raw === '') {
            return null;
        }

        $candidate = $raw;
        if (! preg_match('#^https?://#i', $candidate) && str_contains($candidate, '/')) {
            // "example.com/path" without a scheme — treat as a host/path paste.
            $candidate = 'https://'.$candidate;
        } elseif (preg_match('#^https?://#i', $candidate) === 0 && str_contains($candidate, '.')) {
            // Bare host (or host-like token); normalize via the URL parser.
            $candidate = 'https://'.$candidate;
        } elseif (preg_match('#^https?://#i', $candidate) === 0) {
            return null;
        }

        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?: '';

        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    /**
     * Marketplace countries (Europe + major North America).
     */
    private function getAvailableCountries()
    {
        return Country::marketplace()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtolower($code) => $name])
            ->all();
    }

    /**
     * Marketplace languages.
     */
    private function getAvailableLanguages()
    {
        return Language::marketplace()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtolower($code) => $name])
            ->all();
    }

    /**
     * Catalog niche options — loaded from the categories table (same source as
     * publisher/admin). Prefer Category::catalogPickerRows() / catalogPickerNames().
     *
     * @return list<array{name: string, group: string}>
     */
    private function getAvailableCategories(): array
    {
        return Category::catalogPickerRows();
    }

    // Update your index method
    public function index(Request $request)
    {
        $currentUser = auth()->user();

        // Content Library → Catalog: keep the active article in session for cart assign.
        // Do not pre-filter language/country — advertisers pick filters manually.
        $orderingSubmission = $this->resolveActiveLibraryOrdering($request);

        $listing = $this->buildCatalogListing($request);
        $sites = $listing['sites'];
        $favorites = $listing['favorites'];
        $blacklist = $listing['blacklist'];
        $showBlacklistedOnly = $listing['showBlacklistedOnly'];

        // Get predefined countries for filter dropdown (flat map kept for compat).
        $availableCountries = $this->getAvailableCountries();
        $selectedCountryCodes = array_values(array_filter(array_map(
            static fn ($c) => strtolower(trim((string) $c)),
            explode(',', search_text($request->input('country')))
        )));
        try {
            $countryPicker = app(CatalogCountryInventory::class)
                ->pickerSections($selectedCountryCodes);
            $countryPickerSections = $countryPicker['sections'];
            $countryPickerGroups = $countryPicker['groups'];
        } catch (\Throwable $e) {
            Log::warning('Catalog country picker failed', ['error' => $e->getMessage()]);
            $countryPickerSections = [];
            $countryPickerGroups = [];
        }

        // Get predefined languages for filter dropdown
        $availableLanguages = $this->getAvailableLanguages();

        // Niches from categories table (not a hardcoded controller list).
        $predefinedCategories = $this->getAvailableCategories();
        try {
            $siteCategories = Category::catalogPickerNames();
        } catch (\Throwable $e) {
            Log::warning('Catalog niche picker failed', ['error' => $e->getMessage()]);
            $siteCategories = $predefinedCategories;
        }

        // Drop hidden/owned lines before the banner, wizard chrome, and header badge render.
        $cartRemovedInactive = $this->syncPrunedSessionCart();
        $cart = session()->get('cart', []);

        // Bulk discount marketplace section — follows Catalog country= (Option 1).
        // Option 2: hide the Spendable rail when More → Bulk deals only is on
        // (the results table is already bulk-only).
        $bulkDeals = collect();
        if (! ($request->input('bulk_deals') == '1' || $request->input('bulk_deals') === 1)) {
            try {
                $bulkDeals = $this->loadBulkDeals($request, $blacklist, $showBlacklistedOnly);
            } catch (\Throwable $e) {
                Log::warning('Catalog bulk deals rail failed', ['error' => $e->getMessage()]);
            }
        }

        $featurePrice = (float) config('site_promotions.feature.price', 10);
        $featureDays = (int) config('site_promotions.feature.days', 7);

        $approvedArticleCount = 0;
        try {
            $orderableScope = ContentSubmission::query()
                ->where('user_id', auth()->id())
                ->orderable();

            // Count must not reuse a limited list — same exists-style gate as the dashboard.
            $approvedArticleCount = (clone $orderableScope)->count();
        } catch (\Throwable $e) {
            Log::warning('Catalog orderable article count failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);
        }

        // Resolve domain visibility for the whole page in one query, and hand the
        // service to the view so no template reads site_url directly.
        $urlVisibility = app(SiteUrlVisibility::class);
        $urlVisibility->ensureSchema();
        $urlVisibility->warmFor($currentUser, $sites->getCollection());

        $catalogWallet = auth()->user()->activeWallet();
        $catalogBonusBalance = $catalogWallet ? (float) $catalogWallet->lockedBonusBalance() : 0.0;
        $catalogCashBalance = $catalogWallet ? (float) $catalogWallet->withdrawableBalance() : 0.0;
        $catalogSpendableBalance = (float) ($catalogWallet?->balance ?? 0);

        return view('advertiser.catalog', compact(
            'sites',
            'availableLanguages',
            'availableCountries',
            'countryPickerSections',
            'countryPickerGroups',
            'predefinedCategories',
            'siteCategories',
            'favorites',
            'blacklist',
            'cart',
            'cartRemovedInactive',
            'showBlacklistedOnly',
            'bulkDeals',
            'featurePrice',
            'featureDays',
            'orderingSubmission',
            'approvedArticleCount',
            'catalogBonusBalance',
            'catalogCashBalance',
            'catalogSpendableBalance',
            'currentUser',
            'urlVisibility'
        ));
    }

    /**
     * HTML fragment of catalog rows (table + cards + pagination) for live search.
     * Same filters/sort/pagination as the full catalog page.
     */
    public function results(Request $request)
    {
        if (! config('catalog.live_search.enabled', true)) {
            abort(404);
        }

        $currentUser = auth()->user();
        $listing = $this->buildCatalogListing($request);

        $urlVisibility = app(SiteUrlVisibility::class);
        $urlVisibility->ensureSchema();
        $urlVisibility->warmFor($currentUser, $listing['sites']->getCollection());

        return response()
            ->view('advertiser.partials.catalog-results', [
                'sites' => $listing['sites'],
                'favorites' => $listing['favorites'],
                'blacklist' => $listing['blacklist'],
                'currentUser' => $currentUser,
                'urlVisibility' => $urlVisibility,
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * HTML fragment of the bulk deals rail for live country / filter sync.
     * Same country= matching as the main catalog listing (Option 1 — no extra UI).
     */
    public function bulkDeals(Request $request)
    {
        if (! config('catalog.live_search.enabled', true)) {
            abort(404);
        }

        // Option 2: More → Bulk deals only — table is bulk-only; return empty rail.
        if ($request->input('bulk_deals') == '1' || $request->input('bulk_deals') === 1) {
            return response()
                ->view('advertiser.partials.catalog-bulk-deals', [
                    'bulkDeals' => collect(),
                    'urlVisibility' => app(SiteUrlVisibility::class),
                ])
                ->header('Cache-Control', 'no-store, private');
        }

        $blacklist = UserBlacklist::where('user_id', auth()->id())->pluck('site_id')->toArray();
        $showBlacklistedOnly = search_text($request->input('blacklist_filter')) === '1';
        $bulkDeals = $this->loadBulkDeals($request, $blacklist, $showBlacklistedOnly);

        $urlVisibility = app(SiteUrlVisibility::class);

        return response()
            ->view('advertiser.partials.catalog-bulk-deals', [
                'bulkDeals' => $bulkDeals,
                'urlVisibility' => $urlVisibility,
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Active bulk-discount sites for the catalog rail.
     *
     * When country= / language= are set, uses the same constraints as the
     * main listing (primary country; language offers that code).
     *
     * @param  array<int, int>  $blacklist
     * @return Collection<int, Site>
     */
    private function loadBulkDeals(Request $request, array $blacklist, bool $showBlacklistedOnly)
    {
        if (! Schema::hasColumn('sites', 'bulk_discount_enabled')) {
            return collect();
        }

        $query = Site::query()
            ->catalogVisible()
            ->where('bulk_discount_enabled', 1)
            ->whereNotNull('bulk_discount_percent');

        // Dual-role publishers cannot order their own listings.
        if (auth()->id()) {
            $uid = (int) auth()->id();
            $query->where(function ($q) use ($uid) {
                $q->whereNull('publisher_id')
                    ->orWhere('publisher_id', '!=', $uid);
            });
            if (Schema::hasColumn('sites', 'owner_id')) {
                $query->where(function ($q) use ($uid) {
                    $q->whereNull('owner_id')
                        ->orWhere('owner_id', '!=', $uid);
                });
            }
        }

        // Same blacklist browse modes as the main listing.
        if ($showBlacklistedOnly) {
            if (! empty($blacklist)) {
                $query->whereIn('id', $blacklist);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif (! empty($blacklist)) {
            $query->whereNotIn('id', $blacklist);
        }

        $bulkCountry = search_text($request->input('country'));
        if ($bulkCountry !== '') {
            $countries = array_values(array_filter(array_map(function ($c) {
                return strtolower(trim($c));
            }, explode(',', $bulkCountry))));
            if ($countries !== []) {
                // Primary country only (scalar sites.country) — matches catalog flag.
                app(CatalogCountryInventory::class)
                    ->constrainQueryToPrimaryCountries($query, $countries);
            }
        }

        $bulkLanguage = search_text($request->input('language'));
        if ($bulkLanguage !== '') {
            // Option A: all sites offering these languages (AND with country above).
            app(CatalogLanguageFilter::class)
                ->constrainQuery($query, explode(',', $bulkLanguage));
        }

        $bulkDeals = $query
            ->orderByDesc('bulk_discount_percent')
            ->orderByDesc('dr')
            // Enough for several 6-deal batches without loading the whole catalog.
            ->limit(36)
            ->get()
            ->reject(fn (Site $site) => $site->isOwnedBy(auth()->user()))
            ->values();

        foreach ($bulkDeals as $dealSite) {
            // Pack totals use CartPricingService so the rail “now” price floors
            // at publisher payout the same way checkout does.
            $packQty = (int) config('site_promotions.bulk.min_qty', 3);
            $packPricing = $this->cartPricing()->priceForAdvertiser($dealSite, null, $packQty);
            $dealSite->bulk_pack_qty = $packQty;
            $dealSite->bulk_pack_list_total = round($packPricing['list_total'] * $packQty, 2);
            $dealSite->bulk_pack_now_total = round($packPricing['total'] * $packQty, 2);
            // Badge % must match better-of pricing (custom can beat bulk on the pack).
            $dealSite->bulk_pack_discount_percent = (float) ($packPricing['discount_percent'] ?? 0);
            $customPct = $dealSite->activeCustomDiscountPercent();
            $bulkPct = (float) ($dealSite->bulk_discount_percent ?? 0);
            $dealSite->bulk_pack_badge_kind = ($customPct !== null && (float) $customPct >= $bulkPct)
                ? 'sale'
                : 'bulk';
            $dealSite->original_price = $dealSite->price;
            $dealSite->price = $this->advertiserCatalogListPrice($dealSite->price);
        }

        return $bulkDeals;
    }

    /**
     * Shared listing query for the full catalog page and the results partial.
     *
     * @return array{
     *     sites: LengthAwarePaginator,
     *     favorites: array<int, int>,
     *     blacklist: array<int, int>,
     *     showBlacklistedOnly: bool
     * }
     */
    private function buildCatalogListing(Request $request): array
    {
        // Hostinger often deploys without migrate — ensure placement JSON columns exist
        // so Site Details can show Homepage promotions + Social when publishers offer them.
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();
        } catch (\Throwable $e) {
            Log::warning('Catalog schema ensure failed', ['error' => $e->getMessage()]);
        }

        $userId = auth()->id();
        $currentUser = auth()->user();

        $favorites = UserFavorite::where('user_id', $userId)->pluck('site_id')->toArray();
        $blacklist = UserBlacklist::where('user_id', $userId)->pluck('site_id')->toArray();

        $query = Site::query()->catalogVisible();

        // Free-text search: name / category / domain (always open for advertisers).
        // Hide mode only masks how rows render — it does not limit domain matches.
        // Metric tokens (da>40, traffic 10k+) become range filters — not LIKE.
        // Country & language stay on the dedicated multi-selects.
        // Parse before blacklist so a name search can still surface blocked rows.
        $catalogSearch = app(CatalogSearchQuery::class);
        $rawSearch = search_text($request->input('search'));
        $parsedSearch = $catalogSearch->parse($rawSearch);
        $searchMerge = $catalogSearch->mergeIntoRequestInput(
            $rawSearch,
            $parsedSearch['text'],
            $parsedSearch['ranges'],
            $request->all()
        );
        if ($searchMerge !== []) {
            $request->merge($searchMerge);
        }
        $searchText = search_text($request->input('search'));

        // Blacklist filter / browse hide — but free-text search includes matches
        // (dimmed via blacklisted-row) so buyers can find and unblock them.
        $showBlacklistedOnly = $request->filled('blacklist_filter') && $request->blacklist_filter == 1;
        $searchIncludesBlacklisted = $searchText !== '';

        if ($showBlacklistedOnly) {
            if (! empty($blacklist)) {
                $query->whereIn('id', $blacklist);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif (! empty($blacklist) && ! $searchIncludesBlacklisted) {
            $query->whereNotIn('id', $blacklist);
        }

        $siteId = filter_number($request->input('site'));
        if ($siteId !== null && (int) $siteId > 0) {
            $query->where('id', (int) $siteId);
        }

        if ($searchText !== '') {
            // Free-text search matches name / category / domain for every advertiser.
            // Hide mode only changes how rows paint (mask + eye) — it does not
            // gate domain matching. Revealed-id allow-lists are unused when
            // searchAllDomains is true (kept for the legacy code path / tests).
            $hostNeedle = $this->catalogSearchHostNeedle($searchText);
            $catalogSearch->applyTextConstraints(
                $query,
                $searchText,
                searchableUrlIds: collect(),
                hostNeedle: $hostNeedle,
                searchAllDomains: true,
            );
        }

        if ($request->filled('verified') && $request->verified == 1) {
            $query->where('verified', 1);
        }

        // Optional buyer quality gate (DA≥30, DR≥30, traffic≥10k) — not on by default.
        if ($request->input('quality') == '1' || $request->input('quality') === 1) {
            $query->withGoodMetrics();
        }

        // Min rating — only sites with at least one advertiser rating at/above the floor.
        $ratingMin = filter_number($request->input('rating_min'));
        if ($ratingMin !== null && $ratingMin > 0 && Site::hasSitesColumn('rating_avg') && Site::hasSitesColumn('rating_count')) {
            $query->where('rating_count', '>=', 1)
                ->where('rating_avg', '>=', $ratingMin);
        }

        // Has completions — denormalized completed_orders_count > 0.
        if (($request->input('has_completions') == '1' || $request->input('has_completions') === 1)
            && Site::hasSitesColumn('completed_orders_count')) {
            $query->where('completed_orders_count', '>', 0);
        }

        if ($request->filled('favorites_filter') && $request->favorites_filter == 1) {
            if (! empty($favorites)) {
                $query->whereIn('id', $favorites);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $daMin = filter_number($request->input('da_min'));
        if ($daMin !== null) {
            $query->where('da', '>=', (int) $daMin);
        }
        $daMax = filter_number($request->input('da_max'));
        if ($daMax !== null) {
            $query->where('da', '<=', (int) $daMax);
        }

        $drMin = filter_number($request->input('dr_min'));
        if ($drMin !== null) {
            $query->where('dr', '>=', (int) $drMin);
        }
        $drMax = filter_number($request->input('dr_max'));
        if ($drMax !== null) {
            $query->where('dr', '<=', (int) $drMax);
        }

        $trafficMin = filter_number($request->input('traffic_min'));
        if ($trafficMin !== null) {
            $query->where('traffic', '>=', (int) $trafficMin);
        }
        $trafficMax = filter_number($request->input('traffic_max'));
        if ($trafficMax !== null) {
            $query->where('traffic', '<=', (int) $trafficMax);
        }

        $categoryRaw = search_text($request->input('category'));
        if ($categoryRaw !== '') {
            // category= uses `|` (publisher-aligned). Legacy comma URLs are parsed
            // longest-first against known niches — never blindly explode(',').
            // Include unknown tokens so niches not yet in `categories` still filter.
            $categories = Category::catalogFilterNicheNames($categoryRaw);
            if ($categories !== []) {
                Category::constrainQueryToNicheNames($query, $categories);
            }
        }

        $countryRaw = search_text($request->input('country'));
        if ($countryRaw !== '') {
            $countries = array_values(array_filter(array_map(function ($c) {
                return strtolower(trim($c));
            }, explode(',', $countryRaw))));
            // Primary country only (scalar sites.country) — matches catalog flag /
            // inventory counts. Do not match JSON countries "contains".
            app(CatalogCountryInventory::class)
                ->constrainQueryToPrimaryCountries($query, $countries);
        }

        $languageRaw = search_text($request->input('language'));
        if ($languageRaw !== '') {
            // Option A: language-only → all sites offering these languages (any country).
            // With country= also set, constraints AND. Never auto-sets country.
            // When country is set, drop language codes that are not paired with those countries.
            $languageCodes = explode(',', $languageRaw);
            if ($countryRaw !== '') {
                $countryCodes = array_values(array_filter(array_map(
                    static fn ($c) => strtolower(trim((string) $c)),
                    explode(',', $countryRaw)
                )));
                $allowed = app(CountryLanguagePairs::class)
                    ->languageCodesForCountries($countryCodes);
                if ($allowed !== []) {
                    $languageCodes = array_values(array_intersect(
                        array_map(static fn ($l) => strtolower(trim((string) $l)), $languageCodes),
                        $allowed
                    ));
                }
            }
            if ($languageCodes !== []) {
                app(CatalogLanguageFilter::class)->constrainQuery($query, $languageCodes);
            }
        }

        $advPriceSql = app(PlatformFeeService::class)->advertiserBaseSqlExpression('price');

        $priceMin = filter_number($request->input('price_min'));
        if ($priceMin !== null) {
            $query->whereRaw("({$advPriceSql}) >= ?", [$priceMin]);
        }
        $priceMax = filter_number($request->input('price_max'));
        if ($priceMax !== null) {
            $query->whereRaw("({$advPriceSql}) <= ?", [$priceMax]);
        }

        if ($request->filled('sponsored') && $request->sponsored == 1) {
            $query->where('sponsored', 1);
        }

        // More → Bulk deals — pack program only (not custom Sale −%).
        if ($request->input('bulk_deals') == '1' || $request->input('bulk_deals') === 1) {
            if (Schema::hasColumn('sites', 'bulk_discount_enabled')) {
                $query->where('bulk_discount_enabled', 1)
                    ->whereNotNull('bulk_discount_percent')
                    ->where('bulk_discount_percent', '>', 0);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // More → On sale — live custom per-article discount (Sale −% chip).
        if ($request->input('on_sale') == '1' || $request->input('on_sale') === 1) {
            $query->onDiscount();
        }

        if ($request->filled('new_badge') && $request->new_badge == 1) {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        if (Schema::hasColumn('sites', 'featured_until')) {
            $query->orderByRaw('(featured_until IS NOT NULL AND featured_until > ?) DESC', [now()]);
        }

        if ($searchText !== '' && ! $request->filled('sort')) {
            $catalogSearch->applyRelevanceOrder($query, $searchText);
        }

        $sort = $request->get('sort', 'dr_desc');
        match ($sort) {
            'da_desc' => $query->orderByDesc('da')->orderByDesc('id'),
            'da_asc' => $query->orderBy('da')->orderByDesc('id'),
            'dr_asc' => $query->orderBy('dr')->orderByDesc('id'),
            'traffic_desc' => $query->orderByDesc('traffic')->orderByDesc('id'),
            'price_asc' => $query->orderByRaw($advPriceSql.' ASC')->orderByDesc('id'),
            'price_desc' => $query->orderByRaw($advPriceSql.' DESC')->orderByDesc('id'),
            'newest' => $query->latest('created_at')->orderByDesc('id'),
            'rating_desc' => Site::hasSitesColumn('rating_avg')
                ? $query->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderByDesc('id')
                : $query->orderByDesc('dr')->orderByDesc('id'),
            default => $query->orderByDesc('dr')->orderByDesc('id'),
        };

        // Pagination links always target the full catalog page (not /results),
        // and only carry the allowlisted listing query (URL source of truth).
        $perPage = CatalogUrlQuery::perPage($request);
        $sites = $query->paginate($perPage);
        $sites->appends(CatalogUrlQuery::fromRequest($request));
        $sites->setPath(route('advertiser.catalog', absolute: false));

        foreach ($sites as $site) {
            $site->original_price = $site->price;
            // Own listings stay at the entered publisher price so leftover
            // Add-to-cart markup cannot paint a fee-inclusive number.
            if (! $site->isOwnedBy(auth()->user())) {
                $site->price = $this->advertiserCatalogListPrice($site->price);
            }

            if ($site->sensitive_prices) {
                $sensitivePrices = is_string($site->sensitive_prices)
                    ? json_decode($site->sensitive_prices, true)
                    : $site->sensitive_prices;

                if (is_array($sensitivePrices)) {
                    $processedSensitive = [];
                    foreach ($sensitivePrices as $type => $additionalPrice) {
                        $processedSensitive[$type] = $additionalPrice;
                    }
                    $site->sensitive_prices = $processedSensitive;
                }
            }

            $site->categories_list = $site->nicheBadgeLabels();
        }

        $this->hydrateCatalogTrustCounters($sites);

        // index()/results() own the full page chrome; this helper only builds the listing.
        return [
            'sites' => $sites,
            'favorites' => $favorites,
            'blacklist' => $blacklist,
            'showBlacklistedOnly' => $showBlacklistedOnly,
        ];
    }

    /**
     * Batch-load cancelled order counts so expand trust can show completion %
     * without an N+1 per row on the catalog page.
     *
     * @param  LengthAwarePaginator|Collection<int, Site>  $sites
     */
    private function hydrateCatalogTrustCounters($sites): void
    {
        $collection = method_exists($sites, 'getCollection')
            ? $sites->getCollection()
            : collect($sites);

        $ids = $collection->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return;
        }

        $cancelledBySite = OrderItem::query()
            ->whereIn('site_id', $ids)
            ->whereHas('order', function ($q) {
                $q->where('status', 'cancelled');
            })
            ->selectRaw('site_id, COUNT(*) as cancelled_count')
            ->groupBy('site_id')
            ->pluck('cancelled_count', 'site_id');

        foreach ($collection as $site) {
            $site->setAttribute(
                'cancelled_orders_count',
                (int) ($cancelledBySite[$site->id] ?? 0)
            );
        }
    }

    /**
     * Active Content Library article being ordered through the catalog (session/query).
     */
    private function resolveActiveLibraryOrdering(Request $request): ?ContentSubmission
    {
        if ($request->boolean('cancel_library_order')) {
            session()->forget(['checkout_content_submission_id', 'ordering_from_library']);

            return null;
        }

        $id = (int) scalar_text($request->query('content_submission_id', 0));
        if ($id <= 0 && session('ordering_from_library')) {
            $id = (int) session('checkout_content_submission_id', 0);
        }

        if ($id <= 0) {
            return null;
        }

        $submission = ContentSubmission::query()
            ->forArticlePicker()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->orderable()
            ->first();

        if (! $submission || ! $submission->canBeOrdered()) {
            session()->forget(['checkout_content_submission_id', 'ordering_from_library']);

            return null;
        }

        session()->put('checkout_content_submission_id', $submission->id);
        session()->put('ordering_from_library', true);

        return $submission;
    }

    /**
     * Cart payload for the advertiser drawer (items + assignable articles).
     *
     * @return array{cart: array<int, array>, approved_articles: array<int, array>, ordering_from_library: bool, active_article: ?array}
     */
    /**
     * Per-placement Content Library IDs for a cart line (length === quantity; 0 = unassigned).
     * One article may only ever occupy one placement.
     *
     * @return list<int>
     */
    private function cartLineContentIds(array $line): array
    {
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $raw = [];
        if (! empty($line['content_submission_ids']) && is_array($line['content_submission_ids'])) {
            foreach ($line['content_submission_ids'] as $i => $id) {
                $raw[(int) $i] = (int) $id;
            }
        }
        if ((! isset($raw[0]) || $raw[0] <= 0) && ! empty($line['content_submission_id'])) {
            $raw[0] = (int) $line['content_submission_id'];
        }

        $ids = [];
        for ($i = 0; $i < $qty; $i++) {
            $ids[$i] = max(0, (int) ($raw[$i] ?? 0));
        }

        return $ids;
    }

    /**
     * @param  list<int|string|null>  $ids
     */
    private function applyCartLineContentIds(array $line, array $ids): array
    {
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $normalized = [];
        for ($i = 0; $i < $qty; $i++) {
            $normalized[$i] = max(0, (int) ($ids[$i] ?? 0));
        }
        $line['quantity'] = $qty;
        $line['content_submission_ids'] = $normalized;
        if (($normalized[0] ?? 0) > 0) {
            $line['content_submission_id'] = $normalized[0];
        } else {
            unset($line['content_submission_id']);
        }

        return $line;
    }

    /**
     * Cart line identity: site + sensitive topic + homepage duration.
     * Homepage days empty string means “no homepage placement”.
     */
    private function cartIdentityKey(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $sensitive = (string) ($row['sensitive_type'] ?? '');
        $homepage = $row['homepage_days'] ?? null;
        $homepagePart = ($homepage === null || $homepage === '' || (int) $homepage === 0)
            ? ''
            : (string) (int) $homepage;

        return $id.'|'.$sensitive.'|'.$homepagePart;
    }

    /**
     * Normalize a requested homepage duration for matching (null = none).
     */
    private function normalizeHomepageDaysKey(int|string|null $homepageDays): ?int
    {
        if ($homepageDays === null || $homepageDays === '' || $homepageDays === 'none' || $homepageDays === '0' || $homepageDays === 0) {
            return null;
        }

        return (int) $homepageDays;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function cartLineMatches(
        array $item,
        int $siteId,
        ?string $sensitiveType,
        int|string|null $homepageDays = null,
    ): bool {
        if ((int) ($item['id'] ?? 0) !== $siteId) {
            return false;
        }

        $itemSensitive = $item['sensitive_type'] ?? null;
        if (($itemSensitive ?: null) != ($sensitiveType ?: null)) {
            return false;
        }

        return $this->normalizeHomepageDaysKey($item['homepage_days'] ?? null)
            === $this->normalizeHomepageDaysKey($homepageDays);
    }

    /**
     * Clamp bulk-pack quantities to the configured 3–5 band, resize article slots,
     * and reprice from the live listing so the discount cannot vanish silently.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizeCartLineForSite(Site $site, array $line): array
    {
        $minBulk = (int) config('site_promotions.bulk.min_qty', 3);
        $maxBulk = (int) config('site_promotions.bulk.max_qty', 5);
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $joinsBulk = $site->joinsBulkDiscount();
        $bulkPack = ! empty($line['bulk_pack']);

        // Once a bulk-eligible line is in (or entering) the pack band, keep it there.
        if ($joinsBulk && ($bulkPack || $qty >= $minBulk)) {
            $qty = max($minBulk, min($maxBulk, $qty));
            $bulkPack = true;
        } else {
            // Regular lines (incl. qty 1–2 on bulk sites) still cannot exceed the pack max.
            $qty = max(1, min($maxBulk, $qty));
        }

        $line['quantity'] = $qty;
        $line['bulk_pack'] = $bulkPack;
        $line['bulk_eligible'] = $joinsBulk;
        $line['bulk_min_qty'] = $minBulk;
        $line['bulk_max_qty'] = $maxBulk;

        $sensitiveType = $line['sensitive_type'] ?? null;
        if ($sensitiveType === '') {
            $sensitiveType = null;
        }

        $hasHomepageKey = array_key_exists('homepage_days', $line);
        $homepageInput = $hasHomepageKey
            ? (($line['homepage_days'] === null || $line['homepage_days'] === '')
                ? 'none'
                : $line['homepage_days'])
            : null;

        try {
            $pricing = $this->cartPricing()->priceForAdvertiser(
                $site,
                $sensitiveType,
                $qty,
                $homepageInput,
                ! $hasHomepageKey
            );
        } catch (\InvalidArgumentException $e) {
            // Invalid sensitive and/or homepage — drop the bad choice and retry.
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'homepage')) {
                $homepageInput = 'none';
                $hasHomepageKey = true;
            } else {
                $sensitiveType = null;
                $line['additional_price'] = 0;
            }
            try {
                $pricing = $this->cartPricing()->priceForAdvertiser(
                    $site,
                    $sensitiveType,
                    $qty,
                    $homepageInput,
                    ! $hasHomepageKey
                );
            } catch (\InvalidArgumentException) {
                $pricing = $this->cartPricing()->priceForAdvertiser($site, null, $qty, 'none', false);
                $sensitiveType = null;
                $line['additional_price'] = 0;
            }
        }

        $line['price'] = $pricing['total'];
        $line['base_price'] = $pricing['base'];
        $line['additional_price'] = $pricing['additional'];
        $line['sensitive_type'] = $pricing['sensitive_type'] ?? $sensitiveType;
        $line['homepage_days'] = $pricing['homepage_days'];
        $line['homepage_price'] = $pricing['homepage_price'];
        $line['social_channels'] = $pricing['social_channels'];
        $line['article_total'] = $pricing['article_total'];
        $line['list_total'] = $pricing['list_total'];
        $line['discount_percent'] = $pricing['discount_percent'];
        $line['name'] = $line['name'] ?? $site->site_name;
        $line['url'] = $line['url'] ?? $site->site_url;
        $line['language'] = $line['language'] ?? $site->language;
        $line['country'] = $line['country'] ?? $site->country;
        $line['link_type'] = $line['link_type'] ?? $site->link_type;

        return $this->applyCartLineContentIds($line, $this->cartLineContentIds($line));
    }

    private function cartUsesSubmissionId(
        array $cart,
        int $submissionId,
        int|string|null $exceptLineKey = null,
        ?int $exceptCopyIndex = null
    ): bool {
        if ($submissionId <= 0) {
            return false;
        }

        foreach ($cart as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ($this->cartLineContentIds($item) as $copyIndex => $id) {
                if ($exceptLineKey !== null
                    && (int) $key === (int) $exceptLineKey
                    && (int) $copyIndex === (int) $exceptCopyIndex) {
                    continue;
                }
                if ($id === $submissionId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Drop session cart lines whose sites are missing, not catalog-visible, or owned by the shopper.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @return array{
     *     cart: array<int, array<string, mixed>>,
     *     removed_inactive: list<string>,
     *     removed_owned: list<string>,
     *     changed: bool
     * }
     */
    private function pruneInactiveCartLines(array $cart): array
    {
        return $this->cartPricing()->pruneAdvertiserCart($cart, auth()->user());
    }

    /**
     * Prune inactive/missing lines from the session cart and return display names removed.
     *
     * @return list<string>
     */
    private function syncPrunedSessionCart(): array
    {
        return $this->cartPricing()->syncAdvertiserSessionCart(auth()->user())['removed_inactive'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function putCatalogVisibleCart(array $cart): void
    {
        $pruned = $this->pruneInactiveCartLines($cart);
        session()->put('cart', array_values($pruned['cart']));
    }

    private function cartPayloadForClient(): array
    {
        $cart = array_values(session()->get('cart', []));
        $removedInactive = [];
        $removedOwned = [];
        $buyer = auth()->user();

        // Refresh site market metadata; drop missing/hidden/own-listing lines.
        $siteIds = collect($cart)->pluck('id')->filter()->unique()->values();
        $sites = $siteIds->isEmpty()
            ? collect()
            : Site::query()->catalogVisible()->whereIn('id', $siteIds)->get()->keyBy('id');

        $kept = [];
        foreach ($cart as $line) {
            $site = $sites->get((int) ($line['id'] ?? 0));
            $name = trim((string) ($site?->site_name ?? $line['name'] ?? ''));
            if ($name === '') {
                $name = 'A website';
            }
            if (! $site || ! $site->isCatalogVisible()) {
                $removedInactive[] = $name;

                continue;
            }
            if ($site->isOwnedBy($buyer)) {
                $removedOwned[] = $name;

                continue;
            }
            // Clamp bulk packs to 3–5, resize slots, and reprice from the live listing.
            $kept[] = $this->normalizeCartLineForSite($site, $line);
        }
        $removedInactive = array_values(array_unique($removedInactive));
        $removedOwned = array_values(array_unique($removedOwned));
        $cart = $kept;
        // Repriced lines (sensitive add-ons / live listing) should persist.
        $cartChanged = $removedInactive !== [] || $removedOwned !== [] || $cart !== array_values(session()->get('cart', []));

        $approved = ContentSubmission::query()
            ->forArticlePicker()
            ->where('user_id', auth()->id())
            ->orderable()
            ->latest('id')
            ->limit(100)
            ->get();

        // Drop articles that are no longer orderable (used/archived). Soft language prefer is UI-only unless require_same_language.
        $approvedById = $approved->keyBy('id');
        $requireSame = app(ContentUploadService::class)->requireSameLanguagePlacement();
        foreach ($cart as $i => $line) {
            $site = $sites->get((int) ($line['id'] ?? 0));
            $ids = $this->cartLineContentIds($line);
            $cleaned = [];
            $lineDirty = false;
            $lineNote = null;
            foreach ($ids as $copyIndex => $submissionId) {
                if ($submissionId <= 0) {
                    $cleaned[$copyIndex] = 0;

                    continue;
                }
                $submission = $approvedById->get($submissionId);
                if (! $submission) {
                    $submission = ContentSubmission::query()
                        ->forArticlePicker()
                        ->where('id', $submissionId)
                        ->where('user_id', auth()->id())
                        ->orderable()
                        ->first();
                }
                if (! $submission || ! $submission->canBeOrdered()) {
                    $cleaned[$copyIndex] = 0;
                    $lineDirty = true;
                } elseif ($site && ! $submission->matchesSite($site, $requireSame)) {
                    // Hard-block mode: clear illegal assignments left over from before the flag.
                    $cleaned[$copyIndex] = 0;
                    $lineDirty = true;
                } else {
                    $cleaned[$copyIndex] = $submissionId;
                    if ($site && $lineNote === null) {
                        $lineNote = ContentSubmission::languageMismatchLabel(
                            (string) $submission->language,
                            $site->languageCodes()
                        );
                    }
                }
            }
            if ($lineDirty || $cleaned !== $ids) {
                $cart[$i] = $this->applyCartLineContentIds($line, $cleaned);
                $cartChanged = true;
            }
            if ($lineNote) {
                if (($cart[$i]['language_note'] ?? null) !== $lineNote) {
                    $cart[$i]['language_note'] = $lineNote;
                    $cartChanged = true;
                }
            } elseif (isset($cart[$i]['language_note'])) {
                unset($cart[$i]['language_note']);
                $cartChanged = true;
            }
        }

        if ($cartChanged || $removedInactive !== [] || $removedOwned !== []) {
            session()->put('cart', array_values($cart));
            $cart = array_values(session()->get('cart', []));
        }

        $articles = $approved->map(fn (ContentSubmission $s) => [
            'id' => $s->id,
            'title' => $s->title ?: $s->original_filename,
            'country' => $s->country,
            'language' => $s->language,
            'word_count' => $s->word_count,
        ])->all();

        $active = null;
        if (session('ordering_from_library') && session('checkout_content_submission_id')) {
            $activeModel = $approved->firstWhere('id', (int) session('checkout_content_submission_id'));
            if ($activeModel) {
                $active = [
                    'id' => $activeModel->id,
                    'title' => $activeModel->title ?: $activeModel->original_filename,
                    'language' => $activeModel->language,
                ];
            }
        }

        $cartTotal = round(array_sum(array_map(
            fn ($item) => ((float) ($item['price'] ?? 0)) * ((int) ($item['quantity'] ?? 0)),
            $cart
        )), 2);

        return [
            'cart' => $cart,
            'cart_count' => (int) array_sum(array_map(fn ($item) => (int) ($item['quantity'] ?? 0), $cart)),
            'cart_total' => $cartTotal,
            'approved_articles' => $articles,
            'ordering_from_library' => (bool) session('ordering_from_library'),
            'active_article' => $active,
            'content_library_url' => route('advertiser.content-library', ['upload' => 1]),
            'removed_inactive' => $removedInactive,
            'removed_inactive_count' => count($removedInactive),
            'removed_owned' => $removedOwned,
            'removed_owned_count' => count($removedOwned),
            'require_same_language' => $requireSame,
            'schedule' => $this->checkoutScheduleClientHint(),
        ];
    }

    /**
     * Typeahead suggestions for the catalog search box.
     *
     * Lightweight JSON — not a full results page. Hide mode returns the same
     * dual-masked name/host the table would paint (never plaintext identity).
     */
    public function suggest(Request $request, CatalogSearchQuery $catalogSearch, SiteUrlVisibility $visibility): JsonResponse
    {
        $user = auth()->user();
        $raw = search_text($request->query('q', $request->input('q')));
        $parsed = $catalogSearch->parse($raw);
        $text = trim((string) ($parsed['text'] ?? ''));

        if ($text === '' || mb_strlen($text) < 2) {
            return response()->json([
                'success' => true,
                'q' => $raw,
                'in_hide_mode' => $visibility->inHideMode($user),
                'suggestions' => [],
            ]);
        }

        $query = Site::query()->catalogVisible();
        $hostNeedle = $this->catalogSearchHostNeedle($text);
        $catalogSearch->applyTextConstraints(
            $query,
            $text,
            collect(),
            $hostNeedle,
            searchAllDomains: true,
        );
        $catalogSearch->applyRelevanceOrder($query, $text);
        $query->orderByDesc('dr')->orderByDesc('id');

        $sites = $query
            ->limit(8)
            ->get(['id', 'site_name', 'site_url', 'domain', 'publisher_id', 'dr', 'category']);

        $visibility->warmFor($user, $sites->pluck('id')->all());

        $suggestions = $sites->map(function (Site $site) use ($visibility, $user) {
            $shows = $visibility->showsFullIdentity($user, $site);

            return [
                'id' => (int) $site->id,
                'name' => $visibility->nameFor($user, $site),
                'host' => $visibility->hostFor($user, $site),
                'masked' => ! $shows,
                'dr' => (int) ($site->dr ?? 0),
                'href' => route('advertiser.catalog', ['site' => $site->id]),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'q' => $raw,
            'in_hide_mode' => $visibility->inHideMode($user),
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Save favorites to DATABASE (full replace for this advertiser).
     */
    public function saveFavorites(Request $request)
    {
        try {
            $data = $request->validate([
                'favorites' => 'nullable|array',
                'favorites.*' => 'integer|exists:sites,id',
            ]);

            $userId = auth()->id();
            $favorites = array_values(array_unique(array_map('intval', $data['favorites'] ?? [])));

            UserFavorite::where('user_id', $userId)->delete();

            foreach ($favorites as $siteId) {
                UserFavorite::create([
                    'user_id' => $userId,
                    'site_id' => $siteId,
                ]);
            }

            return response()->json([
                'success' => true,
                'favorites' => $favorites,
                'count' => count($favorites),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error saving favorites: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => UserFacingError::message($e, 'Could not update your saved sites. Please try again.')], 500);
        }
    }

    /**
     * Save blacklist to DATABASE (full replace for this advertiser).
     * Blacklisted sites are hidden from the main catalog and shown under Blacklisted Only.
     */
    public function saveBlacklist(Request $request)
    {
        try {
            $data = $request->validate([
                'blacklist' => 'nullable|array',
                'blacklist.*' => 'integer|exists:sites,id',
            ]);

            $userId = auth()->id();
            $blacklist = array_values(array_unique(array_map('intval', $data['blacklist'] ?? [])));

            UserBlacklist::where('user_id', $userId)->delete();

            foreach ($blacklist as $siteId) {
                UserBlacklist::create([
                    'user_id' => $userId,
                    'site_id' => $siteId,
                ]);
            }

            return response()->json([
                'success' => true,
                'blacklist' => $blacklist,
                'count' => count($blacklist),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error saving blacklist: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => UserFacingError::message($e, 'Could not update your blocked sites. Please try again.')], 500);
        }
    }

    /**
     * Save cart to SESSION
     */
    public function saveCart(Request $request)
    {
        try {
            $incoming = $request->input('cart', []);
            if (! is_array($incoming)) {
                $incoming = [];
            }

            // Preserve article assignments when the client omits them.
            $existingByKey = [];
            foreach (session()->get('cart', []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $existingByKey[$this->cartIdentityKey($row)] = $row;
            }

            $siteIds = collect($incoming)->pluck('id')->filter()->unique()->values();
            $sites = $siteIds->isEmpty()
                ? collect()
                : Site::query()->catalogVisible()->whereIn('id', $siteIds)->get()->keyBy('id');

            $merged = [];
            foreach ($incoming as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }
                $site = $sites->get((int) $row['id']);
                if (! $site || $site->isOwnedBy(auth()->user())) {
                    continue;
                }
                $key = $this->cartIdentityKey($row);
                $prev = $existingByKey[$key] ?? [];
                if (empty($row['content_submission_ids']) && ! empty($prev['content_submission_ids'])) {
                    $row['content_submission_ids'] = $prev['content_submission_ids'];
                }
                if (empty($row['content_submission_id']) && ! empty($prev['content_submission_id'])) {
                    $row['content_submission_id'] = $prev['content_submission_id'];
                }
                if (empty($row['language']) && ! empty($prev['language'])) {
                    $row['language'] = $prev['language'];
                }
                if (empty($row['country']) && ! empty($prev['country'])) {
                    $row['country'] = $prev['country'];
                }
                if (empty($row['bulk_pack']) && ! empty($prev['bulk_pack'])) {
                    $row['bulk_pack'] = true;
                }
                if (! array_key_exists('homepage_days', $row) && array_key_exists('homepage_days', $prev)) {
                    $row['homepage_days'] = $prev['homepage_days'];
                }
                $merged[] = $this->normalizeCartLineForSite($site, $row);
            }

            $this->putCatalogVisibleCart($merged);

            return response()->json(array_merge(['success' => true], $this->cartPayloadForClient()));
        } catch (\Exception $e) {
            Log::error('Error saving cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => UserFacingError::message($e, 'Could not save your cart. Please try again.')], 500);
        }
    }

    /**
     * Get cart from SESSION (enriched with article options for the drawer).
     */
    public function getCart(Request $request)
    {
        return response()->json($this->cartPayloadForClient());
    }

    /**
     * Assign / clear an approved Content Library article on a cart placement.
     * Quantity > 1 creates multiple placements on the same site — each needs its own article.
     */
    public function assignCartArticle(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'sensitive_type' => ['nullable', 'string', 'max:50'],
            'homepage_days' => ['nullable'],
            'content_submission_id' => ['nullable', 'integer'],
            'copy_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $siteId = (int) $data['id'];
        $sensitiveType = $data['sensitive_type'] ?? null;
        $hasHomepageInput = array_key_exists('homepage_days', $data) || $request->exists('homepage_days');
        $homepageDays = $hasHomepageInput
            ? ($data['homepage_days'] ?? $request->input('homepage_days'))
            : null;
        $submissionId = isset($data['content_submission_id']) ? (int) $data['content_submission_id'] : 0;
        $copyIndex = max(0, (int) ($data['copy_index'] ?? 0));

        $cart = session()->get('cart', []);
        $lineKey = null;
        foreach ($cart as $key => $item) {
            $matches = $hasHomepageInput
                ? $this->cartLineMatches($item, $siteId, $sensitiveType, $homepageDays)
                : ((int) ($item['id'] ?? 0) === $siteId
                    && (($item['sensitive_type'] ?? null) == ($sensitiveType ?: null)));
            if ($matches) {
                $lineKey = $key;
                break;
            }
        }

        if ($lineKey === null) {
            return response()->json(['success' => false, 'error' => 'That website is not in your cart.'], 404);
        }

        $site = Site::query()->catalogVisible()->where('id', $siteId)->first();
        if (! $site) {
            $this->putCatalogVisibleCart($cart);

            return response()->json(['success' => false, 'error' => 'Site not found or inactive.'], 404);
        }

        $ids = $this->cartLineContentIds($cart[$lineKey]);
        if ($copyIndex >= count($ids)) {
            return response()->json([
                'success' => false,
                'error' => 'That placement does not exist for this cart quantity.',
            ], 422);
        }

        if ($submissionId <= 0) {
            $ids[$copyIndex] = 0;
            $cart[$lineKey] = $this->applyCartLineContentIds($cart[$lineKey], $ids);
            $this->putCatalogVisibleCart($cart);

            return response()->json(array_merge(['success' => true, 'message' => 'Article cleared for this placement.'], $this->cartPayloadForClient()));
        }

        $submission = ContentSubmission::query()
            ->forArticlePicker()
            ->where('id', $submissionId)
            ->where('user_id', auth()->id())
            ->orderable()
            ->first();

        if (! $submission || ! $submission->canBeOrdered()) {
            return response()->json([
                'success' => false,
                'error' => 'Choose an approved Content Library article that is still available to order.',
            ], 422);
        }

        if ($this->cartUsesSubmissionId($cart, $submissionId, $lineKey, $copyIndex)) {
            return response()->json([
                'success' => false,
                'error' => 'That article is already assigned to another placement in your cart. Each article can only be published on one site.',
            ], 422);
        }

        $requireSame = app(ContentUploadService::class)->requireSameLanguagePlacement();
        if (! $submission->matchesSite($site, $requireSame)) {
            $note = ContentSubmission::languageMismatchLabel(
                (string) $submission->language,
                $site->languageCodes()
            ) ?: 'Article language does not match this site.';

            return response()->json([
                'success' => false,
                'error' => 'Same-language placement is required. '.$note,
                'language_mismatch' => true,
            ], 422);
        }

        $ids[$copyIndex] = $submission->id;
        $cart[$lineKey] = $this->applyCartLineContentIds($cart[$lineKey], $ids);
        $cart[$lineKey]['language'] = $site->language;
        $cart[$lineKey]['country'] = $site->country;
        $mismatchNote = ContentSubmission::languageMismatchLabel(
            (string) $submission->language,
            $site->languageCodes()
        );
        if ($mismatchNote) {
            $cart[$lineKey]['language_note'] = $mismatchNote;
        } else {
            unset($cart[$lineKey]['language_note']);
        }
        $this->putCatalogVisibleCart($cart);

        $message = $mismatchNote
            ? 'Article assigned (language differs: '.$mismatchNote.').'
            : 'Article assigned to this placement.';

        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
        ], $this->cartPayloadForClient()));
    }

    /**
     * Add to cart (SESSION) — prices are always recalculated from the DB.
     * Multiple sites are allowed; each site needs its own Content Library article.
     */
    public function addToCart(Request $request)
    {
        try {
            $rawId = $request->input('id');
            $id = is_numeric($rawId) ? (int) $rawId : 0;
            $sensitiveType = search_text($request->input('sensitive_type'));
            $sensitiveType = $sensitiveType !== '' ? $sensitiveType : null;

            $hasHomepageInput = $request->exists('homepage_days');
            $homepageInput = $hasHomepageInput ? $request->input('homepage_days') : null;

            $site = Site::query()->catalogVisible()->where('id', $id)->first();
            if (! $site) {
                $this->putCatalogVisibleCart(session()->get('cart', []));

                return response()->json([
                    'success' => false,
                    'error' => 'Site not found or inactive.',
                ], 404);
            }

            if ($site->isOwnedBy(auth()->user())) {
                return response()->json([
                    'success' => false,
                    'error' => Site::cannotOrderOwnListingMessage(),
                ], 403);
            }

            // Resolve homepage up-front so re-adds merge onto the same identity key.
            try {
                $homepageResolved = $this->cartPricing()->resolveHomepageSelection(
                    $site,
                    $hasHomepageInput ? $homepageInput : null,
                    ! $hasHomepageInput
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'success' => false,
                    'error' => UserFacingError::message($e, 'That homepage promotion option is not available for this site.'),
                ], 422);
            }
            $resolvedHomepageDays = $homepageResolved['days'];

            $cart = session()->get('cart', []);
            $attachArticleId = null;
            $librarySubmission = null;

            if (session('ordering_from_library') && session('checkout_content_submission_id')) {
                $librarySubmission = ContentSubmission::query()
                    ->forArticlePicker()
                    ->where('id', (int) session('checkout_content_submission_id'))
                    ->where('user_id', auth()->id())
                    ->orderable()
                    ->first();

                if (! $librarySubmission || ! $librarySubmission->canBeOrdered()) {
                    session()->forget(['checkout_content_submission_id', 'ordering_from_library']);
                    $librarySubmission = null;
                } else {
                    $requireSame = app(ContentUploadService::class)->requireSameLanguagePlacement();
                    if (! $librarySubmission->matchesSite($site, $requireSame)) {
                        $note = ContentSubmission::languageMismatchLabel(
                            (string) $librarySubmission->language,
                            $site->languageCodes()
                        ) ?: 'Article language does not match this site.';

                        return response()->json([
                            'success' => false,
                            'error' => 'Same-language placement is required. '.$note.' Choose a matching site, or turn off the admin same-language rule.',
                            'language_mismatch' => true,
                        ], 422);
                    }

                    $alreadyAssigned = $this->cartUsesSubmissionId($cart, (int) $librarySubmission->id);

                    if (! $alreadyAssigned) {
                        $attachArticleId = (int) $librarySubmission->id;
                    }
                }
            }

            $minBulk = (int) config('site_promotions.bulk.min_qty', 3);
            $maxBulk = (int) config('site_promotions.bulk.max_qty', 5);
            $wantsBulk = $request->boolean('bulk') || $request->boolean('bulk_hint');
            $requestedQty = (int) $request->input('quantity', 0);

            // Bulk deals (Buy 3–5) start as a multi-article pack so the cart
            // opens with one document slot per placement to publish separately.
            $isBulkPackAdd = false;
            if ($wantsBulk && $site->joinsBulkDiscount()) {
                if ($requestedQty < $minBulk) {
                    $requestedQty = $minBulk;
                }
                $requestedQty = max($minBulk, min($maxBulk, $requestedQty));
                $isBulkPackAdd = true;
            } elseif ($requestedQty > 0) {
                $requestedQty = max(1, min($maxBulk, $requestedQty));
            }

            $existingItem = null;
            $currentQty = 0;
            foreach ($cart as $key => $item) {
                if ($this->cartLineMatches($item, (int) $id, $sensitiveType, $resolvedHomepageDays)) {
                    $existingItem = $key;
                    $currentQty = max(1, (int) ($item['quantity'] ?? 1));
                    break;
                }
            }

            if ($existingItem !== null) {
                if ($requestedQty > 0) {
                    // Ensure the bulk pack size, then each re-click adds one more up to max.
                    $nextQty = max($currentQty, $requestedQty);
                    if ($nextQty === $currentQty && ! $attachArticleId) {
                        $nextQty = min($maxBulk, $currentQty + 1);
                    }
                } else {
                    $nextQty = $currentQty + 1;
                }
            } else {
                $nextQty = $requestedQty > 0 ? $requestedQty : 1;
            }

            // When attaching a library article, keep that line at qty 1 (one article = one placement).
            if ($attachArticleId && $existingItem !== null) {
                $nextQty = max(1, (int) ($cart[$existingItem]['quantity'] ?? 1));
            } elseif ($attachArticleId) {
                $nextQty = 1;
            }

            if ($existingItem !== null) {
                $line = $cart[$existingItem];
                $line['quantity'] = $nextQty;
                $line['homepage_days'] = $resolvedHomepageDays;
                if ($isBulkPackAdd || ! empty($line['bulk_pack']) || ($site->joinsBulkDiscount() && $nextQty >= $minBulk)) {
                    $line['bulk_pack'] = true;
                }
                $ids = $this->cartLineContentIds($line);
                if ($attachArticleId && ($ids[0] ?? 0) <= 0) {
                    $ids[0] = $attachArticleId;
                }
                $line = $this->applyCartLineContentIds($line, $ids);
                $cart[$existingItem] = $this->normalizeCartLineForSite($site, $line);
            } else {
                // Inside hide mode the row is masked — carting it unlocks identity
                // for that listing (and counts toward pace). Outside hide mode
                // identity is already open; do not invent a disclosure row.
                $visibility = app(SiteUrlVisibility::class);
                $cartUser = auth()->user();
                if ($cartUser && $visibility->inHideMode($cartUser)) {
                    $visibility->reveal(
                        $cartUser,
                        $site,
                        SiteUrlReveal::SOURCE_CART
                    );
                }

                $line = [
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'url' => $site->site_url,
                    'quantity' => $nextQty,
                    'sensitive_type' => $sensitiveType,
                    'homepage_days' => $resolvedHomepageDays,
                    'bulk_pack' => $isBulkPackAdd || ($site->joinsBulkDiscount() && $nextQty >= $minBulk),
                    'link_type' => $site->link_type,
                    'country' => $site->country,
                    'language' => $site->language,
                ];
                if ($attachArticleId) {
                    $line = $this->applyCartLineContentIds($line, [0 => $attachArticleId]);
                    if ($librarySubmission) {
                        $note = ContentSubmission::languageMismatchLabel(
                            (string) $librarySubmission->language,
                            $site->languageCodes()
                        );
                        if ($note) {
                            $line['language_note'] = $note;
                        }
                    }
                } else {
                    $line = $this->applyCartLineContentIds($line, array_fill(0, $nextQty, 0));
                }
                $cart[] = $this->normalizeCartLineForSite($site, $line);
            }

            // Soft-prefer note when library article auto-attached onto an existing line.
            if ($attachArticleId && $librarySubmission && $existingItem !== null) {
                $note = ContentSubmission::languageMismatchLabel(
                    (string) $librarySubmission->language,
                    $site->languageCodes()
                );
                if ($note) {
                    $cart[$existingItem]['language_note'] = $note;
                } else {
                    unset($cart[$existingItem]['language_note']);
                }
            }

            $this->putCatalogVisibleCart($cart);

            $cartCount = array_sum(array_column($cart, 'quantity'));
            $cartTotal = array_sum(array_map(function ($item) {
                return $item['price'] * $item['quantity'];
            }, $cart));

            if ($attachArticleId) {
                $message = 'Website added with your article. Add more sites anytime — each needs its own approved article.';
                $attachNote = ContentSubmission::languageMismatchLabel(
                    (string) ($librarySubmission?->language ?? ''),
                    $site->languageCodes()
                );
                if ($attachNote) {
                    $message .= ' Note: '.$attachNote.' (preferred match is same language).';
                }
            } elseif ($nextQty > 1) {
                $message = 'Added '.$nextQty.' article placements. Attach a separate Content Library document for each before checkout.';
            } else {
                $message = 'Website added to cart. Assign an approved article for each site before checkout.';
            }

            return response()->json(array_merge([
                'success' => true,
                'cart_count' => $cartCount,
                'cart_total' => $cartTotal,
                'message' => $message,
            ], $this->cartPayloadForClient()));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => UserFacingError::message($e, 'This site could not be added to your cart.')], 422);
        } catch (\Exception $e) {
            Log::error('Error adding to cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => UserFacingError::message($e, 'Could not add this site to your cart. Please try again.')], 500);
        }
    }

    /**
     * Remove from cart (SESSION)
     */
    public function removeFromCart(Request $request)
    {
        try {
            $rawId = $request->input('id');
            $id = is_numeric($rawId) ? (int) $rawId : 0;
            $sensitiveType = search_text($request->input('sensitive_type'));
            $sensitiveType = $sensitiveType !== '' ? $sensitiveType : null;
            $hasHomepageInput = $request->exists('homepage_days');
            $homepageDays = $hasHomepageInput ? $request->input('homepage_days') : null;
            $cart = session()->get('cart', []);

            foreach ($cart as $key => $item) {
                $matches = $hasHomepageInput
                    ? $this->cartLineMatches($item, (int) $id, $sensitiveType, $homepageDays)
                    : ((int) ($item['id'] ?? 0) === (int) $id
                        && (($item['sensitive_type'] ?? null) == ($sensitiveType ?: null)));
                if ($matches) {
                    unset($cart[$key]);
                    break;
                }
            }

            $this->putCatalogVisibleCart(array_values($cart));

            return response()->json(array_merge(['success' => true], $this->cartPayloadForClient()));
        } catch (\Exception $e) {
            Log::error('Error removing from cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => UserFacingError::message($e, 'Could not remove this item from your cart. Please try again.')], 500);
        }
    }

    /**
     * Update cart quantity (SESSION) — clamps bulk packs and reprices like saveCart.
     */
    public function updateCartQuantity(Request $request)
    {
        try {
            $rawId = $request->input('id');
            $id = is_numeric($rawId) ? (int) $rawId : 0;
            $quantity = (int) $request->quantity;
            $sensitiveType = search_text($request->input('sensitive_type'));
            $sensitiveType = $sensitiveType !== '' ? $sensitiveType : null;
            $hasHomepageInput = $request->exists('homepage_days');
            $homepageDays = $hasHomepageInput ? $request->input('homepage_days') : null;
            $cart = session()->get('cart', []);

            foreach ($cart as $key => $item) {
                $matches = $hasHomepageInput
                    ? $this->cartLineMatches($item, $id, $sensitiveType, $homepageDays)
                    : ((int) ($item['id'] ?? 0) === $id
                        && (($item['sensitive_type'] ?? null) == ($sensitiveType ?: null)));
                if (! $matches) {
                    continue;
                }

                if ($quantity <= 0) {
                    unset($cart[$key]);
                    break;
                }

                $site = Site::query()->catalogVisible()->where('id', $id)->first();
                if (! $site) {
                    unset($cart[$key]);
                    break;
                }

                $item['quantity'] = $quantity;
                $cart[$key] = $this->normalizeCartLineForSite($site, $item);
                break;
            }

            $this->putCatalogVisibleCart($cart);

            return response()->json(array_merge(['success' => true], $this->cartPayloadForClient()));
        } catch (\Exception $e) {
            Log::error('Error updating cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => UserFacingError::message($e, 'Could not update your cart. Please try again.')], 500);
        }
    }

    /**
     * Clear cart (SESSION)
     */
    public function clearCart(Request $request)
    {
        session()->forget(['cart', 'checkout_content_submission_id', 'checkout_schedule', 'ordering_from_library', GuestPostWizardController::SESSION_KEY]);

        return response()->json(['success' => true]);
    }

    /**
     * Checkout page — display prices recalculated from the DB.
     * Payment covers only sites that are ready (approved article assigned) and need payment.
     */
    public function checkout(Request $request)
    {
        // Abandoned Stripe checkout: cancel unpaid pending card orders for this reference
        if ($request->boolean('canceled') && $request->filled('ref')) {
            $this->cancelUnpaidCardOrdersAndRestoreCart((string) $request->ref);
        }

        $this->syncPrunedSessionCart();
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return redirect()->route('advertiser.catalog')->with('error', 'Your cart is empty or contains sites you can’t order.');
        }

        $partition = $this->partitionCartByCheckoutReadiness($cart);
        $payableCart = $partition['payable'];
        $deferredCart = $partition['deferred'];

        try {
            $allCheckout = $this->cartPricing()->buildCheckoutItems($cart, auth()->id());
            $payableCheckout = $payableCart !== []
                ? $this->cartPricing()->buildCheckoutItems($payableCart, auth()->id())
                : ['items' => [], 'total' => 0.0, 'savings' => 0.0];
            $deferredCheckout = $deferredCart !== []
                ? $this->cartPricing()->buildCheckoutItems($deferredCart, auth()->id())
                : ['items' => [], 'total' => 0.0, 'savings' => 0.0];
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('advertiser.catalog')->with('error', UserFacingError::message($e, 'Some items in your cart are no longer available. Please review your cart.'));
        }

        $cartItems = $allCheckout['items'];
        $deferredItems = $deferredCheckout['items'];
        $payableCount = count($payableCart);
        $deferredCount = count($deferredCart);
        $payableReady = $payableCount > 0;
        // Charge only ready sites that still need payment.
        $total = (float) ($payableCheckout['total'] ?? 0);
        $savings = (float) ($payableCheckout['savings'] ?? 0);
        $payableSiteKeys = collect($payableCart)->mapWithKeys(function ($row) {
            $key = $this->cartIdentityKey($row);

            return [$key => true];
        })->all();
        $cartItems = collect($cartItems)->map(function (array $item) use ($payableSiteKeys) {
            $key = $this->cartIdentityKey($item);
            $item['paying_now'] = isset($payableSiteKeys[$key]);

            return $item;
        })->all();

        if (empty($cartItems)) {
            session()->forget(['cart', 'checkout_content_submission_id', 'checkout_schedule', GuestPostWizardController::SESSION_KEY]);

            return redirect()->route('advertiser.catalog')->with('error', 'Your cart is empty or contains sites you can’t order.');
        }

        $librarySubmission = $this->resolveLibrarySubmissionForCheckout($cart);

        $checkoutWallet = auth()->user()->activeWallet();
        if ($checkoutWallet) {
            $checkoutWallet->repairOrphanedWelcomeBonus();
            $checkoutWallet->reconcileInflatedBonusBalance();
            $checkoutWallet->refresh();
        }
        $checkoutBonusBalance = $checkoutWallet ? $checkoutWallet->lockedBonusBalance() : 0.0;
        $checkoutCashBalance = $checkoutWallet ? $checkoutWallet->withdrawableBalance() : 0.0;
        $checkoutSpendableBalance = (float) ($checkoutWallet?->balance ?? 0);

        // Article assignment lives in the cart drawer (cart.get → orderable list),
        // not on this page — only load articles already attached to the order summary.

        // Include every bulk/qty slot id — not only the legacy scalar.
        $articleIds = collect($cartItems)
            ->flatMap(function (array $item) {
                $ids = is_array($item['content_submission_ids'] ?? null)
                    ? $item['content_submission_ids']
                    : [];
                if ($ids === [] && ! empty($item['content_submission_id'])) {
                    $ids = [$item['content_submission_id']];
                }

                return collect($ids)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0);
            })
            ->unique()
            ->values();
        if ($librarySubmission) {
            $articleIds = $articleIds->push((int) $librarySubmission->id)->unique()->values();
        }
        $checkoutArticles = ContentSubmission::query()
            ->forCheckoutSummary()
            ->with(['orderItems.site', 'orderItems.order'])
            ->where('user_id', auth()->id())
            ->whereIn('id', $articleIds->all() ?: [0])
            ->get()
            ->keyBy('id');

        $savedCards = app(StripeCustomerService::class)->listCards(auth()->user());
        $stripeConfigured = app(StripeCustomerService::class)->configured();
        // Best-effort: auto-add Hostinger-missing Stripe columns before card checkout.
        app(StripeCustomerService::class)->ensureUserStripeColumns();

        $scheduleContext = $this->checkoutScheduleContext();

        $checkoutReferenceCode = session('checkout_reference_code');
        if (! is_string($checkoutReferenceCode) || ! preg_match('/^\d{6}$/', $checkoutReferenceCode)) {
            $checkoutReferenceCode = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            session(['checkout_reference_code' => $checkoutReferenceCode]);
        }

        return view('advertiser.checkout', array_merge(compact(
            'cartItems',
            'deferredItems',
            'payableReady',
            'payableCount',
            'deferredCount',
            'total',
            'savings',
            'librarySubmission',
            'checkoutWallet',
            'checkoutBonusBalance',
            'checkoutCashBalance',
            'checkoutSpendableBalance',
            'checkoutArticles',
            'savedCards',
            'stripeConfigured',
            'checkoutReferenceCode',
        ), $scheduleContext));
    }

    /**
     * Process order - Creates orders ONLY after successful payment for card payments
     */
    public function processOrder(Request $request)
    {
        // Handle Stripe GET callback (after payment)
        if ($request->isMethod('get')) {
            return $this->handleStripeSuccess($request);
        }

        try {
            $prunedCart = $this->cartPricing()->syncAdvertiserSessionCart(auth()->user());
            $cart = session()->get('cart', []);

            if (empty($cart)) {
                if (($prunedCart['removed_owned'] ?? []) !== []) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You cannot order placements on your own websites.',
                    ], 422);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Your cart is empty.',
                ]);
            }

            $userId = auth()->id();
            $paymentMethod = $request->payment_method;
            $userReferenceCode = $request->reference_code;

            // Only charge sites that are ready for checkout (approved article) and need payment.
            $partition = $this->partitionCartByCheckoutReadiness(
                $cart,
                is_array($request->content_submissions) ? $request->content_submissions : null,
                session('checkout_content_submission_id') ? (int) session('checkout_content_submission_id') : null
            );
            $payableCart = $partition['payable'];
            $deferredCart = $partition['deferred'];

            if ($payableCart === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'No websites are ready for checkout yet. Assign an approved article to at least one site, then pay.',
                ], 422);
            }

            // If a previous Stripe attempt linked the article, unlock it before re-resolving content.
            $this->cancelConflictingUnpaidCardOrders(
                (int) $userId,
                $this->collectSubmissionIdsFromRequest($payableCart, $request)
            );

            // Resolve approved library articles + schedule (session fallback from Content Library)
            $sessionSchedule = session('checkout_schedule', []);
            $checkoutContent = $this->resolveCheckoutContent(
                $payableCart,
                is_array($request->content_submissions) ? $request->content_submissions : null,
                [
                    'mode' => $request->input('publication_mode', $sessionSchedule['mode'] ?? null),
                    'date' => $request->input('scheduled_date', $sessionSchedule['date'] ?? null),
                    'time' => $request->input('scheduled_time', $sessionSchedule['time'] ?? null),
                    'timezone' => $request->input('timezone', $sessionSchedule['timezone'] ?? null),
                ],
            );
            if ($checkoutContent instanceof JsonResponse) {
                return $checkoutContent;
            }

            $checkoutContent = $this->excludeSelfOwnedCheckoutLines($checkoutContent, (int) $userId);
            if ($checkoutContent['lines'] === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot order placements on your own websites.',
                ], 422);
            }

            $this->persistCheckoutScheduleSession($checkoutContent['schedule']);

            // Keep not-ready sites in the cart after this payment.
            session()->put('checkout_deferred_cart', array_values($deferredCart));

            // Generate reference code. 6-digit client REFs can collide —
            // never overwrite another advertiser's Stripe-first package.
            $referenceCode = $this->allocateCheckoutReferenceCode(
                (int) $userId,
                is_string($userReferenceCode) ? $userReferenceCode : null
            );
            $useBonus = $request->boolean('use_bonus');

            // Bank / Wise / crypto fund the wallet via invoice — not order checkout.
            if (in_array($paymentMethod, ['wise', 'crypto', 'bank'], true)) {
                $expanded = array_column($checkoutContent['lines'], 'orderItem');
                $cartTotal = round(array_sum(array_column($expanded, 'price')), 2);

                return response()->json([
                    'success' => false,
                    'code' => 'fund_wallet_first',
                    'message' => 'Bank, Wise, and crypto payments go to your wallet first. Add funds with an invoice, then pay this order from your wallet.',
                    'redirect_url' => route('advertiser.add-funds', [
                        'amount' => max(10, (int) ceil($cartTotal)),
                        'method' => $paymentMethod,
                    ]),
                    'suggested_amount' => $cartTotal,
                ], 422);
            }

            // For wallet payment - check balance and reserve funds
            if ($paymentMethod === 'wallet') {
                return $this->processWalletPayment($payableCart, $checkoutContent, $referenceCode, $userId, $useBonus);
            }

            // For card payments — Stripe-first (Add Funds style), then materialize paid orders.
            if ($paymentMethod === 'card') {
                $savedCardId = $request->input('payment_method_id');

                return $this->processCardPayment(
                    $payableCart,
                    $checkoutContent,
                    $referenceCode,
                    $userId,
                    $useBonus,
                    is_string($savedCardId) ? $savedCardId : null
                );
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid payment method',
            ]);

        } catch (\Exception $e) {
            Log::error('Order processing failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not process your order. Please try again.'),
            ]);
        }
    }

    /**
     * Create Stripe Checkout first (same pattern as Add Funds), then materialize
     * orders only after payment succeeds.
     *
     * @param  array{lines: array<int, array{orderItem: array, submission: ContentSubmission}>, schedule: array}  $checkoutContent
     */
    private function processCardPayment($cart, array $checkoutContent, $referenceCode, $userId, bool $useBonus = false, ?string $paymentMethodId = null)
    {
        // Match Add Funds: only require a Stripe secret.
        if (! config('services.stripe.secret') || config('services.stripe.secret') === '') {
            return response()->json([
                'success' => false,
                'message' => 'Stripe is not configured. Please contact support.',
            ], 503);
        }

        $expandedOrders = array_column($checkoutContent['lines'], 'orderItem');
        $totalAmount = round(array_sum(array_column($expandedOrders, 'price')), 2);
        $schedule = $checkoutContent['schedule'];
        $bonusApplied = 0.0;
        $amountDue = $totalAmount;
        $paymentService = app(OrderPaymentService::class);
        $paymentService->releaseRecordedCheckoutBonus((int) $userId, (string) $referenceCode);

        try {
            if ($useBonus) {
                $bonusApplied = $paymentService->reserveCheckoutBonus((int) $userId, $totalAmount);
                $amountDue = round(max(0, $totalAmount - $bonusApplied), 2);
            }
        } catch (\Throwable $e) {
            Log::error('Bonus reserve failed before Stripe checkout', [
                'reference_code' => $referenceCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to apply bonus balance. Please try again without bonus, or contact support.',
            ]);
        }

        // Fully covered by bonus — create paid wallet orders without Stripe.
        // Keep the bonus reserved until approve/reject (same as processWalletPayment).
        // Consuming here made approveOrder() consumeReserved() again (negative reserved)
        // and reject refundReserved() mint withdrawable cash from a zero reserved bucket.
        if ($amountDue <= 0 && $bonusApplied > 0) {
            $this->rememberCheckoutBonus((int) $userId, (string) $referenceCode, $bonusApplied);
            try {
                app(CheckoutSchemaService::class)->ensureCheckoutTables();
                $schema = app(CheckoutSchemaService::class);
                $created = collect();
                DB::beginTransaction();
                foreach ($checkoutContent['lines'] as $line) {
                    $orderItem = $line['orderItem'];
                    $submission = $line['submission'];
                    $site = $orderItem['site'];
                    if ($site instanceof Site && (int) $site->publisher_id === (int) $userId) {
                        continue;
                    }
                    $order = Order::create($schema->filterExistingColumns('orders', array_merge([
                        'user_id' => $userId,
                        'order_number' => Order::nextOrderNumber(),
                        'reference_code' => $referenceCode,
                        'subtotal' => $orderItem['price'],
                        'tax' => 0,
                        'total_amount' => $orderItem['price'],
                        'payment_method' => 'wallet',
                        'payment_status' => 'paid',
                        'status' => $this->initialOrderStatus($schedule),
                        'sensitive_type' => $orderItem['sensitive_type'],
                        'additional_price' => $orderItem['additional_price'],
                        'paid_at' => now(),
                    ], $this->scheduleOrderFields($schedule))));
                    $item = OrderItem::create($schema->filterExistingColumns(
                        'order_items',
                        $this->orderItemPayload($order->id, $site, $orderItem, $submission)
                    ));
                    $this->attachSubmissionToOrder($submission, $order, $item);
                    $created->push($order);
                }
                DB::commit();
                $this->forgetCheckoutBonus((int) $userId, (string) $referenceCode);
                $this->restoreDeferredCartAfterPayment();
                $advertiserRoleId = Wallet::advertiserRoleId();
                if ($advertiserRoleId) {
                    $purchaseWallet = Wallet::query()
                        ->where('user_id', $userId)
                        ->where('role_id', $advertiserRoleId)
                        ->first();
                    if ($purchaseWallet) {
                        app(WalletLedgerService::class)->recordPurchaseOnce(
                            $purchaseWallet,
                            $totalAmount,
                            $bonusApplied,
                            $created->first(),
                            (string) $referenceCode
                        );
                    }
                }
                $paymentService->notifyPublishersOfPaidOrders($created);

                try {
                    app(SpendBudgetService::class)->evaluate(auth()->user());
                } catch (\Throwable $e) {
                    Log::warning('Spend budget evaluate after bonus checkout failed: '.$e->getMessage());
                }

                return response()->json([
                    'success' => true,
                    'message' => count($created).' order(s) placed using your bonus balance. Reference: '.$referenceCode,
                ]);
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->refundCheckoutBonus((int) $userId, (string) $referenceCode);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to place bonus order. Please try again.',
                ]);
            }
        }

        // Never open Stripe Checkout for a €0 charge.
        if ($amountDue <= 0) {
            if ($bonusApplied > 0) {
                $this->refundCheckoutBonus((int) $userId, (string) $referenceCode);
            }

            return response()->json([
                'success' => false,
                'message' => 'Card payment requires an amount greater than €0. Use wallet if covered by bonus, or select ready sites that need payment.',
            ], 422);
        }

        $packageLines = [];
        foreach ($checkoutContent['lines'] as $line) {
            $orderItem = $line['orderItem'];
            $submission = $line['submission'];
            $site = $orderItem['site'];
            if ($site instanceof Site && (int) $site->publisher_id === (int) $userId) {
                continue;
            }
            $packageLines[] = [
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => $orderItem['price'],
                'sensitive_type' => $orderItem['sensitive_type'] ?? null,
                'additional_price' => $orderItem['additional_price'] ?? 0,
                'homepage_days' => $orderItem['homepage_days'] ?? null,
                'homepage_price' => $orderItem['homepage_price'] ?? 0,
                'social_channels' => $orderItem['social_channels']
                    ?? ($site->enabledSocialChannels() ?: []),
                'publisher_price' => $orderItem['publisher_price'] ?? null,
                'platform_fee_percent' => $orderItem['platform_fee_percent'] ?? null,
                'platform_fee_amount' => $orderItem['platform_fee_amount'] ?? null,
                'content_submission_id' => $submission->id,
                'content_link' => route('advertiser.content-submissions.download', $submission),
                'content_disk' => $submission->disk,
                'content_path' => $submission->path,
                'content_original_name' => $submission->original_filename,
                'content_mime' => $submission->mime,
                'anchor_text' => $submission->anchor_text,
                'target_url' => $submission->target_url,
                'feature_image_url' => $submission->feature_image_url,
                'moderation_status' => $submission->moderation_status,
            ];
        }

        try {
            $paymentService->storePendingCheckout($referenceCode, [
                'user_id' => (int) $userId,
                'reference_code' => (string) $referenceCode,
                'order_total' => $totalAmount,
                'amount_due' => $amountDue,
                'bonus_applied' => $bonusApplied,
                'schedule' => $schedule,
                'lines' => $packageLines,
            ]);
        } catch (\Throwable $e) {
            if ($bonusApplied > 0) {
                $this->refundCheckoutBonus((int) $userId, (string) $referenceCode);
            }

            Log::error('Stripe-first checkout package store failed: '.$e->getMessage(), [
                'reference_code' => $referenceCode,
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not start card checkout. Please try again.'),
            ]);
        }

        if ($bonusApplied > 0) {
            $this->rememberCheckoutBonus((int) $userId, (string) $referenceCode, $bonusApplied);
        }

        $user = User::find($userId);

        if (is_string($paymentMethodId) && str_starts_with($paymentMethodId, 'pm_')) {
            return $this->chargeSavedCardForOrder(
                (string) $referenceCode,
                (int) $userId,
                $user,
                $amountDue,
                $totalAmount,
                $bonusApplied,
                $paymentMethodId,
                count($packageLines)
            );
        }

        // Same Stripe Checkout pattern as Add Funds — no pending order rows yet.
        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $sessionPayload = [
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Order Package - '.count($packageLines).' item(s)',
                            'description' => 'Order reference: '.$referenceCode
                                .($bonusApplied > 0 ? ' (bonus −€'.number_format($bonusApplied, 2).')' : ''),
                        ],
                        'unit_amount' => StripePaymentService::toCents($amountDue),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('advertiser.checkout.process').'?session_id={CHECKOUT_SESSION_ID}&ref='.urlencode($referenceCode),
                'cancel_url' => route('advertiser.checkout').'?canceled=1&ref='.urlencode($referenceCode),
                'metadata' => [
                    'type' => 'order_payment',
                    'reference_code' => $referenceCode,
                    'user_id' => (string) $userId,
                    'order_count' => (string) count($packageLines),
                    'expected_amount' => (string) $amountDue,
                    'order_total' => (string) $totalAmount,
                    'bonus_applied' => (string) $bonusApplied,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $referenceCode,
                        'user_id' => (string) $userId,
                        'bonus_applied' => (string) $bonusApplied,
                        'expected_amount' => (string) $amountDue,
                        'order_total' => (string) $totalAmount,
                    ],
                ],
            ];

            $checkoutSession = app(StripeCustomerService::class)
                ->createCheckoutSession($sessionPayload, $user, true);

            session()->put('pending_card_reference', $referenceCode);

            Log::info('Stripe-first card checkout session ready (Add Funds style)', [
                'reference_code' => $referenceCode,
                'session_id' => $checkoutSession->id,
                'order_count' => count($packageLines),
                'total_amount' => $totalAmount,
                'amount_due' => $amountDue,
                'bonus_applied' => $bonusApplied,
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => true,
                'requires_payment' => true,
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
                'reference_code' => $referenceCode,
                'bonus_applied' => $bonusApplied,
                'amount_due' => $amountDue,
            ]);
        } catch (\Exception $e) {
            $this->refundCheckoutBonus((int) $userId, (string) $referenceCode);
            $paymentService->forgetPendingCheckout((string) $referenceCode, (int) $userId);

            Log::error('Stripe checkout error: '.$e->getMessage(), [
                'reference_code' => $referenceCode,
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to create checkout session. Please try again.'),
            ]);
        }
    }

    /**
     * Charge a saved Stripe card for an order (same 3DS contract as Add Funds).
     * Checkout already posts payment_method_id; ignoring it sent every saved-card
     * order to hosted Checkout and never returned client_secret.
     */
    private function chargeSavedCardForOrder(
        string $referenceCode,
        int $userId,
        ?User $user,
        float $amountDue,
        float $totalAmount,
        float $bonusApplied,
        string $paymentMethodId,
        int $itemCount
    ): JsonResponse {
        $paymentService = app(OrderPaymentService::class);
        $returnUrl = route('advertiser.checkout.process').'?ref='.urlencode($referenceCode);

        if (! $user) {
            $this->refundCheckoutBonus($userId, $referenceCode);
            $paymentService->forgetPendingCheckout($referenceCode, $userId);

            return response()->json([
                'success' => false,
                'message' => 'Unable to charge this saved card. Please try again.',
            ], 422);
        }

        try {
            $payResult = app(StripeCustomerService::class)->payWithSavedCard(
                $user,
                $paymentMethodId,
                StripePaymentService::toCents($amountDue),
                [
                    'type' => 'order_payment',
                    'reference_code' => $referenceCode,
                    'user_id' => (string) $userId,
                    'order_count' => (string) $itemCount,
                    'expected_amount' => (string) $amountDue,
                    'order_total' => (string) $totalAmount,
                    'bonus_applied' => (string) $bonusApplied,
                ],
                $returnUrl,
                'Order '.$referenceCode
            );

            if ($payResult['status'] === 'succeeded') {
                $intent = (object) [
                    'id' => $payResult['payment_intent_id'],
                    'object' => 'payment_intent',
                    'amount' => StripePaymentService::toCents($amountDue),
                    'amount_received' => (int) ($payResult['amount_received'] ?? StripePaymentService::toCents($amountDue)),
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $referenceCode,
                        'user_id' => (string) $userId,
                        'expected_amount' => (string) $amountDue,
                        'order_total' => (string) $totalAmount,
                        'bonus_applied' => (string) $bonusApplied,
                    ],
                ];

                $created = $paymentService->finalizeStripeFirstCheckout($referenceCode, $intent);
                if ($created->isEmpty()) {
                    $created = $paymentService->paidCardOrdersForStripeCharge(
                        $referenceCode,
                        $intent,
                        (int) $userId
                    );
                }

                if ($created->isEmpty()) {
                    $credited = $paymentService->creditCapturedCardWhenUnfulfillable(
                        (int) $userId,
                        $referenceCode,
                        $intent
                    );
                    if ($credited <= 0) {
                        $credited = $paymentService->walletCreditForThisCardCharge(
                            $referenceCode,
                            $intent,
                            (int) $userId
                        );
                    }
                    if ($credited > 0) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Payment received. The listing(s) were no longer available, so €'
                                .number_format($credited, 2)
                                .' was credited to your advertiser wallet.',
                            'reference_code' => $referenceCode,
                            'wallet_credit' => $credited,
                        ]);
                    }

                    throw new \RuntimeException('Saved card payment succeeded but orders were not created');
                }

                $paymentService->notifyPublishersOfPaidOrders($created);
                $this->restoreDeferredCartAfterPayment();

                $orderNumbers = $created->pluck('order_number')->filter()->implode(', ');

                return response()->json([
                    'success' => true,
                    'message' => $created->count().' order(s) paid with your saved card. Order numbers: '.$orderNumbers,
                    'reference_code' => $referenceCode,
                ]);
            }

            if (! empty($payResult['redirect_url'])) {
                return response()->json([
                    'success' => true,
                    'requires_payment' => true,
                    'checkout_url' => $payResult['redirect_url'],
                    'reference_code' => $referenceCode,
                ]);
            }

            if (! empty($payResult['client_secret'])) {
                return response()->json([
                    'success' => true,
                    'requires_action' => true,
                    'client_secret' => $payResult['client_secret'],
                    'stripe_key' => config('services.stripe.key'),
                    'return_url' => $returnUrl,
                    'reference_code' => $referenceCode,
                ]);
            }

            $this->refundCheckoutBonus($userId, $referenceCode);
            $paymentService->forgetPendingCheckout($referenceCode, $userId);

            return response()->json([
                'success' => false,
                'message' => 'Could not charge this card. Try another card or pay with a new card.',
            ], 422);
        } catch (\Throwable $e) {
            $this->refundCheckoutBonus($userId, $referenceCode);
            $paymentService->forgetPendingCheckout($referenceCode, $userId);

            Log::error('Saved card order checkout failed: '.$e->getMessage(), [
                'reference_code' => $referenceCode,
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Saved card payment failed. Please try again or use a new card.'),
            ], 422);
        }
    }

    /**
     * Process wallet payment - deduct from balance and move to reserved_balance
     *
     * @param  array{lines: array, schedule: array}  $checkoutContent
     */
    private function processWalletPayment($cart, array $checkoutContent, $referenceCode, $userId, bool $useBonus = false)
    {
        try {
            $expandedOrders = array_column($checkoutContent['lines'], 'orderItem');
            $totalAmount = round(array_sum(array_column($expandedOrders, 'price')), 2);
            $schedule = $checkoutContent['schedule'];

            $advertiserRoleId = Wallet::advertiserRoleId();
            if (! $advertiserRoleId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Advertiser role not configured.',
                ]);
            }

            $paymentService = app(OrderPaymentService::class);
            if ($paymentService->hasInFlightCardCheckout((int) $userId, (string) $referenceCode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A card checkout is already in progress for this order. Cancel it or finish paying by card, then pay from your wallet.',
                ], 422);
            }
            $paymentService->releaseRecordedCheckoutBonus((int) $userId, (string) $referenceCode);
            $paymentService->forgetPendingCheckout((string) $referenceCode, (int) $userId);

            DB::beginTransaction();

            // Lock wallet row inside the transaction to prevent concurrent overspend
            $advertiserWallet = Wallet::lockOrCreateForRole((int) $userId, (int) $advertiserRoleId);
            $advertiserWallet->repairOrphanedWelcomeBonus();
            $advertiserWallet->reconcileInflatedBonusBalance();
            $advertiserWallet->refresh();

            $spendable = round((float) $advertiserWallet->balance, 2);
            $cashAvailable = $advertiserWallet->withdrawableBalance();
            $bonusAvailable = $advertiserWallet->lockedBonusBalance();
            $effectiveAvailable = $useBonus ? $spendable : $cashAvailable;

            if ($effectiveAvailable < $totalAmount) {
                DB::rollBack();

                $hint = (! $useBonus && $bonusAvailable > 0)
                    ? ' Tip: enable “Use bonus balance” (€'.number_format($bonusAvailable, 2).') to apply your promotional credit.'
                    : '';

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance. Available cash: €'
                        .number_format($cashAvailable, 2)
                        .($bonusAvailable > 0 ? ' · Bonus: €'.number_format($bonusAvailable, 2) : '')
                        .'. Required: €'.number_format($totalAmount, 2).'.'.$hint,
                ]);
            }

            // Reserve funds; bonus is only used when the checkout checkbox is enabled
            $bonusUsed = $advertiserWallet->reserveForOrder($totalAmount, $useBonus);
            if ($bonusUsed > 0) {
                $paymentService->persistPaidCheckoutBonus((int) $userId, (string) $referenceCode, $bonusUsed);
            }

            app(WalletLedgerService::class)->recordPurchase(
                $advertiserWallet,
                $totalAmount,
                $bonusUsed,
                null,
                $referenceCode
            );

            Log::info('Wallet payment processed - funds reserved', [
                'user_id' => $userId,
                'wallet_id' => $advertiserWallet->id,
                'total_amount' => $totalAmount,
                'new_balance' => $advertiserWallet->balance,
                'reserved_balance' => $advertiserWallet->reserved_balance,
                'bonus_balance' => $advertiserWallet->bonus_balance,
                'bonus_reserved' => $advertiserWallet->bonus_reserved,
                'reference_code' => $referenceCode,
            ]);

            $createdOrders = [];
            $schema = app(CheckoutSchemaService::class);
            $schema->ensureCheckoutTables();

            foreach ($checkoutContent['lines'] as $line) {
                $orderItem = $line['orderItem'];
                $submission = $line['submission'];
                $site = $orderItem['site'];
                if ($site instanceof Site && (int) $site->publisher_id === (int) $userId) {
                    continue;
                }
                $order = Order::create($schema->filterExistingColumns('orders', array_merge([
                    'user_id' => $userId,
                    'order_number' => Order::nextOrderNumber(),
                    'reference_code' => $referenceCode,
                    'subtotal' => $orderItem['price'],
                    'tax' => 0,
                    'total_amount' => $orderItem['price'],
                    'payment_method' => 'wallet',
                    'payment_status' => 'paid',
                    'status' => $this->initialOrderStatus($schedule),
                    'sensitive_type' => $orderItem['sensitive_type'],
                    'additional_price' => $orderItem['additional_price'],
                    'paid_at' => now(),
                ], $this->scheduleOrderFields($schedule))));

                $item = OrderItem::create($schema->filterExistingColumns(
                    'order_items',
                    $this->orderItemPayload($order->id, $site, $orderItem, $submission)
                ));
                $this->attachSubmissionToOrder($submission, $order, $item);

                $createdOrders[] = $order;
            }

            // Link the purchase ledger row to the first order so wallet activity
            // can resolve the INV tax invoice by order_id / reference.
            if ($createdOrders !== []) {
                $purchaseTx = WalletTransaction::query()
                    ->where('wallet_id', $advertiserWallet->id)
                    ->where('type', WalletTransaction::TYPE_PURCHASE)
                    ->where('reference', $referenceCode)
                    ->latest('id')
                    ->first();
                if ($purchaseTx && ! $purchaseTx->related_id) {
                    $first = $createdOrders[0];
                    $purchaseTx->update([
                        'related_type' => $first->getMorphClass(),
                        'related_id' => $first->id,
                        'meta' => array_merge((array) $purchaseTx->meta, [
                            'order_ids' => collect($createdOrders)->pluck('id')->all(),
                            'order_reference' => $referenceCode,
                        ]),
                    ]);
                }
            }

            DB::commit();
            $this->restoreDeferredCartAfterPayment();

            $isScheduled = ($schedule['mode'] ?? 'immediate') === 'scheduled';

            $freshPaid = collect();
            foreach ($createdOrders as $createdOrder) {
                $fresh = $createdOrder->fresh(['items']);
                $freshPaid->push($fresh);
                app(InAppNotificationService::class)->notifyOrderCreated($fresh);
            }
            app(InAppNotificationService::class)->notifyAdvertiserOrdersPaid($freshPaid);

            try {
                app(SpendBudgetService::class)->evaluate(auth()->user());
            } catch (\Throwable $e) {
                Log::warning('Spend budget evaluate after checkout failed: '.$e->getMessage());
            }

            // Wallet is paid immediately — notify publishers (scheduled orders publish on the date).
            $this->sendSiteOwnerEmails($createdOrders);

            $orderNumbers = implode(', ', array_map(
                fn (Order $order) => $order->order_number,
                $createdOrders
            ));

            Log::info('Orders created with wallet payment (funds reserved)', [
                'reference_code' => $referenceCode,
                'order_count' => count($createdOrders),
                'total_reserved' => $totalAmount,
                'scheduled' => $isScheduled,
            ]);

            $scheduleLabel = $this->scheduleSuccessLabel($schedule);
            $msg = $isScheduled
                ? count($createdOrders).' order(s) placed and charged. Publisher notified — they must publish on '.$scheduleLabel.'. Order numbers: '.$orderNumbers
                : count($createdOrders).' order(s) placed successfully! Funds have been reserved from your wallet. Order numbers: '.$orderNumbers;

            return response()->json([
                'success' => true,
                'message' => $msg,
                'scheduled' => $isScheduled,
                'scheduled_label' => $scheduleLabel,
                'scheduled_orders_url' => $isScheduled
                    ? route('advertiser.scheduled-orders', ['tab' => 'upcoming'])
                    : null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Wallet payment failed: '.$e->getMessage(), [
                'user_id' => $userId,
                'reference_code' => $referenceCode,
            ]);

            $message = $e->getMessage() === 'Insufficient balance to reserve'
                ? 'Insufficient wallet balance for this order.'
                : ($e->getMessage() === ContentSubmission::UNAVAILABLE_MESSAGE
                    ? 'That Content Library article was already purchased. Please choose another article.'
                    : 'Unable to process wallet payment. Please try again.');

            return response()->json([
                'success' => false,
                'message' => $message,
            ]);
        }
    }

    /**
     * Create orders immediately for non-card payments (wise, crypto, bank)
     *
     * @param  array{lines: array, schedule: array}  $checkoutContent
     */
    private function createOrdersImmediately($cart, $paymentMethod, array $checkoutContent, $referenceCode, $userId, bool $useBonus = false)
    {
        try {
            $schedule = $checkoutContent['schedule'];
            $expandedOrders = array_column($checkoutContent['lines'], 'orderItem');
            $totalAmount = round(array_sum(array_column($expandedOrders, 'price')), 2);
            $bonusApplied = 0.0;
            $amountDue = $totalAmount;
            $paymentService = app(OrderPaymentService::class);
            if ($paymentService->hasInFlightCardCheckout((int) $userId, (string) $referenceCode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A card checkout is already in progress for this order. Cancel it or finish paying by card, then pay from your wallet.',
                ], 422);
            }
            $paymentService->releaseRecordedCheckoutBonus((int) $userId, (string) $referenceCode);
            $paymentService->forgetPendingCheckout((string) $referenceCode, (int) $userId);

            DB::beginTransaction();

            if ($useBonus) {
                $bonusApplied = $paymentService->reserveCheckoutBonus((int) $userId, $totalAmount);
                $amountDue = round(max(0, $totalAmount - $bonusApplied), 2);
                if ($bonusApplied > 0) {
                    $paymentService->persistPaidCheckoutBonus((int) $userId, (string) $referenceCode, $bonusApplied);
                }
            }

            $createdOrders = [];

            foreach ($checkoutContent['lines'] as $line) {
                $orderItem = $line['orderItem'];
                $submission = $line['submission'];
                $site = $orderItem['site'];
                if ($site instanceof Site && (int) $site->publisher_id === (int) $userId) {
                    continue;
                }
                $order = Order::create(array_merge([
                    'user_id' => $userId,
                    'order_number' => Order::nextOrderNumber(),
                    'reference_code' => $referenceCode,
                    'subtotal' => $orderItem['price'],
                    'tax' => 0,
                    'total_amount' => $orderItem['price'],
                    'payment_method' => $paymentMethod,
                    'payment_status' => 'pending',
                    'status' => $this->initialOrderStatus($schedule),
                    'sensitive_type' => $orderItem['sensitive_type'],
                    'additional_price' => $orderItem['additional_price'],
                ], $this->scheduleOrderFields($schedule)));

                $item = OrderItem::create($this->orderItemPayload($order->id, $site, $orderItem, $submission));
                $this->attachSubmissionToOrder($submission, $order, $item);

                $createdOrders[] = $order;
            }

            DB::commit();
            if ($bonusApplied > 0) {
                $this->rememberCheckoutBonus((int) $userId, (string) $referenceCode, $bonusApplied);
            }
            $this->restoreDeferredCartAfterPayment();

            $isScheduled = ($schedule['mode'] ?? 'immediate') === 'scheduled';

            // Timeline only for unpaid — publishers are notified after admin marks payment paid.
            // Advertiser gets a payment-pending bell so the order does not go silent.
            $notifications = app(InAppNotificationService::class);
            foreach ($createdOrders as $createdOrder) {
                $fresh = $createdOrder instanceof Order
                    ? $createdOrder->fresh(['items'])
                    : Order::with('items')->find($createdOrder->id);
                if (! $fresh) {
                    continue;
                }
                $notifications->notifyOrderCreated($fresh);
                $notifications->notifyPaymentPending($fresh);
            }

            // Send email to admin for manual payments (wise, crypto, bank)
            $customer = User::find($userId);
            $this->sendAdminManualPaymentEmail($customer, $createdOrders, $paymentMethod);

            $orderNumbers = implode(', ', array_map(fn (Order $o) => $o->order_number, $createdOrders));
            $bonusNote = $bonusApplied > 0
                ? ' Bonus €'.number_format($bonusApplied, 2).' applied — please transfer €'.number_format($amountDue, 2).'.'
                : '';

            return response()->json([
                'success' => true,
                'message' => count($createdOrders).' order(s) placed successfully'
                    .($isScheduled ? ' (scheduled publication — we will notify the publisher after payment is confirmed)' : '')
                    .'! Order numbers: '.$orderNumbers
                    .'. Complete payment so the publisher can start.'
                    .$bonusNote,
                'bonus_applied' => $bonusApplied,
                'amount_due' => $amountDue,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order creation failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Unable to place order. Please try again.',
            ]);
        }
    }

    /**
     * Handle Stripe success callback — finalize pending orders if webhook has not yet.
     * Orders are created before checkout; this path is an idempotent fallback.
     */
    public function handleStripeSuccess(Request $request)
    {
        try {
            $sessionId = $request->query('session_id');
            $paymentIntentId = $request->query('payment_intent');
            $referenceCode = $request->query('ref');

            Log::info('Stripe success callback received', [
                'session_id' => $sessionId,
                'payment_intent' => $paymentIntentId,
                'reference_code' => $referenceCode,
            ]);

            if (! $referenceCode) {
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Invalid payment callback.');
            }

            Stripe::setApiKey(config('services.stripe.secret'));
            $paymentService = app(OrderPaymentService::class);
            $newlyPaid = collect();
            $stripeObject = null;

            // Saved-card / PaymentIntent return (3DS) path
            if ($paymentIntentId && $paymentIntentId !== '{PAYMENT_INTENT_ID}') {
                try {
                    $intent = PaymentIntent::retrieve($paymentIntentId);
                } catch (\Exception $e) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Unable to verify card payment. Please contact support.');
                }

                $deposits = app(WalletStripeDepositService::class);
                $meta = (array) json_decode(json_encode($intent->metadata ?? []), true);
                if (trim((string) ($meta['user_id'] ?? '')) === ''
                    || trim((string) ($meta['type'] ?? '')) === ''
                    || trim((string) ($meta['reference_code'] ?? '')) === '') {
                    $intent = $deposits->withRecoveredCheckoutSessionMetadata($intent);
                    $meta = (array) json_decode(json_encode($intent->metadata ?? []), true);
                }

                if (($meta['reference_code'] ?? null) && $meta['reference_code'] !== $referenceCode) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment reference mismatch.');
                }

                $intentUserId = trim((string) ($meta['user_id'] ?? ''));
                if ($intentUserId === '') {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Unable to verify card payment. Please contact support.');
                }
                if ($intentUserId !== (string) auth()->id()) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment does not belong to this account.');
                }

                $intentType = (string) ($meta['type'] ?? '');
                if ($intentType !== '' && ! in_array($intentType, ['order_payment', 'order'], true)) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'This payment is not an order checkout.');
                }

                if ($intent->status !== 'succeeded') {
                    return redirect()->route('advertiser.orders', ['payment_status' => 'failed'])
                        ->with('error', 'Card payment was not completed.');
                }

                $stripeObject = $intent;
                $newlyPaid = $paymentService->finalizeStripeFirstCheckout($referenceCode, $intent);
            } else {
                if (! $sessionId || $sessionId === '{CHECKOUT_SESSION_ID}') {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Invalid payment session.');
                }

                try {
                    $stripeSession = Session::retrieve($sessionId);
                } catch (\Exception $e) {
                    Log::error('Failed to retrieve Stripe session', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage(),
                    ]);

                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Unable to verify payment. Please contact support.');
                }

                if ($stripeSession->payment_status !== 'paid') {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment not completed.');
                }

                $deposits = app(WalletStripeDepositService::class);
                $meta = (array) json_decode(json_encode($stripeSession->metadata ?? []), true);
                if (trim((string) ($meta['user_id'] ?? '')) === ''
                    || trim((string) ($meta['type'] ?? '')) === ''
                    || trim((string) ($meta['reference_code'] ?? '')) === '') {
                    $stripeSession = $deposits->withRecoveredPaymentIntentMetadata($stripeSession);
                    $meta = (array) json_decode(json_encode($stripeSession->metadata ?? []), true);
                }

                $sessionRef = $meta['reference_code'] ?? null;
                if ($sessionRef && $sessionRef !== $referenceCode) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment reference mismatch.');
                }

                $sessionUserId = trim((string) ($meta['user_id'] ?? ''));
                if ($sessionUserId === '') {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Unable to verify payment. Please contact support.');
                }
                if ($sessionUserId !== (string) auth()->id()) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment does not belong to this account.');
                }

                $sessionType = (string) ($meta['type'] ?? '');
                if ($sessionType !== '' && ! in_array($sessionType, ['order_payment', 'order'], true)) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'This payment is not an order checkout.');
                }

                $stripeObject = $stripeSession;
                $newlyPaid = $paymentService->finalizeStripeFirstCheckout($referenceCode, $stripeSession);
            }

            $paidOrders = $newlyPaid->isNotEmpty()
                ? $newlyPaid
                : ($stripeObject
                    ? $paymentService->paidCardOrdersForStripeCharge(
                        $referenceCode,
                        $stripeObject,
                        (int) auth()->id()
                    )
                    : collect());

            if ($paidOrders->isEmpty()) {
                $credited = 0.0;
                if ($stripeObject) {
                    $credited = $paymentService->creditCapturedCardWhenUnfulfillable(
                        (int) auth()->id(),
                        $referenceCode,
                        $stripeObject
                    );
                }
                if ($credited <= 0 && $stripeObject) {
                    $credited = $paymentService->walletCreditForThisCardCharge(
                        $referenceCode,
                        $stripeObject,
                        (int) auth()->id()
                    );
                }
                if ($credited > 0) {
                    return redirect()->route('advertiser.checkout')
                        ->with(
                            'success',
                            'Payment received. The listing(s) were no longer available, so €'
                            .number_format($credited, 2)
                            .' was credited to your advertiser wallet.'
                        );
                }

                Log::error('No card orders found on success callback', [
                    'reference_code' => $referenceCode,
                    'session_id' => $sessionId,
                    'payment_intent' => $paymentIntentId,
                ]);

                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Order not found. Please contact support with your payment reference.');
            }

            if ($newlyPaid->isNotEmpty()) {
                $paymentService->notifyPublishersOfPaidOrders($newlyPaid);
            }

            $this->removePaidOrdersFromCart($paidOrders);
            session()->forget([
                'pending_card_payment',
                'pending_cart',
                'pending_content_links',
                'pending_reference_code',
                'pending_user_id',
                'pending_card_reference',
                'checkout_content_submission_id',
                'checkout_schedule',
                'checkout_deferred_cart',
            ]);

            $orderNumbers = $paidOrders->pluck('order_number')->implode(', ');
            $paidCount = $paidOrders->count();
            $remaining = count(session('cart', []));
            $scheduledOrders = $paidOrders->filter(fn (Order $order) => ($order->publication_mode ?? '') === 'scheduled');
            $successMsg = $paidCount.' order(s) paid successfully! Order numbers: '.$orderNumbers;
            if ($scheduledOrders->isNotEmpty()) {
                $first = $scheduledOrders->first();
                $label = $this->scheduleSuccessLabel([
                    'mode' => 'scheduled',
                    'at' => $first->scheduled_publish_at,
                    'timezone' => $first->schedule_timezone ?: 'UTC',
                ]);
                if ($label) {
                    $successMsg .= ' Publisher notified — they must publish on '.$label.'.';
                }
            }
            if ($remaining > 0) {
                $successMsg .= ' '.$remaining.' website(s) remain in your cart until they are ready for checkout.';
            }

            $redirect = $scheduledOrders->isNotEmpty()
                ? redirect()->route('advertiser.scheduled-orders', ['tab' => 'upcoming'])
                : redirect()->route('advertiser.orders');

            return $redirect->with('success', $successMsg);
        } catch (\Exception $e) {
            Log::error('Stripe success handling failed: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return redirect()->route('advertiser.checkout')
                ->with('error', UserFacingError::message($e, 'Payment verification failed. Please try again.'));
        }
    }

    /**
     * Send email to site owners with order details
     */
    private function sendSiteOwnerEmails($orders)
    {
        try {
            Log::info('Starting to send site owner emails', ['order_count' => count($orders)]);

            // Group orders by site to avoid duplicate emails
            $siteOrders = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $siteId = $item->site_id;
                    if (! isset($siteOrders[$siteId])) {
                        $site = Site::find($siteId);
                        if ($site) {
                            $siteOrders[$siteId] = [
                                'site' => $site,
                                'orders' => [],
                            ];
                            Log::info('Site found for email', [
                                'site_id' => $siteId,
                                'site_name' => $site->site_name,
                                'publisher_id' => $site->publisher_id,
                            ]);
                        } else {
                            Log::warning('Site not found', ['site_id' => $siteId]);

                            continue;
                        }
                    }
                    if (isset($siteOrders[$siteId])) {
                        $siteOrders[$siteId]['orders'][] = $order;
                    }
                }
            }

            // Send email to each site owner (publisher)
            foreach ($siteOrders as $siteData) {
                $site = $siteData['site'];
                $siteOrdersList = $siteData['orders'];

                // FIXED: Use publisher_id instead of user_id
                $publisherId = $site->publisher_id;

                if (! $publisherId) {
                    Log::warning('No publisher_id found for site', [
                        'site_id' => $site->id,
                        'site_name' => $site->site_name,
                    ]);

                    continue;
                }

                // Get the publisher (site owner) using publisher_id
                $publisher = User::find($publisherId);

                if (! $publisher) {
                    Log::warning('Publisher not found', [
                        'publisher_id' => $publisherId,
                        'site_id' => $site->id,
                    ]);

                    continue;
                }

                if (! $publisher->email) {
                    Log::warning('Publisher has no email', [
                        'publisher_id' => $publisherId,
                        'publisher_name' => $publisher->name,
                    ]);

                    continue;
                }

                try {
                    Mail::to($publisher->email)->send(new SiteOwnerOrderNotification($site, $siteOrdersList));
                    Log::info('Order notification email sent to publisher', [
                        'site_id' => $site->id,
                        'site_name' => $site->site_name,
                        'publisher_id' => $publisherId,
                        'publisher_email' => $publisher->email,
                        'order_count' => count($siteOrdersList),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send email to publisher', [
                        'email' => $publisher->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to send site owner emails: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());
        }
    }

    /**
     * Send email + bell to admins for manual payments only.
     * Bell is independent of mail success (via EmailNotificationService).
     */
    private function sendAdminManualPaymentEmail($customer, $orders, $paymentMethod)
    {
        try {
            app(EmailNotificationService::class)->notifyAdminsManualPayment($customer, $orders, $paymentMethod);
        } catch (\Throwable $e) {
            Log::error('Failed to notify admins of manual payment: '.$e->getMessage());
        }
    }

    /**
     * Request modification from publisher (RESETS auto-approve timer)
     */
    public function requestModification(Request $request, $id)
    {
        try {
            $request->validate([
                'reason' => 'required|string|min:10',
            ]);

            $order = Order::with(['items.site.publisher'])->findOrFail($id);

            if ($order->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be changed because payment is not complete.',
                ], 422);
            }

            if ($order->status !== 'review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot request modification for an order that is not under review',
                ], 400);
            }

            if ($order->items->contains(fn ($line) => $line->isContentRevisionRequested())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Send the revised article first — a placement on this order is still waiting for updated content.',
                ], 422);
            }

            DB::beginTransaction();

            // Update order status back to 'processing'
            $order->update([
                'status' => 'processing',
            ]);

            // Mark unpaid lines as modification requested AND RESET TIMER.
            // Do not rewind a line that already paid the publisher.
            foreach ($order->items as $item) {
                if ($item->isPayoutComplete()) {
                    continue;
                }

                $payload = [
                    'modification_requested' => 'yes',
                    'modification_requested_at' => now(),
                    'live_url_submitted_at' => now(),
                    'auto_approve_triggered' => false,
                ];
                if (Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
                    $payload['auto_approve_reminder_sent_at'] = null;
                }
                if (Schema::hasColumn('order_items', 'completion_notes')) {
                    $payload['completion_notes'] = $request->reason;
                }
                $item->update($payload);
            }

            DB::commit();

            // Persist a chat message so publishers see the revision request in the thread.
            // Contact-detail share/ask in the reason is saved but not delivered to the publisher.
            try {
                $chatBody = "Revision requested: {$request->reason}\nPlease update the article, then paste the corrected live URL in this chat to resubmit.";
                $guard = app(OrderChatContactGuard::class)->inspect($chatBody);
                OrderChatMessage::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'sender_type' => 'advertiser',
                    'message' => $chatBody,
                    'is_read' => false,
                    'is_blocked' => (bool) $guard['blocked'],
                    'blocked_reason' => $guard['blocked'] ? $guard['reason'] : null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to create revision chat message', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $publishers = [];
            foreach ($order->items as $item) {
                if ($item->isPayoutComplete()) {
                    continue;
                }
                $publisher = $item->site?->publisher;
                if ($publisher instanceof User && filled($publisher->email)) {
                    $publishers[$publisher->id] = $publisher;
                }
            }

            foreach ($publishers as $publisher) {
                try {
                    Mail::to($publisher->email)->send(new ModificationRequested($order, $request->reason));
                } catch (\Exception $e) {
                    Log::error('Failed to send email: '.$e->getMessage());
                }
            }

            app(InAppNotificationService::class)->notifyModificationRequested($order, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Change request sent to the publisher.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error requesting modification: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to request modification. Please try again.'),
            ], 500);
        }
    }

    /**
     * Advertiser fulfills a publisher request for a revised / resent article.
     */
    public function fulfillContentRevision(Request $request, $id)
    {
        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $request->validate([
            'content_link' => 'nullable|url|max:2048',
            'content_submission_id' => 'nullable|integer|exists:content_submissions,id',
            'note' => 'nullable|string|max:1000',
            'order_item_id' => 'nullable|integer|exists:order_items,id',
            'confirm_existing' => 'nullable|boolean',
        ]);

        try {
            $order = Order::where('user_id', auth()->id())->findOrFail($id);
            $service = app(ContentRevisionService::class);
            $result = $service->fulfillFromAdvertiser($order, $request->user(), [
                'content_link' => $request->input('content_link'),
                'content_submission_id' => $request->input('content_submission_id'),
                'note' => $request->input('note'),
                'order_item_id' => $request->input('order_item_id'),
                'confirm_existing' => $request->boolean('confirm_existing'),
            ]);

            $service->notifyPublisherFulfilled($result['order'], $result['item'], $result['site']);

            return response()->json([
                'success' => true,
                'message' => 'Revised article sent to the publisher.',
                'item' => [
                    'id' => $result['item']->id,
                    'content_link' => $result['item']->content_link,
                    'content_original_name' => $result['item']->content_original_name,
                    'content_revision_requested' => $result['item']->content_revision_requested,
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error fulfilling content revision: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to send the revised article. Please try again.'),
            ], 500);
        }
    }

    /**
     * Approved Content Library articles available to attach when fulfilling a revision.
     */
    public function contentRevisionLibraryOptions(Request $request, $id)
    {
        $order = Order::where('user_id', auth()->id())->with('items')->findOrFail($id);

        $currentIds = $order->items
            ->pluck('content_submission_id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        $articles = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->orderable()
            ->latest('id')
            ->limit(50)
            ->get(['id', 'title', 'original_filename', 'language', 'country'])
            ->map(fn (ContentSubmission $s) => [
                'id' => $s->id,
                'label' => $s->title ?: $s->original_filename ?: ('Article #'.$s->id),
                'language' => $s->language,
                'country' => $s->country,
            ])
            ->values();

        $current = [];
        if ($currentIds !== []) {
            $current = ContentSubmission::query()
                ->where('user_id', auth()->id())
                ->whereIn('id', $currentIds)
                ->get(['id', 'title', 'original_filename'])
                ->map(fn (ContentSubmission $s) => [
                    'id' => $s->id,
                    'label' => $s->title ?: $s->original_filename ?: ('Article #'.$s->id),
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'success' => true,
            'current' => $current,
            'orderable' => $articles,
        ]);
    }

    /**
     * Get cart count for badge
     */
    public function getCartCount(Request $request)
    {
        // Keep badge in sync: drop inactive/missing lines before counting.
        $this->syncPrunedSessionCart();
        $cart = session()->get('cart', []);
        $count = array_sum(array_column($cart, 'quantity'));
        $total = round(array_sum(array_map(
            fn ($item) => ((float) ($item['price'] ?? 0)) * ((int) ($item['quantity'] ?? 0)),
            $cart
        )), 2);

        return response()->json([
            'count' => $count,
            'cart_total' => $total,
        ]);
    }

    /**
     * Re-run live URL reachability check for an order item (advertiser).
     */
    public function recheckLiveUrl(Request $request, int $id)
    {
        $data = $request->validate([
            'order_item_id' => 'nullable|integer',
        ]);

        $order = Order::with('items')->where('user_id', auth()->id())->findOrFail($id);
        $requestedItemId = isset($data['order_item_id']) ? (int) $data['order_item_id'] : null;
        if ($requestedItemId) {
            $item = $order->items->firstWhere('id', $requestedItemId);
        } else {
            $withLiveUrl = $order->items->filter(fn ($line) => filled($line->live_url));
            $item = $withLiveUrl->count() === 1
                ? $withLiveUrl->first()
                : ($order->items->count() === 1 ? $order->items->first() : null);
        }

        if (! $item instanceof OrderItem) {
            return response()->json([
                'success' => false,
                'message' => $order->items->count() > 1
                    ? 'Please choose which placement to recheck.'
                    : 'No live URL to check yet.',
            ], 422);
        }

        if (! filled($item->live_url)) {
            return response()->json([
                'success' => false,
                'message' => 'No live URL to check yet.',
            ], 422);
        }

        $health = app(LiveUrlHealthChecker::class)->check((string) $item->live_url);
        if (Schema::hasColumn('order_items', 'live_url_check_ok')) {
            $item->update([
                'live_url_check_ok' => $health['ok'],
                'live_url_http_status' => $health['status'],
                'live_url_checked_at' => $health['checked_at'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $health['message'],
            'live_url_check' => [
                'ok' => $health['ok'],
                'status' => $health['status'],
                'checked_at' => optional($health['checked_at'])->toIso8601String(),
                'message' => $health['message'],
            ],
        ]);
    }

    /**
     * Recreate a Stripe Checkout session for a failed card order (Pay again).
     */
    public function retryPayment(int $id)
    {
        try {
            $order = Order::with('items')
                ->where('user_id', auth()->id())
                ->where('id', $id)
                ->first();

            if (! $order || ! $this->orderCanRetryPayment($order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be paid again. Open checkout if your cart was restored.',
                ], 422);
            }

            if (! app(StripeCustomerService::class)->configured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Card payments are not configured. Set STRIPE_SECRET and STRIPE_KEY, or choose another payment method.',
                ], 503);
            }

            $amountDue = round((float) $order->total_amount, 2);
            if ($amountDue <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid order amount for retry.',
                ], 422);
            }

            // Sibling orders sharing the same reference (multi-site checkout package).
            $package = Order::with('items')
                ->where('user_id', auth()->id())
                ->where('reference_code', $order->reference_code)
                ->where('payment_method', 'card')
                ->where('payment_status', 'failed')
                ->where('status', 'pending')
                ->get();

            $packageTotal = round((float) $package->sum('total_amount'), 2);
            $referenceCode = (string) $order->reference_code;

            if ($package->contains(fn (Order $row) => ! $row->hasCatalogVisibleFulfillment())) {
                return response()->json([
                    'success' => false,
                    'message' => 'This listing is no longer available. Open checkout if you still want to order other sites.',
                ], 422);
            }

            // Pay again charges the full package on the card. Release any leftover
            // checkout bonus for this reference first so promo is not left reserved
            // while the advertiser pays the original total again.
            app(OrderPaymentService::class)->refundBonusReservedForReference(
                (int) auth()->id(),
                $referenceCode,
                null,
                $package
            );

            Stripe::setApiKey(config('services.stripe.secret'));

            $retryPayload = [
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Order retry - '.$package->count().' item(s)',
                            'description' => 'Order reference: '.$referenceCode,
                        ],
                        'unit_amount' => StripePaymentService::toCents($packageTotal),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('advertiser.checkout.process').'?session_id={CHECKOUT_SESSION_ID}&ref='.urlencode($referenceCode),
                'cancel_url' => route('advertiser.orders').'?payment_status=failed&retry=canceled',
                'metadata' => [
                    'type' => 'order_payment',
                    'reference_code' => $referenceCode,
                    'user_id' => (string) auth()->id(),
                    'order_count' => (string) $package->count(),
                    'expected_amount' => (string) $packageTotal,
                    'order_total' => (string) $packageTotal,
                    'bonus_applied' => '0',
                    'is_retry' => '1',
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $referenceCode,
                        'user_id' => (string) auth()->id(),
                        'expected_amount' => (string) $packageTotal,
                        'order_total' => (string) $packageTotal,
                        'bonus_applied' => '0',
                    ],
                ],
            ];

            $checkoutSession = app(StripeCustomerService::class)
                ->createCheckoutSession($retryPayload, auth()->user(), true);

            Order::whereIn('id', $package->pluck('id'))
                ->update([
                    'stripe_session_id' => $checkoutSession->id,
                    'payment_status' => 'pending',
                    'status' => 'pending',
                ]);

            session()->put('pending_card_reference', $referenceCode);

            return response()->json([
                'success' => true,
                'requires_payment' => true,
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
                'reference_code' => $referenceCode,
                'amount_due' => $packageTotal,
            ]);
        } catch (\Exception $e) {
            Log::error('Order payment retry failed: '.$e->getMessage(), [
                'order_id' => $id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to start payment retry. Please try again or contact support.',
            ], 500);
        }
    }

    private function orderCanRetryPayment(Order $order): bool
    {
        return $order->payment_method === 'card'
            && $order->payment_status === 'failed'
            && $order->status === 'pending'
            && $order->items->isNotEmpty()
            && $order->hasCatalogVisibleFulfillment();
    }

    /**
     * Orders page
     */
    public function orders()
    {
        return view('advertiser.orders');
    }

    /**
     * Funnel KPI counts for the advertiser Orders page (AJAX).
     */
    public function getOrderStatistics()
    {
        try {
            $userId = auth()->id();
            $base = Order::where('user_id', $userId);

            $needsReview = (clone $base)->where('status', 'review')->count();
            $needsAction = AdvertiserOrderStatus::needsActionCountForUser((int) $userId);
            $inProgress = (clone $base)
                ->where(function ($q) {
                    $q->where(function ($pendingPaid) {
                        $pendingPaid->where('status', 'pending')
                            ->where('payment_status', 'paid')
                            ->notAwaitingScheduledRelease();
                    })->orWhere('status', 'processing');
                })
                ->count();
            $completed = (clone $base)->where('status', 'completed')->count();
            $awaitingPayment = (clone $base)
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('payment_status')
                        ->orWhere('payment_status', '!=', 'paid');
                })
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'needs_review' => $needsReview,
                    'needs_action' => $needsAction,
                    'in_progress' => $inProgress,
                    'completed' => $completed,
                    'awaiting_payment' => $awaitingPayment,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch order statistics. Please try again.'),
            ], 500);
        }
    }

    /**
     * Get orders list (AJAX)
     */
    public function getOrders(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = Order::where('user_id', $userId)
                ->with(OrderItemDispute::tableAvailable() ? ['items.latestDispute'] : ['items']);

            $search = search_text($request->input('search'));
            $statusFilter = strtolower(search_text($request->input('status')));

            // Search filter — word-AND across order #, reference, site name/URL, live URL
            if ($search !== '') {
                $orderSearch = app(AdvertiserOrderSearchQuery::class);
                $hostNeedle = $this->catalogSearchHostNeedle($search);
                $orderSearch->apply($query, $search, $hostNeedle);
            }

            // Status filter — awaiting_* / in_progress composites; other values match column.
            if ($statusFilter !== '') {
                $status = $statusFilter;
                if ($status === 'awaiting_payment') {
                    $query->where('status', 'pending')
                        ->where(function ($q) {
                            $q->whereNull('payment_status')
                                ->orWhere('payment_status', '!=', 'paid');
                        });
                } elseif ($status === 'awaiting_publisher') {
                    $query->where('status', 'pending')
                        ->where('payment_status', 'paid')
                        ->notAwaitingScheduledRelease();
                } elseif ($status === 'in_progress') {
                    // Matches funnel KPI: paid·waiting publisher + publisher working.
                    $query->where(function ($q) {
                        $q->where(function ($pendingPaid) {
                            $pendingPaid->where('status', 'pending')
                                ->where('payment_status', 'paid')
                                ->notAwaitingScheduledRelease();
                        })->orWhere('status', 'processing');
                    });
                } elseif ($status === 'needs_action') {
                    $query->whereIn(
                        'id',
                        AdvertiserOrderStatus::needsActionQuery((int) $userId)->select('orders.id')
                    );
                } else {
                    $query->where('status', $status);
                }
            }

            // Payment status filter
            $paymentStatus = search_text($request->input('payment_status'));
            if ($paymentStatus !== '') {
                $query->where('payment_status', $paymentStatus);
            }

            // Payment method filter
            $paymentMethod = search_text($request->input('payment_method'));
            if ($paymentMethod !== '') {
                $query->where('payment_method', $paymentMethod);
            }

            // Date range filter
            $dateFrom = search_text($request->input('date_from'));
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            $dateTo = search_text($request->input('date_to'));
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            AdvertiserOrderStatus::applyQueueOrder($query, $statusFilter);
            if ($search !== '') {
                app(AdvertiserOrderSearchQuery::class)->applyRelevanceOrder($query, $search);
            }
            $orders = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate(20);

            $orderIds = collect($orders->items())->pluck('id');
            $unreadByOrder = OrderChatMessage::whereIn('order_id', $orderIds)
                ->where('sender_type', 'publisher')
                ->where('is_read', false)
                ->notBlocked()
                ->selectRaw('order_id, COUNT(*) as unread_count')
                ->groupBy('order_id')
                ->pluck('unread_count', 'order_id');

            $clawbacks = app(OrderClawbackService::class);
            $ordersPayload = collect($orders->items())->map(function ($order) use ($unreadByOrder, $clawbacks) {
                $order->unread_chat = (int) ($unreadByOrder[$order->id] ?? 0);
                $order->can_retry_payment = $this->orderCanRetryPayment($order);
                $order->items_count = $order->items->count();
                $meta = AdvertiserOrderStatus::meta($order, $order->items->first());
                $order->status_label = $meta['label'];
                $order->next_action = $meta['next'];
                $order->status_cls = $meta['cls'];
                $order->auto_approve_hint = $meta['auto_approve_hint'];
                $item = $order->items->first();
                if ($item) {
                    $item->auto_approve_hours_remaining = (int) $item->getAutoApproveHoursRemaining();
                }
                $this->attachDisputeMeta($order, $item, $clawbacks);

                return $order;
            });

            $needsAction = AdvertiserOrderStatus::needsActionCountForUser((int) $userId);

            return response()->json([
                'success' => true,
                'orders' => $ordersPayload,
                'needs_action' => $needsAction,
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch orders. Please try again.'),
            ], 500);
        }
    }

    /**
     * Get single order details (AJAX)
     */
    public function getOrder($id)
    {
        try {
            $userId = auth()->id();

            $order = Order::where('user_id', $userId)
                ->with(OrderItemDispute::tableAvailable() ? ['items.latestDispute'] : ['items'])
                ->find($id);

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ]);
            }

            $order->can_retry_payment = $this->orderCanRetryPayment($order);
            $order->items_count = $order->items->count();
            $meta = AdvertiserOrderStatus::meta($order, $order->items->first());
            $order->status_label = $meta['label'];
            $order->next_action = $meta['next'];
            $order->status_cls = $meta['cls'];
            $order->auto_approve_hint = $meta['auto_approve_hint'];
            $item = $order->items->first();
            if ($item) {
                $item->auto_approve_hours_remaining = (int) $item->getAutoApproveHoursRemaining();
            }
            foreach ($order->items as $line) {
                if (method_exists($line, 'getAutoApproveHoursRemaining')) {
                    $line->auto_approve_hours_remaining = (int) $line->getAutoApproveHoursRemaining();
                }
            }
            $this->attachDisputeMeta($order, $item, app(OrderClawbackService::class));

            return response()->json([
                'success' => true,
                'order' => $order,
            ]);
        } catch (\Exception $e) {
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch order details. Please try again.'),
            ], 500);
        }
    }

    /**
     * Approve order and transfer payment from reserved_balance to publisher's wallet
     */
    public function approveOrder(Request $request, $id)
    {
        try {
            // Hostinger deploys often skip migrations — heal checkout columns first
            // so optional fields like order_items.publisher_status cannot 500 Approve.
            $schema = app(CheckoutSchemaService::class);
            $schema->ensureCheckoutTables();

            $order = Order::with('items')->findOrFail($id);

            // Verify this order belongs to the authenticated advertiser
            if ($order->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to you',
                ], 403);
            }

            // Check if order is already completed
            if ($order->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already approved and completed',
                ], 400);
            }

            // Check if order is in review status (publisher submitted live URL)
            if ($order->status !== 'review') {
                $hasLiveUrl = $order->items->contains(fn ($line) => filled($line->live_url));
                $message = match ((string) $order->status) {
                    'processing' => $hasLiveUrl
                        ? 'This order is still in progress (another placement may still need a revised article). It cannot be approved until every placement is ready for review.'
                        : 'The publisher has not submitted a live URL yet. Approve becomes available after they submit the live link.',
                    'pending' => 'This order is not ready to approve yet. Wait until the publisher accepts and submits a live URL.',
                    'cancelled' => 'This order was cancelled and cannot be approved.',
                    'scheduled' => 'This scheduled order is not ready to approve yet.',
                    default => 'Order must be under review to approve (current status: '.$order->status.').',
                };

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 400);
            }

            if ($order->items->contains(fn ($line) => $line->isContentRevisionRequested())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Send the revised article first — a placement on this order is still waiting for updated content.',
                ], 422);
            }

            if ($order->items->contains(fn ($line) => ! $line->isPayoutComplete() && ! $line->isReadyForAdvertiserApprove())) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be approved until every placement has a live URL and no open revision.',
                ], 422);
            }

            if (! $order->items->contains(fn ($line) => filled($line->live_url))) {
                return response()->json([
                    'success' => false,
                    'message' => 'No live URL has been submitted for this order yet.',
                ], 400);
            }

            if ($order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be approved because payment is not complete.',
                ], 422);
            }

            DB::beginTransaction();

            // Lock order to prevent double-approve races
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $order->load('items');

            if ($order->status === 'completed') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order is already approved and completed',
                ], 400);
            }

            if ($order->status !== 'review') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order must be under review to approve (current status: '.$order->status.').',
                ], 400);
            }

            if ($order->payment_status !== 'paid') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be approved because payment is not complete.',
                ], 422);
            }

            if ($order->items->contains(fn ($line) => $line->isContentRevisionRequested())) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Send the revised article first — a placement on this order is still waiting for updated content.',
                ], 422);
            }

            if ($order->items->contains(fn ($line) => ! $line->isPayoutComplete() && ! $line->isReadyForAdvertiserApprove())) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'This order cannot be approved until every placement has a live URL and no open revision.',
                ], 422);
            }

            // Update order status to completed (skip missing columns on older DBs)
            $order->update($schema->filterExistingColumns('orders', [
                'status' => 'completed',
                'completed_at' => now(),
            ]));

            $publisherRoleId = Wallet::publisherRoleId();
            $advertiserRoleId = Wallet::advertiserRoleId();

            $advertiserWallet = $advertiserRoleId
                ? Wallet::lockOrCreateForRole((int) $order->user_id, (int) $advertiserRoleId)
                : null;

            $transferPublishers = [];
            $totalTransferred = 0;
            $rateable = [];
            $mailPayloads = [];

            foreach ($order->items as $orderItem) {
                // Get the site to find the publisher
                $site = Site::find($orderItem->site_id);
                $alreadyPaid = $orderItem->isPayoutComplete();

                // Mark the line completed even when the site/publisher row is gone —
                // otherwise the advertiser UI can keep offering Approve after a
                // partial success, and publisher task lists stay out of sync.
                // Never write columns that do not exist (Hostinger schema drift).
                $itemCompletion = $schema->filterExistingColumns('order_items', [
                    'completed_at' => $orderItem->completed_at ?? now(),
                    'publisher_status' => 'completed',
                ]);
                if ($itemCompletion !== []) {
                    $orderItem->forceFill($itemCompletion)->save();
                }

                if ($alreadyPaid) {
                    continue;
                }

                if ($site) {
                    try {
                        Site::refreshCompletedOrdersCount((int) $site->id);
                    } catch (\Throwable $e) {
                        // Counter is cosmetic; never abort Approve / wallet payout.
                        Log::warning('Approve skipped completed_orders_count refresh', [
                            'order_id' => $order->id,
                            'site_id' => $site->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $rateable[] = [
                        'order_item_id' => $orderItem->id,
                        'site_id' => $site->id,
                        'site_name' => $site->site_name,
                        'domain' => $site->domain,
                    ];
                }

                if ($site && $site->publisher_id) {
                    $publisher = User::find($site->publisher_id);

                    if ($publisher && $publisherRoleId) {
                        $publisherWallet = Wallet::lockOrCreateForRole($publisher->id, $publisherRoleId);

                        // Publisher payout excludes the platform markup retained on the base price
                        $amount = (float) $orderItem->publisherPayoutAmount();
                        $advertiserPaid = round((float) $orderItem->price, 2);
                        // Never credit more than the advertiser paid for this line
                        // (guards legacy rows / discount edge cases).
                        if ($amount > $advertiserPaid) {
                            Log::warning('Capping publisher payout to advertiser-paid amount', [
                                'order_id' => $order->id,
                                'order_item_id' => $orderItem->id,
                                'advertiser_paid' => $advertiserPaid,
                                'uncapped_payout' => $amount,
                            ]);
                            $amount = $advertiserPaid;
                        }
                        $platformFee = max(0, round($advertiserPaid - $amount, 2));
                        $publisherWallet->credit($amount);

                        try {
                            app(WalletLedgerService::class)->recordTransferIn(
                                $publisherWallet,
                                $amount,
                                $orderItem,
                                'ORDER-ITEM-'.$orderItem->id,
                                'Publisher earnings for order #'.($order->order_number ?? $order->id),
                                [
                                    'order_id' => $order->id,
                                    'platform_fee' => $platformFee,
                                    'advertiser_paid' => (float) $orderItem->price,
                                ]
                            );
                        } catch (\Throwable $ledgerError) {
                            // Balance credit already applied; do not fail Approve for ledger drift.
                            Log::error('Approve ledger write failed after publisher credit', [
                                'order_id' => $order->id,
                                'order_item_id' => $orderItem->id,
                                'error' => $ledgerError->getMessage(),
                            ]);
                        }

                        $totalTransferred += $amount;

                        $transferPublishers[] = [
                            'publisher_id' => $publisher->id,
                            'publisher_name' => $publisher->name,
                            'amount' => $amount,
                            'platform_fee' => $platformFee,
                        ];

                        $mailPayloads[] = [$order, $orderItem, $site, $publisher];

                        Log::info('Payment transferred to publisher wallet for approval', [
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'publisher_id' => $publisher->id,
                            'advertiser_paid' => (float) $orderItem->price,
                            'publisher_payout' => $amount,
                            'platform_fee' => $platformFee,
                            'wallet_balance' => $publisherWallet->balance,
                        ]);
                    }
                }
            }

            // Wallet: consume the reserved line. Card / manual: consume leftover
            // checkout bonus so a later reject cannot mint it as cash.
            if ($advertiserWallet) {
                app(OrderRefundService::class)->consumeReservedForSettledOrder($order, $advertiserWallet);

                Log::info('Reserved funds released from advertiser wallet', [
                    'user_id' => auth()->id(),
                    'order_id' => $order->id,
                    'order_total' => $order->total_amount,
                    'payment_method' => $order->payment_method,
                    'remaining_reserved_balance' => $advertiserWallet->reserved_balance,
                    'bonus_reserved' => $advertiserWallet->bonus_reserved,
                ]);
            }

            DB::commit();

            // Side-effects after money has moved: never turn a successful payout into
            // a red "Failed to approve" popup for the advertiser.
            foreach ($mailPayloads as [$mailOrder, $mailItem, $mailSite, $mailPublisher]) {
                try {
                    Mail::to($mailPublisher->email)->send(new OrderApprovedByAdvertiser($mailOrder, $mailItem, $mailSite));
                    Log::info('Order approval email sent to publisher', [
                        'order_id' => $mailOrder->id,
                        'publisher_email' => $mailPublisher->email,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to send order approval email to publisher: '.$e->getMessage(), [
                        'order_id' => $mailOrder->id,
                    ]);
                }
            }

            try {
                foreach ($transferPublishers as $transfer) {
                    $publisherUser = User::find($transfer['publisher_id'] ?? null);
                    if ($publisherUser) {
                        app(InAppNotificationService::class)->notifyOrderCompleted(
                            $order,
                            $publisherUser,
                            (float) ($transfer['amount'] ?? 0)
                        );
                    }
                }
                if (empty($transferPublishers)) {
                    app(InAppNotificationService::class)->notifyOrderCompleted($order);
                }
            } catch (\Throwable $e) {
                Log::error('Order approved but completion notification failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $message = 'Order approved successfully! ';
            if ($order->payment_method === 'wallet') {
                $message .= '€'.number_format($totalTransferred, 2).' (publisher payout, excluding platform fee) has been transferred to the publisher\'s wallet.';
            } else {
                $message .= '€'.number_format($totalTransferred, 2).' publisher payout processed (platform fee retained).';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'ask_rating' => true,
                'rateable' => $rateable,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error approving order: '.$e->getMessage(), [
                'order_id' => $id,
                'user_id' => auth()->id(),
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $payload = [
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to approve order. Please try again.'),
            ];
            // Surface the real exception in local/debug so Swal is actionable.
            if (config('app.debug')) {
                $payload['debug'] = $e::class.': '.$e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }

    /**
     * Advertiser reports that the publisher removed the live link after completion.
     */
    public function reportLinkRemoved(Request $request, $id)
    {
        try {
            $data = $request->validate([
                'reason' => 'required|string|min:10|max:1000',
                'order_item_id' => 'nullable|integer',
            ]);

            $order = Order::with('items')->where('user_id', auth()->id())->findOrFail($id);
            $item = $this->resolveDisputableItem(
                $order,
                isset($data['order_item_id']) ? (int) $data['order_item_id'] : null,
            );
            if (! $item) {
                return response()->json([
                    'success' => false,
                    'message' => $order->items->count() > 1
                        ? 'Please choose which placement to report.'
                        : 'Order has no items to dispute.',
                ], $order->items->count() > 1 ? 422 : 404);
            }

            $dispute = app(OrderClawbackService::class)->openDispute($item, $request->user(), $data['reason']);

            return response()->json([
                'success' => true,
                'message' => 'Dispute submitted. Our team will review and may claw back the publisher payout if the link was removed.',
                'dispute' => [
                    'id' => $dispute->id,
                    'status' => $dispute->status,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Unable to open dispute.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error reporting link removed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit dispute.',
            ], 500);
        }
    }

    private function attachDisputeMeta(Order $order, ?OrderItem $item, OrderClawbackService $clawbacks): void
    {
        $reportable = false;
        $open = null;
        $latest = null;

        foreach ($order->items as $line) {
            $can = $clawbacks->canOpenDispute($order, $line);
            $dispute = OrderItemDispute::tableAvailable() ? $line->latestDispute : null;
            $line->setAttribute('can_report_link_removed', $can);
            $line->setAttribute('dispute_status', $dispute?->status);
            $line->setAttribute('dispute_id', $dispute?->id);
            $reportable = $reportable || $can;
            if ($dispute?->status === OrderItemDispute::STATUS_OPEN) {
                $open = $dispute;
            }
            if ($dispute && ($latest === null || (int) $dispute->id > (int) $latest->id)) {
                $latest = $dispute;
            }
        }

        $shown = $open ?? $latest ?? (OrderItemDispute::tableAvailable() ? $item?->latestDispute : null);
        $order->can_report_link_removed = $reportable;
        $order->dispute_status = $shown?->status;
        $order->dispute_id = $shown?->id;
        $order->dispute_reason = $shown?->reason;
    }

    private function resolveDisputableItem(Order $order, ?int $orderItemId): ?OrderItem
    {
        if ($orderItemId) {
            $item = $order->items->firstWhere('id', $orderItemId);

            return $item instanceof OrderItem ? $item : null;
        }

        $clawbacks = app(OrderClawbackService::class);
        $candidates = $order->items->filter(
            fn ($line) => $line instanceof OrderItem && $clawbacks->canOpenDispute($order, $line)
        );

        if ($candidates->count() === 1) {
            $item = $candidates->first();

            return $item instanceof OrderItem ? $item : null;
        }

        if ($order->items->count() === 1) {
            $item = $order->items->first();

            return $item instanceof OrderItem ? $item : null;
        }

        return null;
    }

    /**
     * Resolve approved content library articles + publication schedule for the cart.
     * Articles must be approved in Content Library before ordering.
     *
     * @return array{lines: array<int, array{orderItem: array, submission: ContentSubmission}>, schedule: array}|JsonResponse
     */
    private function resolveCheckoutContent(array $cart, ?array $contentSubmissions, array $scheduleInput): array|JsonResponse
    {
        try {
            $expandedOrders = $this->cartPricing()->expandCart($cart, auth()->id());
        } catch (\Exception $e) {
            $message = UserFacingError::message($e, 'Some items in your cart are no longer available. Please review your cart.');
            $status = str_contains($e->getMessage(), 'own websites') ? 422 : 200;

            return response()->json(['success' => false, 'message' => $message], $status);
        }

        if ($expandedOrders === []) {
            return response()->json(['success' => false, 'message' => 'Your cart is empty.']);
        }

        $librarySubmissionId = session('checkout_content_submission_id');
        $lines = [];
        $submissionModels = [];
        $seen = [];

        foreach ($expandedOrders as $idx => $orderItem) {
            $site = $orderItem['site'];
            $copyIndex = max(0, ((int) ($orderItem['copy_number'] ?? 1)) - 1);
            $sensitiveType = $orderItem['sensitive_type'] ?? null;
            $homepageDays = $orderItem['homepage_days'] ?? null;

            // Prefer per-cart content_submission_id, then request map, then library session
            $cartLine = collect($cart)->first(function ($row) use ($site, $sensitiveType, $homepageDays) {
                return $this->cartLineMatches(
                    is_array($row) ? $row : [],
                    (int) $site->id,
                    $sensitiveType,
                    $homepageDays
                );
            });

            // Each placement (copy) needs its own article — never reuse scalar/library ID for copyIndex > 0.
            $submissionId = data_get($cartLine, "content_submission_ids.$copyIndex")
                ?? ($copyIndex === 0 ? data_get($cartLine, 'content_submission_id') : null)
                ?? data_get($contentSubmissions, $site->id.'.'.$copyIndex)
                ?? data_get($contentSubmissions, (string) $site->id.'.'.$copyIndex)
                ?? ($copyIndex === 0 ? $librarySubmissionId : null)
                ?? null;

            if (! $submissionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Select an approved article from your Content Library before placing this order.',
                ]);
            }

            if (isset($seen[$submissionId])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Each Content Library article can only be ordered on one website.',
                ], 422);
            }

            // Same gate as cart assign — archived / incomplete approved rows are not orderable.
            $submission = ContentSubmission::query()
                ->where('id', $submissionId)
                ->where('user_id', auth()->id())
                ->orderable()
                ->first();

            if (! $submission || ! $submission->canBeOrdered()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved Content Library articles can be ordered. Edit and resubmit articles that need correction.',
                ], 422);
            }

            if (! $submission->isReadyForCheckout()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add anchor text and a valid HTTPS target URL, or confirm continuing without a link.',
                ], 422);
            }

            $seen[$submissionId] = $submission;
            $submissionModels[] = $submission;
            $lines[] = ['orderItem' => $orderItem, 'submission' => $submission];
        }

        $moderation = app(ContentModerationService::class)->assertSubmissionsApproved($submissionModels, auth()->user());
        if (! $moderation['ok']) {
            $first = $moderation['failures'][0] ?? null;

            return response()->json([
                'success' => false,
                'message' => $first['message'] ?? config('content_upload.help.compliance_reject'),
                'moderation' => [
                    'title' => $first['title'] ?? 'Article Cannot Be Accepted',
                    'failures' => $moderation['failures'],
                ],
            ], 422);
        }

        $schedule = app(ScheduledOrderService::class)->normalizeSchedule(
            $scheduleInput['mode'] ?? 'immediate',
            $scheduleInput['date'] ?? null,
            $scheduleInput['time'] ?? null,
            $scheduleInput['timezone'] ?? null,
        );

        if (! $schedule['ok']) {
            return response()->json([
                'success' => false,
                'message' => $schedule['message'] ?? 'Invalid publication schedule.',
            ], 422);
        }

        return [
            'lines' => $lines,
            'schedule' => $schedule,
        ];
    }

    private function initialOrderStatus(array $schedule): string
    {
        // Charged in advance; publishers are notified immediately and must publish on the scheduled date.
        // Keep status in the normal publisher queue (`pending`).
        return 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleOrderFields(array $schedule): array
    {
        return [
            'publication_mode' => $schedule['mode'] ?? 'immediate',
            'scheduled_publish_at' => $schedule['at'] ?? null,
            'schedule_timezone' => $schedule['timezone'] ?? 'UTC',
        ];
    }

    /**
     * @return array{
     *     schedulingEnabled: bool,
     *     checkoutSchedule: array<string, mixed>,
     *     maxScheduleMonths: int,
     *     maxScheduleDate: string,
     *     scheduleMinDate: string,
     *     scheduleTimezones: list<string>,
     *     scheduleDefaultTimezone: string
     * }
     */
    private function checkoutScheduleContext(): array
    {
        $scheduler = app(ScheduledOrderService::class);
        $session = is_array(session('checkout_schedule')) ? session('checkout_schedule') : [];
        $tz = is_string($session['timezone'] ?? null) && $session['timezone'] !== ''
            ? $session['timezone']
            : $scheduler->defaultTimezone();

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = 'UTC';
        }

        return [
            'schedulingEnabled' => app(ContentUploadService::class)->schedulingEnabled(),
            'checkoutSchedule' => $session,
            'maxScheduleMonths' => $scheduler->maxMonths(),
            'maxScheduleDate' => $scheduler->maxScheduleDateString($tz),
            'scheduleMinDate' => now($tz)->toDateString(),
            'scheduleTimezones' => $scheduler->commonTimezones(),
            'scheduleDefaultTimezone' => $scheduler->defaultTimezone(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function checkoutScheduleClientHint(): ?array
    {
        if (! app(ContentUploadService::class)->schedulingEnabled()) {
            return null;
        }

        $session = is_array(session('checkout_schedule')) ? session('checkout_schedule') : [];
        if (($session['mode'] ?? '') !== 'scheduled' || empty($session['date'])) {
            return null;
        }

        $tz = (string) ($session['timezone'] ?? 'UTC');
        $time = (string) ($session['time'] ?? '09:00');
        $date = (string) $session['date'];

        return [
            'enabled' => true,
            'mode' => 'scheduled',
            'date' => $date,
            'time' => $time,
            'timezone' => $tz,
            'label' => 'Publication: '.$date.', '.$time.' '.$tz.' — change at checkout',
            'checkout_url' => route('advertiser.checkout'),
        ];
    }

    /**
     * Defense in depth: drop the advertiser's own publisher sites if they
     * still appear after cart prune / expandCart. Own-only carts 422 earlier.
     *
     * @param  array{lines: array<int, mixed>, schedule: array}  $checkoutContent
     * @return array{lines: array<int, mixed>, schedule: array}
     */
    private function excludeSelfOwnedCheckoutLines(array $checkoutContent, int $userId): array
    {
        $buyer = User::query()->find($userId);
        $checkoutContent['lines'] = array_values(array_filter(
            $checkoutContent['lines'] ?? [],
            function ($line) use ($buyer) {
                $site = is_array($line) ? ($line['orderItem']['site'] ?? null) : null;

                return ! ($site instanceof Site && $site->isOwnedBy($buyer));
            }
        ));

        return $checkoutContent;
    }

    /**
     * @param  array{mode?: string, at?: mixed, timezone?: string}  $schedule
     */
    private function persistCheckoutScheduleSession(array $schedule): void
    {
        $tz = (string) ($schedule['timezone'] ?? 'UTC');
        $at = $schedule['at'] ?? null;
        $local = $at instanceof CarbonInterface
            ? $at->copy()->timezone($tz)
            : null;

        session()->put('checkout_schedule', [
            'mode' => $schedule['mode'] ?? 'immediate',
            'date' => $local?->toDateString(),
            'time' => $local?->format('H:i'),
            'timezone' => $tz,
        ]);
    }

    private function scheduleSuccessLabel(array $schedule): ?string
    {
        if (($schedule['mode'] ?? '') !== 'scheduled') {
            return null;
        }

        $at = $schedule['at'] ?? null;
        if (! $at instanceof CarbonInterface) {
            return null;
        }

        $tz = (string) ($schedule['timezone'] ?? 'UTC');

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = 'UTC';
        }

        return $at->copy()->timezone($tz)->format('d M Y, H:i').' '.$tz;
    }

    public function saveCheckoutSchedule(Request $request): JsonResponse
    {
        $normalized = app(ScheduledOrderService::class)->normalizeSchedule(
            $request->input('publication_mode'),
            $request->input('scheduled_date'),
            $request->input('scheduled_time'),
            $request->input('timezone'),
        );

        if (! $normalized['ok']) {
            return response()->json([
                'success' => false,
                'message' => $normalized['message'] ?? 'Invalid publication schedule.',
            ], 422);
        }

        $this->persistCheckoutScheduleSession($normalized);

        return response()->json([
            'success' => true,
            'schedule' => $this->checkoutScheduleClientHint(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>
     */
    private function orderItemPayload(int $orderId, Site $site, array $orderItem, ContentSubmission $submission): array
    {
        return [
            'order_id' => $orderId,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $orderItem['price'],
            'content_link' => route('advertiser.content-submissions.download', $submission),
            'content_submission_id' => $submission->id,
            'content_disk' => $submission->disk,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
            'content_mime' => $submission->mime,
            'anchor_text' => $submission->anchor_text,
            'target_url' => $submission->target_url,
            'feature_image_url' => $submission->feature_image_url,
            'moderation_status' => $submission->moderation_status,
            'sensitive_type' => $orderItem['sensitive_type'],
            'additional_price' => $orderItem['additional_price'],
            'homepage_days' => $orderItem['homepage_days'] ?? null,
            'homepage_price' => $orderItem['homepage_price'] ?? 0,
            'social_channels' => $orderItem['social_channels']
                ?? ($site->enabledSocialChannels() ?: []),
            'publisher_price' => $orderItem['publisher_price'] ?? null,
            'platform_fee_percent' => $orderItem['platform_fee_percent'] ?? null,
            'platform_fee_amount' => $orderItem['platform_fee_amount'] ?? null,
        ];
    }

    private function attachSubmissionToOrder(ContentSubmission $submission, Order $order, OrderItem $item): void
    {
        $locked = ContentSubmission::query()->whereKey($submission->id)->lockForUpdate()->first();
        if (! $locked || $locked->isClaimedByAnotherOrder((int) $order->id)) {
            throw new \RuntimeException(ContentSubmission::UNAVAILABLE_MESSAGE);
        }

        // Each article is published on one site only. Keep the first order/item linkage on the
        // submission row; every OrderItem still stores its own content_submission_id.
        $payload = [
            'publication_mode' => $order->publication_mode,
            'scheduled_publish_at' => $order->scheduled_publish_at,
            'timezone' => $order->schedule_timezone ?: $locked->timezone,
        ];

        if (! $locked->order_id) {
            $payload['order_id'] = $order->id;
            $payload['order_item_id'] = $item->id;
        }

        $filtered = app(CheckoutSchemaService::class)
            ->filterExistingColumns($locked->getTable(), $payload);

        if ($filtered !== []) {
            $locked->update($filtered);
        }

        $submission->setRawAttributes($locked->fresh()->getAttributes(), true);
    }

    /**
     * @param  array<int, mixed>  $cart
     */
    private function resolveLibrarySubmissionForCheckout(array $cart): ?ContentSubmission
    {
        $librarySubmissionId = session('checkout_content_submission_id');

        if (! $librarySubmissionId) {
            foreach ($cart as $row) {
                if (! empty($row['content_submission_id'])) {
                    $librarySubmissionId = $row['content_submission_id'];
                    break;
                }
                $nested = data_get($row, 'content_submission_ids.0');
                if ($nested) {
                    $librarySubmissionId = $nested;
                    break;
                }
            }
        }

        if (! $librarySubmissionId) {
            return null;
        }

        return ContentSubmission::query()
            ->forCheckoutSummary()
            ->where('id', $librarySubmissionId)
            ->where('user_id', auth()->id())
            ->orderable()
            ->first();
    }

    private function checkoutBonusCacheKey(int $userId, string $referenceCode): string
    {
        return CheckoutIntentService::bonusCacheKey($userId, $referenceCode);
    }

    private function rememberCheckoutBonus(int $userId, string $referenceCode, float $amount): void
    {
        app(CheckoutIntentService::class)->rememberBonus($userId, $referenceCode, $amount);
    }

    private function forgetCheckoutBonus(int $userId, string $referenceCode): void
    {
        app(CheckoutIntentService::class)->forgetBonus($userId, $referenceCode);
    }

    private function consumeCheckoutBonus(int $userId, string $referenceCode, ?float $amount = null): void
    {
        $bonus = $amount ?? app(CheckoutIntentService::class)->takeBonus($userId, $referenceCode);
        if ($bonus <= 0) {
            return;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            app(CheckoutIntentService::class)->forgetBonus($userId, $referenceCode);

            return;
        }

        $wallet = Wallet::where('user_id', $userId)->where('role_id', $roleId)->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->consumeReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
        app(CheckoutIntentService::class)->forgetBonus($userId, $referenceCode);
    }

    private function refundCheckoutBonus(int $userId, string $referenceCode): void
    {
        $failed = Order::query()
            ->where('user_id', $userId)
            ->where('reference_code', $referenceCode)
            ->whereIn('payment_status', ['failed', 'pending'])
            ->where('status', '!=', 'cancelled')
            ->get();

        app(OrderRefundService::class)->releaseReservedCheckoutBonusForReference(
            $userId,
            $referenceCode,
            $failed
        );
    }

    /**
     * Split cart into sites ready to pay vs sites still missing a ready article.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<int|string, mixed>|null  $contentSubmissions
     * @return array{payable: array<int, array<string, mixed>>, deferred: array<int, array<string, mixed>>}
     */
    private function partitionCartByCheckoutReadiness(
        array $cart,
        ?array $contentSubmissions = null,
        ?int $librarySubmissionId = null
    ): array {
        $payable = [];
        $deferred = [];
        $usedSubmissionIds = [];

        foreach ($cart as $item) {
            if (! is_array($item)) {
                continue;
            }

            $siteId = (int) ($item['id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $site = Site::query()->catalogVisible()->where('id', $siteId)->first();
            if (! $site) {
                // Hidden / missing listings are dropped, not kept as "pay later".
                continue;
            }
            if ($site->isOwnedBy(auth()->user())) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lineReady = true;
            $resolvedIds = [];

            for ($copyIndex = 0; $copyIndex < $quantity; $copyIndex++) {
                $submissionId = (int) (
                    data_get($item, "content_submission_ids.$copyIndex")
                    ?? ($copyIndex === 0 ? data_get($item, 'content_submission_id') : null)
                    ?? data_get($contentSubmissions, $siteId.'.'.$copyIndex)
                    ?? data_get($contentSubmissions, (string) $siteId.'.'.$copyIndex)
                    ?? ($copyIndex === 0 ? $librarySubmissionId : null)
                    ?? 0
                );

                if ($submissionId <= 0 || isset($usedSubmissionIds[$submissionId])) {
                    $lineReady = false;
                    break;
                }

                $submission = ContentSubmission::query()
                    ->where('id', $submissionId)
                    ->where('user_id', auth()->id())
                    ->orderable()
                    ->first();

                if (! $submission || ! $submission->canBeOrdered() || ! $submission->isReadyForCheckout()) {
                    $lineReady = false;
                    break;
                }

                $resolvedIds[$copyIndex] = $submissionId;
                $usedSubmissionIds[$submissionId] = true;
            }

            if (! $lineReady || $resolvedIds === []) {
                $deferred[] = $item;

                continue;
            }

            $readyItem = $item;
            $readyItem['content_submission_id'] = $resolvedIds[0];
            if (count($resolvedIds) > 1) {
                $readyItem['content_submission_ids'] = $resolvedIds;
            }
            // Only charge catalog-visible listings that still need payment (price can be 0 after discounts).
            $payable[] = $readyItem;
        }

        return [
            'payable' => array_values($payable),
            'deferred' => array_values($deferred),
        ];
    }

    /**
     * After a successful payment, keep not-ready sites in the cart.
     */
    private function restoreDeferredCartAfterPayment(): void
    {
        $deferred = session('checkout_deferred_cart');
        session()->forget([
            'checkout_deferred_cart',
            'checkout_content_submission_id',
            'checkout_schedule',
            'pending_card_reference',
            'ordering_from_library',
            GuestPostWizardController::SESSION_KEY,
        ]);

        if (is_array($deferred) && $deferred !== []) {
            $this->putCatalogVisibleCart($deferred);

            return;
        }

        session()->forget('cart');
    }

    /**
     * Remove paid site lines from the session cart; keep anything still unpaid / not ready.
     *
     * @param  iterable<Order>  $orders
     */
    private function removePaidOrdersFromCart(iterable $orders): void
    {
        $paidKeys = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (! $item->site_id) {
                    continue;
                }
                $paidKeys[$this->cartIdentityKey([
                    'id' => $item->site_id,
                    'sensitive_type' => $item->sensitive_type ?? $order->sensitive_type ?? null,
                    'homepage_days' => $item->homepage_days ?? null,
                ])] = true;
            }
        }

        $deferred = session('checkout_deferred_cart');
        if (is_array($deferred) && $deferred !== []) {
            $this->putCatalogVisibleCart($deferred);
            session()->forget('checkout_deferred_cart');

            return;
        }

        $cart = session('cart', []);
        if (! is_array($cart) || $cart === []) {
            return;
        }

        $remaining = [];
        foreach ($cart as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $this->cartIdentityKey($row);
            if (! isset($paidKeys[$key])) {
                $remaining[] = $row;
            }
        }

        if ($remaining === []) {
            session()->forget('cart');
        } else {
            $this->putCatalogVisibleCart($remaining);
        }
    }

    private function allocateCheckoutReferenceCode(int $userId, ?string $requested): string
    {
        $candidate = is_string($requested) && trim($requested) !== ''
            ? trim($requested)
            : str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            if (! $this->checkoutReferenceUnavailable($userId, $candidate)) {
                return $candidate;
            }
            $candidate = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        }

        throw new \RuntimeException('Could not allocate a unique checkout reference.');
    }

    /**
     * 6-digit client REFs are reused from session after a successful pay.
     * Never reuse a reference that already settled (this advertiser, any
     * method) or that another advertiser used on a card charge — a second
     * capture would look idempotent, and a later checkout would release
     * the paid promo hold. Failed/pending rows stay reusable for Pay again.
     */
    private function checkoutReferenceUnavailable(int $userId, string $referenceCode): bool
    {
        $package = app(OrderPaymentService::class)->getPendingCheckout($referenceCode);
        $ownerId = (int) ($package['user_id'] ?? 0);
        if ($ownerId > 0 && $ownerId !== $userId) {
            return true;
        }

        return Order::query()
            ->where('reference_code', $referenceCode)
            ->where(function ($query) use ($userId) {
                $query->where(function ($foreignCard) use ($userId) {
                    $foreignCard->where('user_id', '!=', $userId)
                        ->where('payment_method', 'card');
                })->orWhere(function ($ownSettled) use ($userId) {
                    $ownSettled->where('user_id', $userId)
                        ->whereIn('payment_status', ['paid', 'refunded']);
                });
            })
            ->exists();
    }

    private function cancelUnpaidCardOrdersAndRestoreCart(string $referenceCode): void
    {
        $paymentService = app(OrderPaymentService::class);

        $canceled = Order::with('items')
            ->where('user_id', auth()->id())
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->whereIn('payment_status', ['pending', 'failed'])
            ->whereIn('status', ['pending', 'cancelled'])
            ->get();

        // Stripe-first (Add Funds style): no order rows yet — clear package + refund bonus.
        if ($canceled->isEmpty()) {
            $this->refundCheckoutBonus((int) auth()->id(), $referenceCode);
            $paymentService->forgetPendingCheckout($referenceCode, auth()->id());
            session()->forget(['pending_card_reference', 'checkout_deferred_cart']);

            Log::info('Cancelled Stripe-first card checkout (no order rows yet)', [
                'reference_code' => $referenceCode,
            ]);

            return;
        }

        // Legacy path: pending order rows existed before Stripe redirect.
        $stillPending = $canceled->where('payment_status', 'pending');
        if ($stillPending->isNotEmpty()) {
            $paymentService->markOrdersFailedFromReference(
                $referenceCode,
                'Checkout canceled by customer',
                auth()->id()
            );
            $canceled = Order::with('items')
                ->where('user_id', auth()->id())
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'failed')
                ->get();
        } else {
            $this->refundCheckoutBonus((int) auth()->id(), $referenceCode);
        }

        $paymentService->forgetPendingCheckout($referenceCode, auth()->id());

        $restoredCart = session('cart', []);
        $submissionId = session('checkout_content_submission_id');

        foreach ($canceled as $order) {
            $this->releaseContentSubmissionsForOrder($order);
            if ($order->status !== 'cancelled') {
                $order->update(['status' => 'cancelled']);
            }

            foreach ($order->items as $item) {
                if (! $item->site_id) {
                    continue;
                }
                $exists = collect($restoredCart)->contains(
                    fn ($row) => (int) ($row['id'] ?? 0) === (int) $item->site_id
                );
                if (! $exists) {
                    $restoredCart[] = [
                        'id' => $item->site_id,
                        'name' => $item->site_name,
                        'url' => $item->site_url,
                        'quantity' => 1,
                        'content_submission_id' => $item->content_submission_id,
                    ];
                }
                $submissionId = $submissionId ?: $item->content_submission_id;
            }
        }

        if ($restoredCart !== []) {
            $this->putCatalogVisibleCart($restoredCart);
        }
        if ($submissionId) {
            session()->put('checkout_content_submission_id', $submissionId);
        }
        session()->forget('pending_card_reference');

        Log::info('Cancelled unpaid card orders after Stripe cancel', [
            'reference_code' => $referenceCode,
            'order_count' => $canceled->count(),
        ]);
    }

    /**
     * @param  array<int, int|string>  $submissionIds
     */
    private function cancelConflictingUnpaidCardOrders(int $userId, array $submissionIds): void
    {
        $submissionIds = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
        if ($submissionIds === []) {
            return;
        }

        // Legacy Hostinger DBs may not have run the content-upload migration yet.
        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            Log::warning('Skipping conflicting card-order cleanup: order_items.content_submission_id missing');

            return;
        }

        $orderIds = OrderItem::query()
            ->whereIn('content_submission_id', $submissionIds)
            ->whereHas('order', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->where('payment_method', 'card')
                    ->where('payment_status', 'pending')
                    ->where('status', 'pending');
            })
            ->pluck('order_id')
            ->unique()
            ->all();

        if ($orderIds === []) {
            return;
        }

        $orders = Order::with('items')->whereIn('id', $orderIds)->get();
        $paymentService = app(OrderPaymentService::class);
        foreach ($orders->pluck('reference_code')->unique()->filter() as $referenceCode) {
            $paymentService->markOrdersFailedFromReference(
                (string) $referenceCode,
                'Replaced by a new checkout',
                $userId
            );
        }

        foreach ($orders as $order) {
            $this->releaseContentSubmissionsForOrder($order);
            $fresh = $order->fresh();
            if ($fresh && $fresh->status !== 'cancelled') {
                $fresh->update(['status' => 'cancelled']);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $cart
     * @return array<int, int>
     */
    private function collectSubmissionIdsFromRequest(array $cart, Request $request): array
    {
        $ids = [];
        foreach ($cart as $row) {
            if (! empty($row['content_submission_id'])) {
                $ids[] = (int) $row['content_submission_id'];
            }
            foreach ((array) ($row['content_submission_ids'] ?? []) as $sid) {
                $ids[] = (int) $sid;
            }
        }

        $map = $request->input('content_submissions');
        if (is_array($map)) {
            foreach ($map as $copies) {
                foreach ((array) $copies as $sid) {
                    $ids[] = (int) $sid;
                }
            }
        }

        if ($sessionId = session('checkout_content_submission_id')) {
            $ids[] = (int) $sessionId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function releaseContentSubmissionsForOrder(Order $order): void
    {
        ContentSubmission::releaseAllForOrder((int) $order->id);
    }
}
