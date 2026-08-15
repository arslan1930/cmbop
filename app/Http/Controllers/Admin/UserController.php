<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PayoutProfileUpdatedBySupport;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Wallet\PayoutProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    /** Hard cap on how many users may hold the admin role. */
    public const MAX_ADMINS = 2;

    /** Hard cap on how many users may hold the marketing role. */
    public const MAX_MARKETING = 5;

    public function __construct(
        private PayoutProfileService $payoutProfiles,
    ) {}

    // ✅ Users listing
    public function index(Request $request)
    {
        $query = User::with('roles')
            ->withCount([
                'orders as paid_orders_count' => fn ($q) => $q->where('payment_status', 'paid'),
            ])
            ->withSum([
                'orders as paid_orders_total' => fn ($q) => $q->where('payment_status', 'paid'),
            ], 'total_amount');

        // Orders / finance deep-links: /admin/users?user=123#user-123
        // The hash alone misses when the user is not on page 1 of 10.
        if ($request->integer('user') > 0) {
            $query->whereKey($request->integer('user'));
        }

        $users = $query->latest()->paginate(10)->withQueryString();
        $adminCount = $this->adminCount();
        $marketingCount = $this->marketingCount();
        $maxMarketing = self::MAX_MARKETING;

        return response()
            ->view('admin.users', compact('users', 'adminCount', 'marketingCount', 'maxMarketing'))
            ->header('Cache-Control', 'no-store');
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
                'message' => 'Company updated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
            ], 500);
        }
    }

    /**
     * Support-only: update a user's locked payout destinations and email them.
     */
    public function updatePayoutProfile(Request $request, $id)
    {
        $data = $request->validate([
            'payment_method' => 'required|in:bank,paypal,wise,crypto',
            'paypal_email' => 'nullable|email|max:255',
            'wise_email' => 'nullable|email|max:255',
            'bank_name' => 'nullable|string|max:255',
            'account_holder' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'swift_code' => 'nullable|string|max:50',
            'crypto_type' => 'nullable|string|in:BTC,ETH,USDT,BNB',
            'wallet_address' => 'nullable|string|max:255',
        ]);

        $method = $data['payment_method'];
        if ($method === 'paypal' && empty($data['paypal_email'])) {
            return response()->json(['success' => false, 'message' => 'PayPal email is required.'], 422);
        }
        if ($method === 'wise' && empty($data['wise_email'])) {
            return response()->json(['success' => false, 'message' => 'Wise email is required.'], 422);
        }
        if ($method === 'bank' && (empty($data['bank_name']) || empty($data['account_holder']) || empty($data['account_number']))) {
            return response()->json(['success' => false, 'message' => 'Bank name, holder, and account are required.'], 422);
        }
        if ($method === 'crypto' && (empty($data['wallet_address']) || empty($data['crypto_type']))) {
            return response()->json(['success' => false, 'message' => 'Crypto type and wallet address are required.'], 422);
        }

        $user = User::findOrFail($id);
        $this->payoutProfiles->adminUpdateProfile($user, $method, $data);

        try {
            Mail::to($user->email)->send(new PayoutProfileUpdatedBySupport($user->fresh(), $method));
        } catch (\Throwable $e) {
            report($e);
        }

        ActivityLogger::log(
            'user.payout_profile_updated',
            auth()->user()->name.' updated payout profile for user #'.$user->id.' ('.$method.')',
            $user,
            ['method' => $method],
            'User #'.$user->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Payout details updated. The publisher was notified by email.',
            'payout_profile' => $user->fresh()->payoutProfile(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Grant or revoke the Marketing role for a team member (AJAX).
     *
     * Only admins may change Marketing (route + explicit check).
     * At most {@see MAX_MARKETING} users may hold Marketing at once.
     * Registration already gives Advertiser + Publisher; those are never changed here.
     */
    public function updateRoles(Request $request, $id)
    {
        $actor = auth()->user();
        if (! $actor || (! $actor->isAdmin() && ! $actor->hasRole('admin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Only an admin can grant or revoke Marketing access.',
            ], 403);
        }

        // Accept JSON booleans and common form/string encodings from the Users UI.
        $request->merge([
            'marketing' => filter_var($request->input('marketing'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'can_activate_sites' => filter_var($request->input('can_activate_sites'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $validated = $request->validate([
            'marketing' => 'required|boolean',
            'can_activate_sites' => 'nullable|boolean',
        ], [
            'marketing.required' => 'Please choose whether this user should have Marketing access.',
        ]);

        $user = User::findOrFail($id);
        $previousRoles = $user->roles()->pluck('name')->all();

        // Self-heal if production never re-seeded after marketing was introduced.
        $marketingRole = Role::firstOrCreate(
            ['name' => 'marketing'],
            ['description' => 'Marketing staff: site review in the admin panel (no payments/users).']
        );

        $grantMarketing = (bool) $validated['marketing'];
        $alreadyHasMarketing = $user->hasRole('marketing');
        // Column kept for Hostinger leftovers; runtime uses isAdmin() || isMarketing().
        $canActivateSites = $grantMarketing;

        if ($grantMarketing && ! $alreadyHasMarketing) {
            $current = $this->marketingCount();
            if ($current >= self::MAX_MARKETING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marketing is limited to '.self::MAX_MARKETING.' people. Revoke access from someone else first.',
                    'marketing_count' => $current,
                    'max_marketing' => self::MAX_MARKETING,
                ], 422);
            }
        }

        // Hostinger: missing can_activate_sites column used to 500 on every grant/revoke.
        $hasActivateColumn = User::ensureCanActivateSitesColumn();

        try {
            DB::transaction(function () use ($user, $marketingRole, $grantMarketing, $canActivateSites, $hasActivateColumn) {
                if ($grantMarketing) {
                    $user->roles()->syncWithoutDetaching([$marketingRole->id]);
                    // Activate Marketing so they can open the panel immediately.
                    // Leave admins on Admin — RedirectMarketingFromAdmin would otherwise
                    // bounce them off /admin until they find the role switcher.
                    if (! $user->hasRole('admin')) {
                        $user->active_role_id = $marketingRole->id;
                    }
                    if ($hasActivateColumn) {
                        $user->can_activate_sites = $canActivateSites;
                    }
                    $user->save();
                } else {
                    $user->roles()->detach($marketingRole->id);
                    if ($hasActivateColumn) {
                        $user->can_activate_sites = false;
                    }

                    // If their active role was marketing, fall back to another role they still have.
                    if ((int) $user->active_role_id === (int) $marketingRole->id) {
                        $fallbackId = $user->roles()
                            ->where('roles.id', '!=', $marketingRole->id)
                            ->value('roles.id');

                        $user->active_role_id = $fallbackId;
                    }
                    $user->save();
                }
            });
        } catch (\Exception $e) {
            report($e);

            $hint = str_contains($e->getMessage(), 'can_activate_sites')
                ? ' Database may be missing users.can_activate_sites — run database/sql/add_users_can_activate_sites.sql.'
                : '';

            return response()->json([
                'success' => false,
                'message' => 'Failed to update marketing access. Please try again.'.$hint,
            ], 500);
        }

        $user->load('roles');
        $newRoles = $user->roles->pluck('name')->all();
        $marketingCount = $this->marketingCount();
        $storedActivate = $hasActivateColumn ? (bool) $user->fresh()->can_activate_sites : $canActivateSites;

        try {
            ActivityLogger::log(
                $grantMarketing ? 'user.marketing_granted' : 'user.marketing_revoked',
                auth()->user()->name.($grantMarketing ? ' granted' : ' revoked').' Marketing for '.$user->name,
                $user,
                [
                    'from' => $previousRoles,
                    'to' => $newRoles,
                    'active_role' => $user->activeRole(),
                    'marketing_count' => $marketingCount,
                    'can_activate_sites' => $storedActivate,
                ],
                $user->name
            );
        } catch (\Throwable $e) {
            // Role change already committed — do not fail the admin UI if logging is unavailable.
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => $grantMarketing
                ? 'Marketing access granted. They can review and activate sites (verify stays admin-only).'
                : 'Marketing access removed.',
            'roles' => $newRoles,
            'active_role' => $user->activeRole(),
            'marketing' => in_array('marketing', $newRoles, true),
            'can_activate_sites' => $storedActivate,
            'marketing_count' => $marketingCount,
            'max_marketing' => self::MAX_MARKETING,
        ]);
    }

    private function adminCount(): int
    {
        $adminRoleId = Role::where('name', 'admin')->value('id');
        if (! $adminRoleId) {
            return 0;
        }

        return (int) DB::table('role_user')->where('role_id', $adminRoleId)->distinct()->count('user_id');
    }

    private function marketingCount(): int
    {
        $marketingRoleId = Role::where('name', 'marketing')->value('id');
        if (! $marketingRoleId) {
            return 0;
        }

        return (int) DB::table('role_user')->where('role_id', $marketingRoleId)->distinct()->count('user_id');
    }
}
