<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Mail\AdminManualPaymentNotification;
use App\Mail\ModificationRequested;
use App\Mail\OrderApprovedByAdvertiser;
use App\Mail\SiteOwnerOrderNotification;
use App\Models\ContentSubmission;
use App\Models\Country;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserBlacklist;
use App\Models\UserFavorite;
use App\Models\Wallet;
use App\Services\CartPricingService;
use App\Services\CheckoutSchemaService;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\InAppNotificationService;
use App\Services\LiveUrlHealthChecker;
use App\Services\Marketplace\LanguageCountryMap;
use App\Services\OrderPaymentService;
use App\Services\PlatformFeeService;
use App\Services\StripeCustomerService;
use App\Services\StripePaymentService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * Get price based on user role
     * - Publishers see original price
     * - Advertisers see base + hidden tiered portal fee
     * - Sensitive prices are NOT marked up
     */
    private function getPriceForUser($originalPrice, $sitePublisherId = null)
    {
        $user = auth()->user();

        // Check if user is a publisher and owns this site
        if ($user && $sitePublisherId && $user->id == $sitePublisherId) {
            // Publisher viewing their own site - show original price
            return $originalPrice;
        }

        // Check if user has publisher role (but not owner of this specific site)
        $role = Role::find($user->active_role_id ?? 0);
        if ($role && $role->name === 'publisher') {
            // Publisher viewing someone else's site - show original price
            return $originalPrice;
        }

        return app(PlatformFeeService::class)
            ->advertiserBase((float) $originalPrice);
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
     * Get all available categories with their groups
     */
    private function getAvailableCategories()
    {
        return [
            // Business & Finance
            ['name' => 'Business & Finance', 'group' => 'Business & Finance'],
            ['name' => 'Banking & Insurance', 'group' => 'Business & Finance'],
            ['name' => 'Crypto & Blockchain', 'group' => 'Business & Finance'],
            ['name' => 'Real Estate & Property', 'group' => 'Business & Finance'],
            ['name' => 'Construction & Architecture', 'group' => 'Business & Finance'],
            ['name' => 'Legal Services', 'group' => 'Business & Finance'],
            ['name' => 'Marketing, PR & Advertising', 'group' => 'Business & Finance'],
            ['name' => 'SaaS & B2B Software', 'group' => 'Business & Finance'],
            ['name' => 'Finance for SMEs', 'group' => 'Business & Finance'],

            // Technology
            ['name' => 'Technology & Gadgets', 'group' => 'Technology'],
            ['name' => 'Cybersecurity & Data Privacy', 'group' => 'Technology'],
            ['name' => 'Telecommunications & Internet Providers', 'group' => 'Technology'],
            ['name' => 'Smart Home & IoT', 'group' => 'Technology'],

            // E-commerce & Retail
            ['name' => 'E-commerce & Retail', 'group' => 'E-commerce & Retail'],
            ['name' => 'Logistics & Supply Chain', 'group' => 'E-commerce & Retail'],

            // Automotive
            ['name' => 'Automotive', 'group' => 'Automotive'],

            // Travel & Hospitality
            ['name' => 'Travel & Tourism', 'group' => 'Travel & Hospitality'],
            ['name' => 'Hospitality', 'group' => 'Travel & Hospitality'],
            ['name' => 'Food & Beverage', 'group' => 'Travel & Hospitality'],

            // Health & Wellness
            ['name' => 'Health & Wellness', 'group' => 'Health & Wellness'],
            ['name' => 'Medical & Clinics', 'group' => 'Health & Wellness'],
            ['name' => 'Pharma & Supplements', 'group' => 'Health & Wellness'],
            ['name' => 'Fitness & Sports', 'group' => 'Health & Wellness'],

            // Lifestyle
            ['name' => 'Beauty & Skincare', 'group' => 'Lifestyle'],
            ['name' => 'Fashion & Luxury', 'group' => 'Lifestyle'],
            ['name' => 'Home & Garden', 'group' => 'Lifestyle'],
            ['name' => 'Parenting & Family', 'group' => 'Lifestyle'],
            ['name' => 'Dating & Relationships', 'group' => 'Lifestyle'],
            ['name' => 'Pets & Veterinary', 'group' => 'Lifestyle'],

            // Energy & Environment
            ['name' => 'Energy', 'group' => 'Energy & Environment'],
            ['name' => 'Environment & Sustainability', 'group' => 'Energy & Environment'],

            // Industry
            ['name' => 'Manufacturing & Industry', 'group' => 'Industry'],
            ['name' => 'Agriculture & Agritech', 'group' => 'Industry'],
            ['name' => 'Maritime & Shipping', 'group' => 'Industry'],
            ['name' => 'Aviation & Airports', 'group' => 'Industry'],

            // Education & Careers
            ['name' => 'Education & E-learning', 'group' => 'Education & Careers'],
            ['name' => 'Jobs & Recruitment', 'group' => 'Education & Careers'],
            ['name' => 'HR & Payroll', 'group' => 'Education & Careers'],

            // Entertainment
            ['name' => 'Gaming & Esports', 'group' => 'Entertainment'],
            ['name' => 'Entertainment & Media', 'group' => 'Entertainment'],
            ['name' => 'News & Politics', 'group' => 'Entertainment'],

            // Events & Social
            ['name' => 'Events, Conferences & Trade Fairs', 'group' => 'Events & Social'],
            ['name' => 'NGOs, Charity & Social Impact', 'group' => 'Events & Social'],

            // Other
            ['name' => 'Outdoor & Adventure', 'group' => 'Other'],
            ['name' => 'Regional/Local', 'group' => 'Other'],
        ];
    }

    // Update your index method
    public function index(Request $request)
    {
        $userId = auth()->id();
        $currentUser = auth()->user();

        // Content Library → Catalog: keep the active article in session for cart assign.
        // Do not pre-filter language/country — advertisers pick filters manually.
        $orderingSubmission = $this->resolveActiveLibraryOrdering($request);
        if ($orderingSubmission) {
            $request->merge([
                'filters_open' => 1,
            ]);
        }

        // Get current user's role
        $userRole = null;
        if ($currentUser && $currentUser->active_role_id) {
            $userRole = Role::find($currentUser->active_role_id);
        }

        // Get favorites and blacklist from DATABASE
        $favorites = UserFavorite::where('user_id', $userId)->pluck('site_id')->toArray();
        $blacklist = UserBlacklist::where('user_id', $userId)->pluck('site_id')->toArray();

        $query = Site::where('active', 1);

        // Check if blacklist filter is active
        $showBlacklistedOnly = $request->filled('blacklist_filter') && $request->blacklist_filter == 1;

        if ($showBlacklistedOnly) {
            // Show ONLY blacklisted sites
            if (! empty($blacklist)) {
                $query->whereIn('id', $blacklist);
            } else {
                $query->whereRaw('1 = 0');
            }
        } else {
            // Normal view: Exclude blacklisted sites
            if (! empty($blacklist)) {
                $query->whereNotIn('id', $blacklist);
            }
        }

        // Deep-link from dashboard Recommended → exact site for buy
        if ($request->filled('site')) {
            $query->where('id', (int) $request->site);
        }

        // 🔍 Search by site name/URL, category, country name/code, or language name/code
        if ($request->filled('search')) {
            $search = trim($request->search);
            $matchedCountries = [];
            foreach ($this->getAvailableCountries() as $code => $name) {
                if (stripos($name, $search) !== false || strcasecmp((string) $code, $search) === 0) {
                    $matchedCountries[] = strtolower((string) $code);
                }
            }
            $matchedLanguages = [];
            foreach ($this->getAvailableLanguages() as $code => $name) {
                if (stripos($name, $search) !== false || strcasecmp((string) $code, $search) === 0) {
                    $matchedLanguages[] = strtolower((string) $code);
                }
            }

            $query->where(function ($q) use ($search, $matchedCountries, $matchedLanguages) {
                $q->where('site_url', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('site_name', 'like', "%{$search}%")
                    ->orWhere('categories', 'like', "%{$search}%");

                foreach ($matchedCountries as $code) {
                    $q->orWhere('country', $code)
                        ->orWhereJsonContains('countries', $code);
                }
                foreach ($matchedLanguages as $code) {
                    $q->orWhere('language', $code)
                        ->orWhereJsonContains('languages', $code);
                }
            });
        }

        // ✅ Verified filter
        if ($request->filled('verified') && $request->verified == 1) {
            $query->where('verified', 1);
        }

        // ⭐ Favorites filter
        if ($request->filled('favorites_filter') && $request->favorites_filter == 1) {
            if (! empty($favorites)) {
                $query->whereIn('id', $favorites);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 📊 DA range
        if ($request->filled('da_min')) {
            $query->where('da', '>=', (int) $request->da_min);
        }
        if ($request->filled('da_max')) {
            $query->where('da', '<=', (int) $request->da_max);
        }

        // 📊 DR range
        if ($request->filled('dr_min')) {
            $query->where('dr', '>=', (int) $request->dr_min);
        }
        if ($request->filled('dr_max')) {
            $query->where('dr', '<=', (int) $request->dr_max);
        }

        // 📊 Traffic range
        if ($request->filled('traffic_min')) {
            $query->where('traffic', '>=', (int) $request->traffic_min);
        }
        if ($request->filled('traffic_max')) {
            $query->where('traffic', '<=', (int) $request->traffic_max);
        }

        // 📂 Category filter - Search in category column (comma-separated string)
        if ($request->filled('category') && ! empty($request->category)) {
            $categories = explode(',', $request->category);
            $categories = array_map('trim', $categories);

            $query->where(function ($q) use ($categories) {
                foreach ($categories as $category) {
                    $category = trim($category);
                    // Only check the category column which is a comma-separated string
                    $q->orWhere('category', 'like', '%'.$category.'%');
                }
            });
        }

        // 🌍 Country filter - Support multiple countries (JSON + legacy column)
        if ($request->filled('country') && ! empty($request->country)) {
            $countries = array_values(array_filter(array_map(function ($c) {
                return strtolower(trim($c));
            }, explode(',', $request->country))));
            $query->where(function ($q) use ($countries) {
                foreach ($countries as $code) {
                    $q->orWhere('country', $code)
                        ->orWhereJsonContains('countries', $code);
                }
            });
        }

        // 🌍 Language filter - Support multiple languages (JSON + legacy column)
        if ($request->filled('language') && ! empty($request->language)) {
            $languages = array_values(array_filter(array_map(function ($l) {
                return strtolower(trim($l));
            }, explode(',', $request->language))));
            $query->where(function ($q) use ($languages) {
                foreach ($languages as $code) {
                    $q->orWhere('language', $code)
                        ->orWhereJsonContains('languages', $code);
                }
            });
        }

        // In your CatalogController index method
        if ($request->filled('category')) {
            $categories = explode(',', $request->category);
            $query->where(function ($q) use ($categories) {
                foreach ($categories as $category) {
                    $category = trim($category);
                    if ($category === '') {
                        continue;
                    }
                    $q->orWhere('category', 'like', '%'.$category.'%')
                        ->orWhereJsonContains('categories', $category);
                }
            });
        }

        // 💰 Price range filter
        if ($request->filled('price_min')) {
            $minPrice = $request->price_min;
            // For advertisers, filter on tiered advertiser-facing base price
            if ($userRole && $userRole->name === 'advertiser') {
                $advPriceSql = app(PlatformFeeService::class)
                    ->advertiserBaseSqlExpression('price');
                $query->whereRaw("({$advPriceSql}) >= ?", [$minPrice]);
            } else {
                $query->where('price', '>=', $minPrice);
            }
        }
        if ($request->filled('price_max')) {
            $maxPrice = $request->price_max;
            if ($userRole && $userRole->name === 'advertiser') {
                $advPriceSql = app(PlatformFeeService::class)
                    ->advertiserBaseSqlExpression('price');
                $query->whereRaw("({$advPriceSql}) <= ?", [$maxPrice]);
            } else {
                $query->where('price', '<=', $maxPrice);
            }
        }

        // 🔥 Sponsored filter
        if ($request->filled('sponsored') && $request->sponsored == 1) {
            $query->where('sponsored', 1);
        }

        // New badge filter created At last 30 days
        if ($request->filled('new_badge') && $request->new_badge == 1) {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        // Featured placements rise to the top (skip if promotions columns not migrated yet)
        if (Schema::hasColumn('sites', 'featured_until')) {
            $query->orderByRaw('(featured_until IS NOT NULL AND featured_until > ?) DESC', [now()]);
        }

        // Sort (default: highest DR first — what buyers typically scan for)
        $sort = $request->get('sort', 'dr_desc');
        match ($sort) {
            'da_desc' => $query->orderByDesc('da')->orderByDesc('id'),
            'traffic_desc' => $query->orderByDesc('traffic')->orderByDesc('id'),
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'newest' => $query->latest('created_at')->orderByDesc('id'),
            default => $query->orderByDesc('dr')->orderByDesc('id'),
        };

        // ✅ Pagination (20 per page) — skip per-row correlated subqueries on the list hot path
        $sites = $query->paginate(20)->withQueryString();

        // Transform sites to show appropriate price based on user role
        foreach ($sites as $site) {
            $site->original_price = $site->price;

            // Get price based on who is viewing (ONLY base price gets markup)
            $site->price = $this->getPriceForUser($site->price, $site->publisher_id);

            // Process sensitive prices - NO MARKUP applied to sensitive prices
            if ($site->sensitive_prices) {
                $sensitivePrices = is_string($site->sensitive_prices)
                    ? json_decode($site->sensitive_prices, true)
                    : $site->sensitive_prices;

                if (is_array($sensitivePrices)) {
                    $processedSensitive = [];
                    foreach ($sensitivePrices as $type => $additionalPrice) {
                        // Sensitive prices remain as is (no markup)
                        $processedSensitive[$type] = $additionalPrice;
                    }
                    $site->sensitive_prices = $processedSensitive;
                }
            }

            // Process categories for display
            if ($site->categories) {
                $site->categories_list = is_string($site->categories)
                    ? json_decode($site->categories, true)
                    : $site->categories;
            } else {
                $site->categories_list = [$site->category];
            }
        }

        // Get predefined countries for filter dropdown
        $availableCountries = $this->getAvailableCountries();

        // Get predefined languages for filter dropdown
        $availableLanguages = $this->getAvailableLanguages();

        // Get categories from the predefined array (grouped)
        $predefinedCategories = $this->getAvailableCategories();

        // Get unique category names for filter (from predefined array)
        $siteCategories = collect($predefinedCategories)->pluck('name')->unique()->sort()->values()->toArray();

        // Get cart from SESSION
        $cart = session()->get('cart', []);

        // Pass the filter state to the view
        $showBlacklistedOnly = $showBlacklistedOnly;

        // Bulk discount marketplace section (joined publishers)
        $bulkDeals = collect();
        if (Schema::hasColumn('sites', 'bulk_discount_enabled')) {
            $bulkDeals = Site::query()
                ->where('active', 1)
                ->where('bulk_discount_enabled', 1)
                ->whereNotNull('bulk_discount_percent')
                ->when(! empty($blacklist) && ! $showBlacklistedOnly, fn ($q) => $q->whereNotIn('id', $blacklist))
                ->orderByDesc('bulk_discount_percent')
                ->orderByDesc('dr')
                ->limit(12)
                ->get();

            foreach ($bulkDeals as $dealSite) {
                $dealSite->original_price = $dealSite->price;
                $dealSite->price = $this->getPriceForUser($dealSite->price, $dealSite->publisher_id);
            }
        }

        $featurePrice = (float) config('site_promotions.feature.price', 10);
        $featureDays = (int) config('site_promotions.feature.days', 7);

        $orderableArticles = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereNull('archived_at')
            ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (ContentSubmission $s) => $s->canBeOrdered())
            ->values();

        $approvedArticleCount = $orderableArticles->count();

        $siteReadiness = [];
        foreach ($sites as $site) {
            $match = $orderableArticles->first(fn (ContentSubmission $article) => $article->matchesSite($site));
            $langCodes = method_exists($site, 'languageCodes') ? $site->languageCodes() : [];
            $neededCode = strtolower((string) ($site->language ?: ($langCodes[0] ?? 'en')));
            if ($neededCode === '') {
                $neededCode = 'en';
            }
            $siteReadiness[$site->id] = [
                'ready' => (bool) $match,
                'code' => $neededCode,
                'label' => $match
                    ? 'Ready · article available'
                    : 'Needs approved article',
            ];
        }

        $catalogWallet = auth()->user()->activeWallet();
        $catalogBonusBalance = $catalogWallet ? (float) $catalogWallet->lockedBonusBalance() : 0.0;
        $catalogCashBalance = $catalogWallet ? (float) $catalogWallet->withdrawableBalance() : 0.0;
        $catalogSpendableBalance = (float) ($catalogWallet?->balance ?? 0);

        return view('advertiser.catalog', compact(
            'sites',
            'availableLanguages',
            'availableCountries',
            'predefinedCategories',
            'siteCategories',
            'favorites',
            'blacklist',
            'cart',
            'showBlacklistedOnly',
            'bulkDeals',
            'featurePrice',
            'featureDays',
            'orderingSubmission',
            'approvedArticleCount',
            'siteReadiness',
            'catalogBonusBalance',
            'catalogCashBalance',
            'catalogSpendableBalance'
        ));
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

        $id = (int) $request->query('content_submission_id', 0);
        if ($id <= 0 && session('ordering_from_library')) {
            $id = (int) session('checkout_content_submission_id', 0);
        }

        if ($id <= 0) {
            return null;
        }

        $submission = ContentSubmission::query()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereNull('archived_at')
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
    private function cartPayloadForClient(): array
    {
        $cart = array_values(session()->get('cart', []));

        // Refresh site market metadata on lines when missing.
        $siteIds = collect($cart)->pluck('id')->filter()->unique()->values();
        $sites = $siteIds->isEmpty()
            ? collect()
            : Site::query()->whereIn('id', $siteIds)->get()->keyBy('id');

        foreach ($cart as $i => $line) {
            $site = $sites->get((int) ($line['id'] ?? 0));
            if (! $site) {
                continue;
            }
            $cart[$i]['name'] = $line['name'] ?? $site->site_name;
            $cart[$i]['url'] = $line['url'] ?? $site->site_url;
            $cart[$i]['language'] = $line['language'] ?? $site->language;
            $cart[$i]['country'] = $line['country'] ?? $site->country;
            $cart[$i]['link_type'] = $line['link_type'] ?? $site->link_type;
        }

        $approved = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereNull('archived_at')
            ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
            ->latest('id')
            ->limit(100)
            ->get()
            ->filter(fn (ContentSubmission $s) => $s->canBeOrdered())
            ->values();

        // Drop articles that are no longer orderable (used/archived). Language is not checked.
        $approvedById = $approved->keyBy('id');
        $cartChanged = false;
        foreach ($cart as $i => $line) {
            $submissionId = (int) ($line['content_submission_id'] ?? 0);
            if ($submissionId <= 0) {
                continue;
            }
            $submission = $approvedById->get($submissionId);
            if (! $submission) {
                $submission = ContentSubmission::query()
                    ->where('id', $submissionId)
                    ->where('user_id', auth()->id())
                    ->whereNull('order_id')
                    ->first();
            }
            if (! $submission || ! $submission->canBeOrdered()) {
                unset($cart[$i]['content_submission_id']);
                $cartChanged = true;
            }
        }

        session()->put('cart', array_values($cart));
        if ($cartChanged) {
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
        ];
    }

    /**
     * Helper method to determine if current user is viewing as advertiser
     */
    private function isAdvertiserView()
    {
        $user = auth()->user();

        // Check if user has advertiser role
        if ($user && $user->active_role_id) {
            $role = Role::find($user->active_role_id);
            if ($role && $role->name === 'advertiser') {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper method to determine if current user is publisher viewing their own site
     */
    private function isPublisherOwner($sitePublisherId)
    {
        $user = auth()->user();

        if (! $user || ! $sitePublisherId) {
            return false;
        }

        // Check if user has publisher role and is the owner
        if ($user->active_role_id) {
            $role = Role::find($user->active_role_id);
            if ($role && $role->name === 'publisher' && $user->id == $sitePublisherId) {
                return true;
            }
        }

        return false;
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

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
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

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
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
                $key = ((int) ($row['id'] ?? 0)).'|'.((string) ($row['sensitive_type'] ?? ''));
                $existingByKey[$key] = $row;
            }

            $merged = [];
            foreach ($incoming as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }
                $key = ((int) $row['id']).'|'.((string) ($row['sensitive_type'] ?? ''));
                $prev = $existingByKey[$key] ?? [];
                if (empty($row['content_submission_id']) && ! empty($prev['content_submission_id'])) {
                    $row['content_submission_id'] = $prev['content_submission_id'];
                }
                if (empty($row['language']) && ! empty($prev['language'])) {
                    $row['language'] = $prev['language'];
                }
                if (empty($row['country']) && ! empty($prev['country'])) {
                    $row['country'] = $prev['country'];
                }
                $merged[] = $row;
            }

            session()->put('cart', array_values($merged));

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error saving cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
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
     * Assign / clear an approved Content Library article on a cart line.
     */
    public function assignCartArticle(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'sensitive_type' => ['nullable', 'string', 'max:50'],
            'content_submission_id' => ['nullable', 'integer'],
        ]);

        $siteId = (int) $data['id'];
        $sensitiveType = $data['sensitive_type'] ?? null;
        $submissionId = isset($data['content_submission_id']) ? (int) $data['content_submission_id'] : 0;

        $cart = session()->get('cart', []);
        $lineKey = null;
        foreach ($cart as $key => $item) {
            if ((int) ($item['id'] ?? 0) === $siteId
                && (($item['sensitive_type'] ?? null) == ($sensitiveType ?: null))) {
                $lineKey = $key;
                break;
            }
        }

        if ($lineKey === null) {
            return response()->json(['success' => false, 'error' => 'That website is not in your cart.'], 404);
        }

        $site = Site::query()->where('id', $siteId)->where('active', 1)->first();
        if (! $site) {
            return response()->json(['success' => false, 'error' => 'Site not found or inactive.'], 404);
        }

        if ($submissionId <= 0) {
            unset($cart[$lineKey]['content_submission_id']);
            session()->put('cart', array_values($cart));

            return response()->json(array_merge(['success' => true, 'message' => 'Article cleared for this website.'], $this->cartPayloadForClient()));
        }

        $submission = ContentSubmission::query()
            ->where('id', $submissionId)
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->first();

        if (! $submission || ! $submission->canBeOrdered()) {
            return response()->json([
                'success' => false,
                'error' => 'Choose an approved Content Library article that is still available to order.',
            ], 422);
        }

        foreach ($cart as $key => $item) {
            if ((int) $key === (int) $lineKey) {
                continue;
            }
            if ((int) ($item['content_submission_id'] ?? 0) === $submissionId) {
                return response()->json([
                    'success' => false,
                    'error' => 'That article is already assigned to another website in your cart. Each article can only be used once.',
                ], 422);
            }
        }

        $cart[$lineKey]['content_submission_id'] = $submission->id;
        $cart[$lineKey]['language'] = $site->language;
        $cart[$lineKey]['country'] = $site->country;
        session()->put('cart', array_values($cart));

        return response()->json(array_merge([
            'success' => true,
            'message' => 'Article assigned to this website.',
        ], $this->cartPayloadForClient()));
    }

    /**
     * Add to cart (SESSION) — prices are always recalculated from the DB.
     * Multiple sites are allowed; each site needs its own Content Library article.
     */
    public function addToCart(Request $request)
    {
        try {
            $id = $request->id;
            $sensitiveType = $request->sensitive_type;

            $site = Site::where('id', $id)->where('active', 1)->first();
            if (! $site) {
                return response()->json([
                    'success' => false,
                    'error' => 'Site not found or inactive.',
                ], 404);
            }

            $cart = session()->get('cart', []);
            $attachArticleId = null;
            $librarySubmission = null;

            if (session('ordering_from_library') && session('checkout_content_submission_id')) {
                $librarySubmission = ContentSubmission::query()
                    ->where('id', (int) session('checkout_content_submission_id'))
                    ->where('user_id', auth()->id())
                    ->whereNull('order_id')
                    ->first();

                if (! $librarySubmission || ! $librarySubmission->canBeOrdered()) {
                    session()->forget(['checkout_content_submission_id', 'ordering_from_library']);
                    $librarySubmission = null;
                } else {
                    $alreadyAssigned = collect($cart)->contains(
                        fn ($line) => (int) ($line['content_submission_id'] ?? 0) === (int) $librarySubmission->id
                    );

                    if (! $alreadyAssigned) {
                        $attachArticleId = (int) $librarySubmission->id;
                    }
                }
            }

            $existingItem = null;
            $nextQty = 1;
            foreach ($cart as $key => $item) {
                if ($item['id'] == $id && ($item['sensitive_type'] ?? null) == ($sensitiveType ?: null)) {
                    $existingItem = $key;
                    $nextQty = max(1, (int) ($item['quantity'] ?? 1)) + 1;
                    break;
                }
            }

            // When attaching a library article, keep that line at qty 1 (one article = one placement).
            if ($attachArticleId && $existingItem !== null) {
                $nextQty = max(1, (int) ($cart[$existingItem]['quantity'] ?? 1));
            }

            $pricing = $this->cartPricing()->priceForAdvertiser($site, $sensitiveType, $nextQty);

            if ($existingItem !== null) {
                $cart[$existingItem]['quantity'] = $nextQty;
                $cart[$existingItem]['price'] = $pricing['total'];
                $cart[$existingItem]['base_price'] = $pricing['base'];
                $cart[$existingItem]['additional_price'] = $pricing['additional'];
                $cart[$existingItem]['sensitive_type'] = $pricing['sensitive_type'];
                $cart[$existingItem]['name'] = $site->site_name;
                $cart[$existingItem]['url'] = $site->site_url;
                $cart[$existingItem]['list_total'] = $pricing['list_total'];
                $cart[$existingItem]['discount_percent'] = $pricing['discount_percent'];
                $cart[$existingItem]['link_type'] = $site->link_type;
                $cart[$existingItem]['country'] = $site->country;
                $cart[$existingItem]['language'] = $site->language;
                if ($attachArticleId && empty($cart[$existingItem]['content_submission_id'])) {
                    $cart[$existingItem]['content_submission_id'] = $attachArticleId;
                }
            } else {
                $line = [
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'url' => $site->site_url,
                    'price' => $pricing['total'],
                    'base_price' => $pricing['base'],
                    'additional_price' => $pricing['additional'],
                    'sensitive_type' => $pricing['sensitive_type'],
                    'quantity' => 1,
                    'list_total' => $pricing['list_total'],
                    'discount_percent' => $pricing['discount_percent'],
                    'link_type' => $site->link_type,
                    'country' => $site->country,
                    'language' => $site->language,
                ];
                if ($attachArticleId) {
                    $line['content_submission_id'] = $attachArticleId;
                }
                $cart[] = $line;
            }

            session()->put('cart', array_values($cart));

            $cartCount = array_sum(array_column($cart, 'quantity'));
            $cartTotal = array_sum(array_map(function ($item) {
                return $item['price'] * $item['quantity'];
            }, $cart));

            $message = $attachArticleId
                ? 'Website added with your article. Add more sites anytime — each needs its own approved article.'
                : 'Website added to cart. Assign an approved article for each site before checkout.';

            return response()->json(array_merge([
                'success' => true,
                'cart_count' => $cartCount,
                'cart_total' => $cartTotal,
                'message' => $message,
            ], $this->cartPayloadForClient()));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Error adding to cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove from cart (SESSION)
     */
    public function removeFromCart(Request $request)
    {
        try {
            $id = $request->id;
            $sensitiveType = $request->sensitive_type;
            $cart = session()->get('cart', []);

            foreach ($cart as $key => $item) {
                if ($item['id'] == $id && ($item['sensitive_type'] ?? null) == $sensitiveType) {
                    unset($cart[$key]);
                    break;
                }
            }

            session()->put('cart', array_values($cart));

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error removing from cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update cart quantity (SESSION)
     */
    public function updateCartQuantity(Request $request)
    {
        try {
            $id = $request->id;
            $quantity = $request->quantity;
            $sensitiveType = $request->sensitive_type;
            $cart = session()->get('cart', []);

            foreach ($cart as $key => $item) {
                if ($item['id'] == $id && ($item['sensitive_type'] ?? null) == $sensitiveType) {
                    if ($quantity <= 0) {
                        unset($cart[$key]);
                    } else {
                        $cart[$key]['quantity'] = $quantity;
                    }
                    break;
                }
            }

            session()->put('cart', array_values($cart));

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error updating cart: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
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

        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return redirect()->route('advertiser.catalog')->with('error', 'Your cart is empty.');
        }

        $partition = $this->partitionCartByCheckoutReadiness($cart);
        $payableCart = $partition['payable'];
        $deferredCart = $partition['deferred'];

        try {
            $allCheckout = $this->cartPricing()->buildCheckoutItems($cart);
            $payableCheckout = $payableCart !== []
                ? $this->cartPricing()->buildCheckoutItems($payableCart)
                : ['items' => [], 'total' => 0.0, 'savings' => 0.0];
            $deferredCheckout = $deferredCart !== []
                ? $this->cartPricing()->buildCheckoutItems($deferredCart)
                : ['items' => [], 'total' => 0.0, 'savings' => 0.0];
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('advertiser.catalog')->with('error', $e->getMessage());
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
            $key = (int) ($row['id'] ?? 0).'|'.($row['sensitive_type'] ?? '');

            return [$key => true];
        })->all();
        $cartItems = collect($cartItems)->map(function (array $item) use ($payableSiteKeys) {
            $key = (int) ($item['id'] ?? 0).'|'.($item['sensitive_type'] ?? '');
            $item['paying_now'] = isset($payableSiteKeys[$key]);

            return $item;
        })->all();

        if (empty($cartItems)) {
            session()->forget(['cart', 'checkout_content_submission_id', 'checkout_schedule', GuestPostWizardController::SESSION_KEY]);

            return redirect()->route('advertiser.catalog')->with('error', 'Your cart is empty or contains inactive sites.');
        }

        $librarySubmission = $this->resolveLibrarySubmissionForCheckout($cart);
        $checkoutSchedule = session('checkout_schedule', []);

        $checkoutWallet = auth()->user()->activeWallet();
        if ($checkoutWallet) {
            $checkoutWallet->repairOrphanedWelcomeBonus();
            $checkoutWallet->refresh();
        }
        $checkoutBonusBalance = $checkoutWallet ? $checkoutWallet->lockedBonusBalance() : 0.0;
        $checkoutCashBalance = $checkoutWallet ? $checkoutWallet->withdrawableBalance() : 0.0;
        $checkoutSpendableBalance = (float) ($checkoutWallet?->balance ?? 0);

        $approvedArticles = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
            ->latest('id')
            ->get();

        $correctionArticles = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereIn('moderation_status', [
                ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                ContentSubmission::STATUS_REJECTED,
                ContentSubmission::STATUS_ERROR,
            ])
            ->latest('id')
            ->limit(50)
            ->get();

        $marketplaceCountries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $marketplaceLanguages = Language::marketplace()->orderBy('name')->get(['code', 'name']);
        $languageCountryMap = app(LanguageCountryMap::class)->map();

        $articleIds = collect($cartItems)
            ->pluck('content_submission_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        if ($librarySubmission) {
            $articleIds = $articleIds->push((int) $librarySubmission->id)->unique()->values();
        }
        $checkoutArticles = ContentSubmission::query()
            ->with(['orderItems.site', 'orderItems.order'])
            ->where('user_id', auth()->id())
            ->whereIn('id', $articleIds->all() ?: [0])
            ->get()
            ->keyBy('id');

        $savedCards = app(StripeCustomerService::class)->listCards(auth()->user());
        $stripeConfigured = app(StripeCustomerService::class)->configured();
        // Best-effort: auto-add Hostinger-missing Stripe columns before card checkout.
        app(StripeCustomerService::class)->ensureUserStripeColumns();

        return view('advertiser.checkout', compact(
            'cartItems',
            'deferredItems',
            'payableReady',
            'payableCount',
            'deferredCount',
            'total',
            'savings',
            'librarySubmission',
            'checkoutSchedule',
            'approvedArticles',
            'correctionArticles',
            'marketplaceCountries',
            'marketplaceLanguages',
            'languageCountryMap',
            'checkoutWallet',
            'checkoutBonusBalance',
            'checkoutCashBalance',
            'checkoutSpendableBalance',
            'checkoutArticles',
            'savedCards',
            'stripeConfigured',
        ));
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
            // Get cart from session
            $cart = session()->get('cart', []);

            if (empty($cart)) {
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

            // Keep not-ready sites in the cart after this payment.
            session()->put('checkout_deferred_cart', array_values($deferredCart));

            // Generate reference code
            $referenceCode = $userReferenceCode ?? str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
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
                return $this->processCardPayment($payableCart, $checkoutContent, $referenceCode, $userId, $useBonus);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid payment method',
            ]);

        } catch (\Exception $e) {
            Log::error('Order processing failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create Stripe Checkout first (same pattern as Add Funds), then materialize
     * orders only after payment succeeds.
     *
     * @param  array{lines: array<int, array{orderItem: array, submission: ContentSubmission}>, schedule: array}  $checkoutContent
     */
    private function processCardPayment($cart, array $checkoutContent, $referenceCode, $userId, bool $useBonus = false)
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

        try {
            if ($useBonus) {
                $advertiserRoleId = Wallet::advertiserRoleId();
                if ($advertiserRoleId) {
                    $wallet = Wallet::lockOrCreateForRole((int) $userId, (int) $advertiserRoleId);
                    $wallet->repairOrphanedWelcomeBonus();
                    $wallet->refresh();
                    $bonusApplied = $wallet->reserveBonusOnly(min($wallet->lockedBonusBalance(), $totalAmount));
                    $amountDue = round(max(0, $totalAmount - $bonusApplied), 2);
                }
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

        // Fully covered by bonus — create paid wallet orders without Stripe (like a free checkout).
        if ($amountDue <= 0 && $bonusApplied > 0) {
            try {
                app(CheckoutSchemaService::class)->ensureCheckoutTables();
                $schema = app(CheckoutSchemaService::class);
                $created = collect();
                DB::beginTransaction();
                foreach ($checkoutContent['lines'] as $line) {
                    $orderItem = $line['orderItem'];
                    $submission = $line['submission'];
                    $site = $orderItem['site'];
                    $orderNumber = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                    $order = Order::create($schema->filterExistingColumns('orders', array_merge([
                        'user_id' => $userId,
                        'order_number' => $orderNumber,
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
                $this->consumeCheckoutBonus((int) $userId, (string) $referenceCode, $bonusApplied);
                $this->restoreDeferredCartAfterPayment();
                $paymentService->notifyPublishersOfPaidOrders($created);

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
            $packageLines[] = [
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => $orderItem['price'],
                'sensitive_type' => $orderItem['sensitive_type'] ?? null,
                'additional_price' => $orderItem['additional_price'] ?? 0,
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

        $paymentService->storePendingCheckout($referenceCode, [
            'user_id' => (int) $userId,
            'reference_code' => (string) $referenceCode,
            'order_total' => $totalAmount,
            'amount_due' => $amountDue,
            'bonus_applied' => $bonusApplied,
            'schedule' => $schedule,
            'lines' => $packageLines,
        ]);

        if ($bonusApplied > 0) {
            $this->rememberCheckoutBonus((int) $userId, (string) $referenceCode, $bonusApplied);
        }

        $user = User::find($userId);

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
            $paymentService->forgetPendingCheckout((string) $referenceCode);

            Log::error('Stripe checkout error: '.$e->getMessage(), [
                'reference_code' => $referenceCode,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session: '.$e->getMessage(),
            ]);
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

            DB::beginTransaction();

            // Lock wallet row inside the transaction to prevent concurrent overspend
            $advertiserWallet = Wallet::lockOrCreateForRole((int) $userId, (int) $advertiserRoleId);
            $advertiserWallet->repairOrphanedWelcomeBonus();
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
                $orderNumber = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

                $order = Order::create($schema->filterExistingColumns('orders', array_merge([
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
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

            $msg = $isScheduled
                ? count($createdOrders).' order(s) placed and charged. Publisher notified — publication date scheduled. Order numbers: '.$orderNumbers
                : count($createdOrders).' order(s) placed successfully! Funds have been reserved from your wallet. Order numbers: '.$orderNumbers;

            return response()->json([
                'success' => true,
                'message' => $msg,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Wallet payment failed: '.$e->getMessage(), [
                'user_id' => $userId,
                'reference_code' => $referenceCode,
            ]);

            $message = $e->getMessage() === 'Insufficient balance to reserve'
                ? 'Insufficient wallet balance for this order.'
                : 'Unable to process wallet payment. Please try again.';

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

            DB::beginTransaction();

            if ($useBonus) {
                $advertiserRoleId = Wallet::advertiserRoleId();
                if ($advertiserRoleId) {
                    $wallet = Wallet::lockOrCreateForRole((int) $userId, (int) $advertiserRoleId);
                    $wallet->repairOrphanedWelcomeBonus();
                    $wallet->refresh();
                    $bonusApplied = $wallet->reserveBonusOnly(min($wallet->lockedBonusBalance(), $totalAmount));
                    $amountDue = round(max(0, $totalAmount - $bonusApplied), 2);
                }
            }

            $createdOrders = [];

            foreach ($checkoutContent['lines'] as $line) {
                $orderItem = $line['orderItem'];
                $submission = $line['submission'];
                $site = $orderItem['site'];
                $orderNumber = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

                $order = Order::create(array_merge([
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
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

            // Saved-card / PaymentIntent return (3DS) path
            if ($paymentIntentId && $paymentIntentId !== '{PAYMENT_INTENT_ID}') {
                try {
                    $intent = PaymentIntent::retrieve($paymentIntentId);
                } catch (\Exception $e) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Unable to verify card payment. Please contact support.');
                }

                if (($intent->metadata->reference_code ?? null) && $intent->metadata->reference_code !== $referenceCode) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment reference mismatch.');
                }

                if ((string) ($intent->metadata->user_id ?? '') !== (string) auth()->id()) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment does not belong to this account.');
                }

                if ($intent->status !== 'succeeded') {
                    return redirect()->route('advertiser.orders', ['payment_status' => 'failed'])
                        ->with('error', 'Card payment was not completed.');
                }

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

                $sessionRef = $stripeSession->metadata->reference_code ?? null;
                if ($sessionRef && $sessionRef !== $referenceCode) {
                    return redirect()->route('advertiser.checkout')
                        ->with('error', 'Payment reference mismatch.');
                }

                $newlyPaid = $paymentService->finalizeStripeFirstCheckout($referenceCode, $stripeSession);
            }

            $orders = Order::with('items')
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('user_id', auth()->id())
                ->get();

            if ($orders->isEmpty()) {
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

            $this->removePaidOrdersFromCart($orders);
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

            $orderNumbers = $orders->pluck('order_number')->implode(', ');
            $paidCount = $orders->count();
            $remaining = count(session('cart', []));
            $successMsg = $paidCount.' order(s) paid successfully! Order numbers: '.$orderNumbers;
            if ($remaining > 0) {
                $successMsg .= ' '.$remaining.' website(s) remain in your cart until they are ready for checkout.';
            }

            return redirect()->route('advertiser.orders')
                ->with('success', $successMsg);
        } catch (\Exception $e) {
            Log::error('Stripe success handling failed: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return redirect()->route('advertiser.checkout')
                ->with('error', 'Payment verification failed: '.$e->getMessage());
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
     * Send email to admin for manual payments only
     */
    private function sendAdminManualPaymentEmail($customer, $orders, $paymentMethod)
    {
        try {
            // Get admin users
            $admins = User::whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->get();

            $totalAmount = 0;
            foreach ($orders as $order) {
                $totalAmount += $order->total_amount;
            }

            if ($admins->count() > 0) {
                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(new AdminManualPaymentNotification($customer, $orders, $paymentMethod, $totalAmount));
                    Log::info('Admin manual payment notification sent', [
                        'admin_id' => $admin->id,
                        'admin_email' => $admin->email,
                        'payment_method' => $paymentMethod,
                    ]);
                }
            } else {
                // Fallback to configured admin email
                $adminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                Mail::to($adminEmail)->send(new AdminManualPaymentNotification($customer, $orders, $paymentMethod, $totalAmount));
                Log::info('Admin manual payment notification sent to fallback email', ['email' => $adminEmail]);
            }

            try {
                app(InAppNotificationService::class)
                    ->notifyAdminsManualPayment($customer, $orders, $paymentMethod);
            } catch (\Throwable $e) {
                Log::warning('Failed to send admin manual payment bell notification: '.$e->getMessage());
            }

        } catch (\Exception $e) {
            Log::error('Failed to send admin manual payment email: '.$e->getMessage());
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

            $order = Order::with('items')->findOrFail($id);

            if ($order->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($order->status !== 'review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot request modification for an order that is not under review',
                ], 400);
            }

            DB::beginTransaction();

            // Update order status back to 'processing'
            $order->update([
                'status' => 'processing',
            ]);

            // Mark order items as modification requested AND RESET TIMER; persist reason for publisher UI
            foreach ($order->items as $item) {
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

            // Persist a chat message so publishers see the revision request in the thread
            try {
                OrderChatMessage::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'sender_type' => 'advertiser',
                    'message' => "Revision requested: {$request->reason}\nPlease update the article and resubmit the live URL.",
                    'is_read' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to create revision chat message', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Send email to publisher
            $publisher = null;
            foreach ($order->items as $item) {
                $site = Site::find($item->site_id);
                if ($site && $site->publisher_id) {
                    $publisher = User::find($site->publisher_id);
                    if ($publisher) {
                        break;
                    }
                }
            }

            if ($publisher && $publisher->email) {
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
                'message' => 'Failed to request modification: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cart count for badge
     */
    public function getCartCount(Request $request)
    {
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
    public function recheckLiveUrl(int $id)
    {
        $order = Order::with('items')->where('user_id', auth()->id())->findOrFail($id);
        $item = $order->items->first();
        if (! $item || ! filled($item->live_url)) {
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
            && $order->items->isNotEmpty();
    }

    /**
     * Orders page
     */
    public function orders()
    {
        return view('advertiser.orders');
    }

    /**
     * Get orders list (AJAX)
     */
    public function getOrders(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = Order::where('user_id', $userId)->with('items');

            // Search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($sub) use ($search) {
                            $sub->where('site_name', 'like', "%{$search}%");
                        });
                });
            }

            // Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Payment status filter
            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            // Payment method filter
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $orders = $query->orderBy('created_at', 'desc')->paginate(20);

            $orderIds = collect($orders->items())->pluck('id');
            $unreadByOrder = OrderChatMessage::whereIn('order_id', $orderIds)
                ->where('sender_type', 'publisher')
                ->where('is_read', false)
                ->selectRaw('order_id, COUNT(*) as unread_count')
                ->groupBy('order_id')
                ->pluck('unread_count', 'order_id');

            $ordersPayload = collect($orders->items())->map(function ($order) use ($unreadByOrder) {
                $order->unread_chat = (int) ($unreadByOrder[$order->id] ?? 0);
                $order->can_retry_payment = $this->orderCanRetryPayment($order);
                $meta = AdvertiserOrderStatus::meta($order, $order->items->first());
                $order->status_label = $meta['label'];
                $order->next_action = $meta['next'];
                $order->auto_approve_hint = $meta['auto_approve_hint'];
                $item = $order->items->first();
                if ($item) {
                    $item->auto_approve_hours_remaining = (int) $item->getAutoApproveHoursRemaining();
                }

                return $order;
            });

            $needsAction = Order::where('user_id', $userId)
                ->where('status', 'review')
                ->whereHas('items', function ($q) {
                    $q->whereNotNull('live_url')->where('live_url', '!=', '');
                })
                ->count();

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
            Log::error('Error fetching orders: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
            ]);
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
                ->with('items')
                ->find($id);

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ]);
            }

            $order->can_retry_payment = $this->orderCanRetryPayment($order);
            $meta = AdvertiserOrderStatus::meta($order, $order->items->first());
            $order->status_label = $meta['label'];
            $order->next_action = $meta['next'];
            $order->auto_approve_hint = $meta['auto_approve_hint'];
            $item = $order->items->first();
            if ($item) {
                $item->auto_approve_hours_remaining = (int) $item->getAutoApproveHoursRemaining();
            }

            return response()->json([
                'success' => true,
                'order' => $order,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching order: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details',
            ]);
        }
    }

    /**
     * Approve order and transfer payment from reserved_balance to publisher's wallet
     */
    public function approveOrder(Request $request, $id)
    {
        try {
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

            // Check if order is in review status (has live URL)
            if ($order->status !== 'review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order must be under review to approve',
                ], 400);
            }

            DB::beginTransaction();

            // Lock order to prevent double-approve races
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

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
                    'message' => 'Order must be under review to approve',
                ], 400);
            }

            // Update order status to completed
            $order->update([
                'status' => 'completed',
            ]);

            $publisherRoleId = Wallet::publisherRoleId();
            $advertiserRoleId = Wallet::advertiserRoleId();

            $advertiserWallet = null;
            if ($order->payment_method === 'wallet' && $advertiserRoleId) {
                $advertiserWallet = Wallet::lockOrCreateForRole((int) $order->user_id, (int) $advertiserRoleId);
            }

            $transferPublishers = [];
            $totalTransferred = 0;
            $rateable = [];

            foreach ($order->items as $orderItem) {
                // Get the site to find the publisher
                $site = Site::find($orderItem->site_id);

                if ($site) {
                    Site::refreshCompletedOrdersCount((int) $site->id);
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
                        $platformFee = (float) $orderItem->platformFeeAmount();
                        $publisherWallet->credit($amount);

                        $totalTransferred += $amount;

                        $transferPublishers[] = [
                            'publisher_id' => $publisher->id,
                            'publisher_name' => $publisher->name,
                            'amount' => $amount,
                            'platform_fee' => $platformFee,
                        ];

                        Log::info('Payment transferred to publisher wallet for approval', [
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'publisher_id' => $publisher->id,
                            'advertiser_paid' => (float) $orderItem->price,
                            'publisher_payout' => $amount,
                            'platform_fee' => $platformFee,
                            'wallet_balance' => $publisherWallet->balance,
                        ]);

                        // Send email to publisher
                        try {
                            Mail::to($publisher->email)->send(new OrderApprovedByAdvertiser($order, $orderItem, $site));
                            Log::info('Order approval email sent to publisher', [
                                'order_id' => $order->id,
                                'publisher_email' => $publisher->email,
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send order approval email to publisher: '.$e->getMessage());
                        }
                    }
                }
            }

            // If payment method was wallet, consume reserved funds (bonus portion stays non-withdrawable / spent)
            if ($order->payment_method === 'wallet' && $advertiserWallet) {
                $totalOrderAmount = $order->total_amount;
                $advertiserWallet->consumeReserved($totalOrderAmount);

                Log::info('Reserved funds released from advertiser wallet', [
                    'user_id' => auth()->id(),
                    'order_id' => $order->id,
                    'order_total' => $totalOrderAmount,
                    'remaining_reserved_balance' => $advertiserWallet->reserved_balance,
                    'bonus_reserved' => $advertiserWallet->bonus_reserved,
                ]);
            }

            DB::commit();

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

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving order: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve order: '.$e->getMessage(),
            ], 500);
        }
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
            $expandedOrders = $this->cartPricing()->expandCart($cart);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
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

            // Prefer per-cart content_submission_id, then request map, then library session
            $cartLine = collect($cart)->first(function ($row) use ($site, $sensitiveType) {
                if ((int) ($row['id'] ?? 0) !== (int) $site->id) {
                    return false;
                }
                $rowSensitive = $row['sensitive_type'] ?? null;

                return $rowSensitive == $sensitiveType;
            });

            $submissionId = data_get($cartLine, "content_submission_ids.$copyIndex")
                ?? data_get($cartLine, 'content_submission_id')
                ?? data_get($contentSubmissions, $site->id.'.'.$copyIndex)
                ?? data_get($contentSubmissions, (string) $site->id.'.'.$copyIndex)
                ?? $librarySubmissionId
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

            $submission = ContentSubmission::query()
                ->where('id', $submissionId)
                ->where('user_id', auth()->id())
                ->whereNull('order_id')
                ->first();

            if (! $submission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Approved article not found. Upload and get approval from Content Library first.',
                ]);
            }

            if (! $submission->isApproved() || ! $submission->canBeOrdered()) {
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
            'publisher_price' => $orderItem['publisher_price'] ?? null,
            'platform_fee_percent' => $orderItem['platform_fee_percent'] ?? null,
            'platform_fee_amount' => $orderItem['platform_fee_amount'] ?? null,
        ];
    }

    private function attachSubmissionToOrder(ContentSubmission $submission, Order $order, OrderItem $item): void
    {
        // One approved library article can be placed on multiple sites in one checkout.
        // Keep the first order/item linkage; every OrderItem still stores content_submission_id.
        $payload = [
            'publication_mode' => $order->publication_mode,
            'scheduled_publish_at' => $order->scheduled_publish_at,
            'timezone' => $order->schedule_timezone ?: $submission->timezone,
        ];

        if (! $submission->order_id) {
            $payload['order_id'] = $order->id;
            $payload['order_item_id'] = $item->id;
        }

        $filtered = app(CheckoutSchemaService::class)
            ->filterExistingColumns($submission->getTable(), $payload);

        if ($filtered !== []) {
            $submission->update($filtered);
        }
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
            ->where('id', $librarySubmissionId)
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->first();
    }

    private function checkoutBonusCacheKey(int $userId, string $referenceCode): string
    {
        return 'checkout_bonus:'.$userId.':'.$referenceCode;
    }

    private function rememberCheckoutBonus(int $userId, string $referenceCode, float $amount): void
    {
        Cache::put($this->checkoutBonusCacheKey($userId, $referenceCode), round($amount, 2), now()->addHours(12));
    }

    private function consumeCheckoutBonus(int $userId, string $referenceCode, ?float $amount = null): void
    {
        $key = $this->checkoutBonusCacheKey($userId, $referenceCode);
        $bonus = $amount ?? (float) Cache::pull($key, 0);
        if ($bonus <= 0) {
            return;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return;
        }

        $wallet = Wallet::where('user_id', $userId)->where('role_id', $roleId)->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->consumeReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
        Cache::forget($key);
    }

    private function refundCheckoutBonus(int $userId, string $referenceCode): void
    {
        $key = $this->checkoutBonusCacheKey($userId, $referenceCode);
        $bonus = (float) Cache::pull($key, 0);
        if ($bonus <= 0) {
            return;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return;
        }

        $wallet = Wallet::where('user_id', $userId)->where('role_id', $roleId)->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->refundReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
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
                $deferred[] = $item;

                continue;
            }

            $site = Site::query()->where('id', $siteId)->where('active', 1)->first();
            if (! $site) {
                // Inactive / missing sites are not payable.
                $deferred[] = $item;

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
                    ->whereNull('order_id')
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
            // Only charge active listings that still need payment (price can be 0 after discounts).
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
            session()->put('cart', array_values($deferred));

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
            $sensitive = $order->sensitive_type ?? null;
            foreach ($order->items as $item) {
                if (! $item->site_id) {
                    continue;
                }
                $paidKeys[(int) $item->site_id.'|'.($sensitive ?? '')] = true;
            }
        }

        $deferred = session('checkout_deferred_cart');
        if (is_array($deferred) && $deferred !== []) {
            session()->put('cart', array_values($deferred));
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
            $key = (int) ($row['id'] ?? 0).'|'.($row['sensitive_type'] ?? '');
            if (! isset($paidKeys[$key])) {
                $remaining[] = $row;
            }
        }

        if ($remaining === []) {
            session()->forget('cart');
        } else {
            session()->put('cart', array_values($remaining));
        }
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
            $paymentService->forgetPendingCheckout($referenceCode);
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
                'Checkout canceled by customer'
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

        $paymentService->forgetPendingCheckout($referenceCode);

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
            session()->put('cart', $restoredCart);
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

        foreach (Order::with('items')->whereIn('id', $orderIds)->get() as $order) {
            $this->releaseContentSubmissionsForOrder($order);
            $order->update(['status' => 'cancelled']);
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
        ContentSubmission::query()
            ->where('order_id', $order->id)
            ->get()
            ->each(fn (ContentSubmission $submission) => $submission->releaseFromOrder());

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            return;
        }

        $linkedIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereNotNull('content_submission_id')
            ->pluck('content_submission_id')
            ->all();

        if ($linkedIds !== []) {
            ContentSubmission::query()
                ->whereIn('id', $linkedIds)
                ->whereNotNull('order_id')
                ->get()
                ->each(fn (ContentSubmission $submission) => $submission->releaseFromOrder());
        }
    }
}
