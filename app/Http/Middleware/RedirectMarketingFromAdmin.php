<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marketing staff use /marketing/* — bounce leftover /admin links there when possible.
 */
class RedirectMarketingFromAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->isAdmin() || ! $user->hasRole('marketing')) {
            return $next($request);
        }

        // Role may still be Advertiser/Publisher until they open the marketing panel.
        if (! $user->isMarketing()) {
            $roleId = Role::where('name', 'marketing')->value('id');
            if ($roleId) {
                $user->active_role_id = $roleId;
                $user->save();
                $user->unsetRelation('activeRoleRelation');
                $user->unsetRelation('roles');
            }
        }

        $rest = ltrim((string) preg_replace('#^admin/?#', '', $request->path()), '/');

        if ($this->isMarketingOpsPath($rest)) {
            $target = '/marketing/'.($rest !== '' ? $rest : 'dashboard');
            if ($qs = $request->getQueryString()) {
                $target .= '?'.$qs;
            }

            // GET leftover /admin links can 302. Mutating leftover forms must
            // keep POST/PUT/DELETE + body — a 302 becomes GET /marketing/.../done
            // (no such route) and silently drops Done / reject / seed.
            $status = $request->isMethodSafe() ? 302 : 307;

            return redirect()->to($target, $status);
        }

        if ($request->expectsJson()) {
            abort(403, 'Marketing staff use the /marketing panel for site ops.');
        }

        return redirect()->route('marketing.dashboard');
    }

    private function isMarketingOpsPath(string $rest): bool
    {
        // Marketing home + personal history — not admin money AJAX under /dashboard/*
        if ($rest === '' || $rest === 'dashboard' || $rest === 'history' || str_starts_with($rest, 'history/')) {
            return true;
        }
        if (str_starts_with($rest, 'sites')) {
            // Verify and the records sheet stay admin-only. Activate is mirrored under /marketing.
            if (preg_match('#^sites/\d+/verify$#', $rest) === 1) {
                return false;
            }
            if (preg_match('#^sites/records(/export)?$#', $rest) === 1) {
                return false;
            }

            return true;
        }
        if ($rest === 'staff-handbook' || str_starts_with($rest, 'staff-handbook/')) {
            return true;
        }
        if (str_starts_with($rest, 'bulk-site-requests')) {
            return true;
        }
        if (str_starts_with($rest, 'site-enrichment')) {
            return true;
        }
        if (str_starts_with($rest, 'promotions')) {
            return ! str_contains($rest, 'welcome-bonus');
        }
        // AJAX: /admin/users/{id}/sites (not the Users admin page)
        if (preg_match('#^users/\d+/sites$#', $rest) === 1) {
            return true;
        }

        return false;
    }
}
