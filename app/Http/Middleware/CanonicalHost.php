<?php

namespace App\Http\Middleware;

use App\Support\CountryHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collapse www → apex on the live host so GSC equity is not split.
 * Brand ccTLDs (seolinkbuildings.ch, .pl, …) 301 onto seolinkbuildings.com.
 * Hostinger also 301s HTTP→HTTPS at the edge; this covers www and country hosts on PHP.
 */
class CanonicalHost
{
    public const APEX = 'seolinkbuildings.com';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $host = strtolower((string) $request->getHost());
        // Hostinger already 301s HTTP and www in one hop. Only rewrite www if
        // it still reaches PHP — do not add an HTTP→HTTPS hop on the apex.
        if ($host === 'www.'.self::APEX) {
            $target = 'https://'.self::APEX.$request->getRequestUri();

            return redirect()->to($target, 301);
        }

        if ($host === self::APEX) {
            URL::forceRootUrl('https://'.self::APEX);
            URL::forceScheme('https');
        }

        if (class_exists(CountryHost::class)) {
            try {
                $locale = CountryHost::localeForHost($host);
                if (is_string($locale) && $locale !== '') {
                    $target = CountryHost::apexUrl($request, $locale);
                    $targetHost = strtolower((string) parse_url($target, PHP_URL_HOST));
                    if ($targetHost !== '' && $targetHost !== $host) {
                        return redirect()->to($target, 301);
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $next($request);
    }
}
