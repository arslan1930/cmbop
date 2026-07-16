<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UserFavorite;
use App\Models\UserBlacklist;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Wallet;
use App\Models\User;
use App\Mail\OrderConfirmation;
use App\Mail\SiteOwnerOrderNotification;
use App\Mail\AdminManualPaymentNotification;
use App\Mail\ModificationRequested;
use App\Models\ContentSubmission;
use App\Services\StripePaymentService;
use App\Services\InAppNotificationService;
use App\Services\CartPricingService;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ScheduledOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class CatalogController extends Controller
{

private function cartPricing(): CartPricingService
{
    return app(CartPricingService::class);
}

/**
 * Get price based on user role
 * - Publishers see original price
 * - Advertisers see marked up price (+15% platform fee)
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
    $role = \App\Models\Role::find($user->active_role_id ?? 0);
    if ($role && $role->name === 'publisher') {
        // Publisher viewing someone else's site - show original price
        return $originalPrice;
    }
    
    // Advertisers see marked up price (+15% platform fee)
    return round($originalPrice * OrderItem::PLATFORM_MARKUP_RATE, 2);
}

/**
 * Marketplace countries (Europe + major North America).
 */
private function getAvailableCountries()
{
    return \App\Models\Country::marketplace()
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
    return \App\Models\Language::marketplace()
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
    
    // Get current user's role
    $userRole = null;
    if ($currentUser && $currentUser->active_role_id) {
        $userRole = \App\Models\Role::find($currentUser->active_role_id);
    }
    
    // Get favorites and blacklist from DATABASE
    $favorites = UserFavorite::where('user_id', $userId)->pluck('site_id')->toArray();
    $blacklist = UserBlacklist::where('user_id', $userId)->pluck('site_id')->toArray();
    
    $query = Site::where('active', 1);
    
    // Check if blacklist filter is active
    $showBlacklistedOnly = $request->filled('blacklist_filter') && $request->blacklist_filter == 1;
    
    if ($showBlacklistedOnly) {
        // Show ONLY blacklisted sites
        if (!empty($blacklist)) {
            $query->whereIn('id', $blacklist);
        } else {
            $query->whereRaw('1 = 0');
        }
    } else {
        // Normal view: Exclude blacklisted sites
        if (!empty($blacklist)) {
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
        if (!empty($favorites)) {
            $query->whereIn('id', $favorites);
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    // 📊 DA range
    if ($request->filled('da_min')) {
        $query->where('da', '>=', (int)$request->da_min);
    }
    if ($request->filled('da_max')) {
        $query->where('da', '<=', (int)$request->da_max);
    }

    // 📊 DR range
    if ($request->filled('dr_min')) {
        $query->where('dr', '>=', (int)$request->dr_min);
    }
    if ($request->filled('dr_max')) {
        $query->where('dr', '<=', (int)$request->dr_max);
    }

    // 📊 Traffic range
    if ($request->filled('traffic_min')) {
        $query->where('traffic', '>=', (int)$request->traffic_min);
    }
    if ($request->filled('traffic_max')) {
        $query->where('traffic', '<=', (int)$request->traffic_max);
    }

  // 📂 Category filter - Search in category column (comma-separated string)
if ($request->filled('category') && !empty($request->category)) {
    $categories = explode(',', $request->category);
    $categories = array_map('trim', $categories);
    
    $query->where(function($q) use ($categories) {
        foreach ($categories as $category) {
            $category = trim($category);
            // Only check the category column which is a comma-separated string
            $q->orWhere('category', 'like', '%' . $category . '%');
        }
    });
}

// 🌍 Country filter - Support multiple countries (JSON + legacy column)
if ($request->filled('country') && !empty($request->country)) {
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
if ($request->filled('language') && !empty($request->language)) {
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
            $q->orWhere('category', 'like', '%' . $category . '%')
              ->orWhereJsonContains('categories', $category);
        }
    });
}

    // 💰 Price range filter
    if ($request->filled('price_min')) {
        $minPrice = $request->price_min;
        // For advertisers, we need to filter based on marked up price
        if ($userRole && $userRole->name === 'advertiser') {
            $query->whereRaw('price * ' . CartPricingService::PLATFORM_MARKUP_RATE . ' >= ?', [$minPrice]);
        } else {
            $query->where('price', '>=', $minPrice);
        }
    }
    if ($request->filled('price_max')) {
        $maxPrice = $request->price_max;
        if ($userRole && $userRole->name === 'advertiser') {
            $query->whereRaw('price * ' . CartPricingService::PLATFORM_MARKUP_RATE . ' <= ?', [$maxPrice]);
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

    // Last completed / published placement per site (expand-panel impression copy)
    $query->addSelect([
        'last_completed_at' => \App\Models\OrderItem::query()
            ->selectRaw('MAX(COALESCE(order_items.live_url_submitted_at, order_items.updated_at))')
            ->whereColumn('order_items.site_id', 'sites.id')
            ->where(function ($q) {
                $q->whereNotNull('order_items.live_url')
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('orders')
                            ->whereColumn('orders.id', 'order_items.order_id')
                            ->where('orders.status', 'completed');
                    });
            }),
    ]);

    // ✅ Pagination (20 per page)
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

    return view('advertiser.catalog', compact(
        'sites', 
        'availableLanguages',
        'availableCountries',
        'predefinedCategories',
        'siteCategories',
        'favorites', 
        'blacklist', 
        'cart', 
        'showBlacklistedOnly'
    ));
}


/**
 * Helper method to determine if current user is viewing as advertiser
 */
private function isAdvertiserView()
{
    $user = auth()->user();
    
    // Check if user has advertiser role
    if ($user && $user->active_role_id) {
        $role = \App\Models\Role::find($user->active_role_id);
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
    
    if (!$user || !$sitePublisherId) {
        return false;
    }
    
    // Check if user has publisher role and is the owner
    if ($user->active_role_id) {
        $role = \App\Models\Role::find($user->active_role_id);
        if ($role && $role->name === 'publisher' && $user->id == $sitePublisherId) {
            return true;
        }
    }
    
    return false;
}


    
    /**
     * Save favorites to DATABASE
     */
    public function saveFavorites(Request $request)
    {
        try {
            $userId = auth()->id();
            $favorites = $request->favorites;
            
            UserFavorite::where('user_id', $userId)->delete();
            
            foreach ($favorites as $siteId) {
                UserFavorite::create([
                    'user_id' => $userId,
                    'site_id' => $siteId
                ]);
            }
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error saving favorites: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Save blacklist to DATABASE
     */
    public function saveBlacklist(Request $request)
    {
        try {
            $userId = auth()->id();
            $blacklist = $request->blacklist;
            
            UserBlacklist::where('user_id', $userId)->delete();
            
            foreach ($blacklist as $siteId) {
                UserBlacklist::create([
                    'user_id' => $userId,
                    'site_id' => $siteId
                ]);
            }
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error saving blacklist: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Save cart to SESSION
     */
    public function saveCart(Request $request)
    {
        try {
            session()->put('cart', $request->cart);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error saving cart: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Get cart from SESSION
     */
    public function getCart(Request $request)
    {
        $cart = session()->get('cart', []);
        return response()->json($cart);
    }
    
    /**
 * Add to cart (SESSION) — prices are always recalculated from the DB.
 */
public function addToCart(Request $request)
{
    try {
        $id = $request->id;
        $sensitiveType = $request->sensitive_type;

        $site = Site::where('id', $id)->where('active', 1)->first();
        if (!$site) {
            return response()->json([
                'success' => false,
                'error' => 'Site not found or inactive.'
            ], 404);
        }

        $pricing = $this->cartPricing()->priceForAdvertiser($site, $sensitiveType);
        
        $cart = session()->get('cart', []);
        
        $existingItem = null;
        foreach ($cart as $key => $item) {
            if ($item['id'] == $id && ($item['sensitive_type'] ?? null) == $pricing['sensitive_type']) {
                $existingItem = $key;
                break;
            }
        }
        
        if ($existingItem !== null) {
            $cart[$existingItem]['quantity']++;
            // Refresh stored price in case the listing changed since last add
            $cart[$existingItem]['price'] = $pricing['total'];
            $cart[$existingItem]['base_price'] = $pricing['base'];
            $cart[$existingItem]['additional_price'] = $pricing['additional'];
            $cart[$existingItem]['name'] = $site->site_name;
        } else {
            $cart[] = [
                'id' => $site->id,
                'name' => $site->site_name,
                'price' => $pricing['total'],
                'base_price' => $pricing['base'],
                'additional_price' => $pricing['additional'],
                'sensitive_type' => $pricing['sensitive_type'],
                'quantity' => 1
            ];
        }
        
        session()->put('cart', $cart);
        
        $cartCount = array_sum(array_column($cart, 'quantity'));
        $cartTotal = array_sum(array_map(function($item) {
            return $item['price'] * $item['quantity'];
        }, $cart));
        
        return response()->json([
            'success' => true,
            'cart_count' => $cartCount,
            'cart_total' => $cartTotal
        ]);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
    } catch (\Exception $e) {
        Log::error('Error adding to cart: ' . $e->getMessage());
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
            Log::error('Error removing from cart: ' . $e->getMessage());
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
            Log::error('Error updating cart: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Clear cart (SESSION)
     */
    public function clearCart(Request $request)
    {
        session()->forget(['cart', 'checkout_content_submission_id', 'checkout_schedule']);
        return response()->json(['success' => true]);
    }
    
   /**
 * Checkout page — display prices recalculated from the DB.
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

    try {
        $checkout = $this->cartPricing()->buildCheckoutItems($cart);
    } catch (\InvalidArgumentException $e) {
        return redirect()->route('advertiser.catalog')->with('error', $e->getMessage());
    }

    $cartItems = $checkout['items'];
    $total = $checkout['total'];

    if (empty($cartItems)) {
        session()->forget(['cart', 'checkout_content_submission_id', 'checkout_schedule']);
        return redirect()->route('advertiser.catalog')->with('error', 'Your cart is empty or contains inactive sites.');
    }

    $librarySubmission = $this->resolveLibrarySubmissionForCheckout($cart);
    $checkoutSchedule = session('checkout_schedule', []);

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

    $marketplaceCountries = \App\Models\Country::marketplace()->orderBy('name')->get(['code', 'name']);
    $marketplaceLanguages = \App\Models\Language::marketplace()->orderBy('name')->get(['code', 'name']);

    return view('advertiser.checkout', compact(
        'cartItems',
        'total',
        'librarySubmission',
        'checkoutSchedule',
        'approvedArticles',
        'correctionArticles',
        'marketplaceCountries',
        'marketplaceLanguages',
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
                    'message' => 'Your cart is empty.'
                ]);
            }
            
            $userId = auth()->id();
            $paymentMethod = $request->payment_method;
            $userReferenceCode = $request->reference_code;

            // If a previous Stripe attempt linked the article, unlock it before re-resolving content.
            $this->cancelConflictingUnpaidCardOrders(
                (int) $userId,
                $this->collectSubmissionIdsFromRequest($cart, $request)
            );

            // Resolve approved library articles + schedule (session fallback from Content Library)
            $sessionSchedule = session('checkout_schedule', []);
            $checkoutContent = $this->resolveCheckoutContent(
                $cart,
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

            // Generate reference code
            $referenceCode = $userReferenceCode ?? str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            
            // For manual payment methods (wise, crypto, bank) - create orders immediately
            if (in_array($paymentMethod, ['wise', 'crypto', 'bank'])) {
                return $this->createOrdersImmediately($cart, $paymentMethod, $checkoutContent, $referenceCode, $userId);
            }
            
            // For wallet payment - check balance and reserve funds
            if ($paymentMethod === 'wallet') {
                return $this->processWalletPayment($cart, $checkoutContent, $referenceCode, $userId);
            }
            
            // For card payments - create durable pending orders BEFORE Stripe checkout
            // so webhook/success can finalize payment without relying on browser session.
            if ($paymentMethod === 'card') {
                return $this->processCardPayment($cart, $checkoutContent, $referenceCode, $userId);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment method'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Order processing failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Create pending card orders in DB, then redirect to Stripe Checkout.
     * Payment finalization is handled by webhook (authoritative) or success URL (fallback).
     *
     * @param  array{lines: array<int, array{orderItem: array, submission: ContentSubmission}>, schedule: array}  $checkoutContent
     */
    private function processCardPayment($cart, array $checkoutContent, $referenceCode, $userId)
    {
        $expandedOrders = array_column($checkoutContent['lines'], 'orderItem');
        $createdOrders = collect();
        $totalAmount = round(array_sum(array_column($expandedOrders, 'price')), 2);
        $schedule = $checkoutContent['schedule'];

        DB::beginTransaction();
        try {
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
                    'payment_method' => 'card',
                    'payment_status' => 'pending',
                    'status' => $this->initialOrderStatus($schedule),
                    'sensitive_type' => $orderItem['sensitive_type'],
                    'additional_price' => $orderItem['additional_price'],
                ], $this->scheduleOrderFields($schedule)));

                $item = OrderItem::create($this->orderItemPayload($order->id, $site, $orderItem, $submission));
                $this->attachSubmissionToOrder($submission, $order, $item);

                $createdOrders->push($order);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed creating pending card orders', [
                'reference_code' => $referenceCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to start card payment. Please try again.',
            ]);
        }

        // Publishers are notified only after Stripe payment is confirmed
        // (see OrderPaymentService::notifyPublishersOfPaidOrders).

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $checkoutSession = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Order Package - ' . $createdOrders->count() . ' item(s)',
                            'description' => 'Order reference: ' . $referenceCode,
                        ],
                        'unit_amount' => StripePaymentService::toCents($totalAmount),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('advertiser.checkout.process') . '?session_id={CHECKOUT_SESSION_ID}&ref=' . urlencode($referenceCode),
                'cancel_url' => route('advertiser.checkout') . '?canceled=1&ref=' . urlencode($referenceCode),
                'metadata' => [
                    'type' => 'order_payment',
                    'reference_code' => $referenceCode,
                    'user_id' => (string) $userId,
                    'order_count' => (string) $createdOrders->count(),
                    'expected_amount' => (string) $totalAmount,
                ],
            ]);

            Order::where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->where('payment_method', 'card')
                ->where('payment_status', 'pending')
                ->update(['stripe_session_id' => $checkoutSession->id]);

            // Keep cart until payment succeeds so Stripe cancel can return to checkout.
            // Store a pending marker so a second card attempt can be detected if needed.
            session()->put('pending_card_reference', $referenceCode);

            Log::info('Pending card orders created; Stripe session ready', [
                'reference_code' => $referenceCode,
                'session_id' => $checkoutSession->id,
                'order_count' => $createdOrders->count(),
                'total_amount' => $totalAmount,
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => true,
                'requires_payment' => true,
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
                'reference_code' => $referenceCode,
            ]);
        } catch (\Exception $e) {
            // Stripe session failed — release content + remove unpaid pending orders
            foreach ($createdOrders as $order) {
                try {
                    $this->releaseContentSubmissionsForOrder($order);
                    $order->items()->delete();
                    $order->delete();
                } catch (\Throwable $cleanupError) {
                    Log::warning('Failed cleaning up pending card order after Stripe error', [
                        'order_id' => $order->id,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            Log::error('Stripe session creation failed; pending orders rolled back', [
                'reference_code' => $referenceCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to start Stripe checkout. Please try again or choose another payment method.',
            ]);
        }
    }

    /**
     * Process wallet payment - deduct from balance and move to reserved_balance
     *
     * @param  array{lines: array, schedule: array}  $checkoutContent
     */
    private function processWalletPayment($cart, array $checkoutContent, $referenceCode, $userId)
    {
        try {
            $expandedOrders = array_column($checkoutContent['lines'], 'orderItem');
            $totalAmount = round(array_sum(array_column($expandedOrders, 'price')), 2);
            $schedule = $checkoutContent['schedule'];

            $advertiserRoleId = Wallet::advertiserRoleId();
            if (!$advertiserRoleId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Advertiser role not configured.',
                ]);
            }

            DB::beginTransaction();

            // Lock wallet row inside the transaction to prevent concurrent overspend
            $advertiserWallet = Wallet::lockOrCreateForRole((int) $userId, (int) $advertiserRoleId);

            if (round((float) $advertiserWallet->balance, 2) < $totalAmount) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance. Available: €'
                        . number_format((float) $advertiserWallet->balance, 2)
                        . '. Required: €' . number_format($totalAmount, 2) . '.',
                ]);
            }

            // Deduct from balance and add to reserved_balance (welcome bonus is consumed first)
            $advertiserWallet->reserveForOrder($totalAmount);

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
                    'payment_method' => 'wallet',
                    'payment_status' => 'paid',
                    'status' => $this->initialOrderStatus($schedule),
                    'sensitive_type' => $orderItem['sensitive_type'],
                    'additional_price' => $orderItem['additional_price'],
                    'paid_at' => now(),
                ], $this->scheduleOrderFields($schedule)));

                $item = OrderItem::create($this->orderItemPayload($order->id, $site, $orderItem, $submission));
                $this->attachSubmissionToOrder($submission, $order, $item);

                $createdOrders[] = $order;
            }

            DB::commit();
            session()->forget(['cart', 'checkout_content_submission_id', 'checkout_schedule']);

            $isScheduled = ($schedule['mode'] ?? 'immediate') === 'scheduled';

            foreach ($createdOrders as $createdOrder) {
                app(InAppNotificationService::class)->notifyOrderCreated(
                    $createdOrder->fresh(['items'])
                );
            }

            // Always notify publishers (scheduled orders are charged in advance; publish on the date).
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
                ? count($createdOrders) . ' order(s) placed and charged. Publisher notified — publication date scheduled. Order numbers: ' . $orderNumbers
                : count($createdOrders) . ' order(s) placed successfully! Funds have been reserved from your wallet. Order numbers: ' . $orderNumbers;

            return response()->json([
                'success' => true,
                'message' => $msg,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Wallet payment failed: ' . $e->getMessage(), [
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
    private function createOrdersImmediately($cart, $paymentMethod, array $checkoutContent, $referenceCode, $userId)
    {
        try {
            $schedule = $checkoutContent['schedule'];

            DB::beginTransaction();

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
            session()->forget(['cart', 'checkout_content_submission_id', 'checkout_schedule']);

            $isScheduled = ($schedule['mode'] ?? 'immediate') === 'scheduled';

            foreach ($createdOrders as $createdOrder) {
                app(InAppNotificationService::class)->notifyOrderCreated(
                    $createdOrder instanceof Order ? $createdOrder->fresh(['items']) : Order::with('items')->find($createdOrder->id)
                );
            }

            $this->sendSiteOwnerEmails($createdOrders);

            // Send email to admin for manual payments (wise, crypto, bank)
            $customer = User::find($userId);
            $this->sendAdminManualPaymentEmail($customer, $createdOrders, $paymentMethod);

            $orderNumbers = implode(', ', array_map(fn (Order $o) => $o->order_number, $createdOrders));

            return response()->json([
                'success' => true,
                'message' => count($createdOrders) . ' order(s) placed successfully'
                    . ($isScheduled ? ' (scheduled publication — publisher notified to publish on the selected date)' : '')
                    . '! Order numbers: ' . $orderNumbers,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order creation failed: ' . $e->getMessage());
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
            $referenceCode = $request->query('ref');

            Log::info('Stripe success callback received', [
                'session_id' => $sessionId,
                'reference_code' => $referenceCode,
            ]);

            if (!$sessionId || $sessionId === '{CHECKOUT_SESSION_ID}') {
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Invalid payment session.');
            }

            if (!$referenceCode) {
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Invalid payment callback.');
            }

            Stripe::setApiKey(config('services.stripe.secret'));

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

            // Ensure the session belongs to this reference / user
            $sessionRef = $stripeSession->metadata->reference_code ?? null;
            if ($sessionRef && $sessionRef !== $referenceCode) {
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Payment reference mismatch.');
            }

            $orders = Order::with('items')
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('user_id', auth()->id())
                ->get();

            if ($orders->isEmpty()) {
                Log::error('No pending card orders found on success callback', [
                    'reference_code' => $referenceCode,
                    'session_id' => $sessionId,
                ]);
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Order not found. Please contact support with your payment reference.');
            }

            $paymentService = app(OrderPaymentService::class);
            $newlyPaid = $paymentService->markOrdersPaidFromStripeSession($referenceCode, $stripeSession);

            if ($newlyPaid->isNotEmpty()) {
                $paymentService->notifyPublishersOfPaidOrders($newlyPaid);
            }

            session()->forget([
                'pending_card_payment',
                'pending_cart',
                'pending_content_links',
                'pending_reference_code',
                'pending_user_id',
                'pending_card_reference',
                'cart',
                'checkout_content_submission_id',
                'checkout_schedule',
            ]);

            $orderNumbers = $orders->pluck('order_number')->implode(', ');
            $paidCount = $orders->count();

            return redirect()->route('advertiser.orders')
                ->with('success', $paidCount . ' order(s) paid successfully! Order numbers: ' . $orderNumbers);
        } catch (\Exception $e) {
            Log::error('Stripe success handling failed: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return redirect()->route('advertiser.checkout')
                ->with('error', 'Payment verification failed: ' . $e->getMessage());
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
                    if (!isset($siteOrders[$siteId])) {
                        $site = Site::find($siteId);
                        if ($site) {
                            $siteOrders[$siteId] = [
                                'site' => $site,
                                'orders' => []
                            ];
                            Log::info('Site found for email', [
                                'site_id' => $siteId,
                                'site_name' => $site->site_name,
                                'publisher_id' => $site->publisher_id
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
                
                if (!$publisherId) {
                    Log::warning('No publisher_id found for site', [
                        'site_id' => $site->id,
                        'site_name' => $site->site_name
                    ]);
                    continue;
                }
                
                // Get the publisher (site owner) using publisher_id
                $publisher = User::find($publisherId);
                
                if (!$publisher) {
                    Log::warning('Publisher not found', [
                        'publisher_id' => $publisherId,
                        'site_id' => $site->id
                    ]);
                    continue;
                }
                
                if (!$publisher->email) {
                    Log::warning('Publisher has no email', [
                        'publisher_id' => $publisherId,
                        'publisher_name' => $publisher->name
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
                        'order_count' => count($siteOrdersList)
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send email to publisher', [
                        'email' => $publisher->email,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send site owner emails: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
        }
    }
    
    /**
     * Send email to admin for manual payments only
     */
    private function sendAdminManualPaymentEmail($customer, $orders, $paymentMethod)
    {
        try {
            // Get admin users
            $admins = User::whereHas('roles', function($query) {
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
                        'payment_method' => $paymentMethod
                    ]);
                }
            } else {
                // Fallback to configured admin email
                $adminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                Mail::to($adminEmail)->send(new AdminManualPaymentNotification($customer, $orders, $paymentMethod, $totalAmount));
                Log::info('Admin manual payment notification sent to fallback email', ['email' => $adminEmail]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send admin manual payment email: ' . $e->getMessage());
        }
    }
    
    /**
 * Request modification from publisher (RESETS auto-approve timer)
 */
public function requestModification(Request $request, $id)
{
    try {
        $request->validate([
            'reason' => 'required|string|min:10'
        ]);
        
        $order = Order::with('items')->findOrFail($id);
        
        if ($order->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }
        
        if ($order->status !== 'review') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot request modification for an order that is not under review'
            ], 400);
        }
        
        DB::beginTransaction();
        
        // Update order status back to 'processing'
        $order->update([
            'status' => 'processing'
        ]);
        
        // Mark order items as modification requested AND RESET TIMER
        foreach ($order->items as $item) {
            $item->update([
                'modification_requested' => 'yes',
                'modification_requested_at' => now(),
                'live_url_submitted_at' => now(),  // ✅ RESET TIMER HERE!
                'auto_approve_triggered' => false
            ]);
        }
        
        DB::commit();
        
        // Send email to publisher
        $publisher = null;
        foreach ($order->items as $item) {
            $site = Site::find($item->site_id);
            if ($site && $site->publisher_id) {
                $publisher = User::find($site->publisher_id);
                if ($publisher) break;
            }
        }
        
        if ($publisher && $publisher->email) {
            try {
                Mail::to($publisher->email)->send(new ModificationRequested($order, $request->reason));
            } catch (\Exception $e) {
                Log::error('Failed to send email: ' . $e->getMessage());
            }
        }

        app(InAppNotificationService::class)->notifyModificationRequested($order, $request->reason);
        
        return response()->json([
            'success' => true,
            'message' => 'Modification requested successfully!'
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error requesting modification: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to request modification: ' . $e->getMessage()
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
        return response()->json(['count' => $count]);
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
                $query->where(function($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhereHas('items', function($sub) use ($search) {
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
            $unreadByOrder = \App\Models\OrderChatMessage::whereIn('order_id', $orderIds)
                ->where('sender_type', 'publisher')
                ->where('is_read', false)
                ->selectRaw('order_id, COUNT(*) as unread_count')
                ->groupBy('order_id')
                ->pluck('unread_count', 'order_id');

            $ordersPayload = collect($orders->items())->map(function ($order) use ($unreadByOrder) {
                $order->unread_chat = (int) ($unreadByOrder[$order->id] ?? 0);
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
                    'to' => $orders->lastItem()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching orders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders'
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
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ]);
            }
            
            return response()->json([
                'success' => true,
                'order' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details'
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
                'message' => 'Unauthorized: This order does not belong to you'
            ], 403);
        }
        
        // Check if order is already completed
        if ($order->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already approved and completed'
            ], 400);
        }
        
        // Check if order is in review status (has live URL)
        if ($order->status !== 'review') {
            return response()->json([
                'success' => false,
                'message' => 'Order must be under review to approve'
            ], 400);
        }
        
        DB::beginTransaction();

        // Lock order to prevent double-approve races
        $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

        if ($order->status === 'completed') {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Order is already approved and completed'
            ], 400);
        }

        if ($order->status !== 'review') {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Order must be under review to approve'
            ], 400);
        }
        
        // Update order status to completed
        $order->update([
            'status' => 'completed'
        ]);
        
        $publisherRoleId = Wallet::publisherRoleId();
        $advertiserRoleId = Wallet::advertiserRoleId();
        
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
                    
                    // Add the order amount to publisher's wallet balance
                    $amount = (float) $orderItem->price;
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
                        'wallet_balance' => $publisherWallet->balance
                    ]);
                    
                    // Send email to publisher
                    try {
                        Mail::to($publisher->email)->send(new \App\Mail\OrderApprovedByAdvertiser($order, $orderItem, $site));
                        Log::info('Order approval email sent to publisher', [
                            'order_id' => $order->id,
                            'publisher_email' => $publisher->email
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send order approval email to publisher: ' . $e->getMessage());
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
            $message .= '€' . number_format($totalTransferred, 2) . ' (publisher payout, excluding platform fee) has been transferred to the publisher\'s wallet.';
        } else {
            $message .= '€' . number_format($totalTransferred, 2) . ' publisher payout processed (platform fee retained).';
        }
        
        return response()->json([
            'success' => true,
            'message' => $message,
            'ask_rating' => true,
            'rateable' => $rateable,
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error approving order: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to approve order: ' . $e->getMessage()
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
            ?? data_get($contentSubmissions, $site->id . '.' . $copyIndex)
            ?? data_get($contentSubmissions, (string) $site->id . '.' . $copyIndex)
            ?? $librarySubmissionId
            ?? null;

        if (!$submissionId) {
            return response()->json([
                'success' => false,
                'message' => 'Select an approved article from your Content Library before placing this order.',
            ]);
        }

        if (!isset($seen[$submissionId])) {
            $submission = ContentSubmission::query()
                ->where('id', $submissionId)
                ->where('user_id', auth()->id())
                ->whereNull('order_id')
                ->first();

            if (!$submission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Approved article not found. Upload and get approval from Content Library first.',
                ]);
            }

            if (!$submission->isApproved() || !$submission->canBeOrdered()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved Content Library articles can be ordered. Edit and resubmit articles that need correction.',
                ], 422);
            }

            if (!$submission->isReadyForCheckout()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add anchor text and a valid HTTPS target URL, or confirm continuing without a link.',
                ], 422);
            }

            if (!$submission->matchesSite($site)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article country/language must match the website market ('
                        . strtoupper((string) ($site->country ?: 'any')) . ' / '
                        . strtoupper((string) ($site->language ?: 'any')) . ').',
                ], 422);
            }

            $seen[$submissionId] = $submission;
            $submissionModels[] = $submission;
        }

        $lines[] = ['orderItem' => $orderItem, 'submission' => $seen[$submissionId]];
    }

    $moderation = app(ContentModerationService::class)->assertSubmissionsApproved($submissionModels, auth()->user());
    if (!$moderation['ok']) {
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

    if (!$schedule['ok']) {
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

    if (!$submission->order_id) {
        $payload['order_id'] = $order->id;
        $payload['order_item_id'] = $item->id;
    }

    $submission->update($payload);
}

/**
 * @param  array<int, mixed>  $cart
 */
private function resolveLibrarySubmissionForCheckout(array $cart): ?ContentSubmission
{
    $librarySubmissionId = session('checkout_content_submission_id');

    if (!$librarySubmissionId) {
        foreach ($cart as $row) {
            if (!empty($row['content_submission_id'])) {
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

    if (!$librarySubmissionId) {
        return null;
    }

    return ContentSubmission::query()
        ->where('id', $librarySubmissionId)
        ->where('user_id', auth()->id())
        ->whereNull('order_id')
        ->first();
}

private function cancelUnpaidCardOrdersAndRestoreCart(string $referenceCode): void
{
    $canceled = Order::with('items')
        ->where('user_id', auth()->id())
        ->where('reference_code', $referenceCode)
        ->where('payment_method', 'card')
        ->where('payment_status', 'pending')
        ->whereIn('status', ['pending', 'cancelled'])
        ->get();

    if ($canceled->isEmpty()) {
        return;
    }

    $restoredCart = session('cart', []);
    $submissionId = session('checkout_content_submission_id');

    foreach ($canceled as $order) {
        $this->releaseContentSubmissionsForOrder($order);
        if ($order->status !== 'cancelled') {
            $order->update(['status' => 'cancelled']);
        }

        foreach ($order->items as $item) {
            if (!$item->site_id) {
                continue;
            }
            $exists = collect($restoredCart)->contains(
                fn ($row) => (int) ($row['id'] ?? 0) === (int) $item->site_id
            );
            if (!$exists) {
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
        if (!empty($row['content_submission_id'])) {
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