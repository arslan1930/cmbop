<?php

namespace App\Http\Middleware;

use App\Support\PartnerDashboard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the partners host: English only, noindex, never render the marketing site.
 */
class PartnerDashboardHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! class_exists(PartnerDashboard::class) || ! PartnerDashboard::isPartnerHost($request)) {
            return $next($request);
        }

        App::setLocale('en');

        if (! PartnerDashboard::allowsPath($request)) {
            return $this->noindex(redirect()->to(PartnerDashboard::url('/'), 302));
        }

        /** @var Response $response */
        $response = $next($request);

        return $this->noindex($response);
    }

    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
