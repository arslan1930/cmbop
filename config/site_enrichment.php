<?php

/**
 * Publisher catalog enrichment: SEO metrics + homepage screenshots.
 * Providers are swappable via env — never hardcode a single vendor in app code.
 */
return [

    'enabled' => (bool) env('SITE_ENRICHMENT_ENABLED', true),

    /**
     * Primary metrics provider key: manual|ahrefs|moz|semrush
     * "manual" preserves publisher/admin-entered DA/DR/traffic and stamps timestamps.
     */
    'default_provider' => env('SITE_METRICS_PROVIDER', 'manual'),

    /** Tried in order after the primary until DR/DA/traffic are filled. */
    'fallback_providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SITE_METRICS_FALLBACKS', 'manual'))
    ))),

    /** Metrics older than this are considered stale (default 90 days / 3 months). */
    'max_age_days' => (int) env('SITE_ENRICHMENT_MAX_AGE_DAYS', 90),

    /** daily|weekly */
    'refresh_frequency' => env('SITE_ENRICHMENT_FREQUENCY', 'weekly'),

    'batch_limit' => (int) env('SITE_ENRICHMENT_BATCH', 40),

    'disk' => env('SITE_ENRICHMENT_DISK', 'public'),

    'providers' => [
        'ahrefs' => [
            'enabled' => (bool) env('AHREFS_API_KEY'),
            'api_token' => env('AHREFS_API_KEY'),
            'base_url' => env('AHREFS_API_BASE', 'https://api.ahrefs.com/v3'),
        ],
        'moz' => [
            'enabled' => (bool) env('MOZ_ACCESS_TOKEN') || (bool) env('MOZ_ACCESS_ID'),
            'access_token' => env('MOZ_ACCESS_TOKEN'),
            'access_id' => env('MOZ_ACCESS_ID'),
            'secret_key' => env('MOZ_SECRET_KEY'),
            'base_url' => env('MOZ_API_BASE', 'https://lsapi.seomoz.com/v2'),
        ],
        'semrush' => [
            'enabled' => (bool) env('SEMRUSH_API_KEY'),
            'api_key' => env('SEMRUSH_API_KEY'),
            'base_url' => env('SEMRUSH_API_BASE', 'https://api.semrush.com'),
        ],
    ],

    'screenshots' => [
        /** thum_io|screenshotone|url_api|none */
        'provider' => env('SITE_SCREENSHOT_PROVIDER', 'thum_io'),
        'storage_path' => env('SITE_SCREENSHOT_DIR', 'site-screenshots'),
        'quality' => (int) env('SITE_SCREENSHOT_QUALITY', 82),
        'width' => (int) env('SITE_SCREENSHOT_WIDTH', 1280),
        'height' => (int) env('SITE_SCREENSHOT_HEIGHT', 800),
        'thumb_width' => (int) env('SITE_SCREENSHOT_THUMB_WIDTH', 640),
        'screenshotone_access_key' => env('SCREENSHOTONE_ACCESS_KEY'),
        /** Template with {url} placeholder, used when provider=url_api */
        'api_url' => env('SITE_SCREENSHOT_API_URL'),
        'timeout' => (int) env('SITE_SCREENSHOT_TIMEOUT', 45),
        'refresh_with_metrics' => (bool) env('SITE_SCREENSHOT_REFRESH_WITH_METRICS', true),
    ],
];
