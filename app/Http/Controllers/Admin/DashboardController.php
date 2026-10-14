<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardMetricsService;
use App\Services\ContentModeration\ContentModerationService;
use App\Support\ProductionReadiness;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public const SHOW_TEST_SESSION_KEY = 'admin.dashboard.show_test';

    public function __construct(private DashboardMetricsService $metrics) {}

    /**
     * Admin dashboard page (marketing uses Marketing\PanelController).
     */
    public function index(Request $request)
    {
        $moderationOff = false;
        $opsAlerts = [];
        $showTestData = $this->resolveShowTestData($request);

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

        return view('admin.dashboard', compact('moderationOff', 'opsAlerts', 'showTestData'));
    }

    /**
     * Top-level KPI cards + action counts (AJAX)
     */
    public function getStatistics(Request $request)
    {
        try {
            $metrics = $this->metricsFor($request);
            $data = $this->remember('statistics', fn () => $metrics->statistics(), $request);
            // Queue fields are also the nav badges (live). Overlay so a cached
            // KPI payload cannot disagree with pending_deposits / needs_attention.
            if ($this->cacheTtl() > 0) {
                $data = array_merge($data, $metrics->queueCounts());
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
                ...$this->remember('trends.'.$days, fn () => $this->metricsFor($request)->trends($days), $request),
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
    public function getDistributions(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                ...$this->remember('distributions', fn () => $this->metricsFor($request)->distributions(), $request),
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
    public function getQueueCounts(Request $request)
    {
        try {
            // Nav badges poll this every 60s — do not put it behind the metrics cache.
            return response()->json([
                'success' => true,
                ...$this->metricsFor($request)->queueCounts(),
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
    public function getFinanceStrip(Request $request)
    {
        try {
            // Due to pay now sits next to the live withdrawal queue — do not cache it.
            return response()->json(['success' => true, 'data' => $this->metricsFor($request)->financeStrip()]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard finance strip error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load finance'),
            ], 500);
        }
    }

    /**
     * Supply, in-flight orders, and time-to-publish (AJAX). Not cached.
     */
    public function getMarketplaceHealth(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->metricsFor($request)->marketplaceHealth(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard marketplace health error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load marketplace health'),
            ], 500);
        }
    }

    /**
     * Items that need admin attention (AJAX)
     */
    public function getActionQueue(Request $request)
    {
        try {
            $minAgeDays = max(0, min(365, (int) $request->integer('min_age_days', 0)));

            // Work list — same reason as queue-counts: do not freeze pending rows.
            return response()->json([
                'success' => true,
                ...$this->metricsFor($request)->actionQueue($minAgeDays),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin dashboard action queue error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load action queue'),
            ], 500);
        }
    }

    private function metricsFor(Request $request): DashboardMetricsService
    {
        return $this->metrics->includeTestData($this->resolveShowTestData($request));
    }

    private function resolveShowTestData(Request $request): bool
    {
        if ($request->has('show_test')) {
            $show = $request->boolean('show_test');
            $request->session()->put(self::SHOW_TEST_SESSION_KEY, $show);

            return $show;
        }

        return (bool) $request->session()->get(self::SHOW_TEST_SESSION_KEY, false);
    }

    /**
     * Optional short-lived cache. TTL 0 (default) skips the store.
     */
    private function remember(string $key, callable $callback, ?Request $request = null): mixed
    {
        $ttl = $this->cacheTtl();
        if ($ttl <= 0) {
            return $callback();
        }

        $suffix = $request && $this->resolveShowTestData($request) ? '.test' : '';

        return Cache::remember('admin.dashboard.'.$key.$suffix, $ttl, $callback);
    }

    private function cacheTtl(): int
    {
        return (int) config('dashboard.metrics_cache_seconds', 0);
    }
}
