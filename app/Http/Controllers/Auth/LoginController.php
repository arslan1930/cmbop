<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
                'message' => $this->copy('login.throttled', 'Too many login attempts. Please try again later.'),
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
                'message' => $this->copy('register.validation', 'Please fix the highlighted fields and try again.'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Attempt login
        if (! Auth::attempt($credentials, $remember)) {
            return $this->invalidCredentialsResponse();
        }

        $user = Auth::user();

        // Same JSON as a bad password so login cannot confirm the account exists.
        if (method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->invalidCredentialsResponse();
        }

        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'status' => 'error',
                'message' => $this->copy('login.suspended', 'This account has been suspended. Contact support if you think this is a mistake.'),
            ]);
        }

        $request->session()->regenerate();

        // Relative dashboard path — survives APP_URL=localhost misconfig
        $user->load('activeRoleRelation', 'roles');
        $redirect = method_exists($user, 'getDashboardRoute')
            ? $user->getDashboardRoute()
            : '/';

        RateLimiter::clear($key);
        RateLimiter::clear($ipKey);

        return response()->json([
            'status' => 'success',
            'message' => $this->copy('login.success', 'Login successful!'),
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
            'message' => $this->copy('login.invalid', 'Invalid email or password.'),
        ]);
    }

    private function copy(string $key, string $fallback): string
    {
        return function_exists('user_message')
            ? user_message($key, $fallback)
            : $fallback;
    }
}
