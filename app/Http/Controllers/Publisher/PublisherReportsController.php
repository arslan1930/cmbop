<?php
// app/Http/Controllers/Publisher/PublisherReportsController.php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublisherReportsController extends Controller
{
    /**
     * Display reports page
     */
    public function index()
    {
        return view('publisher.reports');
    }

    /**
     * Get statistics for publisher dashboard
     */
    public function getStatistics()
    {
        try {
            $userId = auth()->id();
            
            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            // If no sites found, return zero stats
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_earned' => 0,
                        'completed_orders' => 0,
                        'pending_orders' => 0,
                        'total_withdrawn' => 0
                    ]
                ]);
            }
            
            // Publisher earnings exclude the 15% platform markup fee
            $totalEarned = OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function($q) {
                    $q->where('payment_status', 'paid')
                      ->where('status', 'completed');
                })
                ->sum(OrderItem::publisherPayoutSqlExpression());
            
            // Count completed orders
            $completedOrders = OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function($q) {
                    $q->where('status', 'completed');
                })
                ->count();
            
            // Count pending/processing orders
            $pendingOrders = OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function($q) {
                    $q->whereIn('status', ['pending', 'processing']);
                })
                ->count();
            
            // Calculate total withdrawn amount
            $totalWithdrawn = Withdrawal::where('user_id', $userId)
                ->where('status', 'completed')
                ->sum('amount');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total_earned' => round($totalEarned, 2),
                    'completed_orders' => $completedOrders,
                    'pending_orders' => $pendingOrders,
                    'total_withdrawn' => round($totalWithdrawn, 2)
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching publisher statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get orders list for publisher reports
     */
    public function getOrders(Request $request)
    {
        try {
            $userId = auth()->id();
            
            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            // If no sites found, return empty data
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'total' => 0,
                        'from' => 0,
                        'to' => 0
                    ]
                ]);
            }
            
            // Get order items for these sites (exclude unpaid card checkouts)
            $query = OrderItem::with(['order'])
                ->whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where(function ($inner) {
                        $inner->where('payment_status', 'paid')
                            ->orWhere('payment_method', '!=', 'card');
                    });
                })
                ->orderBy('created_at', 'desc');
            
            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            // Status filter
            if ($request->filled('status')) {
                $query->whereHas('order', function($q) use ($request) {
                    $q->where('status', $request->status);
                });
            }
            
            $perPage = $request->get('per_page', 20);
            $orderItems = $query->paginate($perPage);

            // Expose publisher payout as price so UI matches credited earnings
            $data = collect($orderItems->items())->map(function (OrderItem $item) {
                $item->setAttribute('price', $item->publisherPayoutAmount());
                return $item;
            })->values();
            
            return response()->json([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'current_page' => $orderItems->currentPage(),
                    'last_page' => $orderItems->lastPage(),
                    'per_page' => $orderItems->perPage(),
                    'total' => $orderItems->total(),
                    'from' => $orderItems->firstItem(),
                    'to' => $orderItems->lastItem()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching publisher orders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get order details for a specific order item
     */
    public function getOrderDetails($orderItemId)
    {
        try {
            $userId = auth()->id();
            
            // Get order item with order and verify ownership
            $orderItem = OrderItem::with(['order', 'site'])
                ->whereHas('site', function($q) use ($userId) {
                    $q->where('publisher_id', $userId);
                })
                ->findOrFail($orderItemId);

            $orderItem->setAttribute('price', $orderItem->publisherPayoutAmount());
            
            return response()->json([
                'success' => true,
                'data' => $orderItem
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching order details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    /**
     * Get withdrawals list for publisher
     */
    public function getWithdrawals(Request $request)
    {
        try {
            $userId = auth()->id();
            
            $query = Withdrawal::where('user_id', $userId)
                ->orderBy('created_at', 'desc');
            
            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            // Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            
            $perPage = $request->get('per_page', 20);
            $withdrawals = $query->paginate($perPage);

            // Never expose platform fee fields to publishers
            $items = collect($withdrawals->items())->map(function ($w) {
                return [
                    'id' => $w->id,
                    'amount' => $w->amount,
                    'payment_method' => $w->payment_method,
                    'status' => $w->status,
                    'payment_reference' => $w->payment_details['reference'] ?? null,
                    'created_at' => $w->created_at,
                    'processed_at' => $w->processed_at,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'current_page' => $withdrawals->currentPage(),
                    'last_page' => $withdrawals->lastPage(),
                    'per_page' => $withdrawals->perPage(),
                    'total' => $withdrawals->total(),
                    'from' => $withdrawals->firstItem(),
                    'to' => $withdrawals->lastItem()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching withdrawals: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawals: ' . $e->getMessage()
            ], 500);
        }
    }
}