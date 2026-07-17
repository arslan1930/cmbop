<?php

/**
 * Public-website localization only.
 * Authenticated SaaS (advertiser / publisher / admin / wallet / billing) stays English.
 */
return [

    'default' => 'en',

    'supported' => ['en', 'de', 'fr', 'nl'],

    /** Prefixed locales (English has no URL prefix). */
    'prefixed' => ['de', 'fr', 'nl'],

    /**
     * Public marketing path prefixes (after optional locale segment).
     * Auth entry points are intentionally English-only.
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
