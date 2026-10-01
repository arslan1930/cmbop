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
        // Validate first: empty / array fields used to 500 on the rate-limit
        // key (`email[]=`) and still burned the 5-attempt budget.
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

        $email = function_exists('scalar_text')
            ? scalar_text($request->input('email'))
            : (string) $request->input('email');

        // 🔒 Rate limiting (5 attempts per minute per email + IP)
        $key = 'login:'.$request->ip().'|'.$email;

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

        $credentials = [
            'email' => $email,
            'password' => $request->input('password'),
        ];
        $remember = $request->boolean('remember');

        // Validate without Auth::attempt — login regenerates the session CSRF,
        // which 419s the same-page "Need a verification email?" fetch.
        if (! Auth::validate($credentials)) {
            return $this->invalidCredentialsResponse();
        }

        $user = Auth::getProvider()->retrieveByCredentials($credentials);
        if (! $user) {
            return $this->invalidCredentialsResponse();
        }

        // Same JSON as a bad password so login cannot confirm the account exists.
        if (method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
            return $this->invalidCredentialsResponse();
        }

        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return response()->json([
                'status' => 'error',
                'message' => $this->copy('login.suspended', 'This account has been suspended. Contact support if you think this is a mistake.'),
            ]);
        }

        Auth::login($user, $remember);
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
