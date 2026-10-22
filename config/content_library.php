<?php

return [
    /*
    | Kill switch: set LIBRARY_LIVE_SEARCH=false to fall back to classic
    | full-page admin library navigation and 404 the fragment endpoint.
    */
    'live_search' => [
        'enabled' => filter_var(env('LIBRARY_LIVE_SEARCH', true), FILTER_VALIDATE_BOOL),
    ],

    'bulk_limit' => 50,

    /*
    | Max .docx files accepted in one upload-modal session. Each file still
    | posts to the existing single-file chunked endpoint.
    */
    'multi_upload_limit' => max(1, min(20, (int) env('LIBRARY_MULTI_UPLOAD_LIMIT', 10))),

    /*
    | Unpaid cart lines older than this many days show an in-app
    | "Still in your cart — finish payment?" hint on the library row.
    | There is no abandoned-cart email; reuse would need a new campaign.
    */
    'stale_cart_days' => max(1, (int) env('LIBRARY_STALE_CART_DAYS', 3)),
];
