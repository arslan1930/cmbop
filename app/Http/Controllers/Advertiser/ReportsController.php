<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Advertiser\AdvertiserSpendService;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(private AdvertiserSpendService $spend) {}

    public function index()
    {
        $userId = auth()->id();

        $fundsActivity = DepositRequest::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        foreach ($fundsActivity as $activity) {
            $activity->type = 'deposit';
        }

        $orders = Order::where('user_id', $userId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $spendSummary = $this->spend->summary((int) $userId);

        $orderStats = [
            'total_orders' => $spendSummary['spent_orders'] + $spendSummary['in_progress_orders'],
            'total_base_amount' => 0,
            'total_sensitive_amount' => 0,
            'total_amount' => $spendSummary['net'],
            'orders_with_sensitive' => 0,
        ];

        $activeOrders = $this->spend->activePaidOrdersQuery((int) $userId)->with('items')->get();
        foreach ($activeOrders as $order) {
            foreach ($order->items as $item) {
                $additionalPrice = $item->additional_price ?? 0;
                $basePrice = $item->price - $additionalPrice;
                $orderStats['total_base_amount'] += $basePrice;
                $orderStats['total_sensitive_amount'] += $additionalPrice;
                if ($additionalPrice > 0) {
                    $orderStats['orders_with_sensitive']++;
                }
            }
        }

        $totalDeposits = DepositRequest::where('user_id', $userId)
            ->whereIn('status', $this->spend->settledDepositStatuses())
            ->sum('amount');

        $totalSpent = $spendSummary['net'];
        $totalOrders = $spendSummary['spent_orders'] + $spendSummary['in_progress_orders'];

        $sensitiveBreakdown = OrderItem::whereHas('order', function ($query) use ($userId) {
            $query->where('user_id', $userId)
                ->where('payment_status', 'paid')
                ->whereNotIn('status', ['cancelled', 'rejected', 'failed']);
        })
            ->whereNotNull('sensitive_type')
            ->where('additional_price', '>', 0)
            ->selectRaw('sensitive_type, SUM(additional_price) as total, COUNT(*) as count')
            ->groupBy('sensitive_type')
            ->get();

        $monthlySpending = collect($this->spend->candles((int) $userId, 'month')['series'])
            ->map(fn ($row) => (object) [
                'month' => $row['key'],
                'total' => $row['amount'],
                'spent' => $row['spent'],
                'in_progress' => $row['in_progress'],
            ])
            ->sortByDesc('month')
            ->take(12)
            ->values();

        return view('advertiser.reports', compact(
            'fundsActivity',
            'orders',
            'totalDeposits',
            'totalSpent',
            'totalOrders',
            'orderStats',
            'sensitiveBreakdown',
            'monthlySpending',
            'spendSummary'
        ));
    }

    public function getStatistics(Request $request)
    {
        try {
            $userId = auth()->id();
            $summary = $this->spend->summary((int) $userId);

            $totalDeposits = DepositRequest::where('user_id', $userId)
                ->whereIn('status', $this->spend->settledDepositStatuses())
                ->sum('amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_deposits' => $totalDeposits,
                    'total_spent' => $summary['net'],
                    'gross' => $summary['gross'],
                    'refunded' => $summary['refunded'],
                    'spent_completed' => $summary['spent'],
                    'in_progress' => $summary['in_progress'],
                    'total_orders' => $summary['spent_orders'] + $summary['in_progress_orders'],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching statistics: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to load statistics',
            ], 500);
        }
    }

    public function getOrderReport(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = Order::where('user_id', $userId)
                ->with('items')
                ->orderBy('created_at', 'desc');

            $dateFrom = scalar_text($request->date_from);
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            $dateTo = scalar_text($request->date_to);
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }
            $status = scalar_text($request->status);
            if ($status !== '') {
                $query->where('status', $status);
            }
            $paymentStatus = scalar_text($request->payment_status);
            if ($paymentStatus !== '') {
                $query->where('payment_status', $paymentStatus);
            }

            $perPage = $request->get('per_page', 20);
            $orders = $query->paginate($perPage);

            $transformedOrders = [];
            foreach ($orders as $order) {
                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'reference_code' => $order->reference_code,
                    'created_at' => $order->created_at,
                    'status' => $order->status,
                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status,
                    'total_amount' => $order->total_amount,
                    'items' => [],
                ];

                $totalBase = 0;
                $totalSensitive = 0;

                foreach ($order->items as $item) {
                    $additionalPrice = $item->additional_price ?? 0;
                    $basePrice = $item->price - $additionalPrice;

                    $totalBase += $basePrice;
                    $totalSensitive += $additionalPrice;

                    $orderData['items'][] = [
                        'site_name' => $item->site_name,
                        'site_url' => $item->site_url,
                        'price' => $item->price,
                        'base_price' => $basePrice,
                        'additional_price' => $additionalPrice,
                        'sensitive_type' => $item->sensitive_type,
                        'content_link' => $item->content_link,
                        'live_url' => $item->live_url,
                    ];
                }

                $orderData['base_total'] = $totalBase;
                $orderData['sensitive_total'] = $totalSensitive;

                $transformedOrders[] = $orderData;
            }

            return response()->json([
                'success' => true,
                'orders' => $transformedOrders,
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
            \Log::error('Error fetching order report: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order report',
            ], 500);
        }
    }

    public function getSensitiveAnalytics(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = OrderItem::whereHas('order', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->where('payment_status', 'paid')
                    ->whereNotIn('status', ['cancelled', 'rejected', 'failed']);
            })->whereNotNull('sensitive_type');

            $dateFrom = scalar_text($request->date_from);
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            $dateTo = scalar_text($request->date_to);
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $sensitiveItems = $query->with('order')->get();

            $analytics = [
                'total_sensitive_orders' => $sensitiveItems->count(),
                'total_sensitive_amount' => $sensitiveItems->sum('additional_price'),
                'by_type' => [],
            ];

            $byType = $sensitiveItems->groupBy('sensitive_type');
            foreach ($byType as $type => $items) {
                $analytics['by_type'][] = [
                    'type' => $type,
                    'count' => $items->count(),
                    'total_amount' => $items->sum('additional_price'),
                    'avg_amount' => $items->avg('additional_price'),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching sensitive analytics: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics',
            ], 500);
        }
    }

    public function getFundsActivity(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = DepositRequest::where('user_id', $userId)
                ->orderBy('created_at', 'desc');

            $dateFrom = scalar_text($request->date_from);
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            $dateTo = scalar_text($request->date_to);
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }
            $status = scalar_text($request->status);
            if ($status !== '') {
                $query->where('status', $status);
            }

            $perPage = $request->get('per_page', 20);
            $activities = $query->paginate($perPage);

            foreach ($activities as $activity) {
                $activity->type = 'deposit';
            }

            return response()->json([
                'success' => true,
                'data' => $activities->items(),
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'last_page' => $activities->lastPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'from' => $activities->firstItem(),
                    'to' => $activities->lastItem(),
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching funds activity: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch funds activity',
            ], 500);
        }
    }
}
