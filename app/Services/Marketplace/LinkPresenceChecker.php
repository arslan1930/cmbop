<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkPresenceChecker
{
    /**
     * Fetch a live URL and check that $targetUrl appears as an href.
     *
     * @return array{ok: bool, href_found: bool, status: ?int, body: string, message: string}
     */
    public function check(string $liveUrl, string $targetUrl): array
    {
        $liveUrl = trim($liveUrl);
        $targetUrl = trim($targetUrl);

        if ($liveUrl === '' || ! filter_var($liveUrl, FILTER_VALIDATE_URL)) {
            return [
                'ok' => false,
                'href_found' => false,
                'status' => null,
                'body' => '',
                'message' => 'Invalid live URL.',
            ];
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'SEOLinkBuildings-LinkGuarantee/1.0',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($liveUrl);

            $status = $response->status();
            $reachable = $status >= 200 && $status < 400;
            $body = $reachable ? (string) $response->body() : '';
            $hrefFound = $reachable && $targetUrl !== '' && $this->hrefContainsTarget($body, $targetUrl);

            return [
                'ok' => $reachable && $hrefFound,
                'href_found' => $hrefFound,
                'status' => $status,
                'body' => $body,
                'message' => ! $reachable
                    ? 'Live URL returned HTTP '.$status.'.'
                    : ($hrefFound
                        ? 'Target URL found as a hyperlink.'
                        : 'Live page is reachable but the target URL was not found as a hyperlink.'),
            ];
        } catch (\Throwable $e) {
            Log::info('Link guarantee fetch failed', [
                'url' => $liveUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'href_found' => false,
                'status' => null,
                'body' => '',
                'message' => 'Could not fetch the live URL right now.',
            ];
        }
    }

    public function hrefContainsTarget(string $html, string $targetUrl): bool
    {
        $needle = $this->normalizeUrl($targetUrl);
        if ($needle === '') {
            return false;
        }

        if (! preg_match_all('/href\s*=\s*(["\'])(.*?)\1/i', $html, $matches)) {
            return false;
        }

        foreach ($matches[2] as $href) {
            $href = html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->normalizeUrl($href) === $needle) {
                return true;
            }
            $normalizedHref = $this->normalizeUrl($href);
            if ($normalizedHref !== '' && (str_contains($normalizedHref, $needle) || str_contains($needle, $normalizedHref))) {
                $hrefParts = $this->urlParts($normalizedHref);
                $needleParts = $this->urlParts($needle);
                if ($hrefParts['host'] !== '' && $hrefParts['host'] === $needleParts['host']
                    && $hrefParts['path'] === $needleParts['path']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }

        $host = strtolower($parts['host']);
        $path = strtolower($parts['path'] ?? '/');
        $path = '/'.ltrim($path, '/');
        $path = rtrim($path, '/') ?: '/';

        return $host.$path;
    }

    /**
     * @return array{host: string, path: string}
     */
    private function urlParts(string $normalized): array
    {
        $slash = strpos($normalized, '/');
        if ($slash === false) {
            return ['host' => $normalized, 'path' => '/'];
        }

        return [
            'host' => substr($normalized, 0, $slash),
            'path' => substr($normalized, $slash) ?: '/',
        ];
    }
}
