<?php

namespace App\Http\Middleware;

use App\Support\VisitorChatEmbed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leftover Hostinger public/portal layouts omit @include('partials.tawk'),
 * so live HTML only had the help FAB. Append the visitor-chat snippet
 * before </body> when the widget is configured and missing.
 */
class EnsureVisitorChat
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! class_exists(VisitorChatEmbed::class) || ! VisitorChatEmbed::anyOn()) {
            return $response;
        }

        if ($request->is('admin', 'admin/*', 'api/*', 'up')) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return $response;
        }

        $injected = VisitorChatEmbed::inject($html);
        if ($injected !== $html) {
            $response->setContent($injected);
        }

        return $response;
    }
}
