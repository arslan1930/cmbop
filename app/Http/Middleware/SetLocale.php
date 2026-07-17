<?php

namespace App\Http\Middleware;

use App\Support\PublicI18n;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Authenticated SaaS + English-only auth pages always stay English.
        if (PublicI18n::isEnglishOnlyPath($request) || $this->isAuthenticatedAppPath($request)) {
            App::setLocale(PublicI18n::default());
            Session::put('locale', PublicI18n::default());

            return $next($request);
        }

        [$urlLocale] = PublicI18n::splitPath($request);

        if (PublicI18n::isPrefixed($urlLocale)) {
            $locale = $urlLocale;
        } elseif (PublicI18n::isPublicMarketingPath($request)) {
            // Unprefixed public URL = English (canonical)
            $locale = PublicI18n::default();
        } else {
            $locale = PublicI18n::default();
        }

        App::setLocale($locale);
        Session::put('locale', $locale);

        /** @var Response $response */
        $response = $next($request);

        // Remember public browsing language for logo/home links after English auth.
        if (PublicI18n::isPublicMarketingPath($request) && PublicI18n::isSupported($locale)) {
            $response->headers->setCookie(
                Cookie::make(
                    config('i18n.cookie', 'public_locale'),
                    $locale,
                    60 * 24 * 365,
                    '/',
                    null,
                    $request->isSecure(),
                    false,
                    false,
                    'Lax'
                )
            );
        }

        return $response;
    }

    private function isAuthenticatedAppPath(Request $request): bool
    {
        $first = $request->segment(1);

        return in_array($first, ['advertiser', 'publisher', 'admin'], true);
    }
}
