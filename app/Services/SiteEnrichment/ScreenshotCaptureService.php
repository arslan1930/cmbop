<?php

namespace App\Services\SiteEnrichment;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScreenshotCaptureService
{
    public function __construct(
        private readonly ImageOptimizationService $images,
    ) {
    }

    /**
     * Capture homepage screenshot, optimize to WebP, store publicly.
     *
     * @return array{path: ?string, thumb_path: ?string, success: bool, error: ?string, used_placeholder: bool}
     */
    public function capture(Site $site): array
    {
        $url = $this->homepageUrl($site);
        $directory = trim((string) config('site_enrichment.screenshots.storage_path', 'site-screenshots'), '/');
        $basename = 'site-'.$site->id.'-'.now()->format('YmdHis');

        $binary = $this->fetchScreenshotBinary($url);

        if ($binary === null) {
            Log::warning('Screenshot capture failed; using placeholder', [
                'site_id' => $site->id,
                'url' => $url,
            ]);

            $placeholder = $this->images->storePlaceholder($directory, $basename, 'Preview unavailable');
            if ($placeholder === null) {
                return [
                    'path' => null,
                    'thumb_path' => null,
                    'success' => false,
                    'error' => 'Screenshot capture failed and placeholder could not be generated.',
                    'used_placeholder' => true,
                ];
            }

            $this->deleteOldFiles($site);

            return [
                'path' => $placeholder['path'],
                'thumb_path' => $placeholder['thumb_path'],
                'success' => false,
                'error' => 'Screenshot provider failed; placeholder stored.',
                'used_placeholder' => true,
            ];
        }

        $stored = $this->images->storeOptimizedWebp($binary, $directory, $basename);
        if ($stored === null) {
            $placeholder = $this->images->storePlaceholder($directory, $basename, 'Preview unavailable');

            return [
                'path' => $placeholder['path'] ?? null,
                'thumb_path' => $placeholder['thumb_path'] ?? null,
                'success' => false,
                'error' => 'Image optimization failed.',
                'used_placeholder' => true,
            ];
        }

        $this->deleteOldFiles($site);

        return [
            'path' => $stored['path'],
            'thumb_path' => $stored['thumb_path'],
            'success' => true,
            'error' => null,
            'used_placeholder' => false,
        ];
    }

    public function homepageUrl(Site $site): string
    {
        $url = trim((string) ($site->url ?: ''));
        if ($url === '') {
            $url = 'https://'.ltrim((string) $site->domain, '/');
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    private function fetchScreenshotBinary(string $url): ?string
    {
        $provider = (string) config('site_enrichment.screenshots.provider', 'thum_io');

        try {
            return match ($provider) {
                'screenshotone' => $this->viaScreenshotOne($url),
                'url_api' => $this->viaUrlApi($url),
                'thum_io' => $this->viaThumIo($url),
                'placeholder', 'none' => null,
                default => $this->viaThumIo($url),
            };
        } catch (\Throwable $e) {
            Log::error('Screenshot provider exception', [
                'provider' => $provider,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function viaThumIo(string $url): ?string
    {
        // Free/public thumbnail service — replaceable via config.
        $width = (int) config('site_enrichment.screenshots.width', 1280);
        $endpoint = 'https://image.thum.io/get/width/'.$width.'/noanimate/'.rawurlencode($url);
        $response = Http::timeout(45)->get($endpoint);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return is_string($body) && strlen($body) > 500 ? $body : null;
    }

    private function viaUrlApi(string $url): ?string
    {
        $template = (string) config('site_enrichment.screenshots.api_url');
        if ($template === '' || ! str_contains($template, '{url}')) {
            return null;
        }

        $endpoint = str_replace('{url}', rawurlencode($url), $template);
        $response = Http::timeout((int) config('site_enrichment.screenshots.timeout', 45))->get($endpoint);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return is_string($body) && strlen($body) > 500 ? $body : null;
    }

    private function viaScreenshotOne(string $url): ?string
    {
        $key = (string) config('site_enrichment.screenshots.screenshotone_access_key');
        if ($key === '') {
            return null;
        }

        $response = Http::timeout(45)->get('https://api.screenshotone.com/take', [
            'access_key' => $key,
            'url' => $url,
            'viewport_width' => (int) config('site_enrichment.screenshots.width', 1280),
            'viewport_height' => (int) config('site_enrichment.screenshots.height', 800),
            'format' => 'png',
            'block_ads' => true,
            'block_cookie_banners' => true,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return is_string($body) && strlen($body) > 500 ? $body : null;
    }

    private function deleteOldFiles(Site $site): void
    {
        $disk = Storage::disk('public');
        foreach ([$site->screenshot_path, $site->screenshot_thumb_path] as $path) {
            if ($path && $disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
