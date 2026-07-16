<?php

namespace App\Services\SiteEnrichment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageOptimizationService
{
    /**
     * Store binary image as optimized WebP (+ optional thumbnail).
     *
     * @return array{path: string, thumb_path: ?string}|null
     */
    public function storeOptimizedWebp(string $binary, string $directory, string $basename): ?array
    {
        $disk = Storage::disk('public');
        $directory = trim($directory, '/');
        $basename = Str::slug($basename) ?: 'site';
        $path = $directory.'/'.$basename.'.webp';
        $thumbPath = $directory.'/'.$basename.'-thumb.webp';

        $optimized = $this->toWebp($binary, (int) config('site_enrichment.screenshots.quality', 82));
        if ($optimized === null) {
            return null;
        }

        $disk->put($path, $optimized);

        $thumbBinary = $this->resizeToWebp(
            $binary,
            (int) config('site_enrichment.screenshots.thumb_width', 640),
            (int) config('site_enrichment.screenshots.quality', 80)
        );

        $thumbStored = null;
        if ($thumbBinary !== null) {
            $disk->put($thumbPath, $thumbBinary);
            $thumbStored = $thumbPath;
        }

        return [
            'path' => $path,
            'thumb_path' => $thumbStored,
        ];
    }

    public function toWebp(string $binary, int $quality = 82): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            Log::warning('GD WebP support unavailable; storing original bytes as fallback is disabled.');

            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }
        @imagealphablending($image, true);
        @imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, max(1, min(100, $quality)));
        imagedestroy($image);
        $data = ob_get_clean();

        return $ok && is_string($data) && $data !== '' ? $data : null;
    }

    public function resizeToWebp(string $binary, int $targetWidth, int $quality = 80): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($source);

            return null;
        }

        $targetWidth = max(120, $targetWidth);
        if ($srcW <= $targetWidth) {
            imagedestroy($source);

            return $this->toWebp($binary, $quality);
        }

        $targetHeight = (int) max(1, round($srcH * ($targetWidth / $srcW)));
        $dest = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH);

        ob_start();
        $ok = imagewebp($dest, null, max(1, min(100, $quality)));
        $data = ob_get_clean();
        imagedestroy($source);
        imagedestroy($dest);

        return $ok && is_string($data) && $data !== '' ? $data : null;
    }

    /**
     * Generate a professional placeholder WebP when capture fails.
     */
    public function storePlaceholder(string $directory, string $basename, string $label = 'Preview unavailable'): ?array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $width = 1280;
        $height = 720;
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 241, 245, 249);
        $bar = imagecolorallocate($img, 226, 232, 240);
        $text = imagecolorallocate($img, 100, 116, 139);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        imagefilledrectangle($img, 0, 0, $width, 56, $bar);

        $message = $label;
        imagestring($img, 5, (int) (($width - (strlen($message) * 9)) / 2), (int) ($height / 2 - 8), $message, $text);

        ob_start();
        imagewebp($img, null, 80);
        $binary = ob_get_clean();
        imagedestroy($img);

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        return $this->storeOptimizedWebp($binary, $directory, $basename.'-placeholder');
    }
}
