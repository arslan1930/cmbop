<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

class ForgotPasswordController extends Controller
{
    public function show()
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        // Rate limiting: max 5 attempts per 10 minutes per IP
        $key = 'forgot:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status'=>'error',
                'message'=>'Too many attempts. Please try again later.'
            ]);
        }
        RateLimiter::hit($key, 600);

        // Send reset link (generic message even if email doesn't exist)
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'status'=>'success',
            'message'=>'If an account with this email exists, a password reset link has been sent.'
        ]);
    }
}