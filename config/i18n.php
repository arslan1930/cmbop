<?php

/**
 * Public-website localization only.
 * Authenticated SaaS (advertiser / publisher / admin / wallet / billing) stays English.
 */
return [

    /** Unprefixed canonical English — treated as UK English for SEO (hreflang en-GB). */
    'default' => 'en',

    'supported' => ['en', 'de', 'fr', 'nl', 'es', 'it', 'us'],

    /** Prefixed locales (UK English has no URL prefix). `us` is US English. */
    'prefixed' => ['de', 'fr', 'nl', 'es', 'it', 'us'],

    /**
     * Public marketing path prefixes (after optional locale segment).
     * Localized aliases (ueber-uns, marktplatz, …) live in LocalizedPublicPath
     * and are treated as marketing too. Auth entry points stay English-only.
     */
    'public_paths' => [
        '',
        'contact',
        'about',
        'faq',
        'pricing',
        'marketplace',
        'how-it-works',
        'become-a-publisher',
        'why-choose-us',
        'blog',
        'privacy-policy',
        'terms-of-services',
        'cookie-policy',
        'refund-policy',
        'newsletter',
        'guest-posts-germany',
        'guest-posts-uk',
        'guest-posts-italy',
        'guest-posts-spain',
        'guest-posts-france',
        'guest-posts-netherlands',
        'guest-post-prices-europe',
    ],

    /** Paths that must always render in English (no locale prefix). */
    'english_only_paths' => [
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'email',
        'auth',
        'advertiser',
        'publisher',
        'admin',
        'profile',
        'chat',
        'notifications',
        'billing',
        'invoices',
        'api',
        'cron',
        'banners',
        'sitemap.xml',
        'robots.txt',
        'up',
    ],

    'cookie' => 'public_locale',

    'suggestion_dismiss_cookie' => 'locale_suggest_dismissed',
];
