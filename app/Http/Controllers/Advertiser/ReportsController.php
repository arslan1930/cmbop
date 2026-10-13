<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Advertiser\AdvertiserSpendService;
use App\Support\AdvertiserOrderDetails;
use App\Support\AdvertiserOrderStatus;
use App\Support\UserFacingError;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(private AdvertiserSpendService $spend) {}

    public function index()
    {
        try {
            return $this->renderReportsIndex();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('advertiser.dashboard')
                ->with('error', UserFacingError::message($e, 'We could not load reports. Please try again shortly.'));
        }
    }

    private function renderReportsIndex()
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
        } catch (\Throwable $e) {
            Log::error('Error fetching statistics: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load statistics'),
            ], 500);
        }
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $tab = search_text($request->input('tab')) === 'orders' ? 'orders' : 'funds';

        try {
            if ($tab === 'orders') {
                $rows = $this->orderReportQuery($request)->limit(2000)->get();
                $dates = $this->invoiceDatesForOrders($rows->pluck('id')->all(), (int) auth()->id());
                $filename = 'reports-orders-'.now()->format('Y-m-d').'.csv';

                return response()->streamDownload(function () use ($rows, $dates) {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['order_number', 'date', 'reference', 'total', 'payment_method', 'status', 'payment_status', 'site']);
                    foreach ($rows as $order) {
                        $first = $order->items->first();
                        fputcsv($out, [
                            $order->order_number,
                            $this->displayDate($dates[$order->id] ?? $order->created_at),
                            $order->reference_code,
                            number_format((float) $order->total_amount, 2, '.', ''),
                            $order->payment_method,
                            $order->status,
                            $order->payment_status,
                            $this->siteDomain($first?->site_url, $first?->site_name),
                        ]);
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
            }

            $rows = $this->fundsActivityQuery($request)->limit(2000)->get();
            $dates = $this->invoiceDatesForDeposits($rows->pluck('reference_code')->all(), (int) auth()->id());
            $filename = 'reports-funds-'.now()->format('Y-m-d').'.csv';

            return response()->streamDownload(function () use ($rows, $dates) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['date', 'reference', 'amount', 'payment_method', 'status']);
                foreach ($rows as $activity) {
                    fputcsv($out, [
                        $this->displayDate($dates[(string) $activity->reference_code] ?? $activity->created_at),
                        $activity->reference_code,
                        number_format((float) $activity->amount, 2, '.', ''),
                        $activity->payment_method,
                        $activity->status,
                    ]);
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.reports')
                ->with('error', UserFacingError::message($e, 'Unable to export reports. Please try again.'));
        }
    }

    public function getOrderReport(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = $this->orderReportQuery($request);

            $perPage = $request->get('per_page', 20);
            $orders = $query->paginate($perPage);

            $invoiceDates = $this->invoiceDatesForOrders(
                collect($orders->items())->pluck('id')->all(),
                (int) $userId
            );

            $transformedOrders = [];
            foreach ($orders as $order) {
                $meta = AdvertiserOrderStatus::meta($order);
                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'reference_code' => $order->reference_code,
                    'created_at' => $order->created_at,
                    'display_date' => $this->displayDate($invoiceDates[$order->id] ?? $order->created_at),
                    'status' => $order->status,
                    'status_label' => $meta['label'],
                    'status_cls' => $meta['cls'],
                    'placements_missing' => AdvertiserOrderDetails::placementsMissing($order),
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

        } catch (\Throwable $e) {
            Log::error('Error fetching order report: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch order report'),
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

            $dateFrom = search_text($request->input('date_from'));
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            $dateTo = search_text($request->input('date_to'));
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

        } catch (\Throwable $e) {
            Log::error('Error fetching sensitive analytics: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch analytics'),
            ], 500);
        }
    }

    public function getFundsActivity(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = $this->fundsActivityQuery($request);

            $perPage = $request->get('per_page', 20);
            $activities = $query->paginate($perPage);
            $invoiceDates = $this->invoiceDatesForDeposits(
                collect($activities->items())->pluck('reference_code')->all(),
                (int) $userId
            );

            $rows = [];
            foreach ($activities as $activity) {
                $row = $activity->toArray();
                $row['type'] = 'deposit';
                $row['display_date'] = $this->displayDate(
                    $invoiceDates[(string) $activity->reference_code] ?? $activity->created_at
                );
                $rows[] = $row;
            }

            return response()->json([
                'success' => true,
                'data' => $rows,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'last_page' => $activities->lastPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'from' => $activities->firstItem(),
                    'to' => $activities->lastItem(),
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Error fetching funds activity: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch funds activity'),
            ], 500);
        }
    }

    private function fundsActivityQuery(Request $request)
    {
        $query = DepositRequest::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc');

        $search = search_text($request->input('search'));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('reference_code', 'like', $like)
                    ->orWhere('payment_method', 'like', $like)
                    ->orWhere('status', 'like', $like);
            });
        }

        $dateFrom = search_text($request->input('date_from'));
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        $dateTo = search_text($request->input('date_to'));
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        $status = search_text($request->input('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query;
    }

    private function orderReportQuery(Request $request)
    {
        $query = Order::where('user_id', auth()->id())
            ->with('items')
            ->orderBy('created_at', 'desc');

        $search = search_text($request->input('search'));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('order_number', 'like', $like)
                    ->orWhere('reference_code', 'like', $like)
                    ->orWhereHas('items', function ($items) use ($like) {
                        $items->where('site_name', 'like', $like)
                            ->orWhere('site_url', 'like', $like);
                    });
            });
        }

        $dateFrom = search_text($request->input('date_from'));
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        $dateTo = search_text($request->input('date_to'));
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        $status = search_text($request->input('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $paymentStatus = search_text($request->input('payment_status'));
        if ($paymentStatus !== '') {
            $query->where('payment_status', $paymentStatus);
        }

        return $query;
    }

    /**
     * Same calendar date Billing shows: invoice_date in app timezone.
     */
    private function displayDate(mixed $date): string
    {
        if ($date instanceof Carbon) {
            return $date->timezone((string) config('app.timezone'))->format('M j, Y');
        }
        if ($date) {
            try {
                return Carbon::parse($date)->timezone((string) config('app.timezone'))->format('M j, Y');
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }

    /**
     * @param  list<int|string>  $orderIds
     * @return array<int, Carbon>
     */
    private function invoiceDatesForOrders(array $orderIds, int $userId): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            return [];
        }

        try {
            if (! Invoice::tableAvailable() || ! Invoice::orderLinkReady()) {
                return [];
            }

            return Invoice::query()
                ->where('user_id', $userId)
                ->whereIn('order_id', $orderIds)
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->get(['order_id', 'invoice_date'])
                ->unique('order_id')
                ->mapWithKeys(fn (Invoice $invoice) => [(int) $invoice->order_id => $invoice->invoice_date])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<mixed>  $refs
     * @return array<string, Carbon>
     */
    private function invoiceDatesForDeposits(array $refs, int $userId): array
    {
        $refs = array_values(array_filter(array_map(static fn ($ref) => trim((string) $ref), $refs)));
        if ($refs === []) {
            return [];
        }

        try {
            if (! Invoice::tableAvailable()) {
                return [];
            }

            return Invoice::query()
                ->where('user_id', $userId)
                ->whereIn('reference_code', $refs)
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->get(['reference_code', 'invoice_date'])
                ->unique('reference_code')
                ->mapWithKeys(fn (Invoice $invoice) => [(string) $invoice->reference_code => $invoice->invoice_date])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function siteDomain(?string $url, ?string $name): string
    {
        $raw = trim((string) $url);
        if ($raw !== '') {
            try {
                $host = parse_url(str_contains($raw, '://') ? $raw : 'https://'.$raw, PHP_URL_HOST);

                return $host ? preg_replace('/^www\./i', '', (string) $host) : $raw;
            } catch (\Throwable) {
                return $raw;
            }
        }

        $label = trim((string) $name);

        return ($label !== '' && strcasecmp($label, 'Placement details') !== 0) ? $label : '';
    }
}
