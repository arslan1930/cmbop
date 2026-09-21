<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Services\Publisher\PublisherDashboardService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DashboardController extends Controller
{
    public function __construct(private PublisherDashboardService $dashboard) {}

    /**
     * Display publisher dashboard (server-rendered summary + chart payloads).
     *
     * Blade/layout queries run after a normal `return view()`, so leftover
     * SQLSTATE there would still 500. Render inside this method and fall back.
     */
    public function index()
    {
        try {
            $payload = $this->dashboard->build(auth()->user());
        } catch (\Throwable $e) {
            report($e);
            $this->flashDashboardError($e);

            try {
                $payload = $this->dashboard->emptyPayload();
            } catch (\Throwable $inner) {
                report($inner);
                $payload = PublisherDashboardService::inertPayload();
            }
        }

        return $this->dashboardResponse($payload);
    }

    /**
     * Get dashboard statistics (AJAX)
     */
    public function getStatistics(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->dashboard->statisticsPayload(auth()->user()),
            ]);
        } catch (\Throwable $e) {
            report($e);

            try {
                $data = $this->dashboard->emptyStatisticsPayload();
            } catch (\Throwable $inner) {
                report($inner);
                $data = PublisherDashboardService::inertStatisticsPayload();
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }
    }

    /**
     * Get recent orders for dashboard (AJAX)
     */
    public function getRecentOrders(Request $request)
    {
        try {
            $userId = (int) auth()->id();

            return response()->json([
                'success' => true,
                'orders' => $this->dashboard->recentTasksPayload(
                    $this->dashboard->publisherSiteIds($userId),
                    $userId
                ),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => true,
                'orders' => [],
            ]);
        }
    }

    /**
     * Get weekly earnings for chart (AJAX)
     */
    public function getWeeklyEarnings(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->dashboard->weeklyEarningsPayload(
                    $this->dashboard->publisherSiteIds((int) auth()->id())
                ),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'values' => [0, 0, 0, 0, 0, 0, 0],
                ],
            ]);
        }
    }

    /**
     * Get order status distribution for chart (AJAX)
     */
    public function getOrderStatusDistribution(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->dashboard->orderStatusPayload(
                    $this->dashboard->publisherSiteIds((int) auth()->id())
                ),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => ['Pending', 'Processing', 'In Review', 'Scheduled', 'Completed', 'Cancelled'],
                    'values' => [0, 0, 0, 0, 0, 0],
                ],
            ]);
        }
    }

    /**
     * Get monthly earnings for chart (AJAX)
     */
    public function getMonthlyEarnings(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->dashboard->monthlyEarningsPayload(
                    $this->dashboard->publisherSiteIds((int) auth()->id())
                ),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    'values' => [0, 0, 0, 0, 0, 0],
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dashboardResponse(array $payload): Response
    {
        try {
            return response()->make(view('publisher.dashboard', $payload)->render());
        } catch (\Throwable $e) {
            report($e);
            $this->flashDashboardError($e);

            try {
                return response()->make(
                    view('publisher.dashboard', PublisherDashboardService::inertPayload())->render()
                );
            } catch (\Throwable $inner) {
                report($inner);

                return response()->make(PublisherDashboardService::inertHtml(), 200);
            }
        }
    }

    private function flashDashboardError(\Throwable $e): void
    {
        try {
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load your dashboard. Please refresh and try again.')
            );
        } catch (\Throwable $flash) {
            report($flash);
        }
    }
}
