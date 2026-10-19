<?php

namespace App\Http\Middleware;

use App\Support\PartnerDashboard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host-only partner session cookie, applied before StartSession.
 * Never sets Domain= so apex login cookies stay on seolinkbuildings.com.
 */
class ConfigurePartnerDashboardSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! class_exists(PartnerDashboard::class)) {
            return $next($request);
        }

        $onHost = PartnerDashboard::isPartnerHost($request);
        $onPath = PartnerDashboard::isPathFallbackRequest($request);
        if (! $onHost && ! $onPath) {
            return $next($request);
        }

        config([
            'session.cookie' => PartnerDashboard::sessionCookieName(),
            'session.domain' => null,
            'session.same_site' => 'lax',
            'session.http_only' => true,
            'session.path' => $onHost ? '/' : '/'.PartnerDashboard::pathPrefix(),
        ]);

        if ($onHost) {
            URL::forceRootUrl($request->getSchemeAndHttpHost());
            if ($request->secure() || app()->environment('production')) {
                URL::forceScheme('https');
            }
        }

        return $next($request);
    }
}
