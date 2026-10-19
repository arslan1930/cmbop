<?php

return [

    /*
    | Teqno LTD supply-partner catalog import + dual pricing.
    | Publishers/partners are never charged a fee.
    */

    'margin_floor' => (float) env('PARTNER_MARGIN_FLOOR', 0.35),

    'hard_floor' => (float) env('PARTNER_HARD_FLOOR', 0.15),

    'min_commission_eur' => (float) env('PARTNER_MIN_COMMISSION_EUR', 25),

    'feed_drop_percent' => (int) env('PARTNER_FEED_DROP_PERCENT', 30),

    'rate_increase_limit_per_year' => (int) env('PARTNER_RATE_INCREASE_LIMIT', 4),

    'currencies' => ['EUR', 'CZK', 'PLN'],

    'fx_fallbacks' => [
        'CZK' => (float) env('CZK_PER_EUR', 25.0),
        'PLN' => (float) env('PLN_PER_EUR', 4.30),
    ],

    'fx_ecb_url' => env('PARTNER_FX_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'),

    'fx_frankfurter_url' => env(
        'PARTNER_FX_FRANKFURTER_URL',
        'https://api.frankfurter.app/latest?from=EUR&to=CZK,PLN'
    ),

    'reachability_timeout' => (float) env('PARTNER_REACHABILITY_TIMEOUT', 4),

    'reachability_pool' => (int) env('PARTNER_REACHABILITY_POOL', 20),

    'sensitive_niches' => [
        'Crypto & Blockchain',
        'Pharma & Supplements',
        'Dating & Relationships',
    ],

    'contracting_entity' => 'Teqno LTD (17392540)',
];
