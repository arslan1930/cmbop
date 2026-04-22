<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UserFavorite;
use App\Models\UserBlacklist;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();
        
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

        // 🔍 Search (by URL or category)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('site_url', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%")
                  ->orWhere('site_name', 'like', "%{$search}%");
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

        // 🌍 Language filter
        if ($request->filled('language') && !empty($request->language)) {
            $query->where('language', $request->language);
        }

        // ✅ Pagination (20 per page)
        $sites = $query->latest()->paginate(20)->withQueryString();

        // Get unique languages for the filter dropdown
        $availableLanguages = Site::where('active', 1)
            ->whereNotNull('language')
            ->where('language', '!=', '')
            ->select('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language');
        
        // Get cart from SESSION
        $cart = session()->get('cart', []);

        // Pass the filter state to the view
        $showBlacklistedOnly = $showBlacklistedOnly;

        return view('advertiser.catalog', compact('sites', 'availableLanguages', 'favorites', 'blacklist', 'cart', 'showBlacklistedOnly'));
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
     * Add to cart (SESSION)
     */
    public function addToCart(Request $request)
    {
        try {
            $id = $request->id;
            $price = $request->price;
            $name = $request->name;
            
            $cart = session()->get('cart', []);
            
            $existingItem = null;
            foreach ($cart as $key => $item) {
                if ($item['id'] == $id) {
                    $existingItem = $key;
                    break;
                }
            }
            
            if ($existingItem !== null) {
                $cart[$existingItem]['quantity']++;
            } else {
                $cart[] = [
                    'id' => $id,
                    'name' => $name,
                    'price' => $price,
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
            $cart = session()->get('cart', []);
            
            foreach ($cart as $key => $item) {
                if ($item['id'] == $id) {
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
            $cart = session()->get('cart', []);
            
            foreach ($cart as $key => $item) {
                if ($item['id'] == $id) {
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
        session()->forget('cart');
        return response()->json(['success' => true]);
    }
    
    /**
     * Checkout page
     */
    public function checkout()
    {
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return redirect()->route('advertiser.catalog')->with('error', 'Your cart is empty.');
        }
        
        // Get full site details for items in cart
        $cartItems = [];
        foreach ($cart as $item) {
            $site = Site::where('id', $item['id'])->where('active', 1)->first();
            if ($site) {
                $cartItems[] = [
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'url' => $site->site_url,
                    'price' => $site->price,
                    'quantity' => $item['quantity'],
                    'total' => $site->price * $item['quantity']
                ];
            }
        }
        
        $total = array_sum(array_column($cartItems, 'total'));
        
        return view('advertiser.checkout', compact('cartItems', 'total'));
    }
    
    /**
 * Process order - Returns JSON for AJAX requests
 */
public function processOrder(Request $request)
{
    try {
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty.'
            ]);
        }
        
        $userId = auth()->id();
        $paymentMethod = $request->payment_method;
        $contentLinks = $request->content_links;
        
        // Expand cart items to individual orders (one per quantity)
        $expandedOrders = [];
        $orderIndex = 0;
        
        foreach ($cart as $item) {
            for ($i = 0; $i < $item['quantity']; $i++) {
                $expandedOrders[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'copy_number' => $i + 1
                ];
            }
        }
        
        // Process each order individually
        DB::beginTransaction();
        
        $createdOrders = [];
        $orderIndex = 0;
        
        foreach ($expandedOrders as $orderItem) {
            $site = Site::where('id', $orderItem['id'])->where('active', 1)->first();
            if (!$site) {
                throw new \Exception("Site not found: " . $orderItem['name']);
            }
            
            // Get the content link for this order
            if (!isset($contentLinks[$site->id]) || !isset($contentLinks[$site->id][$orderIndex])) {
                throw new \Exception("Content link is required for: " . $site->site_name);
            }
            
            $link = $contentLinks[$site->id][$orderIndex];
            
            // Validate Google Docs URL
            if (!preg_match('/^https?:\/\/(docs\.google\.com|drive\.google\.com)\/.*$/i', $link)) {
                throw new \Exception("Invalid Google Docs link for: " . $site->site_name);
            }
            
            // Generate 6-digit order number
            $orderNumber = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            
            // Create individual order for this copy
            $order = Order::create([
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'subtotal' => $site->price,
                'tax' => 0,
                'total_amount' => $site->price,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'pending',
                'status' => 'pending'
            ]);
            
            // Create order item
            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => $site->price,
                'content_link' => $link
            ]);
            
            $createdOrders[] = $order;
            $orderIndex++;
        }
        
        // Process payment based on method (deduct total once)
        if ($paymentMethod === 'wallet') {
            $total = array_sum(array_column($expandedOrders, 'price'));
            $wallet = auth()->user()->activeWallet();
            if (!$wallet || $wallet->balance < $total) {
                throw new \Exception('Insufficient wallet balance. Available: €' . number_format($wallet?->balance ?? 0, 2));
            }
            // Deduct from wallet
            $wallet->balance -= $total;
            $wallet->save();
        }
        
        DB::commit();
        
        // Clear the cart
        session()->forget('cart');
        
        $orderNumbers = implode(', ', array_column($createdOrders, 'order_number'));
        
        return response()->json([
            'success' => true,
            'message' => count($createdOrders) . ' order(s) placed successfully! Order numbers: ' . $orderNumbers
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Order processing failed: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
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
    public function getOrders()
    {
        try {
            $userId = auth()->id();
            
            $orders = Order::where('user_id', $userId)
                ->with('items')
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'orders' => $orders
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
}