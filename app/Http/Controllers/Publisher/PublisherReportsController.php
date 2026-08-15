<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class PublisherReportsController extends Controller
{
    private const ORDER_STATUSES = ['pending', 'processing', 'review', 'scheduled', 'completed', 'cancelled'];

    private const WITHDRAWAL_STATUSES = ['pending', 'processing', 'completed', 'cancelled'];

    private const OPEN_ORDER_STATUSES = ['pending', 'processing', 'review', 'scheduled'];

    public function index()
    {
        return view('publisher.reports');
    }

    public function getStatistics()
    {
        try {
            $user = auth()->user();
            $userId = $user->id;
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();

            $totalEarned = 0.0;
            $completedOrders = 0;
            $pendingOrders = 0;

            if ($siteIds !== []) {
                $paidCompleted = function ($q) {
                    $q->where('payment_status', 'paid')->where('status', 'completed');
                };

                $totalEarned = (float) OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', $paidCompleted)
                    ->sum(OrderItem::publisherPayoutSqlExpression());

                $completedOrders = OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', $paidCompleted)
                    ->count();

                $pendingOrders = OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function ($q) {
                        $q->where('payment_status', 'paid')
                            ->whereIn('status', self::OPEN_ORDER_STATUSES);
                    })
                    ->count();
            }

            $completedWithdrawals = Withdrawal::where('user_id', $userId)
                ->where('status', 'completed');

            // Older rows may lack net_amount; fall back to gross amount.
            $totalWithdrawnNet = (float) (clone $completedWithdrawals)->sum(
                DB::raw('COALESCE(net_amount, amount)')
            );
            $totalWithdrawnGross = (float) (clone $completedWithdrawals)->sum('amount');
            $totalWithdrawalFees = round(max(0, $totalWithdrawnGross - $totalWithdrawnNet), 2);

            $availableToWithdraw = 0.0;
            $wallet = $this->publisherWallet($user);
            if ($wallet) {
                $availableToWithdraw = round($wallet->withdrawableBalance(), 2);
            }

            return $this->noStoreJson([
                'success' => true,
                'data' => [
                    'total_earned' => round($totalEarned, 2),
                    'completed_orders' => $completedOrders,
                    'pending_orders' => $pendingOrders,
                    'total_withdrawn' => round($totalWithdrawnNet, 2),
                    'total_withdrawn_gross' => round($totalWithdrawnGross, 2),
                    'total_withdrawal_fees' => $totalWithdrawalFees,
                    'available_to_withdraw' => $availableToWithdraw,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching publisher statistics: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch statistics. Please try again.'),
            ], 500);
        }
    }

    public function getOrders(Request $request)
    {
        try {
            $userId = auth()->id();
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();

            if ($siteIds === []) {
                return $this->noStoreJson([
                    'success' => true,
                    'data' => [],
                    'pagination' => $this->emptyPagination((int) $request->get('per_page', 20)),
                ]);
            }

            $query = OrderItem::with(['order'])
                ->whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    // Financial reports: paid only (exclude abandoned card checkouts).
                    $q->where('payment_status', 'paid');
                })
                ->orderBy('created_at', 'desc');

            $this->applyDateFilters($query, $request);

            $status = search_text($request->input('status'));
            if ($status === '') {
                $status = 'completed';
            }
            if ($status !== '' && $status !== 'all' && in_array($status, self::ORDER_STATUSES, true)) {
                $query->whereHas('order', function ($q) use ($status) {
                    if ($status === 'scheduled') {
                        $q->awaitingScheduledRelease();
                    } elseif ($status === 'pending') {
                        $q->where('status', 'pending')->notAwaitingScheduledRelease();
                    } else {
                        $q->where('status', $status);
                    }
                });
            }

            $perPage = max(1, min(100, (int) $request->get('per_page', 20)));
            $orderItems = $query->paginate($perPage);

            $data = collect($orderItems->items())->map(function (OrderItem $item) {
                $payout = $item->publisherPayoutAmount();
                $additional = (float) ($item->additional_price ?? 0);

                return [
                    'id' => $item->id,
                    'site_name' => $item->site_name,
                    'site_url' => $item->site_url,
                    'content_link' => $item->content_link,
                    'live_url' => $item->live_url,
                    'sensitive_type' => $item->sensitive_type,
                    'additional_price' => $additional,
                    'price' => $payout,
                    'publisher_base_price' => round($payout - $additional, 2),
                    'created_at' => $item->created_at,
                    'order' => $item->order ? [
                        'id' => $item->order->id,
                        'order_number' => $item->order->order_number,
                        'reference_code' => $item->order->reference_code,
                        'status' => $item->order->status,
                        'is_awaiting_scheduled_release' => $item->order->isAwaitingScheduledRelease(),
                        'payment_status' => $item->order->payment_status,
                        'payment_method' => $item->order->payment_method,
                        'created_at' => $item->order->created_at,
                    ] : null,
                ];
            })->values();

            return $this->noStoreJson([
                'success' => true,
                'data' => $data,
                'pagination' => $this->paginationMeta($orderItems),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching publisher orders: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch orders. Please try again.'),
            ], 500);
        }
    }

    public function getOrderDetails($orderItemId)
    {
        try {
            $userId = auth()->id();

            $orderItem = OrderItem::with(['order', 'site'])
                ->whereHas('site', function ($q) use ($userId) {
                    $q->where('publisher_id', $userId);
                })
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid');
                })
                ->findOrFail($orderItemId);

            $payout = $orderItem->publisherPayoutAmount();
            $additional = (float) ($orderItem->additional_price ?? 0);

            return $this->noStoreJson([
                'success' => true,
                'data' => [
                    'id' => $orderItem->id,
                    'site_name' => $orderItem->site_name,
                    'site_url' => $orderItem->site_url,
                    'content_link' => $orderItem->content_link,
                    'live_url' => $orderItem->live_url,
                    'sensitive_type' => $orderItem->sensitive_type,
                    'additional_price' => $additional,
                    'price' => $payout,
                    'publisher_base_price' => round($payout - $additional, 2),
                    'created_at' => $orderItem->created_at,
                    'order' => $orderItem->order ? [
                        'id' => $orderItem->order->id,
                        'order_number' => $orderItem->order->order_number,
                        'reference_code' => $orderItem->order->reference_code,
                        'status' => $orderItem->order->status,
                        'payment_status' => $orderItem->order->payment_status,
                        'payment_method' => $orderItem->order->payment_method,
                        'created_at' => $orderItem->order->created_at,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching order details: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }
    }

    public function getWithdrawals(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = Withdrawal::where('user_id', $userId)
                ->orderBy('created_at', 'desc');

            $this->applyDateFilters($query, $request);

            $status = search_text($request->input('status'));
            if ($status === '') {
                $status = 'completed';
            }
            if ($status !== '' && $status !== 'all' && in_array($status, self::WITHDRAWAL_STATUSES, true)) {
                $query->where('status', $status);
            }

            $perPage = max(1, min(100, (int) $request->get('per_page', 20)));
            $withdrawals = $query->paginate($perPage);

            $items = collect($withdrawals->items())->map(function (Withdrawal $w) {
                return [
                    'id' => $w->id,
                    'reference' => 'WD-'.$w->id,
                    'amount' => (float) $w->amount,
                    'fee' => (float) ($w->fee ?? 0),
                    'net_amount' => (float) ($w->net_amount ?? $w->amount),
                    'payment_method' => $w->payment_method,
                    'status' => $w->status,
                    'status_label' => $w->publisher_status_label,
                    'created_at' => $w->created_at,
                    'processed_at' => $w->processed_at,
                ];
            })->values();

            return $this->noStoreJson([
                'success' => true,
                'data' => $items,
                'pagination' => $this->paginationMeta($withdrawals),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching withdrawals: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch withdrawals. Please try again.'),
            ], 500);
        }
    }

    private function publisherWallet($user): ?Wallet
    {
        if (! $user) {
            return null;
        }

        if (method_exists($user, 'activeWallet')) {
            $wallet = $user->activeWallet();
            if ($wallet) {
                return $wallet;
            }
        }

        $query = Wallet::where('user_id', $user->id);
        if (Schema::hasColumn('wallets', 'role_id') && $user->active_role_id) {
            $query->where('role_id', $user->active_role_id);
        }

        return $query->first();
    }

    /**
     * Ignore invalid date filters so bad query strings do not 500 the page.
     *
     * @param  Builder<Model>  $query
     */
    private function applyDateFilters($query, Request $request): void
    {
        $validated = Validator::make($request->only(['date_from', 'date_to']), [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ])->valid();

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }
    }

    /**
     * @param  LengthAwarePaginator  $paginator
     * @return array<string, int|null>
     */
    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function emptyPagination(int $perPage): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
    }

    private function noStoreJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }
}
