<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ActivityLogDateBounds;
use App\Support\ActivityLogTextSearch;
use App\Support\MarketingOpsQueues;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    /** @var list<string> */
    public const TRACKED_ACTIONS = [
        'bulk_request.done',
        'bulk_request.seeded',
        'bulk_request.sheet_sent',
        'bulk_request.cancelled',
        'bulk_request.items_rejected',
        'bulk_request.notes_updated',
        'site.deleted_by_marketing',
        'site.updated',
        'site.activated',
        'site.approved',
        'site.deactivated',
        'site.assigned_for_acceptance',
        'site.staff_published',
        'bulk_request.staff_assigned',
        'bulk_request.staff_published',
        'site.image_uploaded',
        'site.metrics_refreshed',
        'site.metrics_refresh_queued',
        'site.screenshot_refreshed',
        'site.screenshot_refresh_queued',
        'site.metrics_manual',
        'site.enrichment_queued',
        'site.enrichment_refreshed',
        'site.enrichment_batch_queued',
        'site.enrichment_rerun_queued',
    ];

    public const READY_PREVIEW = 8;

    public const WAITING_PREVIEW = 5;

    public const BULK_PREVIEW = 5;

    public const HISTORY_PREVIEW = 12;

    public function dashboard()
    {
        try {
            return $this->renderDashboard();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load the marketing dashboard. Please refresh and try again.')
            );

            return view('marketing.dashboard', $this->emptyDashboardPayload());
        }
    }

    public function queueCounts()
    {
        try {
            return response()->json(array_merge(
                ['success' => true],
                $this->queueCountPayload((int) auth()->id())
            ));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(array_merge(
                [
                    'success' => false,
                    'message' => UserFacingError::message($e, 'We could not load queue counts. Please try again.'),
                ],
                $this->emptyQueueCountPayload()
            ), 500);
        }
    }

    public function history(Request $request)
    {
        $userId = (int) auth()->id();
        $query = $this->marketerHistoryQuery($userId);

        $selectedAction = search_text($request->input('action'));
        if ($selectedAction !== '' && ! in_array($selectedAction, self::TRACKED_ACTIONS, true)) {
            $selectedAction = '';
        }

        $dateErrors = ActivityLogDateBounds::apply(
            $query,
            $request->input('from'),
            $request->input('to')
        );

        $searchNeedle = search_text($request->input('q'));
        if ($searchNeedle !== '') {
            $matchedActions = marketing_task_actions_matching($searchNeedle);
            $like = like_contains($searchNeedle);
            $query->where(function ($q) use ($searchNeedle, $matchedActions, $like) {
                $q->whereRaw('subject_label LIKE ? ESCAPE ?', [$like, '\\']);
                $q->orWhere(function ($inner) use ($searchNeedle) {
                    ActivityLogTextSearch::whereDescriptionHasWord($inner, $searchNeedle);
                });
                if ($matchedActions !== []) {
                    $q->orWhereIn('action', $matchedActions);
                }
            });
        }

        $actionCounts = (clone $query)
            ->selectRaw('action, COUNT(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');

        if ($selectedAction !== '') {
            $query->where('action', $selectedAction);
        }

        $logs = $query->latest('id')->paginate(30)->withQueryString();

        if ($request->integer('page') > 1 && $logs->total() > 0 && $logs->count() === 0) {
            return redirect()->to($logs->url(max(1, $logs->lastPage())));
        }

        $actions = self::TRACKED_ACTIONS;

        $filtersActive = $searchNeedle !== ''
            || $selectedAction !== ''
            || $request->filled('from')
            || $request->filled('to');

        return view('marketing.history', compact(
            'logs',
            'actions',
            'actionCounts',
            'selectedAction',
            'dateErrors',
            'filtersActive'
        ));
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function marketerHistoryQuery(int $userId)
    {
        return ActivityLog::query()
            ->where('user_id', $userId)
            ->where('role', 'marketing')
            ->whereIn('action', self::TRACKED_ACTIONS);
    }

    private function renderDashboard()
    {
        $userId = (int) auth()->id();
        $stats = $this->dashboardStats($userId);

        $readySites = MarketingOpsQueues::sitesReadyForStaff()
            ->with('publisher:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(self::READY_PREVIEW)
            ->get();

        $waitingSites = MarketingOpsQueues::sitesWaitingOnPublisher()
            ->with('publisher:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(self::WAITING_PREVIEW)
            ->get();

        $openBulk = MarketingOpsQueues::bulkWaitingOnMarketer()
            ->with([
                'publisher:id,name,email',
                'handler:id,name',
            ])
            ->withCount([
                'items as pending_items_count' => fn ($q) => $q->whereNull('site_id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(self::BULK_PREVIEW)
            ->get();

        $recentHistory = $this->marketerHistoryQuery($userId)
            ->latest('id')
            ->take(self::HISTORY_PREVIEW)
            ->get();

        return view('marketing.dashboard', [
            'stats' => $stats,
            'readySites' => $readySites,
            'waitingSites' => $waitingSites,
            'openBulk' => $openBulk,
            'recentHistory' => $recentHistory,
            'historyToday' => ActivityLogDateBounds::todayDateString(),
            'readyPreviewCap' => self::READY_PREVIEW,
            'waitingPreviewCap' => self::WAITING_PREVIEW,
            'bulkPreviewCap' => self::BULK_PREVIEW,
            'historyPreviewCap' => self::HISTORY_PREVIEW,
        ]);
    }

    /**
     * @return array{
     *     ready_to_activate: int,
     *     bulk_waiting_on_you: int,
     *     sites_waiting_on_publisher: int,
     *     bulk_waiting_on_publisher: int,
     *     my_tasks_today: int,
     *     my_tasks_total: int
     * }
     */
    private function dashboardStats(int $userId): array
    {
        [$todayStart, $todayEnd] = ActivityLogDateBounds::todayBounds();

        return [
            'ready_to_activate' => MarketingOpsQueues::sitesReadyForStaffCount(),
            'bulk_waiting_on_you' => MarketingOpsQueues::bulkWaitingOnMarketerCount(),
            'sites_waiting_on_publisher' => MarketingOpsQueues::sitesWaitingOnPublisherCount(),
            'bulk_waiting_on_publisher' => MarketingOpsQueues::bulkWaitingOnPublisherCount(),
            'my_tasks_today' => $this->marketerHistoryQuery($userId)
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->count(),
            'my_tasks_total' => $this->marketerHistoryQuery($userId)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function queueCountPayload(int $userId): array
    {
        $stats = $this->dashboardStats($userId);

        return [
            'ready_sites' => $stats['ready_to_activate'],
            'bulk_waiting' => $stats['bulk_waiting_on_you'],
            'sites_waiting_on_publisher' => $stats['sites_waiting_on_publisher'],
            'bulk_waiting_on_publisher' => $stats['bulk_waiting_on_publisher'],
            'my_tasks_today' => $stats['my_tasks_today'],
            'my_tasks_total' => $stats['my_tasks_total'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyQueueCountPayload(): array
    {
        return [
            'ready_sites' => 0,
            'bulk_waiting' => 0,
            'sites_waiting_on_publisher' => 0,
            'bulk_waiting_on_publisher' => 0,
            'my_tasks_today' => 0,
            'my_tasks_total' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDashboardPayload(): array
    {
        return [
            'stats' => [
                'ready_to_activate' => 0,
                'bulk_waiting_on_you' => 0,
                'sites_waiting_on_publisher' => 0,
                'bulk_waiting_on_publisher' => 0,
                'my_tasks_today' => 0,
                'my_tasks_total' => 0,
            ],
            'readySites' => collect(),
            'waitingSites' => collect(),
            'openBulk' => collect(),
            'recentHistory' => collect(),
            'historyToday' => ActivityLogDateBounds::todayDateString(),
            'readyPreviewCap' => self::READY_PREVIEW,
            'waitingPreviewCap' => self::WAITING_PREVIEW,
            'bulkPreviewCap' => self::BULK_PREVIEW,
            'historyPreviewCap' => self::HISTORY_PREVIEW,
        ];
    }
}
