<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzip/Brotli HTML and other text when the client asks for it.
 * PHP's built-in server does not compress; this is what Lighthouse
 * measures as uses-text-compression on local catalog audits.
 */
class CompressTextResponse
{
    private const MIN_BYTES = 1024;

    /** @var list<string> */
    private const TYPES = [
        'text/html',
        'text/css',
        'text/plain',
        'text/xml',
        'text/javascript',
        'application/javascript',
        'application/json',
        'application/xml',
        'application/rss+xml',
        'image/svg+xml',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $packed = $this->encode($request, $content);
        if ($packed === null) {
            return $response;
        }

        [$encoding, $bytes] = $packed;
        if (strlen($bytes) >= strlen($content)) {
            return $response;
        }

        $response->setContent($bytes);
        $response->headers->set('Content-Encoding', $encoding);
        $response->headers->set('Content-Length', (string) strlen($bytes));
        $this->addVary($response, 'Accept-Encoding');

        return $response;
    }

    private function shouldCompress(Request $request, mixed $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return false;
        }

        if (! is_object($response) || ! method_exists($response, 'getContent') || ! isset($response->headers)) {
            return false;
        }

        if ($request->isMethod('HEAD')) {
            return false;
        }

        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        if ($status < 200 || $status >= 300) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        if (filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // Feature tests read raw HTML. Only compress in tests when asked.
        if (app()->runningUnitTests() && $request->headers->get('X-Test-Compress') !== '1') {
            return false;
        }

        $accept = strtolower((string) $request->header('Accept-Encoding', ''));
        if (! str_contains($accept, 'gzip') && ! str_contains($accept, 'br')) {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', ''));
        // View responses often get charset only after prepare(); empty means HTML.
        if ($type === '') {
            $type = 'text/html';
        }
        foreach (self::TYPES as $allowed) {
            if (str_contains($type, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function encode(Request $request, string $content): ?array
    {
        $accept = strtolower((string) $request->header('Accept-Encoding', ''));

        if (str_contains($accept, 'br') && function_exists('brotli_compress')) {
            $bytes = brotli_compress($content, 5);
            if (is_string($bytes) && $bytes !== '') {
                return ['br', $bytes];
            }
        }

        if (str_contains($accept, 'gzip')) {
            $bytes = gzencode($content, 5);
            if (is_string($bytes) && $bytes !== '') {
                return ['gzip', $bytes];
            }
        }

        return null;
    }

    private function addVary(mixed $response, string $value): void
    {
        $existing = (string) $response->headers->get('Vary', '');
        if ($existing === '') {
            $response->headers->set('Vary', $value);

            return;
        }

        $parts = array_map('trim', explode(',', $existing));
        if (! in_array($value, $parts, true)) {
            $parts[] = $value;
            $response->headers->set('Vary', implode(', ', $parts));
        }
    }
}
