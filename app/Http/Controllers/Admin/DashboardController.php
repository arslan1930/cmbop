<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Admin\DashboardMetricsService;
use App\Services\ContentModeration\ContentModerationService;
use App\Support\ProductionReadiness;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function __construct(private DashboardMetricsService $metrics) {}

    /**
     * Admin dashboard page (marketing uses Marketing\PanelController).
     */
    public function index()
    {
        $moderationOff = false;
        $opsAlerts = [];

        try {
            $moderationOff = ! app(ContentModerationService::class)->isEnabled();
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $opsAlerts = app(ProductionReadiness::class)->dashboardAlerts();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load production alerts. Please refresh and try again.')
            );
        }

        return view('admin.dashboard', compact('moderationOff', 'opsAlerts'));
    }

    /**
     * Top-level KPI cards + action counts (AJAX)
     */
    public function getStatistics()
    {
        try {
            $data = $this->remember('statistics', fn () => $this->metrics->statistics());
            // Queue fields are also the nav badges (live). Overlay so a cached
            // KPI payload cannot disagree with pending_deposits / needs_attention.
            if ($this->cacheTtl() > 0) {
                $data = array_merge($data, $this->metrics->queueCounts());
            }

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard statistics error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load statistics'),
            ], 500);
        }
    }

    /**
     * Revenue + user signup series for the last N days (AJAX)
     */
    public function getTrends(Request $request)
    {
        try {
            $days = min(90, max(7, (int) $request->get('days', 30)));

            return response()->json([
                'success' => true,
                ...$this->remember('trends.'.$days, fn () => $this->metrics->trends($days)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard trends error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load trends'),
            ], 500);
        }
    }

    /**
     * Order status + role distribution pie data (AJAX)
     */
    public function getDistributions()
    {
        try {
            return response()->json([
                'success' => true,
                ...$this->remember('distributions', fn () => $this->metrics->distributions()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard distributions error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load distributions'),
            ], 500);
        }
    }

    /**
     * Sidebar badge counts for pending ops queues (AJAX)
     */
    public function getQueueCounts()
    {
        try {
            // Nav badges poll this every 60s — do not put it behind the metrics cache.
            return response()->json([
                'success' => true,
                ...$this->metrics->queueCounts(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard queue counts error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load queue counts'),
            ], 500);
        }
    }

    /**
     * Liability + this-month margin (same source as the finance hub).
     */
    public function getFinanceStrip()
    {
        try {
            // Due to pay now sits next to the live withdrawal queue — do not cache it.
            return response()->json(['success' => true, 'data' => $this->metrics->financeStrip()]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard finance strip error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load finance'),
            ], 500);
        }
    }

    /**
     * This-month margin, refunds, payouts, and DAU (AJAX).
     */
    public function getBusinessStrip()
    {
        try {
            return response()->json(['success' => true, 'data' => $this->metrics->businessStrip()]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard business strip error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load this-month figures'),
            ], 500);
        }
    }

    /**
     * Mail/queue health counters (AJAX, not cached).
     */
    public function getOpsHealth()
    {
        try {
            return response()->json(['success' => true, 'data' => $this->metrics->opsHealth()]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard ops health error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load mail and queue health'),
            ], 500);
        }
    }

    /**
     * Items that need admin attention (AJAX)
     */
    public function getActionQueue()
    {
        try {
            // Work list — same reason as queue-counts: do not freeze pending rows.
            return response()->json([
                'success' => true,
                ...$this->scopedActionQueue(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard action queue error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load action queue'),
            ], 500);
        }
    }

    /**
     * Optional short-lived cache. TTL 0 (default) skips the store.
     */
    private function remember(string $key, callable $callback): mixed
    {
        $ttl = $this->cacheTtl();
        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember('admin.dashboard.'.$key, $ttl, $callback);
    }

    private function cacheTtl(): int
    {
        return (int) config('dashboard.metrics_cache_seconds', 0);
    }

    /**
     * Hide money queues from support-only staff and community from finance-only.
     *
     * @return array<string, mixed>
     */
    private function scopedActionQueue(): array
    {
        $data = $this->metrics->actionQueue();
        $user = request()->user();
        if (! $user instanceof User) {
            return $data;
        }

        if (! $user->staffCan(StaffCapability::FINANCE)) {
            $data['deposits'] = collect();
            $data['withdrawals'] = collect();
            $data['unpaid'] = collect();
        }
        if (! $user->staffCan(StaffCapability::SUPPORT)) {
            $data['community'] = collect();
        }

        return $data;
    }
}
