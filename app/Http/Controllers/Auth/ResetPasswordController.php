<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChangedMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ResetPasswordController extends Controller
{
    public function show($token)
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation',
                'message' => $this->copy('register.validation', 'Please fix the highlighted fields and try again.'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = 'reset:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $retry = max(RateLimiter::availableIn($key), 1);

            return response()->json([
                'status' => 'error',
                'message' => $this->copy('password.reset_throttled', 'Too many attempts. Try again later.'),
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($key, 600);

        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) use ($request) {
                    // Hashed cast hashes once — do not bcrypt here or login breaks.
                    $user->password = $password;
                    $user->save();
                    if (class_exists(PasswordChangedMail::class) && method_exists(PasswordChangedMail::class, 'notify')) {
                        PasswordChangedMail::notify($user);
                    }

                    try {
                        if (Auth::check() && (int) Auth::id() === (int) $user->id) {
                            Auth::logoutOtherDevices($password);
                            $request->session()->regenerate();

                            return;
                        }

                        if (Schema::hasTable('sessions')) {
                            DB::table('sessions')->where('user_id', $user->id)->delete();
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Could not invalidate other sessions after password reset', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            );
        } catch (\Throwable $e) {
            Log::error('Password reset failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $this->copy('generic.retry', 'Something went wrong. Please try again.'),
            ], 503);
        }

        return response()->json([
            'status' => $status === Password::PASSWORD_RESET ? 'success' : 'error',
            'message' => $status === Password::PASSWORD_RESET
                ? $this->copy('password.reset_success', 'Password has been reset successfully.')
                : $this->copy('password.reset_invalid', 'Invalid token or email.'),
        ]);
    }

    private function copy(string $key, string $fallback): string
    {
        return function_exists('user_message')
            ? user_message($key, $fallback)
            : $fallback;
    }
}
