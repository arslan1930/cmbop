<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserFacingError;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EmailVerificationController extends Controller
{
    public function notice(Request $request)
    {
        $user = $request->user();
        if ($user && method_exists($user, 'hasVerifiedEmail') && $user->hasVerifiedEmail()) {
            $home = method_exists($user, 'getDashboardRoute')
                ? $user->getDashboardRoute()
                : '/';

            return redirect($home);
        }

        $email = old('email', $request->session()->get('verify_email', ''));

        return view('auth.verify-email', [
            'prefillEmail' => is_string($email) ? $email : '',
            'authenticatedUnverified' => $user !== null,
        ]);
    }

    public function verify(Request $request, $id, $hash)
    {
        // Relative signature — host/scheme must not be part of the HMAC (email
        // links are prefixed with a public origin that may differ from APP_URL).
        // Ignore tracker params email clients often append (utm_*, fbclid, …).
        if (! $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params())) {
            return redirect()->route('verification.notice')->with(
                'error',
                $this->copy(
                    'verification.link_expired',
                    'This verification link is invalid or has expired. Enter your email below to request a new one.'
                )
            );
        }

        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        // Do not auto-login — send the user to sign in manually after verify.
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect('/login')->with(
            'message',
            $this->copy('verification.verified', 'Email verified successfully. Please sign in to continue.')
        );
    }

    public function send(Request $request)
    {
        $user = $request->user();

        if (method_exists($user, 'hasVerifiedEmail') && $user->hasVerifiedEmail()) {
            $home = method_exists($user, 'getDashboardRoute')
                ? $user->getDashboardRoute()
                : route('login', absolute: false);

            return redirect($home)->with(
                'info',
                $this->copy('verification.already', 'This email is already verified. Please sign in.')
            );
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'Could not send the verification email. Please try again.')
            );
        }

        return back()->with(
            'success',
            $this->copy('verification.sent', 'A new verification link has been sent to your email address.')
        );
    }

    public function resend(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            if ($this->wantsJson($request)) {
                return response()->json([
                    'status' => 'validation',
                    'message' => $this->copy('register.validation', 'Please fix the highlighted fields and try again.'),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return back()
                ->withErrors($validator)
                ->withInput();
        }

        $email = function_exists('scalar_text')
            ? scalar_text($request->input('email'))
            : (string) $request->input('email');
        $email = trim($email);

        try {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->first();

            if ($user && method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()
                && method_exists($user, 'sendEmailVerificationNotification')) {
                $user->sendEmailVerificationNotification();
            }
        } catch (\Throwable $e) {
            Log::error('Verification resend failed', ['error' => $e->getMessage()]);

            $message = $this->copy('generic.retry', 'Something went wrong. Please try again.');

            if ($this->wantsJson($request)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $message,
                ], 503);
            }

            return back()
                ->withInput()
                ->with('error', $message)
                ->with('verify_email', $email);
        }

        $message = $this->copy(
            'verification.resent',
            'If that email is registered and still unverified, a new link is on its way.'
        );

        if ($this->wantsJson($request)) {
            return response()->json([
                'status' => 'success',
                'message' => $message,
            ]);
        }

        return back()
            ->withInput()
            ->with('info', $message)
            ->with('verify_email', $email);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->wantsJson();
    }

    private function copy(string $key, string $fallback): string
    {
        return function_exists('user_message')
            ? user_message($key, $fallback)
            : $fallback;
    }
}
