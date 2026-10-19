<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChangedMail;
use App\Models\Partner;
use App\Models\User;
use App\Services\Partners\PartnerEmailActionService;
use App\Services\Partners\PartnerEmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $partner = Partner::forUser($user);

        return view('partner.settings.index', [
            'user' => $user,
            'partner' => $partner,
            'prefs' => $partner?->notification_preferences ?? [],
            'oneClick' => $partner?->oneClickLinksEnabled() ?? true,
            'pendingEmail' => $this->pendingEmail($user),
        ]);
    }

    public function updateName(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        $request->user()->forceFill(['name' => $data['name']])->save();

        return back()->with('success', 'Name saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => 'required',
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        $user = $request->user();
        if (! Hash::check($request->input('current_password'), $user->password)) {
            return back()->with('error', 'Current password is incorrect.');
        }
        $user->password = $request->input('password');
        $user->save();
        app(PartnerEmailActionService::class)->revokeAllForUser($user);
        if (class_exists(PasswordChangedMail::class) && method_exists(PasswordChangedMail::class, 'notify')) {
            PasswordChangedMail::notify($user);
        }
        Auth::logoutOtherDevices($request->input('password'));
        $request->session()->regenerate();
        $request->session()->put('partner_scope_level', 'full');

        return back()->with('success', 'Password changed. Other sessions were signed out.');
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $partner = Partner::forUser($request->user());
        if (! $partner) {
            return back()->with('error', 'Partner record not found.');
        }
        $prefs = [
            'new_order' => $request->boolean('new_order'),
            'correction' => $request->boolean('correction'),
            'payout_released' => true,
            'security' => true,
        ];
        $partner->notification_preferences = $prefs;
        if (Schema::hasColumn('partners', 'one_click_links')) {
            $partner->one_click_links = $request->boolean('one_click_links');
        }
        $partner->save();

        return back()->with('success', 'Notification preferences saved. Security and payout emails stay on.');
    }

    public function requestEmailChange(Request $request, PartnerEmailChangeService $changes): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => 'required',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->with('error', 'Current password is incorrect.');
        }
        if (strcasecmp($data['email'], (string) $user->email) === 0) {
            return back()->with('error', 'That is already your email address.');
        }
        $changes->request($user, $data['email']);

        return back()->with('success', 'Confirm the new address from the inbox we just emailed. The change applies only after that confirmation.');
    }

    private function pendingEmail(User $user): ?string
    {
        if (! Schema::hasTable('partner_email_change_requests')) {
            return null;
        }
        $row = DB::table('partner_email_change_requests')
            ->where('user_id', $user->id)
            ->whereNull('confirmed_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        return $row->new_email ?? null;
    }
}
