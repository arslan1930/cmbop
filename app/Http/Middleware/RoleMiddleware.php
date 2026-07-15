<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Supports one or more roles, e.g. RoleMiddleware:admin or RoleMiddleware:admin,marketing
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if (empty($roles)) {
            abort(403, 'Unauthorized: No role specified.');
        }

        // Flatten comma-separated role lists if any
        $allowed = [];
        foreach ($roles as $role) {
            foreach (explode(',', $role) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $allowed[] = $part;
                }
            }
        }

        // User must have at least one of the allowed roles
        $userRoleNames = $user->roles()->pluck('name')->all();
        if (count(array_intersect($allowed, $userRoleNames)) === 0) {
            abort(403, 'Unauthorized: You do not have this role.');
        }

        // Active role must be one of the allowed roles
        if (!in_array($user->activeRole(), $allowed, true)) {
            abort(403, 'Unauthorized: This role is not active.');
        }

        return $next($request);
    }
}
