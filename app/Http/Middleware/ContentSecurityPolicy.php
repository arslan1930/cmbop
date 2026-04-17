<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'
                https://cdn.jsdelivr.net
                https://cdnjs.cloudflare.com
                https://code.jquery.com
                https://www.google.com
                https://www.gstatic.com
                https://www.recaptcha.net",
            "style-src 'self' 'unsafe-inline'
                https://cdn.jsdelivr.net
                https://cdnjs.cloudflare.com
                https://fonts.googleapis.com",
            "img-src 'self' data: https:",
            "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self' https:",
            "frame-src 'self' https://www.google.com https://www.recaptcha.net",
            "frame-ancestors 'self'",
        ]);

        // IMPORTANT: single header only (no newline issue)
        $response->headers->set('Content-Security-Policy', trim(preg_replace('/\s+/', ' ', $csp)));

        return $response;
    }
}