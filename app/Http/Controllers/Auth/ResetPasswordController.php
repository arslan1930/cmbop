<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

class ResetPasswordController extends Controller
{
    public function show($token)
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required','confirmed','min:8'],
        ]);

        // Rate limiting: max 5 resets per 10 minutes per IP
        $key = 'reset:' . $request->ip();
        if(RateLimiter::tooManyAttempts($key, 5)){
            return response()->json([
                'status'=>'error',
                'message'=>'Too many attempts. Try again later.'
            ]);
        }
        RateLimiter::hit($key, 600);

        $status = Password::reset(
            $request->only('email','password','password_confirmation','token'),
            function($user, $password){
                $user->password = bcrypt($password);
                $user->save();
            }
        );

        return response()->json([
            'status' => $status === Password::PASSWORD_RESET ? 'success':'error',
            'message' => $status === Password::PASSWORD_RESET
                ? 'Password has been reset successfully.'
                : 'Invalid token or email.'
        ]);
    }
}