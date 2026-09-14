<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DuplicateDomainReport;
use App\Support\UserFacingError;
use Illuminate\Http\Request;

class SiteDuplicateController extends Controller
{
    public function index(Request $request, DuplicateDomainReport $report)
    {
        try {
            $groups = $report->groups();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load duplicate domains. Please refresh and try again.')
            );
            $groups = collect();
        }

        return view('admin.sites.duplicates', [
            'groups' => $groups,
            'groupCount' => $groups->count(),
            'siteCount' => $groups->sum('count'),
        ]);
    }
}
