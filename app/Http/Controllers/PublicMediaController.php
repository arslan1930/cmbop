<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fallback when public/storage symlink is missing/wrong (common on Hostinger).
 * Serves only known public media prefixes from the public disk (MEDIA_PATH).
 */
class PublicMediaController extends Controller
{
    // chat_images/ is intentionally absent — that upload route is unused.
    private const ALLOWED_PREFIXES = [
        'sites/',
        'site-favicons/',
        'site-screenshots/',
        'banners/',
        'blogs/',
        'content-articles/',
    ];

    public function show(Request $request, string $path): StreamedResponse
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $normalized = rawurldecode($normalized);
        if ($normalized === '' || str_contains($normalized, '..') || str_contains($normalized, '%') || str_contains($normalized, "\0")) {
            abort(404);
        }

        $allowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($normalized)) {
            abort(404);
        }

        return $disk->response($normalized, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
