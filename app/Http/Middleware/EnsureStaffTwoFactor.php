<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\StaffTwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $twoFactor = app(StaffTwoFactorService::class);
        if (! $twoFactor->mustChallenge($user) || $twoFactor->sessionPassed($request, $user)) {
            return $next($request);
        }

        Auth::logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = 'Enter the authentication code from your authenticator app to continue.';

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 403);
        }

        return redirect()->route('login')->with('error', $message);
    }
}
