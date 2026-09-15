<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\WorkInboxService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;

class WorkInboxController extends Controller
{
    public function index(Request $request, WorkInboxService $inbox)
    {
        $tab = $inbox->normalizeTab($request->query('tab'));

        try {
            $counts = $inbox->counts();
            $items = $inbox->items($tab);
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load the work inbox. Please refresh and try again.')
            );
            $counts = ['disputes' => 0, 'community' => 0, 'stalled' => 0, 'total' => 0];
            $items = collect();
        }

        return view('admin.inbox.index', [
            'tab' => $tab,
            'tabs' => WorkInboxService::TABS,
            'counts' => $counts,
            'items' => $items,
        ]);
    }
}
