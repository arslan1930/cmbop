<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publisher domain visibility
    |--------------------------------------------------------------------------
    |
    | Catalog listings show a partially masked domain until the advertiser opens
    | it. The point is not secrecy — anyone allowed to evaluate a site before
    | buying will end up knowing the domain — it is to stop a competitor
    | harvesting the whole inventory, and to leave a trail when a publisher
    | reports being approached directly.
    |
    | Browsing and opening addresses are UNLIMITED. An agency planning a campaign
    | may legitimately work through hundreds of listings, and a quota punishes
    | exactly that person while barely inconveniencing a scraper who can register
    | again. Volume is not the tell. Pace is.
    |
    */
    'url_reveal' => [

        /*
        | Pace, not quota.
        |
        | Every threshold below describes a rate a person cannot sustain. They
        | are starting points: run with `enforce` off for a couple of weeks,
        | look at what your real users actually do, and set these at the far
        | tail of that distribution. Numbers picked by guesswork are how you
        | throttle your best customer on a Friday afternoon.
        */
        'pace' => [

            /*
            | Off means detect and report but never restrict. Use it to
            | calibrate before anything can bite a real buyer.
            */
            'enforce' => filter_var(env('CATALOG_PACE_ENFORCE', true), FILTER_VALIDATE_BOOL),

            /*
            | Short-term rate. More than this many inside the window and the next
            | address waits — and because the window is short, the wait we quote
            | is the real time until it clears, so retrying actually works.
            |
            | Deliberately not a long window: "60 in five minutes" cannot be
            | cleared by waiting three seconds, which turns a courtesy pause into
            | a dead end for anyone browsing briskly.
            */
            'slow_after' => (int) env('CATALOG_PACE_SLOW_AFTER', 30),
            'slow_window_seconds' => (int) env('CATALOG_PACE_SLOW_WINDOW_SECONDS', 60),

            /*
            | Waits up to this are absorbed silently by the page: the reader sees
            | a brief spinner. Anything longer is said out loud instead of spun.
            */
            'slow_silent_retry_ceiling' => (int) env('CATALOG_PACE_SILENT_RETRY', 10),

            /*
            | Sustained at this rate, new addresses stop until the window
            | clears. Browsing, filtering and everything already opened keep
            | working — only further disclosure pauses.
            */
            'freeze_after' => (int) env('CATALOG_PACE_FREEZE_AFTER', 250),
            'freeze_window_minutes' => (int) env('CATALOG_PACE_FREEZE_WINDOW', 30),

            /*
            | Volume worth a human glance. Nothing changes for the user — most
            | of these will be real buyers, and that is how you learn what
            | normal looks like here.
            */
            'review_after' => (int) env('CATALOG_PACE_REVIEW_AFTER', 300),
            'review_window_hours' => (int) env('CATALOG_PACE_REVIEW_WINDOW', 24),

            /*
            | Humans are irregular: they pause, re-read, get distracted. Scripts
            | are metronomic. Once there are enough samples, a near-constant gap
            | between requests is the most reliable tell there is, and evading it
            | means slowing down — which is the point.
            */
            'regularity_samples' => (int) env('CATALOG_PACE_REGULARITY_SAMPLES', 15),
            'regularity_stddev_seconds' => (float) env('CATALOG_PACE_REGULARITY_STDDEV', 1.5),

            /*
            | How long "Mark as trusted" lasts. After this the account is under
            | the usual pace checks again; reveal history is untouched.
            */
            'exemption_minutes' => (int) env('CATALOG_PACE_EXEMPTION_MINUTES', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Clipboard copy strikes
    |--------------------------------------------------------------------------
    |
    | Detect mass-copying of URL/domain identity from the catalog. Threshold is
    | ~5 pages of listings (20 per page → 100) inside a short window. Strike 1
    | warns; strike 2 sets catalog_hide_until for hide-mode UX (Phase 3).
    |
    */
    'copy_strikes' => [
        'threshold' => (int) env('CATALOG_COPY_STRIKE_THRESHOLD', 100),
        'window_seconds' => (int) env('CATALOG_COPY_STRIKE_WINDOW_SECONDS', 120),
        'hide_hours' => (int) env('CATALOG_COPY_HIDE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live catalog search / filter results
    |--------------------------------------------------------------------------
    |
    | When enabled, the advertiser catalog updates the results table via
    | GET /advertiser/catalog/results without a full page reload. The URL query
    | string remains the source of truth either way.
    |
    | Kill switch: set CATALOG_LIVE_SEARCH=false to fall back to classic full
    | GET navigations and disable the fragment endpoint (404).
    |
    */
    'live_search' => [
        'enabled' => filter_var(env('CATALOG_LIVE_SEARCH', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hide placeholder / demo listings from default browse
    |--------------------------------------------------------------------------
    |
    | When on, lorem/demo/example.com rows stay out of the advertiser catalog
    | unless the buyer searches that host or opens ?site={id}. Cart add is
    | refused. Staff health queues still see them.
    |
    */
    'hide_placeholders' => filter_var(env('CATALOG_HIDE_PLACEHOLDERS', true), FILTER_VALIDATE_BOOL),

    /*
    | Default browse filters. Keep off until the admin health queues are clean,
    | then set CATALOG_DEFAULT_VERIFIED=true / CATALOG_DEFAULT_QUALITY=true.
    | Buyers can still dismiss the chip (?verified=0 / ?quality=0).
    */
    'default_verified' => filter_var(env('CATALOG_DEFAULT_VERIFIED', false), FILTER_VALIDATE_BOOL),

    'default_quality' => filter_var(env('CATALOG_DEFAULT_QUALITY', false), FILTER_VALIDATE_BOOL),

];
