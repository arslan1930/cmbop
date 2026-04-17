<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Site;
use Illuminate\Http\Request;

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

        return response()->json([
            'success' => true,
            'message' => 'Site updated successfully'
        ]);
    }

    // VERIFY / UNVERIFY
    public function verify(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        $site->verified = (int) $request->verified;
        $site->save();

        return response()->json([
            'success' => true,
            'message' => 'Verification updated'
        ]);
    }

    // TOGGLE ACTIVE STATUS (FIXED)
    public function toggleActive(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        $site->active = (int) $request->active;
        $site->save();

        return response()->json([
            'success' => true,
            'message' => 'Active status updated'
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