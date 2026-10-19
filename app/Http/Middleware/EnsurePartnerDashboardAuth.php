<?php

namespace App\Http\Middleware;

use App\Support\PartnerDashboard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerDashboardAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->guest(PartnerDashboard::route('login'));
        }

        $user = Auth::user();
        if (! PartnerDashboard::userMayAccess($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', PartnerDashboard::denialMessage($user))
                ->with('main_site_url', PartnerDashboard::mainSiteUrl('login'));
        }

        if ($this->scopedSessionExpired($request)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', 'That one-click link has expired. Sign in to continue.');
        }

        $this->touchScopedSession($request);

        if ($this->isScoped($request) && $this->requiresFullLogin($request)) {
            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', 'Sign in to open money and settings.');
        }

        if ($this->isScoped($request) && ! $this->scopedOrderAllowed($request)) {
            return redirect()->to(PartnerDashboard::route('login'))
                ->with('error', 'Sign in to open other pages.');
        }

        return $next($request);
    }

    private function isScoped(Request $request): bool
    {
        return $request->session()->get('partner_scope_level') === 'order';
    }

    private function requiresFullLogin(Request $request): bool
    {
        $path = trim($request->path(), '/');
        foreach (['money', 'settings'] as $prefix) {
            if ($path === $prefix || str_ends_with($path, '/'.$prefix) || str_contains($path, '/'.$prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function scopedOrderAllowed(Request $request): bool
    {
        $itemId = (int) $request->session()->get('partner_scope_item_id');
        if ($itemId <= 0) {
            return false;
        }
        $routeItem = (int) $request->route('order');
        if ($routeItem <= 0) {
            $routeItem = (int) $request->route('id');
        }

        return $routeItem === $itemId;
    }

    private function touchScopedSession(Request $request): void
    {
        if (! $this->isScoped($request)) {
            return;
        }
        $idle = max(5, (int) config('partners.dashboard_session_idle_minutes', 30));
        $request->session()->put('partner_scope_idle_at', now()->addMinutes($idle)->timestamp);
    }

    private function scopedSessionExpired(Request $request): bool
    {
        if (! $this->isScoped($request)) {
            return false;
        }
        $maxAt = (int) $request->session()->get('partner_scope_max_at', 0);
        $idleAt = (int) $request->session()->get('partner_scope_idle_at', 0);
        $now = now()->timestamp;

        return ($maxAt > 0 && $now > $maxAt) || ($idleAt > 0 && $now > $idleAt);
    }
}
