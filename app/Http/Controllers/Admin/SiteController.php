<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\SiteDescriptionSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
        $site = Site::with('publisher:id,name,email')->findOrFail($id);
        $user = auth()->user();
        $isMarketingEditor = (bool) ($user?->isMarketing() && ! $user?->isAdmin());
        $languages = Language::marketplace()->orderBy('name')->get();
        $countries = Country::marketplace()->orderBy('name')->get();

        // Load by absolute path so a stale `view:cache` manifest cannot report
        // "View [admin.site-edit] not found" when the Blade file is on disk.
        $editViewPath = resource_path('views/admin/site-edit.blade.php');
        if (is_file($editViewPath)) {
            return view()->file($editViewPath, compact(
                'site',
                'isMarketingEditor',
                'languages',
                'countries'
            ));
        }

        // Fallback: open the existing Sites UI editor for this publisher/site.
        return redirect()->to(staff_route('sites.index', [
            'publisher' => $site->publisher_id,
            'edit_site' => $site->id,
        ]));
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
        $user = auth()->user();
        $isMarketingEditor = (bool) ($user?->isMarketing() && ! $user?->isAdmin());

        // Store old data for email comparison / activity log
        $oldData = [
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $site->price,
            'language' => $site->language,
            'country' => $site->country,
            'active' => $site->active,
            'verified' => $site->verified,
        ];

        if ($isMarketingEditor) {
            $data = $this->marketingUpdatePayload($request);

            if ($data instanceof \Illuminate\Http\JsonResponse) {
                return $data;
            }

            if ($data instanceof \Illuminate\Http\RedirectResponse) {
                return $data;
            }
        } else {
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

            // Derive domain from URL when the edit form omits it.
            if (empty($data['domain']) && ! empty($data['site_url'])) {
                try {
                    $data['domain'] = preg_replace('/^www\./i', '', parse_url($data['site_url'], PHP_URL_HOST) ?: '');
                } catch (\Throwable $e) {
                    $data['domain'] = null;
                }
                if ($data['domain'] === '') {
                    $data['domain'] = null;
                }
            }

            // Manual metric edits from admin — mark as manual so auto-refresh does not overwrite.
            if ($request->hasAny(['da', 'dr', 'traffic'])) {
                $data['metrics_manual'] = true;
                $data['metrics_provider'] = 'manual';
                $data['metrics_fetched_at'] = now();
                $data['enrichment_status'] = 'ready';
            }

            // Multipart form upload from the dedicated edit page.
            if ($request->hasFile('site_image')) {
                $request->validate([
                    'site_image' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                ]);

                if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
                    Storage::disk('public')->delete($site->site_image);
                }

                $data['site_image'] = $request->file('site_image')->store('sites', 'public');
            } elseif ($request->has('site_image') && $request->site_image !== null) {
                // JSON/AJAX path: image path already uploaded via upload-image.
                $data['site_image'] = $request->site_image;
            } else {
                unset($data['site_image']);
            }

            // Prevent overwriting NOT NULL fields with null
            $data = array_filter($data, function ($value) {
                return $value !== null;
            });

            if (isset($data['description']) && is_string($data['description'])) {
                $data['description'] = app(SiteDescriptionSanitizer::class)
                    ->sanitize($data['description']);
            }
        }

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

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site updated successfully',
                'email_sent' => $emailSent,
            ]);
        }

        $message = 'Site updated successfully.'.($emailSent ? ' Publisher notified.' : '');

        return redirect()
            ->to(staff_route('sites.edit', $site->id))
            ->with('success', $message);
    }

    /**
     * Marketing may only edit metrics + geo for the bulk handoff.
     *
     * @return array<string, mixed>|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function marketingUpdatePayload(Request $request)
    {
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        $validator = Validator::make($request->all(), [
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'language' => 'required|string|max:10',
            'country' => 'required|string|max:10',
        ]);

        $validator->after(function ($validator) use ($request, $allowedCountries, $allowedLanguages) {
            $language = strtolower(trim((string) $request->input('language', '')));
            $country = strtolower(trim((string) $request->input('country', '')));

            if ($language !== '' && ! in_array($language, $allowedLanguages, true)) {
                $validator->errors()->add('language', 'Choose a valid marketplace language.');
            }
            if ($country !== '' && ! in_array($country, $allowedCountries, true)) {
                $validator->errors()->add('country', 'Choose a valid marketplace country.');
            }
        });

        if ($validator->fails()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        $language = strtolower(trim((string) $request->input('language')));
        $country = strtolower(trim((string) $request->input('country')));

        return [
            'da' => (int) $request->input('da'),
            'dr' => (int) $request->input('dr'),
            'traffic' => (int) $request->input('traffic'),
            'language' => $language,
            'languages' => [$language],
            'country' => $country,
            'countries' => [$country],
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'enrichment_status' => 'ready',
        ];
    }

    // VERIFY / UNVERIFY (approve / reject) — admin only
    public function verify(Request $request, $id)
    {
        if (! auth()->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can verify or unverify sites.',
            ], 403);
        }

        $site = Site::findOrFail($id);

        if ($site->awaitsPublisherDetails()) {
            return response()->json([
                'success' => false,
                'message' => 'Publisher has not finished required details yet. Do not approve incomplete bulk drafts.',
            ], 422);
        }

        $oldStatus = (int) $site->verified;
        $site->verified = (int) $request->verified;
        if ($site->verified) {
            $site->verified_at = now();
            $site->verify_method = 'manual';
            $site->verify_token = null;
            $site->verify_token_created_at = null;
        } else {
            $site->verified_at = null;
            $site->verify_method = null;
        }
        $site->save();

        $action = $site->verified ? 'site.approved' : 'site.rejected';
        $label = $site->verified ? 'approved' : 'rejected';

        ActivityLogger::log(
            $action,
            auth()->user()->name.' '.$label.' site "'.$site->site_name.'"',
            $site,
            [
                'from' => $oldStatus,
                'to' => (int) $site->verified,
                'bulk_site_request_id' => $site->bulk_site_request_id,
            ],
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

    // TOGGLE ACTIVE STATUS — admin only
    public function toggleActive(Request $request, $id)
    {
        if (! auth()->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can activate or deactivate sites.',
            ], 403);
        }

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
            [
                'from' => $oldStatus,
                'to' => (int) $site->active,
                'bulk_site_request_id' => $site->bulk_site_request_id,
            ],
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

    // DELETE — admin: any site; marketing: pending / not-live only
    public function destroy($id)
    {
        $user = auth()->user();
        $site = Site::findOrFail($id);

        $isAdmin = (bool) $user?->isAdmin();
        $isMarketingPendingDelete = (bool) $user?->isMarketing() && $site->canBeDeletedByMarketing();

        if (! $isAdmin && ! $isMarketingPendingDelete) {
            return response()->json([
                'success' => false,
                'message' => $user?->isMarketing()
                    ? 'Marketing can only delete pending sites that are not verified or active in the portal.'
                    : 'Only admins can delete sites.',
            ], 403);
        }

        $siteName = $site->site_name;
        $siteId = $site->id;
        $domain = $site->domain;
        $bulkRequestId = $site->bulk_site_request_id;
        $onboarding = $site->onboarding_status;

        if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
            Storage::disk('public')->delete($site->site_image);
        }

        $site->delete();

        ActivityLogger::log(
            $isMarketingPendingDelete && ! $isAdmin ? 'site.deleted_by_marketing' : 'site.deleted',
            ($user->name ?? 'Staff').' deleted site "'.$siteName.'"'.($domain ? ' ('.$domain.')' : ''),
            null,
            [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'domain' => $domain,
                'bulk_site_request_id' => $bulkRequestId,
                'onboarding_status' => $onboarding,
                'deleted_by_role' => $user?->activeRole(),
            ],
            $siteName
        );

        return response()->json([
            'success' => true,
            'message' => 'Site deleted successfully',
        ]);
    }
}
