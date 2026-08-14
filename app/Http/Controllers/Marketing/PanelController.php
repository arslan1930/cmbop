<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\MarketingOpsQueues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PanelController extends Controller
{
    /** @var list<string> */
    public const TRACKED_ACTIONS = [
        'bulk_request.done',
        'bulk_request.seeded',
        'bulk_request.sheet_sent',
        'bulk_request.cancelled',
        'bulk_request.notes_updated',
        'site.deleted_by_marketing',
        'site.updated',
        'site.activated',
        'site.deactivated',
        'site.assigned_for_acceptance',
        'site.image_uploaded',
        'site.metrics_refreshed',
        'site.screenshot_refreshed',
        'site.metrics_manual',
    ];

    public function dashboard()
    {
        $userId = (int) auth()->id();

        [$todayStart, $todayEnd] = $this->marketerTodayBounds();

        $stats = [
            'ready_to_activate' => MarketingOpsQueues::sitesReadyForStaffCount(),
            'bulk_waiting_on_you' => MarketingOpsQueues::bulkWaitingOnMarketerCount(),
            'sites_waiting_on_publisher' => MarketingOpsQueues::sitesWaitingOnPublisher()->count(),
            'bulk_waiting_on_publisher' => MarketingOpsQueues::bulkWaitingOnPublisher()->count(),
            'my_tasks_today' => $this->marketerHistoryQuery($userId)
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->count(),
            'my_tasks_total' => $this->marketerHistoryQuery($userId)->count(),
        ];

        $readySites = MarketingOpsQueues::sitesReadyForStaff()
            ->with('publisher:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(8)
            ->get();

        $waitingSites = MarketingOpsQueues::sitesWaitingOnPublisher()
            ->with('publisher:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(5)
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
            ->take(5)
            ->get();

        $recentHistory = $this->marketerHistoryQuery($userId)
            ->latest('id')
            ->take(12)
            ->get();

        $historyToday = $this->marketerTodayDateString();

        return view('marketing.dashboard', compact(
            'stats',
            'readySites',
            'waitingSites',
            'openBulk',
            'recentHistory',
            'historyToday'
        ));
    }

    public function queueCounts()
    {
        return response()->json([
            'success' => true,
            'ready_sites' => MarketingOpsQueues::sitesReadyForStaffCount(),
            'bulk_waiting' => MarketingOpsQueues::bulkWaitingOnMarketerCount(),
        ]);
    }

    public function history(Request $request)
    {
        $userId = (int) auth()->id();
        $query = $this->marketerHistoryQuery($userId);
        $dateErrors = [];

        $selectedAction = $request->string('action')->toString();
        if ($selectedAction !== '' && ! in_array($selectedAction, self::TRACKED_ACTIONS, true)) {
            $selectedAction = '';
        }

        $fromBound = null;
        $toBound = null;
        $datesOk = true;

        if ($request->filled('from')) {
            $fromBound = $this->parseMarketerDay($request->input('from'), true);
            if (! $fromBound) {
                $dateErrors[] = 'Use a valid From date.';
                $datesOk = false;
            }
        }

        if ($request->filled('to')) {
            $toBound = $this->parseMarketerDay($request->input('to'), false);
            if (! $toBound) {
                $dateErrors[] = 'Use a valid To date.';
                $datesOk = false;
            }
        }

        if ($fromBound && $toBound && $fromBound->gt($toBound)) {
            $dateErrors[] = 'From date must be on or before To date.';
            $datesOk = false;
        }

        if ($datesOk) {
            if ($fromBound) {
                $query->where('created_at', '>=', $fromBound);
            }
            if ($toBound) {
                $query->where('created_at', '<=', $toBound);
            }
        }

        $searchNeedle = mb_strtolower(trim($request->string('q')->toString()));
        if ($searchNeedle !== '') {
            $matchedActions = marketing_task_actions_matching($searchNeedle);
            $query->where(function ($q) use ($searchNeedle, $matchedActions) {
                $this->whereHistoryDescriptionHasWord($q, $searchNeedle);
                $q->orWhereRaw('LOWER(COALESCE(subject_label, \'\')) LIKE ?', ['%'.$searchNeedle.'%']);
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

    /**
     * Inclusive "today" window in the app timezone, stored as UTC bounds.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function marketerTodayBounds(): array
    {
        $today = now()->timezone($this->marketerTimezone());

        return [
            $today->copy()->startOfDay()->utc(),
            $today->copy()->endOfDay()->utc(),
        ];
    }

    private function marketerTodayDateString(): string
    {
        return now()->timezone($this->marketerTimezone())->toDateString();
    }

    /**
     * Word-aware description match so "Activated" does not hit "Deactivated".
     */
    private function whereHistoryDescriptionHasWord(Builder $q, string $needle): void
    {
        $pattern = '% '.$needle.' %';
        $driver = $q->getConnection()->getDriverName();
        $haystack = in_array($driver, ['sqlite', 'pgsql'], true)
            ? "(' ' || LOWER(COALESCE(description, '')) || ' ')"
            : "CONCAT(' ', LOWER(COALESCE(description, '')), ' ')";

        $q->whereRaw($haystack.' LIKE ?', [$pattern]);
    }

    private function parseMarketerDay(mixed $value, bool $start): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $local = Carbon::createFromFormat('Y-m-d', $value, $this->marketerTimezone());
        } catch (\Throwable) {
            return null;
        }

        if (! $local || $local->format('Y-m-d') !== $value) {
            return null;
        }

        return $start
            ? $local->copy()->startOfDay()->utc()
            : $local->copy()->endOfDay()->utc();
    }

    private function marketerTimezone(): string
    {
        return config('app.timezone') ?: 'UTC';
    }
}
