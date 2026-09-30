<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PayoutProfileUpdatedBySupport;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\UserAdminNote;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Wallet\PayoutProfileService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
        $hasRolePivot = $this->rolePivotAvailable();
        $filters = $this->userIndexFilters($request);
        $query = User::query();
        if ($hasRolePivot) {
            $query->with('roles');
        }
        if ($this->tableExists('orders') && $this->hasColumn('orders', 'payment_status')) {
            $query->withCount([
                'orders as paid_orders_count' => fn ($q) => $q->where('payment_status', 'paid'),
            ]);
            if ($this->hasColumn('orders', 'total_amount')) {
                $query->withSum([
                    'orders as paid_orders_total' => fn ($q) => $q->where('payment_status', 'paid'),
                ], 'total_amount');
            }
        }

        $this->applyUserIndexFilters($query, $filters);

        try {
            $users = $query->paginate(25)->withQueryString();
        } catch (\Throwable $e) {
            report($e);
            $fallback = User::query();
            $this->applyUserIndexFilters($fallback, $filters);
            $users = $fallback->paginate(25)->withQueryString();
        }
        $users->getCollection()->each(function (User $user) use ($hasRolePivot) {
            if ($user->relationLoaded('roles')) {
                return;
            }
            if (! $hasRolePivot) {
                $user->setRelation('roles', collect());

                return;
            }
            try {
                $user->load('roles');
            } catch (\Throwable) {
                $user->setRelation('roles', collect());
            }
        });
        $adminCount = $this->adminCount();
        $marketingCount = $this->marketingCount();
        $maxMarketing = self::MAX_MARKETING;

        return view('admin.users', compact('users', 'adminCount', 'marketingCount', 'maxMarketing', 'filters'));
    }

    /**
     * User 360 — identity, money snapshot, sites, orders, notes.
     */
    public function show(User $user, FinanceOverviewService $finance)
    {
        try {
            $user->load('roles');
        } catch (\Throwable) {
            $user->setRelation('roles', collect());
        }

        $dossier = [];
        try {
            $dossier = $finance->userDossier($user);
        } catch (\Throwable $e) {
            report($e);
            $dossier = ['user' => $user, 'totals' => [], 'roles' => []];
        }

        $sites = collect();
        try {
            $sites = $user->sites()->latest('id')->limit(20)->get();
        } catch (\Throwable) {
            $sites = collect();
        }

        $notes = collect();
        if (UserAdminNote::tableAvailable()) {
            try {
                $notes = UserAdminNote::query()
                    ->with('admin:id,name,email')
                    ->where('user_id', $user->id)
                    ->latest('id')
                    ->limit(50)
                    ->get();
            } catch (\Throwable) {
                $notes = collect();
            }
        }

        $activities = collect();
        try {
            if ($this->tableExists('activity_logs')) {
                $activities = ActivityLog::query()
                    ->where(function ($q) use ($user) {
                        $q->where(function ($inner) use ($user) {
                            $inner->where('subject_type', User::class)
                                ->where('subject_id', $user->id);
                        })->orWhere('user_id', $user->id);
                    })
                    ->latest('id')
                    ->limit(25)
                    ->get();
            }
        } catch (\Throwable) {
            $activities = collect();
        }

        try {
            $related = $this->userRelatedSnapshot($user);
        } catch (\Throwable $e) {
            report($e);
            $related = [];
        }
        $marketingCount = $this->marketingCount();
        $maxMarketing = self::MAX_MARKETING;

        return view('admin.users.show', compact(
            'user',
            'dossier',
            'sites',
            'notes',
            'activities',
            'related',
            'marketingCount',
            'maxMarketing'
        ));
    }

    public function suspend(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $blocked = $this->suspendGuard($request->user(), $user);
        if ($blocked !== null) {
            return $blocked;
        }

        if (! User::hasUsersColumn('suspended_at')) {
            return back()->with('error', 'Account suspension cannot be saved on this database.');
        }

        if ($user->isSuspended()) {
            return back()->with('error', 'This account is already suspended.');
        }

        $from = $user->suspended_at;
        $user->forceFill(User::existingAttributes([
            'suspended_at' => now(),
            'suspended_reason' => $data['reason'],
            'suspended_by' => $request->user()?->id,
        ]))->save();

        ActivityLogger::tryLog(
            'user.suspended',
            ($request->user()?->name ?? 'Admin').' suspended user #'.$user->id,
            $user,
            [
                'from' => $from,
                'to' => $user->suspended_at?->toIso8601String(),
                'reason' => $data['reason'],
            ],
            $user->name
        );

        return back()->with('success', $user->name.' has been suspended and cannot sign in.');
    }

    public function unsuspend(Request $request, User $user)
    {
        $blocked = $this->suspendGuard($request->user(), $user, allowAdmins: true);
        if ($blocked !== null) {
            return $blocked;
        }

        if (! User::hasUsersColumn('suspended_at')) {
            return back()->with('error', 'Account suspension cannot be saved on this database.');
        }

        if (! $user->isSuspended()) {
            return back()->with('error', 'This account is not suspended.');
        }

        $from = $user->suspended_at;
        $reason = $user->suspended_reason;
        $user->forceFill(User::existingAttributes([
            'suspended_at' => null,
            'suspended_reason' => null,
            'suspended_by' => null,
        ]))->save();

        ActivityLogger::tryLog(
            'user.unsuspended',
            ($request->user()?->name ?? 'Admin').' reactivated user #'.$user->id,
            $user,
            [
                'from' => $from?->toIso8601String(),
                'to' => null,
                'previous_reason' => $reason,
            ],
            $user->name
        );

        return back()->with('success', $user->name.' can sign in again.');
    }

    public function storeNote(Request $request, User $user)
    {
        $data = $request->validate([
            'body' => 'required|string|min:3|max:2000',
        ]);

        if (! UserAdminNote::tableAvailable()) {
            return back()->with('error', 'Admin notes cannot be saved on this database.');
        }

        $note = UserAdminNote::create([
            'user_id' => $user->id,
            'admin_id' => $request->user()?->id,
            'body' => trim($data['body']),
            'created_at' => now(),
        ]);

        ActivityLogger::tryLog(
            'user.note_added',
            ($request->user()?->name ?? 'Admin').' added an internal note on user #'.$user->id,
            $user,
            ['note_id' => $note->id],
            $user->name
        );

        return back()->with('success', 'Note saved.');
    }

    public function verifyEmail(Request $request, User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('success', 'This email is already verified.');
        }

        $from = $user->email_verified_at;
        $user->forceFill(['email_verified_at' => now()])->save();

        ActivityLogger::tryLog(
            'user.email_verified',
            ($request->user()?->name ?? 'Admin').' marked user #'.$user->id.' as verified',
            $user,
            [
                'from' => $from,
                'to' => $user->email_verified_at?->toIso8601String(),
            ],
            $user->name
        );

        return back()->with('success', $user->email.' is now verified. They can sign in.');
    }

    public function resendVerification(Request $request, User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('success', 'This email is already verified.');
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'Could not send the verification email.'));
        }

        ActivityLogger::tryLog(
            'user.verification_resent',
            ($request->user()?->name ?? 'Admin').' resent the verification email for user #'.$user->id,
            $user,
            [],
            $user->name
        );

        return back()->with('success', 'Verification email queued for '.$user->email.'.');
    }

    public function sendPasswordReset(Request $request, User $user)
    {
        $email = trim((string) $user->email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return back()->with('error', 'This account has no valid email for a reset link.');
        }

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'Could not send the password reset email.'));
        }

        if ($status === Password::RESET_THROTTLED) {
            return back()->with('error', 'A reset email was just sent. Wait a minute and try again.');
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return back()->with('error', 'Could not send the password reset email.');
        }

        ActivityLogger::tryLog(
            'user.password_reset_sent',
            ($request->user()?->name ?? 'Admin').' sent a password reset to user #'.$user->id,
            $user,
            [],
            $user->name
        );

        return back()->with('success', 'Password reset email queued for '.$email.'.');
    }

    // ✅ Update Company (AJAX)
    public function updateCompany(Request $request, $id)
    {
        $request->validate([
            'company_name' => 'nullable|string|max:255',
        ]);

        $user = User::findOrFail($id);

        if (! User::hasUsersColumn('company_name')) {
            return response()->json([
                'success' => false,
                'message' => 'Company name cannot be saved on this database.',
            ], 422);
        }

        try {

            $from = $user->company_name;
            $to = $request->input('company_name');
            $to = is_string($to) ? trim($to) : null;
            if ($to === '') {
                $to = null;
            }
            $fromNorm = ($from === null || $from === '') ? null : (string) $from;

            if ($fromNorm === $to) {
                return response()->json([
                    'success' => true,
                    'message' => 'Company updated successfully',
                ]);
            }

            $user->company_name = $to;
            $user->save();

            ActivityLogger::tryLog(
                'user.company_updated',
                (auth()->user()?->name ?? 'Admin').' updated company name for user #'.$user->id,
                $user,
                ['from' => $fromNorm, 'to' => $to, 'company_name' => $to],
                $user->name
            );

            return response()->json([
                'success' => true,
                'message' => 'Company updated successfully',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not update company name. Please try again.'),
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
        $before = $user->payoutProfile();
        try {
            $this->payoutProfiles->adminUpdateProfile($user, $method, $data);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Payout details cannot be saved on this database.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Payout details cannot be saved. Please try again.'),
            ], 500);
        }
        $after = $user->fresh()?->payoutProfile() ?? $user->payoutProfile();

        if (! $this->payoutDestinationsChanged($before, $after)) {
            return response()->json([
                'success' => true,
                'message' => 'Payout details are already up to date.',
                'payout_profile' => $after,
            ]);
        }

        try {
            Mail::to($user->email)->send(new PayoutProfileUpdatedBySupport($user->fresh(), $method));
        } catch (\Throwable $e) {
            report($e);
        }

        ActivityLogger::tryLog(
            'user.payout_profile_updated',
            (auth()->user()?->name ?? 'Admin').' updated payout profile for user #'.$user->id.' ('.$method.')',
            $user,
            ['method' => $method],
            'User #'.$user->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Payout details updated. The publisher was notified by email.',
            'payout_profile' => $after,
        ]);
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
        if (! $this->rolePivotAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Roles cannot be updated on this database.',
            ], 422);
        }
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

            return response()->json([
                'success' => false,
                'message' => 'Failed to update marketing access. Please try again.',
            ], 500);
        }

        try {
            $user->load('roles');
            $newRoles = $user->roles->pluck('name')->all();
        } catch (\Throwable $e) {
            report($e);
            $newRoles = $previousRoles;
            if ($grantMarketing && ! in_array('marketing', $newRoles, true)) {
                $newRoles[] = 'marketing';
            }
            if (! $grantMarketing) {
                $newRoles = array_values(array_filter($newRoles, fn ($name) => $name !== 'marketing'));
            }
        }
        $marketingCount = $this->marketingCount();
        $storedActivate = $canActivateSites;
        if ($hasActivateColumn) {
            try {
                $storedActivate = (bool) ($user->fresh()?->can_activate_sites ?? $canActivateSites);
            } catch (\Throwable) {
                $storedActivate = $canActivateSites;
            }
        }

        if ($grantMarketing !== $alreadyHasMarketing) {
            try {
                ActivityLogger::log(
                    $grantMarketing ? 'user.marketing_granted' : 'user.marketing_revoked',
                    (auth()->user()?->name ?? 'Admin').($grantMarketing ? ' granted' : ' revoked').' Marketing for '.$user->name,
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

    /**
     * @return array{q: string, role: string, status: string, flag: string, sort: string, user: int}
     */
    private function userIndexFilters(Request $request): array
    {
        $role = search_text($request->input('role'));
        if (! in_array($role, ['advertiser', 'publisher', 'admin', 'marketing'], true)) {
            $role = '';
        }

        $status = search_text($request->input('status'));
        if (! in_array($status, ['verified', 'unverified', 'suspended', 'active'], true)) {
            $status = '';
        }

        $flag = search_text($request->input('flag'));
        if (! in_array($flag, ['catalog_hide', 'copy_strike', 'payout_locked'], true)) {
            $flag = '';
        }

        $sort = search_text($request->input('sort'));
        if (! in_array($sort, ['oldest', 'name', 'last_seen'], true)) {
            $sort = 'newest';
        }

        return [
            'q' => search_text($request->input('q', $request->input('user_search'))),
            'role' => $role,
            'status' => $status,
            'flag' => $flag,
            'sort' => $sort,
            'user' => max(0, (int) (filter_number($request->input('user')) ?? 0)),
        ];
    }

    /**
     * @param  array{q: string, role: string, status: string, flag: string, sort: string, user: int}  $filters
     */
    private function applyUserIndexFilters($query, array $filters): void
    {
        if ($filters['user'] > 0) {
            $query->whereKey($filters['user']);
        }

        $q = $filters['q'];
        if ($q !== '') {
            $like = like_contains($q);
            $query->where(function ($inner) use ($like, $q) {
                $inner->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                if ($this->hasColumn('users', 'company_name')) {
                    $inner->orWhereRaw('company_name LIKE ? ESCAPE ?', [$like, '\\']);
                }
                if ($this->hasColumn('users', 'phone')) {
                    $inner->orWhereRaw('phone LIKE ? ESCAPE ?', [$like, '\\']);
                }
                if (ctype_digit($q)) {
                    $inner->orWhere('id', (int) $q);
                }
            });
        }

        $role = $filters['role'];
        if ($role !== '' && $this->rolePivotAvailable()) {
            $query->whereHas('roles', fn ($r) => $r->where('name', $role));
        }

        $status = $filters['status'];
        if ($status === 'verified') {
            $query->where(function ($inner) {
                $inner->whereEmailVerified()
                    ->orWhere(function ($google) {
                        $google->whereNotNull('google_id')->where('google_id', '!=', '');
                    });
            });
        } elseif ($status === 'unverified') {
            $query->whereEmailUnverified()
                ->where(function ($google) {
                    $google->whereNull('google_id')->orWhere('google_id', '');
                });
        } elseif ($status === 'suspended' && $this->hasColumn('users', 'suspended_at')) {
            $query->whereNotNull('suspended_at');
        } elseif ($status === 'active' && $this->hasColumn('users', 'suspended_at')) {
            $query->whereNull('suspended_at');
        }

        $flag = $filters['flag'] ?? '';
        if ($flag === 'catalog_hide' && $this->hasColumn('users', 'catalog_hide_until')) {
            $query->whereNotNull('catalog_hide_until')->where('catalog_hide_until', '>', now());
        } elseif ($flag === 'copy_strike' && $this->hasColumn('users', 'catalog_copy_strike_count')) {
            $query->where('catalog_copy_strike_count', '>=', 1);
        } elseif ($flag === 'payout_locked' && $this->hasColumn('users', 'payout_profile_locked_at')) {
            $query->whereNotNull('payout_profile_locked_at');
        }

        match ($filters['sort']) {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderBy('name')->orderByDesc('id'),
            'last_seen' => $this->hasColumn('users', 'last_seen_at')
                ? $query->orderByRaw('last_seen_at IS NULL')->orderByDesc('last_seen_at')->orderByDesc('id')
                : $query->latest('id'),
            default => $query->latest('id'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function userRelatedSnapshot(User $user): array
    {
        $email = trim((string) $user->email);
        $q = $email !== '' ? $email : null;
        $snapshot = [
            'sites_count' => 0,
            'sites_url' => null,
            'problems_count' => 0,
            'problems_url' => null,
            'claims_count' => 0,
            'claims_url' => null,
            'suggestions_count' => 0,
            'suggestions_url' => null,
            'websites_count' => 0,
            'websites_url' => null,
            'bulk_count' => 0,
            'bulk_url' => null,
            'catalog_url' => null,
        ];

        try {
            $snapshot['sites_url'] = staff_route('sites.index', ['publisher' => $user->id]);
            $snapshot['problems_url'] = route('admin.community.index', array_filter(['tab' => 'problems', 'q' => $q]));
            $snapshot['claims_url'] = route('admin.community.index', array_filter(['tab' => 'claims', 'q' => $q]));
            $snapshot['suggestions_url'] = route('admin.community.index', array_filter(['tab' => 'suggestions', 'q' => $q]));
            $snapshot['websites_url'] = route('admin.community.index', array_filter(['tab' => 'websites', 'q' => $q]));
            $snapshot['bulk_url'] = route('admin.bulk-site-requests.index', array_filter(['q' => $q, 'status' => 'all']));
            $snapshot['catalog_url'] = route('admin.catalog-activity.show', $user->id);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            if ($this->tableExists('sites') && $this->hasColumn('sites', 'publisher_id')) {
                $snapshot['sites_count'] = (int) $user->sites()->count();
            }
        } catch (\Throwable) {
        }

        try {
            if (ProblemReport::tableAvailable() && ProblemReport::hasTableColumn('user_id')) {
                $snapshot['problems_count'] = (int) ProblemReport::query()->where('user_id', $user->id)->count();
            }
        } catch (\Throwable) {
        }

        try {
            if (Suggestion::tableAvailable() && Suggestion::hasTableColumn('user_id')) {
                $snapshot['suggestions_count'] = (int) Suggestion::query()->where('user_id', $user->id)->count();
            }
        } catch (\Throwable) {
        }

        try {
            if (WebsiteSuggestion::tableAvailable() && WebsiteSuggestion::hasTableColumn('user_id')) {
                $snapshot['websites_count'] = (int) WebsiteSuggestion::query()->where('user_id', $user->id)->count();
            }
        } catch (\Throwable) {
        }

        try {
            if (SiteClaim::tableAvailable() && SiteClaim::hasTableColumn('claimer_id')) {
                $snapshot['claims_count'] = (int) SiteClaim::query()->where('claimer_id', $user->id)->count();
            }
        } catch (\Throwable) {
        }

        try {
            if ($this->tableExists('bulk_site_requests') && $this->hasColumn('bulk_site_requests', 'publisher_id')) {
                $snapshot['bulk_count'] = (int) BulkSiteRequest::query()->where('publisher_id', $user->id)->count();
            }
        } catch (\Throwable) {
        }

        return $snapshot;
    }

    private function suspendGuard(?User $actor, User $target, bool $allowAdmins = false)
    {
        if (! $actor || (! $actor->isAdmin() && ! $actor->hasRole('admin'))) {
            return back()->with('error', 'Only an admin can change account status.');
        }

        if ((int) $actor->id === (int) $target->id) {
            return back()->with('error', 'You cannot change the status of your own account.');
        }

        if (! $allowAdmins && $target->hasRole('admin')) {
            return back()->with('error', 'Admin accounts cannot be suspended from this screen.');
        }

        return null;
    }

    private function adminCount(): int
    {
        if (! $this->rolePivotAvailable()) {
            return 0;
        }

        try {
            $adminRoleId = Role::where('name', 'admin')->value('id');
            if (! $adminRoleId) {
                return 0;
            }

            return (int) DB::table('role_user')->where('role_id', $adminRoleId)->distinct()->count('user_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function marketingCount(): int
    {
        if (! $this->rolePivotAvailable()) {
            return 0;
        }

        try {
            $marketingRoleId = Role::where('name', 'marketing')->value('id');
            if (! $marketingRoleId) {
                return 0;
            }

            return (int) DB::table('role_user')->where('role_id', $marketingRoleId)->distinct()->count('user_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function rolePivotAvailable(): bool
    {
        return $this->tableExists('roles') && $this->tableExists('role_user');
    }

    private function tableExists(string $table): bool
    {
        try {
            if (! Schema::hasTable($table)) {
                return false;
            }
            // Schema::hasTable can stay true after a leftover DROP TABLE.
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return $this->tableExists($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function payoutDestinationsChanged(array $before, array $after): bool
    {
        foreach ([
            'preferred_method',
            'paypal_email',
            'wise_email',
            'bank_holder_name',
            'bank_name',
            'bank_account',
            'bank_swift',
            'crypto_wallet',
            'crypto_type',
        ] as $key) {
            if ((string) ($before[$key] ?? '') !== (string) ($after[$key] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
