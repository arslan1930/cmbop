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
        'bulk_request.item_rejected',
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
                'items as pending_items_count' => fn ($q) => $q->pending(),
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

    public function history(Request $request)
    {
        $userId = (int) auth()->id();
        $query = $this->marketerHistoryQuery($userId)->latest('id');
        $dateErrors = [];

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
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

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.$raw.'%';
            $matchedActions = marketing_task_actions_matching($raw);
            $query->where(function ($q) use ($term, $matchedActions) {
                $q->where('description', 'like', $term)
                    ->orWhere('subject_label', 'like', $term);
                if ($matchedActions !== []) {
                    $q->orWhereIn('action', $matchedActions);
                }
            });
        }

        $logs = $query->paginate(30)->withQueryString();

        $actions = ActivityLog::query()
            ->where('user_id', $userId)
            ->where('role', 'marketing')
            ->whereIn('action', self::TRACKED_ACTIONS)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $filtersActive = $request->filled('q')
            || $request->filled('action')
            || $request->filled('from')
            || $request->filled('to');

        return view('marketing.history', compact('logs', 'actions', 'dateErrors', 'filtersActive'));
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
