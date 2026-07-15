<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Roles that can be assigned from the admin panel. */
    public const ASSIGNABLE_ROLES = ['advertiser', 'publisher', 'marketing'];

    /** Hard cap on how many users may hold the admin role. */
    public const MAX_ADMINS = 2;

    // ✅ Users listing
    public function index()
    {
        $users = User::with('roles')->latest()->paginate(10);

        // Only the 3 customer/staff roles are assignable — admin is locked.
        $roles = Role::whereIn('name', self::ASSIGNABLE_ROLES)
            ->orderByRaw("FIELD(name, 'advertiser', 'publisher', 'marketing')")
            ->get();

        $adminCount = $this->adminCount();

        return view('admin.users', compact('users', 'roles', 'adminCount'));
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
     * ✅ Assign / update roles for a user (AJAX)
     *
     * Only advertiser / publisher / marketing may be assigned from the panel.
     * Admin is limited to MAX_ADMINS accounts and cannot be granted here.
     * Existing admins keep their admin role when other roles are edited.
     */
    public function updateRoles(Request $request, $id)
    {
        $validated = $request->validate([
            'roles'   => 'required|array|min:1',
            'roles.*' => ['string', Rule::in(self::ASSIGNABLE_ROLES)],
        ], [
            'roles.required' => 'Please select at least one role.',
            'roles.min'      => 'A user must have at least one role.',
            'roles.*.in'     => 'Only Advertiser, Publisher, and Marketing can be assigned. Admin is limited and not assignable.',
        ]);

        // Explicitly reject any attempt to pass admin (even if validation is bypassed).
        if (in_array('admin', (array) $request->input('roles', []), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Admin role cannot be assigned from the panel. Only ' . self::MAX_ADMINS . ' admin accounts are allowed.',
            ], 422);
        }

        $user = User::findOrFail($id);
        $previousRoles = $user->roles()->pluck('name')->all();
        $wasAdmin = in_array('admin', $previousRoles, true);

        // Build final role set from the 3 assignable roles only.
        $finalRoleNames = array_values(array_unique($validated['roles']));

        // Preserve admin on users who already have it (admin is not managed here).
        if ($wasAdmin) {
            $finalRoleNames[] = 'admin';
        }

        $selectedRoles = Role::whereIn('name', $finalRoleNames)->get();
        $roleIds       = $selectedRoles->pluck('id')->all();

        try {
            DB::transaction(function () use ($user, $selectedRoles, $roleIds) {
                $user->roles()->sync($roleIds);

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

                if (!in_array($user->active_role_id, $roleIds, true)) {
                    // Prefer a non-admin active role when repairing.
                    $preferred = $selectedRoles->first(fn ($r) => $r->name !== 'admin')
                        ?? $selectedRoles->first();
                    $user->active_role_id = $preferred->id;
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
        $newRoles = $user->roles->pluck('name')->all();

        ActivityLogger::log(
            'user.roles_updated',
            auth()->user()->name . ' updated roles for ' . $user->name,
            $user,
            [
                'from' => $previousRoles,
                'to'   => $newRoles,
            ],
            $user->name
        );

        return response()->json([
            'success'     => true,
            'message'     => 'Roles updated successfully.',
            'roles'       => $newRoles,
            'active_role' => $user->activeRole(),
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
