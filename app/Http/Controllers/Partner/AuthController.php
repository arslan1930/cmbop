<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Mail\PartnerPasswordResetMail;
use App\Models\User;
use App\Services\Partners\PartnerEmailActionService;
use App\Support\PartnerDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('partner.auth.login', [
            'googleEnabled' => PartnerDashboard::googleConfigured(),
            'mainSiteUrl' => PartnerDashboard::mainSiteUrl('login'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->only('email'));
        }

        $email = strtolower(trim((string) $request->input('email')));
        $key = 'partner-login:'.$request->ip().'|'.$email;
        $ipKey = 'partner-login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            return back()->with('error', 'Too many login attempts. Please try again later.')->withInput($request->only('email'));
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($ipKey, 300);

        if (! Auth::validate(['email' => $email, 'password' => $request->input('password')])) {
            return back()->with('error', 'Invalid email or password.')->withInput($request->only('email'));
        }

        $user = Auth::getProvider()->retrieveByCredentials(['email' => $email, 'password' => $request->input('password')]);
        if (! $user instanceof User) {
            return back()->with('error', 'Invalid email or password.')->withInput($request->only('email'));
        }
        if (method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
            return back()->with('error', 'Invalid email or password.')->withInput($request->only('email'));
        }
        if (! PartnerDashboard::userMayAccess($user)) {
            return back()
                ->with('error', PartnerDashboard::denialMessage($user))
                ->with('main_site_url', PartnerDashboard::mainSiteUrl('login'))
                ->withInput($request->only('email'));
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('partner_scope_level', 'full');
        $request->session()->forget(['partner_scope_item_id', 'partner_scope_actions', 'partner_scope_max_at', 'partner_scope_idle_at']);
        RateLimiter::clear($key);
        RateLimiter::clear($ipKey);

        return redirect()->intended(PartnerDashboard::route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(PartnerDashboard::route('login'));
    }

    public function showForgot(): View
    {
        return view('partner.auth.forgot');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }
        $key = 'partner-forgot:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', 'Too many attempts. Please try again later.');
        }
        RateLimiter::hit($key, 600);

        Password::sendResetLink($request->only('email'), function (User $user, string $token) {
            if (PartnerDashboard::userMayAccess($user) || $user->isSupplyPartner()) {
                Mail::to($user->email)->send(new PartnerPasswordResetMail($user, $token));
            }
        });

        return back()->with('success', 'If an account with this email exists, a password reset link has been sent.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('partner.auth.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->only('email'));
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->password = $password;
                $user->save();
                app(PartnerEmailActionService::class)->revokeAllForUser($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->with('error', 'Invalid token or email.');
        }

        return redirect()->to(PartnerDashboard::route('login'))
            ->with('success', 'Password has been reset. Sign in to continue.');
    }
}
