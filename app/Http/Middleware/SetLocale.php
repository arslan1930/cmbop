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
            $explicit = $this->redirectForExplicitLocale($request);
            if ($explicit !== null) {
                return $explicit;
            }
            // Geo-IP redirects of unprefixed URLs are intentionally skipped.
            // They 302'd US visitors from /blog/{slug} onto /us/blog (or looped
            // with BlogController's fallback 301), which GSC reports as Redirect error.
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
     * Language switcher (?locale= / ?hl=) wins. The clean URL is kept and the
     * public_locale cookie is set so a leftover /us cookie cannot steal English.
     * Unprefixed home also stores the visitor-country locale once when no cookie exists.
     */
    private function redirectForExplicitLocale(Request $request): ?Response
    {
        if (! method_exists(PublicI18n::class, 'isPublicMarketingPath')
            || ! PublicI18n::isPublicMarketingPath($request)
            || ! method_exists(PublicI18n::class, 'urlForLocale')
            || ! method_exists(PublicI18n::class, 'pathWithoutLocale')) {
            return null;
        }

        $path = PublicI18n::pathWithoutLocale($request);
        $requested = method_exists(PublicI18n::class, 'requestedLocale')
            ? PublicI18n::requestedLocale($request)
            : null;

        if ($requested !== null) {
            $target = PublicI18n::urlForLocale($path, $requested);
            $query = $request->query();
            unset($query['locale'], $query['hl']);
            if ($query !== []) {
                $target .= (str_contains($target, '?') ? '&' : '?').http_build_query($query);
            }

            $redirect = redirect()->to($target, 302);
            if (method_exists(PublicI18n::class, 'localeCookie')) {
                $redirect->headers->setCookie(PublicI18n::localeCookie($requested, $request));
            }

            return $redirect;
        }

        if ($path !== ''
            || (method_exists(PublicI18n::class, 'isPrefixed') && PublicI18n::isPrefixed(
                method_exists(PublicI18n::class, 'splitPath') ? PublicI18n::splitPath($request)[0] : null
            ))) {
            return null;
        }

        if ($this->isSearchEngineBot($request)) {
            return null;
        }

        $cookieName = (string) config('i18n.cookie', 'public_locale');
        if (trim((string) $request->cookie($cookieName)) !== '') {
            return null;
        }

        $fromCountry = method_exists(PublicI18n::class, 'localeForCountry')
            ? PublicI18n::localeForCountry(app(ViewerCountry::class)->code($request))
            : null;
        if ($fromCountry === null
            || (method_exists(PublicI18n::class, 'isPrefixed') && ! PublicI18n::isPrefixed($fromCountry))
            || ! method_exists(PublicI18n::class, 'localeCookie')) {
            return null;
        }

        return redirect()->to($request->url(), 302)
            ->withCookie(PublicI18n::localeCookie($fromCountry, $request));
    }

    /**
     * Leftover Hostinger copies may still call this. Do not geo-redirect
     * unprefixed URLs — that 302'd /blog/{slug} onto /us/blog for US IPs
     * and looped with the fallback 301 from /us/blog/{en-slug}.
     */
    private function redirectForLocation(Request $request, ?string $urlLocale): ?Response
    {
        unset($request, $urlLocale);

        return null;
    }

    private function isSearchEngineBot(Request $request): bool
    {
        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return false;
        }

        foreach (['googlebot', 'bingbot', 'slurp', 'duckduckbot', 'yandex', 'baiduspider', 'applebot'] as $bot) {
            if (str_contains($ua, $bot)) {
                return true;
            }
        }

        return false;
    }

    private function isAuthenticatedAppPath(Request $request): bool
    {
        $first = $request->segment(1);

        return in_array($first, ['advertiser', 'publisher', 'admin'], true);
    }
}
