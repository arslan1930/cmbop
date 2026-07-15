<?php
// app/Http/Controllers/Publisher/DashboardController.php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\Wallet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * Display publisher dashboard
     */
    public function index()
    {
        return view('publisher.dashboard');
    }
    
    /**
     * Get dashboard statistics (AJAX)
     */
    public function getStatistics(Request $request)
    {
        try {
            $userId = auth()->id();
            
            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_orders' => 0,
                        'pending_orders' => 0,
                        'processing_orders' => 0,
                        'review_orders' => 0,
                        'completed_orders' => 0,
                        'cancelled_orders' => 0,
                        'total_earnings' => 0,
                        'pending_earnings' => 0,
                        'total_sites' => 0,
                        'success_rate' => 0,
                    ]
                ]);
            }
            
            // Exclude unpaid card checkouts from publisher-facing stats
            $orderIds = OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where(function ($inner) {
                        $inner->where('payment_status', 'paid')
                            ->orWhere('payment_method', '!=', 'card');
                    });
                })
                ->pluck('order_id')
                ->unique()
                ->toArray();

            $completedOrders = Order::whereIn('id', $orderIds)->where('status', 'completed')->count();
            $cancelledOrders = Order::whereIn('id', $orderIds)->where('status', 'cancelled')->count();
            $resolvedOrders = $completedOrders + $cancelledOrders;
            $successRate = $resolvedOrders > 0
                ? round(($completedOrders / $resolvedOrders) * 100, 1)
                : 0;
            
            // Calculate statistics
            $stats = [
                'total_orders' => count($orderIds),
                'pending_orders' => Order::whereIn('id', $orderIds)->where('status', 'pending')->count(),
                'processing_orders' => Order::whereIn('id', $orderIds)->where('status', 'processing')->count(),
                'review_orders' => Order::whereIn('id', $orderIds)->where('status', 'review')->count(),
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'total_sites' => count($siteIds),
                'total_earnings' => (float) OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function($q) {
                        $q->where('status', 'completed')
                          ->where('payment_status', 'paid');
                    })
                    ->sum('price'),
                'pending_earnings' => (float) OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function($q) {
                        $q->where('status', 'review')
                          ->where('payment_status', 'paid');
                    })
                    ->sum('price'),
                // Resolved-order success rate (completed / completed+cancelled). Not an on-time SLA metric.
                'success_rate' => $successRate,
            ];
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching dashboard statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load statistics'
            ], 500);
        }
    }
    
    /**
     * Get recent orders for dashboard (AJAX)
     */
    public function getRecentOrders(Request $request)
    {
        try {
            $userId = auth()->id();
            
            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'orders' => []
                ]);
            }
            
            // Get recent order items (last 5); hide unpaid card checkouts
            $recentOrderItems = OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where(function ($inner) {
                        $inner->where('payment_status', 'paid')
                            ->orWhere('payment_method', '!=', 'card');
                    });
                })
                ->with(['order', 'site'])
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();
            
            $orders = [];
            foreach ($recentOrderItems as $item) {
                $orders[] = [
                    'order_number' => $item->order->order_number,
                    'status' => $item->order->status,
                    'total_amount' => (float) $item->order->total_amount,
                    'created_at' => $item->created_at,
                    'items' => [
                        [
                            'site_name' => $item->site_name,
                            'site_url' => $item->site_url
                        ]
                    ]
                ];
            }
            
            return response()->json([
                'success' => true,
                'orders' => $orders
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching recent orders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent orders'
            ], 500);
        }
    }
    
    /**
     * Get weekly earnings for chart (AJAX)
     */
    public function getWeeklyEarnings(Request $request)
    {
        try {
            $userId = auth()->id();
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        'values' => [0, 0, 0, 0, 0, 0, 0]
                    ]
                ]);
            }
            
            $weeklyData = [];
            $labels = [];
            
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i);
                $labels[] = $date->format('D');
                
                $earnings = OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function($q) {
                        $q->where('status', 'completed')
                          ->where('payment_status', 'paid');
                    })
                    ->whereDate('created_at', $date->toDateString())
                    ->sum('price');
                
                $weeklyData[] = (float) $earnings;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => $labels,
                    'values' => $weeklyData
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching weekly earnings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'data' => [
                    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'values' => [0, 0, 0, 0, 0, 0, 0]
                ]
            ]);
        }
    }
    
    /**
     * Get order status distribution for chart (AJAX)
     */
    public function getOrderStatusDistribution(Request $request)
    {
        try {
            $userId = auth()->id();
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'labels' => ['Pending', 'Processing', 'Completed', 'Cancelled'],
                        'values' => [0, 0, 0, 0]
                    ]
                ]);
            }
            
            // Exclude unpaid card checkouts from publisher-facing charts
            $orderIds = OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where(function ($inner) {
                        $inner->where('payment_status', 'paid')
                            ->orWhere('payment_method', '!=', 'card');
                    });
                })
                ->pluck('order_id')
                ->unique();
            
            $statuses = [
                'pending' => Order::whereIn('id', $orderIds)->where('status', 'pending')->count(),
                'processing' => Order::whereIn('id', $orderIds)->where('status', 'processing')->count(),
                'completed' => Order::whereIn('id', $orderIds)->where('status', 'completed')->count(),
                'cancelled' => Order::whereIn('id', $orderIds)->where('status', 'cancelled')->count()
            ];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => ['Pending', 'Processing', 'Completed', 'Cancelled'],
                    'values' => array_values($statuses)
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching order status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'data' => [
                    'labels' => ['Pending', 'Processing', 'Completed', 'Cancelled'],
                    'values' => [0, 0, 0, 0]
                ]
            ]);
        }
    }
    
    /**
     * Get monthly earnings for chart (AJAX)
     */
    public function getMonthlyEarnings(Request $request)
    {
        try {
            $userId = auth()->id();
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                        'values' => [0, 0, 0, 0, 0, 0]
                    ]
                ]);
            }
            
            $monthlyData = [];
            $labels = [];
            
            for ($i = 5; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $labels[] = $date->format('M');
                
                $earnings = OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function($q) {
                        $q->where('status', 'completed')
                          ->where('payment_status', 'paid');
                    })
                    ->whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->sum('price');
                
                $monthlyData[] = (float) $earnings;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => $labels,
                    'values' => $monthlyData
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching monthly earnings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'data' => [
                    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    'values' => [0, 0, 0, 0, 0, 0]
                ]
            ]);
        }
    }
}