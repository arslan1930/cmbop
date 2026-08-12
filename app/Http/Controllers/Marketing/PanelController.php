<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgencySiteImport;
use App\Models\BulkSiteRequest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PanelController extends Controller
{
    /** @var list<string> */
    public const TRACKED_ACTIONS = [
        'bulk_request.seeded',
        'bulk_request.sheet_sent',
        'bulk_request.cancelled',
        'bulk_request.notes_updated',
        'site.deleted_by_marketing',
        'site.updated',
        'site.image_uploaded',
        'site.metrics_refreshed',
        'site.screenshot_refreshed',
        'site.metrics_manual',
    ];

    public function dashboard()
    {
        $userId = (int) auth()->id();

        $stats = [
            'pending_sites' => Site::query()
                ->where(function ($q) {
                    $q->where('verified', 0)->orWhereNull('verified');
                })
                ->where(function ($q) {
                    $q->where('active', 0)->orWhereNull('active');
                })
                ->count(),
            'open_bulk_requests' => BulkSiteRequest::query()
                ->whereNotIn('status', [
                    BulkSiteRequest::STATUS_COMPLETED,
                    BulkSiteRequest::STATUS_CANCELLED,
                ])
                ->count(),
            'open_agency_imports' => AgencySiteImport::query()
                ->where('dry_run', false)
                ->whereIn('status', [
                    AgencySiteImport::STATUS_SUBMITTED,
                    AgencySiteImport::STATUS_PARTIAL,
                ])
                ->count(),
            'my_tasks_today' => $this->marketerHistoryQuery($userId)
                ->whereDate('created_at', Carbon::today())
                ->count(),
            'my_tasks_total' => $this->marketerHistoryQuery($userId)->count(),
        ];

        $pendingSites = Site::with('publisher:id,name,email')
            ->where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            })
            ->where(function ($q) {
                $q->where('active', 0)->orWhereNull('active');
            })
            ->latest()
            ->take(8)
            ->get();

        $openBulk = BulkSiteRequest::with('publisher:id,name,email')
            ->whereNotIn('status', [
                BulkSiteRequest::STATUS_COMPLETED,
                BulkSiteRequest::STATUS_CANCELLED,
            ])
            ->latest()
            ->take(5)
            ->get();

        $recentHistory = $this->marketerHistoryQuery($userId)
            ->latest('id')
            ->take(12)
            ->get();

        return view('marketing.dashboard', compact(
            'stats',
            'pendingSites',
            'openBulk',
            'recentHistory'
        ));
    }

    public function history(Request $request)
    {
        $userId = (int) auth()->id();
        $query = $this->marketerHistoryQuery($userId)->latest('id');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        if ($request->filled('q')) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', $term)
                    ->orWhere('subject_label', 'like', $term)
                    ->orWhere('action', 'like', $term);
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

        return view('marketing.history', compact('logs', 'actions'));
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
}
