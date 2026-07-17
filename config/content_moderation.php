<?php

/**
 * Content Compliance & Moderation — configurable policy for article submissions.
 * Categories/keywords can be extended from Admin without code deploys
 * (admin overrides are stored in content_moderation_settings).
 */
return [

    'enabled' => env('CONTENT_MODERATION_ENABLED', true),

    /** Reject when any restricted category confidence >= this (0–100) */
    'confidence_threshold' => (int) env('CONTENT_MODERATION_THRESHOLD', 70),

    /** Cache successful fetches / scans briefly (seconds) */
    'scan_cache_seconds' => 900,

    /** Require a recent approved scan before order can proceed */
    'require_approved_scan' => true,

    'quality' => [
        'min_word_count' => 500,
        'warn_word_count' => 300,
        'max_external_links' => 15,
        'block_placeholder_text' => true,
        'require_headings' => false, // warn only by default
        'block_on_quality_failure' => false, // quality is advisory unless true
    ],

    /*
    | Restricted categories. Each has weight, keywords, phrases, domains, synonyms.
    | Contextual scoring uses co-occurrence and promotional intent signals.
    */
    'categories' => [

        'gambling' => [
            'label' => 'Casino / Gambling / Betting',
            'enabled' => true,
            'weight' => 1.0,
            'keywords' => [
                'casino', 'casinos', 'gambling', 'gamble', 'bettor', 'betting', 'sportsbook',
                'sports betting', 'sport betting', 'poker', 'blackjack', 'roulette', 'baccarat',
                'slot machine', 'slot machines', 'online slots', 'slots', 'jackpot', 'bookmaker',
                'bookie', 'odds', 'wager', 'wagering', 'lottery', 'lotto', 'scratch card',
                'crypto casino', 'crypto gambling', 'bitcoin casino', 'online casino',
                'live dealer', 'spin the wheel', 'free spins', 'no deposit bonus',
                'casino bonus', 'gambling site', 'betting site', 'poker room', 'texas holdem',
                'holdem', 'sports bet', 'parlay', 'betting odds', 'gambling addiction',
            ],
            'domains' => [
                'bet365', 'draftkings', 'fanduel', 'williamhill', 'pokerstars', '888casino',
                'betway', 'unibet', 'casumo', 'leovegas', 'stake.com', 'roobet', 'rollbit',
            ],
            'intent_phrases' => [
                'place a bet', 'claim your bonus', 'sign up and play', 'deposit bonus',
                'best online casino', 'top betting sites', 'win real money', 'play slots',
            ],
        ],

        'adult' => [
            'label' => 'Adult / Erotic / 18+ Content',
            'enabled' => true,
            'weight' => 1.0,
            'keywords' => [
                'pornography', 'porn', 'porno', 'xxx', 'nsfw', 'adult content', 'adult video',
                'erotic', 'erotica', 'escort', 'escorts', 'escort service', 'sex worker',
                'onlyfans', 'camgirl', 'webcam sex', 'nude', 'nudity', 'strip club',
                'sex toy', 'pornographic', 'explicit sexual', '18+ content', 'adult dating',
                'hookup site', 'sugar daddy', 'brothel',
            ],
            'domains' => [
                'pornhub', 'xvideos', 'xnxx', 'onlyfans', 'chaturbate', 'stripchat',
            ],
            'intent_phrases' => [
                'watch free porn', 'adult entertainment', 'hire an escort', 'xxx videos',
            ],
        ],

        // Future-ready (disabled by default — enable from Admin)
        'cbd' => [
            'label' => 'CBD / Cannabis',
            'enabled' => false,
            'weight' => 0.9,
            'keywords' => ['cbd oil', 'cannabis', 'marijuana', 'thc', 'weed dispensary', 'hemp flower'],
            'domains' => [],
            'intent_phrases' => ['buy cbd', 'order cannabis'],
        ],
        'alcohol' => [
            'label' => 'Alcohol',
            'enabled' => false,
            'weight' => 0.8,
            'keywords' => ['buy vodka', 'cheap whiskey', 'online liquor store'],
            'domains' => [],
            'intent_phrases' => [],
        ],
        'tobacco' => [
            'label' => 'Tobacco / Vaping',
            'enabled' => false,
            'weight' => 0.8,
            'keywords' => ['buy cigarettes', 'vape juice wholesale', 'tobacco shop online'],
            'domains' => [],
            'intent_phrases' => [],
        ],
        'weapons' => [
            'label' => 'Weapons',
            'enabled' => false,
            'weight' => 0.95,
            'keywords' => ['buy firearms', 'ammunition for sale', 'ghost gun'],
            'domains' => [],
            'intent_phrases' => [],
        ],
        'crypto_promo' => [
            'label' => 'Cryptocurrency Promotions',
            'enabled' => false,
            'weight' => 0.7,
            'keywords' => ['guaranteed crypto profits', 'pump and dump', 'get rich with bitcoin'],
            'domains' => [],
            'intent_phrases' => [],
        ],
    ],

    /** Terms that reduce false positives when alone (contextual dampening) */
    'exceptions' => [
        'casino royale', // film
        'poker face',
        'slot' => ['time slot', 'parking slot', 'slot in'], // handled specially in engine
    ],
];
