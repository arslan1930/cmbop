<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class ForgotPasswordController extends Controller
{
    public function show()
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation',
                'message' => $this->copy('register.validation', 'Please fix the highlighted fields and try again.'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = 'forgot:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $retry = max(RateLimiter::availableIn($key), 1);

            return response()->json([
                'status' => 'error',
                'message' => $this->copy('password.throttled', 'Too many attempts. Please try again later.'),
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($key, 600);

        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            Log::error('Forgot password reset link failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $this->copy('generic.retry', 'Something went wrong. Please try again.'),
            ], 503);
        }

        return response()->json([
            'status' => 'success',
            'message' => $this->copy('password.reset_sent', 'If an account with this email exists, a password reset link has been sent.'),
        ]);
    }

    private function copy(string $key, string $fallback): string
    {
        return function_exists('user_message')
            ? user_message($key, $fallback)
            : $fallback;
    }
}
