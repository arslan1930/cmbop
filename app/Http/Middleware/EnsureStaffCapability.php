<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffCapability
{
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $allowed = [];
        foreach ($capabilities as $capability) {
            foreach (explode(',', $capability) as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $allowed[] = $part;
                }
            }
        }

        $service = app(StaffCapabilityService::class);
        $wantsUnrestricted = in_array('unrestricted', $allowed, true);
        $allowed = array_values(array_filter($allowed, fn (string $cap) => $cap !== 'unrestricted'));

        if ($wantsUnrestricted && ! $service->isUnrestricted($user)) {
            return $this->deny($request);
        }

        if ($allowed === []) {
            return $wantsUnrestricted ? $next($request) : $this->deny($request);
        }

        if ($service->allows($user, ...$allowed)) {
            return $next($request);
        }

        return $this->deny($request);
    }

    private function deny(Request $request): Response
    {
        $message = 'This area is limited to a different admin capability.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('error', $message);
    }
}
