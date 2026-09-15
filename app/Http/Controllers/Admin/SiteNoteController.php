<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteAdminNote;
use App\Services\ActivityLogger;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiteNoteController extends Controller
{
    public function store(Request $request, $id): RedirectResponse
    {
        $site = Site::findOrFail($id);
        $actor = $request->user();
        if (! $actor || (! $actor->isMarketing() && ! $actor->staffCan('support'))) {
            $message = 'This area is limited to a different admin capability.';
            if ($request->expectsJson()) {
                abort(403, $message);
            }

            return redirect()->route('admin.dashboard')->with('error', $message);
        }

        $data = $request->validate([
            'body' => 'required|string|min:3|max:2000',
        ]);

        SiteAdminNote::ensureTable();
        if (! SiteAdminNote::tableAvailable()) {
            return back()->with('error', 'Admin notes cannot be saved on this database.');
        }

        try {
            $note = SiteAdminNote::create([
                'site_id' => $site->id,
                'admin_id' => $actor->id,
                'body' => trim($data['body']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not save that note.'));
        }

        ActivityLogger::tryLog(
            'site.note_added',
            ($actor->name ?? 'Staff').' added an internal note on site "'.$site->site_name.'"',
            $site,
            ['note_id' => $note->id],
            $site->site_name
        );

        return back()->with('success', 'Note saved.');
    }
}
