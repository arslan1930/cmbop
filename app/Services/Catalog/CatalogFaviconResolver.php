<?php

namespace App\Services\Catalog;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same-origin catalog tile icon. The browser never hits publisher hosts
 * (demo DNS / TLS noise). Real listings are fetched once and cached.
 */
class CatalogFaviconResolver
{
    public const STORAGE_DIR = 'site-favicons';

    private const FETCH_TIMEOUT = 4;

    private const MAX_BYTES = 524288;

    private const MISS_TTL = 600;

    public function response(Site $site): Response
    {
        $cached = $this->cachedPath($site);
        if ($cached !== null) {
            return $this->fileResponse($cached);
        }

        if ($this->isMissCached($site)) {
            return $this->fallbackResponse();
        }

        if ($this->capture($site)) {
            $cached = $this->cachedPath($site);
            if ($cached !== null) {
                return $this->fileResponse($cached);
            }
        }

        $this->rememberMiss($site);

        return $this->fallbackResponse();
    }

    public function fallbackResponse(): Response
    {
        $path = public_path('assets/img/catalog-site-fallback.svg');
        if (! is_file($path)) {
            return response('', 204);
        }

        return response()->file($path, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function capture(Site $site): bool
    {
        foreach ($site->catalogFaviconHosts() as $host) {
            if (! $this->shouldFetchHost($host)) {
                continue;
            }

            $candidates = [
                'https://www.google.com/s2/favicons?sz=128&domain='.rawurlencode($host),
                'https://icons.duckduckgo.com/ip3/'.$host.'.ico',
                'https://'.$host.'/favicon.ico',
            ];

            foreach ($candidates as $url) {
                if ($this->storeFromUrl($site, $url)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function shouldFetchHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        if (preg_match('/^demo\d+\.com$/', $host) === 1) {
            return false;
        }
        if (preg_match('/\.(example|test|invalid|localhost)$/', $host) === 1) {
            return false;
        }

        return true;
    }

    private function storeFromUrl(Site $site, string $url): bool
    {
        try {
            $res = Http::timeout(self::FETCH_TIMEOUT)
                ->connectTimeout(2)
                ->withOptions([
                    'allow_redirects' => ['max' => 3],
                    'http_errors' => false,
                    'verify' => ! str_contains($url, '/favicon.ico'),
                ])
                ->withHeaders([
                    'Accept' => 'image/*,*/*;q=0.8',
                    'User-Agent' => 'SeolinkbuildingsCatalogFavicon/1.0',
                ])
                ->get($url);
        } catch (\Throwable) {
            return false;
        }

        if (! $res->successful()) {
            return false;
        }

        $body = $res->body();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return false;
        }

        $ext = $this->sniffExtension($body, (string) $res->header('Content-Type'));
        if ($ext === null) {
            return false;
        }

        $path = self::STORAGE_DIR.'/'.$site->id.'.'.$ext;
        try {
            Storage::disk('public')->put($path, $body);

            return Storage::disk('public')->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    private function sniffExtension(string $body, string $contentType): ?string
    {
        $type = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if (str_contains($type, 'html') || str_contains($type, 'javascript') || str_contains($type, 'json')) {
            return null;
        }

        $head = substr($body, 0, 16);
        if (str_starts_with($head, "\x89PNG")) {
            return 'png';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, 'GIF8')) {
            return 'gif';
        }
        if (str_starts_with($head, "\x00\x00\x01\x00") || str_starts_with($head, "\x00\x00\x02\x00")) {
            return 'ico';
        }
        if (str_starts_with($body, 'RIFF') && str_contains(substr($body, 0, 16), 'WEBP')) {
            return 'webp';
        }
        if (str_contains($type, 'svg') || str_contains(strtolower(substr($body, 0, 200)), '<svg')) {
            return 'svg';
        }
        if (str_contains($type, 'icon') || str_contains($type, 'x-icon')) {
            return 'ico';
        }
        if (str_contains($type, 'png')) {
            return 'png';
        }

        return null;
    }

    private function cachedPath(Site $site): ?string
    {
        $stored = $site->leftoverStringAttribute('favicon_path');
        if (is_string($stored) && $stored !== '' && $site->publicDiskHasFile($stored)) {
            return $this->diskAbsolute($stored);
        }

        $disk = Storage::disk('public');
        foreach (['png', 'ico', 'jpg', 'webp', 'gif', 'svg'] as $ext) {
            $rel = self::STORAGE_DIR.'/'.$site->id.'.'.$ext;
            if ($disk->exists($rel)) {
                return $this->diskAbsolute($rel);
            }
        }

        return null;
    }

    private function diskAbsolute(string $relative): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', $relative), '/');
        foreach (['storage/', 'media/'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = ltrim(substr($normalized, strlen($prefix)), '/');
            }
        }

        try {
            $full = Storage::disk('public')->path($normalized);
        } catch (\Throwable) {
            $full = storage_path('app/public/'.$normalized);
        }

        return is_file($full) ? $full : null;
    }

    private function fileResponse(string $absolute): BinaryFileResponse
    {
        $mime = match (strtolower(pathinfo($absolute, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function missKey(Site $site): string
    {
        return 'catalog-favicon-miss:'.$site->id;
    }

    private function isMissCached(Site $site): bool
    {
        try {
            return Cache::has($this->missKey($site));
        } catch (\Throwable) {
            return false;
        }
    }

    private function rememberMiss(Site $site): void
    {
        try {
            Cache::put($this->missKey($site), 1, self::MISS_TTL);
        } catch (\Throwable) {
        }
    }
}
