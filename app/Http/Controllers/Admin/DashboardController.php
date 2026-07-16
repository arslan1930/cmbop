<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * Admin / Marketing dashboard page
     */
    public function index()
    {
        if (auth()->user()->isMarketing()) {
            $pendingSites = Site::with('publisher:id,name,email')
                ->where(function ($q) {
                    $q->where('verified', 0)->orWhereNull('verified');
                })
                ->latest()
                ->take(10)
                ->get();

            $stats = [
                'unverified_sites' => Site::where(function ($q) {
                    $q->where('verified', 0)->orWhereNull('verified');
                })->count(),
                'verified_sites'   => Site::where('verified', 1)->count(),
                'active_sites'     => Site::where('active', 1)->count(),
                'inactive_sites'   => Site::where(function ($q) {
                    $q->where('active', 0)->orWhereNull('active');
                })->count(),
                'total_sites'      => Site::count(),
            ];

            return view('admin.marketing-dashboard', compact('pendingSites', 'stats'));
        }

        return view('admin.dashboard');
    }

    /**
     * Top-level KPI cards + action counts (AJAX)
     */
    public function getStatistics()
    {
        try {
            $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
            $publisherRoleId  = Role::where('name', 'publisher')->value('id');
            $adminRoleId      = Role::where('name', 'admin')->value('id');

            $data = [
                'total_users'       => User::count(),
                'advertisers'       => $advertiserRoleId
                    ? (int) DB::table('role_user')->where('role_id', $advertiserRoleId)->distinct()->count('user_id')
                    : 0,
                'publishers'        => $publisherRoleId
                    ? (int) DB::table('role_user')->where('role_id', $publisherRoleId)->distinct()->count('user_id')
                    : 0,
                'admins'            => $adminRoleId
                    ? (int) DB::table('role_user')->where('role_id', $adminRoleId)->distinct()->count('user_id')
                    : 0,
                'total_sites'       => Site::count(),
                'verified_sites'    => Site::where('verified', 1)->count(),
                'unverified_sites'  => Site::where(function ($q) {
                    $q->where('verified', 0)->orWhereNull('verified');
                })->count(),
                'total_orders'      => Order::count(),
                'paid_orders'       => Order::where('payment_status', 'paid')->count(),
                'revenue'           => (float) Order::where('payment_status', 'paid')->sum('total_amount'),
                'pending_deposits'  => DepositRequest::where('status', 'pending')->count(),
                'pending_withdrawals' => Withdrawal::where('status', 'pending')->count(),
                'new_users_7d'      => User::where('created_at', '>=', now()->subDays(7))->count(),
                'orders_7d'         => Order::where('created_at', '>=', now()->subDays(7))->count(),
                'revenue_7d'        => (float) Order::where('payment_status', 'paid')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->sum('total_amount'),
            ];

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard statistics error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load statistics'], 500);
        }
    }

    /**
     * Revenue + user signup series for the last N days (AJAX)
     */
    public function getTrends(Request $request)
    {
        try {
            $days = min(90, max(7, (int) $request->get('days', 30)));
            $start = now()->subDays($days - 1)->startOfDay();

            $labels = [];
            for ($i = 0; $i < $days; $i++) {
                $labels[] = $start->copy()->addDays($i)->format('Y-m-d');
            }

            $revenueRows = Order::where('payment_status', 'paid')
                ->where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, SUM(total_amount) as total')
                ->groupBy('day')
                ->pluck('total', 'day');

            $signupRows = User::where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->pluck('total', 'day');

            $orderRows = Order::where('created_at', '>=', $start)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupBy('day')
                ->pluck('total', 'day');

            $revenue = [];
            $signups = [];
            $orders  = [];
            foreach ($labels as $day) {
                $revenue[] = (float) ($revenueRows[$day] ?? 0);
                $signups[] = (int) ($signupRows[$day] ?? 0);
                $orders[]  = (int) ($orderRows[$day] ?? 0);
            }

            return response()->json([
                'success' => true,
                'labels'  => array_map(fn ($d) => Carbon::parse($d)->format('M j'), $labels),
                'revenue' => $revenue,
                'signups' => $signups,
                'orders'  => $orders,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard trends error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load trends'], 500);
        }
    }

    /**
     * Order status + role distribution pie data (AJAX)
     */
    public function getDistributions()
    {
        try {
            $orderStatus = Order::select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status');

            $roleCounts = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->select('roles.name', DB::raw('COUNT(DISTINCT role_user.user_id) as total'))
                ->groupBy('roles.name')
                ->pluck('total', 'name');

            return response()->json([
                'success' => true,
                'orders'  => [
                    'labels' => $orderStatus->keys()->map(fn ($s) => ucfirst($s))->values(),
                    'values' => $orderStatus->values()->map(fn ($v) => (int) $v)->values(),
                ],
                'roles'   => [
                    'labels' => $roleCounts->keys()->map(fn ($s) => ucfirst($s))->values(),
                    'values' => $roleCounts->values()->map(fn ($v) => (int) $v)->values(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard distributions error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load distributions'], 500);
        }
    }

    /**
     * Sidebar badge counts for pending ops queues (AJAX)
     */
    public function getQueueCounts()
    {
        try {
            $pendingDeposits = DepositRequest::where('status', 'pending')->count();
            $pendingWithdrawals = Withdrawal::where('status', 'pending')->count();
            $unverifiedSites = Site::where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })->count();
            $pendingPayments = Order::where(function ($q) {
                $q->whereNull('payment_status')
                    ->orWhereNotIn('payment_status', ['paid', 'refunded']);
            })->whereIn('status', ['pending', 'processing', 'review'])->count();

            return response()->json([
                'success' => true,
                'pending_deposits' => $pendingDeposits,
                'pending_withdrawals' => $pendingWithdrawals,
                'unverified_sites' => $unverifiedSites,
                'pending_payments' => $pendingPayments,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard queue counts error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load queue counts'], 500);
        }
    }

    /**
     * Items that need admin attention (AJAX)
     */
    public function getActionQueue()
    {
        try {
            $deposits = DepositRequest::with('user:id,name,email')
                ->where('status', 'pending')
                ->latest()
                ->take(5)
                ->get()
                ->map(fn ($d) => [
                    'id'     => $d->id,
                    'user'   => $d->user?->name ?? 'Unknown',
                    'email'  => $d->user?->email,
                    'amount' => (float) $d->amount,
                    'method' => $d->payment_method,
                    'date'   => optional($d->created_at)->format('d M Y H:i'),
                ]);

            $withdrawals = Withdrawal::with('user:id,name,email')
                ->where('status', 'pending')
                ->latest()
                ->take(5)
                ->get()
                ->map(fn ($w) => [
                    'id'     => $w->id,
                    'user'   => $w->user?->name ?? 'Unknown',
                    'email'  => $w->user?->email,
                    'amount' => (float) $w->amount,
                    'method' => $w->payment_method,
                    'date'   => optional($w->created_at)->format('d M Y H:i'),
                ]);

            $sites = Site::with('publisher:id,name,email')
                ->where(function ($q) {
                    $q->where('verified', 0)->orWhereNull('verified');
                })
                ->latest()
                ->take(5)
                ->get()
                ->map(fn ($s) => [
                    'id'        => $s->id,
                    'site_name' => $s->site_name,
                    'site_url'  => $s->site_url,
                    'publisher' => $s->publisher?->name ?? 'Unknown',
                    'date'      => optional($s->created_at)->format('d M Y'),
                ]);

            return response()->json([
                'success'     => true,
                'deposits'    => $deposits,
                'withdrawals' => $withdrawals,
                'sites'       => $sites,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard action queue error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load action queue'], 500);
        }
    }
}
