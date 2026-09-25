<?php

namespace App\Http\Middleware;

use App\Support\PublicI18n;
use App\Support\ViewerCountry;
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
        if (! class_exists(PublicI18n::class)) {
            App::setLocale((string) config('i18n.default', 'en'));

            return $next($request);
        }

        $defaultLocale = method_exists(PublicI18n::class, 'default')
            ? PublicI18n::default()
            : (string) config('i18n.default', 'en');

        // Authenticated SaaS + English-only auth pages always stay English.
        $englishOnly = method_exists(PublicI18n::class, 'isEnglishOnlyPath')
            && PublicI18n::isEnglishOnlyPath($request);
        if ($englishOnly || $this->isAuthenticatedAppPath($request)) {
            App::setLocale($defaultLocale);
            Session::put('locale', $defaultLocale);

            return $next($request);
        }

        $urlLocale = null;
        if (method_exists(PublicI18n::class, 'splitPath')) {
            [$urlLocale] = PublicI18n::splitPath($request);
        }

        if ($request->isMethod('GET') && ! $request->ajax()) {
            $located = $this->redirectForLocation($request, $urlLocale);
            if ($located !== null) {
                return $located;
            }
        }

        if (method_exists(PublicI18n::class, 'isPrefixed') && PublicI18n::isPrefixed($urlLocale)) {
            $locale = $urlLocale;
        } elseif (method_exists(PublicI18n::class, 'isPublicMarketingPath') && PublicI18n::isPublicMarketingPath($request)) {
            // Unprefixed public URL = English (canonical)
            $locale = $defaultLocale;
        } else {
            $locale = $defaultLocale;
        }

        App::setLocale($locale);
        $messagesFallback = method_exists(PublicI18n::class, 'messagesFallback')
            ? PublicI18n::messagesFallback($locale)
            : null;
        if ($messagesFallback !== null && $messagesFallback !== $locale) {
            App::setFallbackLocale($messagesFallback);
        }
        Session::put('locale', $locale);

        /** @var Response $response */
        $response = $next($request);

        // Remember public browsing language for logo/home links after English auth.
        if (method_exists(PublicI18n::class, 'isPublicMarketingPath')
            && method_exists(PublicI18n::class, 'isSupported')
            && PublicI18n::isPublicMarketingPath($request)
            && PublicI18n::isSupported($locale)) {
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

    /**
     * Unprefixed public pages follow the visitor country, then a saved locale cookie.
     * An explicit /de or /us URL is left alone. Login and the signed-in app stay English.
     */
    private function redirectForLocation(Request $request, ?string $urlLocale): ?Response
    {
        if (! method_exists(PublicI18n::class, 'isPublicMarketingPath')
            || ! PublicI18n::isPublicMarketingPath($request)
            || (method_exists(PublicI18n::class, 'isPrefixed') && PublicI18n::isPrefixed($urlLocale))
            || (method_exists(PublicI18n::class, 'isEnglishOnlyMarketingPath') && PublicI18n::isEnglishOnlyMarketingPath($request))) {
            return null;
        }

        $cookieName = (string) config('i18n.cookie', 'public_locale');
        $remembered = $request->cookie($cookieName);
        $locale = null;
        if (is_string($remembered) && method_exists(PublicI18n::class, 'isPrefixed') && PublicI18n::isPrefixed($remembered)) {
            $locale = $remembered;
        } elseif ($remembered === null || $remembered === '') {
            $country = app(ViewerCountry::class)->code($request);
            $fromCountry = method_exists(PublicI18n::class, 'localeForCountry')
                ? PublicI18n::localeForCountry($country)
                : null;
            if ($fromCountry !== null && PublicI18n::isPrefixed($fromCountry)) {
                $locale = $fromCountry;
            }
        }

        if ($locale === null || ! method_exists(PublicI18n::class, 'switchUrl')) {
            return null;
        }

        $target = PublicI18n::switchUrl($request, $locale);
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?').$query;
        }

        if ($target === $request->fullUrl() || $target === $request->url()) {
            return null;
        }

        return redirect()->to($target, 302);
    }

    private function isAuthenticatedAppPath(Request $request): bool
    {
        $first = $request->segment(1);

        return in_array($first, ['advertiser', 'publisher', 'admin'], true);
    }
}
