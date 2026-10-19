<?php

namespace App\Support;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\Request;

class PartnerDashboard
{
    public static function host(): string
    {
        $host = strtolower(trim((string) config('partners.dashboard_domain', 'partners.seolinkbuildings.com')));

        return $host !== '' ? $host : 'partners.seolinkbuildings.com';
    }

    public static function pathPrefix(): string
    {
        $prefix = trim((string) config('partners.dashboard_path_prefix', 'partners'), '/');

        return $prefix !== '' ? $prefix : 'partners';
    }

    public static function pathFallbackEnabled(): bool
    {
        $explicit = config('partners.dashboard_path_fallback');
        if ($explicit === true || $explicit === 1 || $explicit === '1' || $explicit === 'true') {
            return true;
        }
        if ($explicit === false || $explicit === 0 || $explicit === '0' || $explicit === 'false') {
            return false;
        }

        return app()->environment('local', 'testing');
    }

    public static function allowAnyPublisher(): bool
    {
        return (bool) config('partners.dashboard_allow_all_publishers', false);
    }

    public static function isPartnerHost(?Request $request = null): bool
    {
        $request ??= request();
        if (! $request) {
            return false;
        }

        return strtolower((string) $request->getHost()) === self::host();
    }

    public static function isPathFallbackRequest(?Request $request = null): bool
    {
        if (! self::pathFallbackEnabled()) {
            return false;
        }
        $request ??= request();
        if (! $request) {
            return false;
        }
        $prefix = self::pathPrefix();
        $path = trim($request->path(), '/');

        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    public static function isDashboardRequest(?Request $request = null): bool
    {
        $request ??= request();

        return self::isPartnerHost($request) || self::isPathFallbackRequest($request);
    }

    public static function origin(?Request $request = null): string
    {
        $request ??= request();
        $production = app()->environment('production');
        $scheme = $production ? 'https' : (string) ($request?->getScheme() ?: 'http');
        if ($scheme !== 'https' && $scheme !== 'http') {
            $scheme = 'http';
        }

        return $scheme.'://'.self::host();
    }

    public static function url(string $path = '/', array $query = []): string
    {
        $path = '/'.ltrim($path, '/');
        if ($path === '/index.php') {
            $path = '/';
        }
        $base = self::isPathFallbackRequest() && ! self::isPartnerHost()
            ? rtrim((string) request()->getSchemeAndHttpHost(), '/').'/'.self::pathPrefix()
            : self::origin();
        $url = rtrim($base, '/').($path === '/' ? '/' : $path);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }

    public static function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        $prefix = self::isPathFallbackRequest() && ! self::isPartnerHost()
            ? 'partner.path.'
            : 'partner.';

        try {
            return route($prefix.$name, $parameters, $absolute);
        } catch (\Throwable) {
            try {
                return route('partner.'.$name, $parameters, $absolute);
            } catch (\Throwable) {
                return self::url('/');
            }
        }
    }

    public static function mainSiteUrl(string $path = '/'): string
    {
        $origin = function_exists('app_public_url') ? rtrim(app_public_url(), '/') : rtrim((string) config('app.url'), '/');

        return $origin.'/'.ltrim($path, '/');
    }

    public static function sessionCookieName(): string
    {
        return (string) config('session.cookie', 'laravel-session').'-partner';
    }

    /**
     * Paths served on the partners host. Anything else redirects home.
     *
     * @return list<string>
     */
    public static function allowedPathPrefixes(): array
    {
        return [
            'login',
            'logout',
            'forgot-password',
            'reset-password',
            'onboard/password',
            'auth/google',
            'orders',
            'sites',
            'money',
            'settings',
            'a',
            'email-change',
            'robots.txt',
            'up',
        ];
    }

    public static function allowsPath(?Request $request = null): bool
    {
        $request ??= request();
        $path = trim($request->path(), '/');
        if ($path === '' || $path === '/') {
            return true;
        }
        foreach (self::allowedPathPrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public static function userMayAccess(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return false;
        }
        $partner = Partner::forUser($user);
        if ($partner) {
            if ($partner->isLockedForSecurityReview()) {
                return false;
            }

            return $partner->isActive() && $user->roles()->where('roles.name', 'publisher')->exists();
        }

        if (! self::allowAnyPublisher()) {
            return false;
        }

        return $user->roles()->where('roles.name', 'publisher')->exists();
    }

    public static function denialMessage(?User $user): string
    {
        if ($user && method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return 'This account has been suspended. Contact support if you think this is a mistake.';
        }
        $partner = $user ? Partner::forUser($user) : null;
        if ($partner && $partner->isLockedForSecurityReview()) {
            return 'This account is locked for a security review. Email partners@seolinkbuildings.com.';
        }
        if ($partner && ! $partner->isActive()) {
            return 'This partner account is not active. Email partners@seolinkbuildings.com.';
        }

        return 'This dashboard is for SEOLinkBuildings catalog partners. Sign in on the main site if you have an advertiser or publisher account.';
    }

    public static function googleConfigured(): bool
    {
        return function_exists('google_oauth_configured') && google_oauth_configured();
    }
}
