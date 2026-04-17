<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Site;
use App\Mail\SiteStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SiteController extends Controller
{
    public function index()
    {
        $users = User::withCount('sites')
            ->latest()
            ->paginate(20);

        return view('admin.sites', compact('users'));
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

    // UPDATE (supports partial + full updates safely)
    public function update(Request $request, $id)
    {
        $site = Site::findOrFail($id);
        
        // Store old data for email comparison
        $oldData = [
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
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
            'active'
        ]);

        // Prevent overwriting NOT NULL fields with null
        $data = array_filter($data, function ($value) {
            return $value !== null;
        });

        $site->update($data);
        
        $emailSent = false;
        
        // Send email notification to publisher about the update
        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, 'update', $oldData));
                $emailSent = true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send update notification: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Site updated successfully',
            'email_sent' => $emailSent
        ]);
    }

    // VERIFY / UNVERIFY
    public function verify(Request $request, $id)
    {
        $site = Site::findOrFail($id);
        
        $oldStatus = $site->verified;
        $site->verified = (int) $request->verified;
        $site->save();
        
        $emailSent = false;
        
        // Send email notification
        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                $status = $site->verified ? 'verified' : 'unverified';
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status));
                $emailSent = true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send verification notification: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification updated',
            'email_sent' => $emailSent
        ]);
    }

    // TOGGLE ACTIVE STATUS (FIXED)
    public function toggleActive(Request $request, $id)
    {
        $site = Site::findOrFail($id);
        
        $oldStatus = $site->active;
        $site->active = (int) $request->active;
        $site->save();
        
        $emailSent = false;
        
        // Send email notification
        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                $status = $site->active ? 'activated' : 'deactivated';
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status));
                $emailSent = true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send status notification: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Active status updated',
            'email_sent' => $emailSent
        ]);
    }

    // DELETE
    public function destroy($id)
    {
        $site = Site::findOrFail($id);
        $site->delete();

        return response()->json([
            'success' => true,
            'message' => 'Site deleted successfully'
        ]);
    }
}