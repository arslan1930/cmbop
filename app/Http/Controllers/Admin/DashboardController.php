<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        $pendingDeposits = DepositRequest::with('user')
            ->where('status', 'pending')
            ->latest()
            ->limit(8)
            ->get();

        $pendingWithdrawals = Withdrawal::with('user')
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->limit(8)
            ->get();

        $unverifiedSites = Site::with('publisher')
            ->where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })
            ->latest()
            ->limit(8)
            ->get();

        $pendingPayments = Order::with('user')
            ->where('payment_status', 'pending')
            ->whereIn('payment_method', ['wise', 'crypto', 'bank'])
            ->latest()
            ->limit(8)
            ->get();

        $counts = [
            'pending_deposits' => DepositRequest::where('status', 'pending')->count(),
            'pending_withdrawals' => Withdrawal::whereIn('status', ['pending', 'processing'])->count(),
            'unverified_sites' => Site::where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })->count(),
            'pending_payments' => Order::where('payment_status', 'pending')
                ->whereIn('payment_method', ['wise', 'crypto', 'bank'])
                ->count(),
            'users' => User::count(),
            'orders_today' => Order::whereDate('created_at', $now->toDateString())->count(),
        ];

        $counts['total_attention'] = $counts['pending_deposits']
            + $counts['pending_withdrawals']
            + $counts['unverified_sites']
            + $counts['pending_payments'];

        $aging = [
            'deposits_over_24h' => DepositRequest::where('status', 'pending')
                ->where('created_at', '<=', $now->copy()->subDay())
                ->count(),
            'withdrawals_over_24h' => Withdrawal::whereIn('status', ['pending', 'processing'])
                ->where('created_at', '<=', $now->copy()->subDay())
                ->count(),
            'payments_over_24h' => Order::where('payment_status', 'pending')
                ->whereIn('payment_method', ['wise', 'crypto', 'bank'])
                ->where('created_at', '<=', $now->copy()->subDay())
                ->count(),
            'sites_over_48h' => Site::where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })->where('created_at', '<=', $now->copy()->subDays(2))->count(),
        ];

        return view('admin.dashboard', compact(
            'counts',
            'aging',
            'pendingDeposits',
            'pendingWithdrawals',
            'unverifiedSites',
            'pendingPayments'
        ));
    }

    /**
     * Lightweight counts for sidebar badges.
     */
    public function queueCounts()
    {
        return response()->json([
            'success' => true,
            'pending_deposits' => DepositRequest::where('status', 'pending')->count(),
            'pending_withdrawals' => Withdrawal::whereIn('status', ['pending', 'processing'])->count(),
            'unverified_sites' => Site::where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })->count(),
            'pending_payments' => Order::where('payment_status', 'pending')
                ->whereIn('payment_method', ['wise', 'crypto', 'bank'])
                ->count(),
        ]);
    }
}
