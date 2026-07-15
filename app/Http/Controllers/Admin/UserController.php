<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    // ✅ Users listing
    public function index()
    {
        $users = User::with('roles')->latest()->paginate(10);
        $roles = Role::orderBy('id')->get();

        return view('admin.users', compact('users', 'roles'));
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
     * ✅ Assign / update the roles for a user (AJAX)
     *
     * Admin selects any combination of advertiser / publisher / admin.
     * A wallet is created for every non-admin role the user gains, and the
     * user's active role is repaired when the previously active role is removed.
     */
    public function updateRoles(Request $request, $id)
    {
        $validated = $request->validate([
            'roles'   => 'required|array|min:1',
            'roles.*' => 'string|exists:roles,name',
        ], [
            'roles.required' => 'Please select at least one role.',
            'roles.min'      => 'A user must have at least one role.',
        ]);

        $user = User::findOrFail($id);

        // Safety: an admin cannot strip their own admin role and lock themselves out.
        if (
            auth()->id() === $user->id
            && $user->hasRole('admin')
            && !in_array('admin', $validated['roles'], true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot remove the admin role from your own account.',
            ], 422);
        }

        $selectedRoles = Role::whereIn('name', $validated['roles'])->get();
        $roleIds       = $selectedRoles->pluck('id')->all();

        try {
            DB::transaction(function () use ($user, $selectedRoles, $roleIds) {
                // Sync the pivot table to exactly the selected roles.
                $user->roles()->sync($roleIds);

                // Ensure a wallet exists for every wallet-backed role the user now has.
                foreach ($selectedRoles as $role) {
                    if (in_array($role->name, ['advertiser', 'publisher'], true)) {
                        Wallet::firstOrCreate(
                            ['user_id' => $user->id, 'role_id' => $role->id],
                            [
                                'balance'          => 0.00,
                                'reserved_balance' => 0.00,
                                'currency'         => 'EUR',
                            ]
                        );
                    }
                }

                // Repair the active role if it is no longer assigned (or was never set).
                if (!in_array($user->active_role_id, $roleIds, true)) {
                    $user->active_role_id = $roleIds[0];
                    $user->save();
                }
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update roles. Please try again.',
            ], 500);
        }

        $user->load('roles');

        return response()->json([
            'success'     => true,
            'message'     => 'Roles updated successfully.',
            'roles'       => $user->roles->pluck('name')->all(),
            'active_role' => $user->activeRole(),
        ]);
    }
}
