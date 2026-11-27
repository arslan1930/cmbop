<?php

namespace App\Support;

/**
 * Draft-only gate for the Verified traffic HTML mockups.
 *
 * Production stays 404 unless VERIFIED_TRAFFIC_PREVIEW=true (staging review).
 * Local and PHPUnit always allow the routes so screenshots and tests work.
 */
final class VerifiedTrafficPreview
{
    public static function enabled(): bool
    {
        if ((bool) config('app.verified_traffic_preview', false)) {
            return true;
        }

        return app()->environment(['local', 'testing']);
    }
}
