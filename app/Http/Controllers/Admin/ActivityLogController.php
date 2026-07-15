<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Activity history for the admin panel.
     * Visible to admin + marketing (read-only).
     */
    public function index(Request $request)
    {
        $query = ActivityLog::query()->latest();

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user')) {
            $term = $request->user;
            $query->where(function ($q) use ($term) {
                $q->where('user_name', 'like', '%' . $term . '%')
                  ->orWhere('user_email', 'like', '%' . $term . '%');
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->paginate(25)->withQueryString();

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('admin.activity-logs', compact('logs', 'actions'));
    }
}
