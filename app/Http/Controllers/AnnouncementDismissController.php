<?php

namespace App\Http\Controllers;

use App\Models\AnnouncementDismissal;
use App\Models\SiteAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnouncementDismissController extends Controller
{
    public function __invoke(Request $request, SiteAnnouncement $announcement): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $version = max(1, (int) $request->input('version', $announcement->version ?: 1));
        AnnouncementDismissal::dismiss((int) $user->id, (int) $announcement->id, $version);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
