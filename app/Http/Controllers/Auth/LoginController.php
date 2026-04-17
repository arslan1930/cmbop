<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;
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
        $key = 'login:' . $request->ip() . '|' . $request->email;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Too many login attempts. Please try again later.'
            ]);
        }

        RateLimiter::hit($key, 60); // 60 seconds

        // ✅ Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'g-recaptcha-response' => 'required'
        ], [
            'g-recaptcha-response.required' => 'Please verify that you are not a robot.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation',
                'errors' => $validator->errors()
            ]);
        }

        // 🔒 Validate reCAPTCHA
        $recaptcha = Http::asForm()->post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
                'secret'   => env('GOOGLE_RECAPTCHA_SECRET_KEY'),
                'response' => $request->input('g-recaptcha-response'),
                'remoteip' => $request->ip(),
            ]
        );

        if (!$recaptcha->json('success')) {
            return response()->json([
                'status' => 'error',
                'message' => 'reCAPTCHA verification failed. Please try again.'
            ]);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember'); // ✅ added

        // Attempt login
        if (!Auth::attempt($credentials, $remember)) { // ✅ updated
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password.'
            ]);
        }

        $user = Auth::user();

        // 🚨 Email verification check (UPDATED)
        if (!$user->hasVerifiedEmail()) {
            Auth::logout();

            return response()->json([
                'status' => 'unverified',
                'message' => 'Your email is not verified.',
                'email' => $user->email
            ]);
        }

        // ✅ FIX: use active_role_id via model
        $user->load('activeRoleRelation', 'roles');
        $redirect = $user->getDashboardRoute();

        // ✅ Clear rate limiter on successful login
        RateLimiter::clear($key);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful!',
            'redirect' => $redirect
        ]);
    }

    /**
     * Logout
     */
    public function logout()
    {
        Auth::logout();
        return redirect('/');
    }
}