<?php

/**
 * Public-website localization only.
 * Authenticated SaaS (advertiser / publisher / admin / wallet / billing) stays English.
 */
return [

    /** Unprefixed canonical English — treated as UK English for SEO (hreflang en-GB). */
    'default' => 'en',

    'supported' => [
        'en', 'de', 'fr', 'nl', 'es', 'it', 'pt', 'us',
        'at', 'ch', 'ro', 'gr', 'dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl',
    ],

    /** Prefixed locales (UK English has no URL prefix). `us` is US English. */
    'prefixed' => [
        'de', 'fr', 'nl', 'es', 'it', 'pt', 'us',
        'at', 'ch', 'ro', 'gr', 'dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl',
    ],

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
        'guest-posts-portugal',
        'guest-posts-france',
        'guest-posts-netherlands',
        'guest-posts-switzerland',
        'guest-posts-austria',
        'guest-posts-romania',
        'guest-posts-greece',
        'guest-posts-denmark',
        'guest-posts-sweden',
        'guest-posts-norway',
        'guest-posts-bulgaria',
        'guest-posts-hungary',
        'guest-posts-estonia',
        'guest-posts-poland',
        'guest-post-prices-europe',
        'comprare-guest-post',
        'articoli-sponsorizzati',
        'link-building',
        'comprare-backlink',
        'agenzie',
        'digital-pr',
        'catalogo',
        'prezzi-guest-post',
        'gastbeitrag-kaufen',
        'advertorial',
        'linkbuilding',
        'backlinks-kaufen',
        'agenturen',
        'niche-edits',
        'guest-post-kaufen',
        'linkaufbau',
        'preisliste',
        'fuer-agenturen',
        'medienplatzierung',
        'advertorial-kaufen',
        'backlink-kaufen',
        'gastartikel-kaufen',
        'gastbeitrag-bestellen',
    ],

    /**
     * Brand ccTLDs (DNS aliases) 301 onto seolinkbuildings.com.
     * Values are public locales; `en` stays unprefixed. .uk and .co.uk are one UK version.
     */
    'country_hosts' => [
        'seolinkbuildings.de' => 'de',
        'seolinkbuildings.fr' => 'fr',
        'seolinkbuildings.it' => 'it',
        'seolinkbuildings.es' => 'es',
        'seolinkbuildings.nl' => 'nl',
        'seolinkbuildings.co.uk' => 'en',
        'seolinkbuildings.uk' => 'en',
        'seolinkbuildings.ch' => 'ch',
        'seolinkbuildings.at' => 'at',
        'seolinkbuildings.se' => 'se',
        'seolinkbuildings.no' => 'no',
        'seolinkbuildings.dk' => 'dk',
        'seolinkbuildings.hu' => 'hu',
        'seolinkbuildings.bg' => 'bg',
        'seolinkbuildings.gr' => 'gr',
        'seolinkbuildings.ro' => 'ro',
        'seolinkbuildings.pl' => 'pl',
        'seolinkbuildings.pt' => 'pt',
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
