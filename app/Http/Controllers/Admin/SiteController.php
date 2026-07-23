<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        $query = User::withCount('sites')->with(['sites' => function ($q) {
            $q->latest();
        }]);

        // Ops dashboard deep-link: only publishers who still have unverified sites
        if ($request->query('verified') === '0' || $request->query('verified') === 0) {
            $query->whereHas('sites', function ($q) {
                $q->where(function ($inner) {
                    $inner->where('verified', 0)->orWhereNull('verified');
                });
            })->withCount(['sites as unverified_sites_count' => function ($q) {
                $q->where(function ($inner) {
                    $inner->where('verified', 0)->orWhereNull('verified');
                });
            }]);
        }

        $users = $query->latest()->paginate(20)->appends($request->query());
        $unverifiedFilter = $request->query('verified') === '0' || $request->query('verified') === 0;

        return view('admin.sites', compact('users', 'unverifiedFilter'));
    }

    // Get all sites of a user (AJAX)
    public function userSites($id)
    {
        $user = User::with('sites')->findOrFail($id);

        return response()->json($user->sites);
    }

    // Edit page (optional)
    public function edit($id)
    {
        $site = Site::findOrFail($id);

        return view('admin.site-edit', compact('site'));
    }

    // Upload image for site
    public function uploadImage(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        $request->validate([
            'site_image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        // Delete old image if exists
        if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
            Storage::disk('public')->delete($site->site_image);
        }

        // Store new image
        $file = $request->file('site_image');
        $path = $file->store('sites', 'public');

        ActivityLogger::log(
            'site.image_uploaded',
            auth()->user()->name.' uploaded an image for site "'.$site->site_name.'"',
            $site,
            ['image_path' => $path],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'image_path' => $path,
            'message' => 'Image uploaded successfully',
        ]);
    }

    // UPDATE (supports partial + full updates safely)
    public function update(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        // Store old data for email comparison / activity log
        $oldData = [
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $site->price,
            'active' => $site->active,
            'verified' => $site->verified,
        ];

        $data = $request->only([
            'site_name',
            'site_url',
            'domain',
            'example_url',
            'da',
            'dr',
            'traffic',
            'country',
            'language',
            'category',
            'price',
            'publication_time',
            'link_type',
            'sponsored',
            'partner_material',
            'as_you_prefer',
            'sensitive_prices',
            'description',
            'active',
            'site_image',
        ]);

        // Manual metric edits from admin — mark as manual so auto-refresh does not overwrite.
        if ($request->hasAny(['da', 'dr', 'traffic'])) {
            $data['metrics_manual'] = true;
            $data['metrics_provider'] = 'manual';
            $data['metrics_fetched_at'] = now();
            $data['enrichment_status'] = 'ready';
        }

        // Handle site_image - only update if provided (not null)
        if ($request->has('site_image') && $request->site_image !== null) {
            $data['site_image'] = $request->site_image;
        } else {
            unset($data['site_image']);
        }

        // Prevent overwriting NOT NULL fields with null
        $data = array_filter($data, function ($value) {
            return $value !== null;
        });

        $site->update($data);

        $changes = [];
        foreach ($oldData as $key => $oldValue) {
            $newValue = $site->{$key} ?? null;
            if ((string) $oldValue !== (string) $newValue) {
                $changes[$key] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        ActivityLogger::log(
            'site.updated',
            auth()->user()->name.' modified site "'.$site->site_name.'"',
            $site,
            ['changes' => $changes],
            $site->site_name
        );

        $emailSent = false;

        // Send email notification to publisher about the update
        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, 'update', $oldData));
                $emailSent = true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send update notification: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Site updated successfully',
            'email_sent' => $emailSent,
        ]);
    }

    // VERIFY / UNVERIFY (approve / reject)
    public function verify(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        if ($site->awaitsPublisherDetails()) {
            return response()->json([
                'success' => false,
                'message' => 'Publisher has not finished required details yet. Do not approve incomplete bulk drafts.',
            ], 422);
        }

        $oldStatus = (int) $site->verified;
        $site->verified = (int) $request->verified;
        $site->save();

        $action = $site->verified ? 'site.approved' : 'site.rejected';
        $label = $site->verified ? 'approved' : 'rejected';

        ActivityLogger::log(
            $action,
            auth()->user()->name.' '.$label.' site "'.$site->site_name.'"',
            $site,
            ['from' => $oldStatus, 'to' => (int) $site->verified],
            $site->site_name
        );

        // After verification: always refresh homepage screenshot.
        // Skip automated metrics when the publisher entered DA/DR/traffic manually.
        if ($site->verified && config('site_enrichment.enabled', true)) {
            $runMetrics = ! (bool) $site->metrics_manual;
            EnrichSiteJob::dispatch($site->id, 'verify', $runMetrics, true);
        }

        $emailSent = false;
        $status = $site->verified ? 'verified' : 'unverified';

        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status));
                $emailSent = true;
            }
            if ($publisher) {
                app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send verification notification: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification updated',
            'email_sent' => $emailSent,
        ]);
    }

    // TOGGLE ACTIVE STATUS
    public function toggleActive(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        if ($site->awaitsPublisherDetails()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot activate: publisher still needs to complete site details.',
            ], 422);
        }

        $oldStatus = (int) $site->active;
        $site->active = (int) $request->active;
        $site->save();

        $action = $site->active ? 'site.activated' : 'site.deactivated';
        $label = $site->active ? 'activated' : 'deactivated';

        ActivityLogger::log(
            $action,
            auth()->user()->name.' '.$label.' site "'.$site->site_name.'"',
            $site,
            ['from' => $oldStatus, 'to' => (int) $site->active],
            $site->site_name
        );

        $emailSent = false;
        $status = $site->active ? 'activated' : 'deactivated';

        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status));
                $emailSent = true;
            }
            if ($publisher) {
                app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send status notification: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Active status updated',
            'email_sent' => $emailSent,
        ]);
    }

    // DELETE — admin only
    public function destroy($id)
    {
        if (! auth()->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can delete sites.',
            ], 403);
        }

        $site = Site::findOrFail($id);
        $siteName = $site->site_name;
        $siteId = $site->id;

        if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
            Storage::disk('public')->delete($site->site_image);
        }

        $site->delete();

        ActivityLogger::log(
            'site.deleted',
            auth()->user()->name.' deleted site "'.$siteName.'"',
            null,
            ['site_id' => $siteId, 'site_name' => $siteName],
            $siteName
        );

        return response()->json([
            'success' => true,
            'message' => 'Site deleted successfully',
        ]);
    }
}
