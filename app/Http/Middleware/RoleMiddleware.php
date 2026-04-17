<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $role  Role to check
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $role)
    {
        $user = $request->user();

        if (!$user) {
            // Not logged in
            return redirect()->route('login');
        }

        // Ensure user has the role
        if (!$user->hasRole($role)) {
            abort(403, 'Unauthorized: You do not have this role.');
        }

        // Ensure role is currently active
        if ($user->activeRoleModel()?->name !== $role) {
            abort(403, 'Unauthorized: This role is not active.');
        }

        return $next($request);
    }
}