<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production security headers. CSP allows the CDN assets the Blade layouts use today.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            // Quill (cdn.quilljs.com) powers publisher/admin rich-text editors; Chart.js/SweetAlert via jsDelivr
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.quilljs.com https://code.jquery.com https://www.google.com https://www.gstatic.com https://www.recaptcha.net https://js.stripe.com https://appleid.cdn-apple.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.quilljs.com https://fonts.googleapis.com",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
            "connect-src 'self' https:",
            "frame-src 'self' https://www.google.com https://www.recaptcha.net https://js.stripe.com https://hooks.stripe.com https://appleid.apple.com",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self' https://checkout.stripe.com https://appleid.apple.com",
            "object-src 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', trim(preg_replace('/\s+/', ' ', $csp)));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        if ($request->secure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Long-cache fingerprinted/static CSS & JS served from /css and /js
        $path = $request->path();
        if (preg_match('#^(css|js|assets)/#', $path) && $response->getStatusCode() === 200) {
            $response->headers->set('Cache-Control', 'public, max-age=604800, immutable');
        }

        return $response;
    }
}
