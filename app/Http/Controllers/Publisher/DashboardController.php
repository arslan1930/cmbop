<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Services\Publisher\PublisherDashboardService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private PublisherDashboardService $dashboard) {}

    /**
     * Display publisher dashboard (server-rendered summary + chart payloads).
     */
    public function index()
    {
        try {
            return view('publisher.dashboard', $this->dashboard->build(auth()->user()));
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load your dashboard. Please refresh and try again.')
            );

            return view('publisher.dashboard', $this->dashboard->emptyPayload());
        }
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

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not load dashboard statistics. Please try again.'),
            ], 500);
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
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch recent orders.'),
            ], 500);
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
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load weekly earnings.'),
                'data' => [
                    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    'values' => [0, 0, 0, 0, 0, 0, 0],
                ],
            ], 500);
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
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load order status.'),
                'data' => [
                    'labels' => ['Pending', 'Processing', 'In Review', 'Scheduled', 'Completed', 'Cancelled'],
                    'values' => [0, 0, 0, 0, 0, 0],
                ],
            ], 500);
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
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load monthly earnings.'),
                'data' => [
                    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    'values' => [0, 0, 0, 0, 0, 0],
                ],
            ], 500);
        }
    }
}
