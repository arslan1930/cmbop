<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\StaffTwoFactorService;
use App\Support\UserMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private StaffTwoFactorService $twoFactor,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->twoFactor->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->with('error', UserMessages::get('login.two_factor_expired'));
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->twoFactor->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->with('error', UserMessages::get('login.two_factor_expired'));
        }

        $key = 'staff-2fa:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', UserMessages::get('login.throttled'));
        }

        $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        RateLimiter::hit($key, 60);

        if (! $this->twoFactor->verifyLoginCode($user, (string) $request->input('code'))) {
            return back()->with('error', UserMessages::get('login.two_factor_invalid'));
        }

        RateLimiter::clear($key);

        $remember = $this->twoFactor->pendingRemember($request);
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $this->twoFactor->markSessionPassed($request, $user);

        $user->load('activeRoleRelation', 'roles');

        return redirect()->to($user->getDashboardRoute());
    }
}
