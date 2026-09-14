<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\StaffTwoFactorService;
use App\Support\UserMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    /**
     * Show login form
     */
    public function show()
    {
        return view('auth.login');
    }

    /**
     * Handle login (AJAX)
     */
    public function login(Request $request)
    {
        // 🔒 Rate limiting (5 attempts per minute per email + IP)
        $key = 'login:'.$request->ip().'|'.$request->email;

        // Per-IP budget as well: the email+IP key alone lets one host spray
        // credentials across many accounts without ever tripping the limit.
        $ipKey = 'login-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $retry = max(
                RateLimiter::availableIn($key),
                RateLimiter::availableIn($ipKey),
                1
            );

            return response()->json([
                'status' => 'error',
                'message' => UserMessages::get('login.throttled'),
            ], 429)->header('Retry-After', (string) $retry);
        }

        RateLimiter::hit($key, 60); // 60 seconds
        RateLimiter::hit($ipKey, 300); // 5 minutes

        // ✅ Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation',
                'errors' => $validator->errors(),
            ]);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Attempt login
        if (! Auth::attempt($credentials, $remember)) {
            return $this->invalidCredentialsResponse();
        }

        $user = Auth::user();

        // Same JSON as a bad password so login cannot confirm the account exists.
        if (! $user->hasVerifiedEmail()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->invalidCredentialsResponse();
        }

        if ($user->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'status' => 'error',
                'message' => UserMessages::get('login.suspended'),
            ]);
        }

        $request->session()->regenerate();

        RateLimiter::clear($key);
        RateLimiter::clear($ipKey);

        $twoFactor = app(StaffTwoFactorService::class);
        if ($twoFactor->mustChallenge($user)) {
            $remember = $request->boolean('remember');
            Auth::logout();
            $twoFactor->beginPending($request, $user, $remember);

            return response()->json([
                'status' => 'two_factor',
                'message' => UserMessages::get('login.two_factor'),
                'redirect' => route('login.two-factor', absolute: false),
            ]);
        }

        if ($request->boolean('remember')) {
            Auth::login($user, true);
        }

        $twoFactor->markSessionPassed($request, $user);

        // Relative dashboard path — survives APP_URL=localhost misconfig
        $user->load('activeRoleRelation', 'roles');
        $redirect = $user->getDashboardRoute();

        return response()->json([
            'status' => 'success',
            'message' => UserMessages::get('login.success'),
            'redirect' => $redirect,
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function invalidCredentialsResponse()
    {
        return response()->json([
            'status' => 'error',
            'message' => UserMessages::get('login.invalid'),
        ]);
    }
}
