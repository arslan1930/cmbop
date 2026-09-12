<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collapse www → apex on the live host so GSC equity is not split.
 * Hostinger also 301s HTTP→HTTPS at the edge; this covers www on PHP.
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
        if ($host !== 'www.'.self::APEX) {
            return $next($request);
        }

        $target = 'https://'.self::APEX.$request->getRequestUri();

        return redirect()->to($target, 301);
    }
}
