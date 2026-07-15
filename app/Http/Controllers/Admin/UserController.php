<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /** Hard cap on how many users may hold the admin role. */
    public const MAX_ADMINS = 2;

    // ✅ Users listing
    public function index()
    {
        $users = User::with('roles')->latest()->paginate(10);
        $adminCount = $this->adminCount();

        return view('admin.users', compact('users', 'adminCount'));
    }

    // ✅ Update Company (AJAX)
    public function updateCompany(Request $request, $id)
    {
        try {
            $request->validate([
                'company_name' => 'nullable|string|max:255',
            ]);

            $user = User::findOrFail($id);

            $user->company_name = $request->company_name;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Company updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    /**
     * ✅ Grant or revoke the Marketing role for a team member (AJAX)
     *
     * Registration already gives users Advertiser + Publisher.
     * From the admin panel you may only add/remove Marketing for your team.
     * Admin / Advertiser / Publisher are never changed here.
     */
    public function updateRoles(Request $request, $id)
    {
        $validated = $request->validate([
            'marketing' => 'required|boolean',
        ], [
            'marketing.required' => 'Please choose whether this user should have Marketing access.',
        ]);

        $user = User::findOrFail($id);
        $previousRoles = $user->roles()->pluck('name')->all();
        $marketingRole = Role::where('name', 'marketing')->firstOrFail();

        $grantMarketing = (bool) $validated['marketing'];

        try {
            DB::transaction(function () use ($user, $marketingRole, $grantMarketing) {
                if ($grantMarketing) {
                    $user->roles()->syncWithoutDetaching([$marketingRole->id]);
                } else {
                    $user->roles()->detach($marketingRole->id);

                    // If their active role was marketing, fall back to another role they still have.
                    if ((int) $user->active_role_id === (int) $marketingRole->id) {
                        $fallbackId = $user->roles()
                            ->where('roles.id', '!=', $marketingRole->id)
                            ->value('roles.id');

                        $user->active_role_id = $fallbackId;
                        $user->save();
                    }
                }
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update marketing access. Please try again.',
            ], 500);
        }

        $user->load('roles');
        $newRoles = $user->roles->pluck('name')->all();

        ActivityLogger::log(
            $grantMarketing ? 'user.marketing_granted' : 'user.marketing_revoked',
            auth()->user()->name . ($grantMarketing ? ' granted' : ' revoked') . ' Marketing for ' . $user->name,
            $user,
            [
                'from' => $previousRoles,
                'to'   => $newRoles,
            ],
            $user->name
        );

        return response()->json([
            'success'     => true,
            'message'     => $grantMarketing
                ? 'Marketing access granted.'
                : 'Marketing access removed.',
            'roles'       => $newRoles,
            'active_role' => $user->activeRole(),
            'marketing'   => $grantMarketing,
        ]);
    }

    private function adminCount(): int
    {
        $adminRoleId = Role::where('name', 'admin')->value('id');
        if (!$adminRoleId) {
            return 0;
        }

        return (int) DB::table('role_user')->where('role_id', $adminRoleId)->distinct()->count('user_id');
    }
}
