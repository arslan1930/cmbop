<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\Auth\StaffTwoFactorService;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class StaffTwoFactorController extends Controller
{
    public function __construct(
        private StaffTwoFactorService $twoFactor,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $this->twoFactor->holdsStaffRole($user)) {
            abort(403);
        }
        if ($this->twoFactor->isConfirmed($user)) {
            return back()->with('error', 'Two-factor authentication is already on.');
        }

        try {
            $this->twoFactor->startSetup($user);
        } catch (\Throwable $e) {
            Log::warning('Staff 2FA setup failed: '.$e->getMessage());

            return back()->with('error', UserFacingError::message($e, 'Could not start two-factor setup. Please try again.'));
        }

        return redirect()->to(route('profile').'#staff-two-factor');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $this->twoFactor->holdsStaffRole($user)) {
            abort(403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        try {
            $codes = $this->twoFactor->confirmSetup($user, $data['code']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('Staff 2FA confirm failed: '.$e->getMessage());

            return back()->with('error', UserFacingError::message($e, 'Could not enable two-factor authentication. Please try again.'));
        }

        $this->twoFactor->markSessionPassed($request, $user);
        $request->session()->flash('staff_2fa_recovery_codes', $codes);

        ActivityLogger::tryLog(
            'staff_two_factor.enabled',
            ($user->name ?? 'Staff').' enabled two-factor authentication',
            $user,
            ['user_id' => $user->id]
        );

        return redirect()->route('profile')->with('success', 'Two-factor authentication is on. Save your backup codes now — they are shown only once.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $this->twoFactor->holdsStaffRole($user)) {
            abort(403);
        }
        if (! $this->twoFactor->isConfirmed($user)) {
            return back()->with('success', 'Two-factor authentication is already off.');
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->with('error', 'Current password is incorrect.');
        }

        if (! $this->twoFactor->verifyLoginCode($user, $data['code'])) {
            return back()->with('error', 'That code is not valid. Try the next code from your app, or a backup code.');
        }

        $this->twoFactor->disable($user);
        $request->session()->forget(StaffTwoFactorService::PASSED_KEY);

        ActivityLogger::tryLog(
            'staff_two_factor.disabled',
            ($user->name ?? 'Staff').' disabled two-factor authentication',
            $user,
            ['user_id' => $user->id]
        );

        return back()->with('success', 'Two-factor authentication is off. Staff sign-in will not ask for an app code until you turn it on again.');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $this->twoFactor->holdsStaffRole($user) || ! $this->twoFactor->isConfirmed($user)) {
            abort(403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes($user, $data['code']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('Staff 2FA recovery regenerate failed: '.$e->getMessage());

            return back()->with('error', UserFacingError::message($e, 'Could not replace backup codes. Please try again.'));
        }

        $request->session()->flash('staff_2fa_recovery_codes', $codes);

        ActivityLogger::tryLog(
            'staff_two_factor.recovery_regenerated',
            ($user->name ?? 'Staff').' replaced two-factor backup codes',
            $user,
            ['user_id' => $user->id]
        );

        return redirect()->route('profile')->with('success', 'New backup codes are ready. Save them now — they are shown only once. Old codes no longer work.');
    }
}
