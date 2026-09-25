<?php

return [
    /*
    | Live display conversion only. Wallet, Stripe, and invoices stay EUR.
    */
    'cache_seconds' => (int) env('FX_CACHE_SECONDS', 3600),

    'frankfurter_url' => env(
        'FX_FRANKFURTER_URL',
        'https://api.frankfurter.app/latest?from=EUR&to=USD,GBP'
    ),

    'ecb_url' => env('FX_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'),

    'currencies' => [
        'USD' => [
            'symbol' => '$',
            'fallback' => (float) env('USD_PER_EUR', 1.08),
        ],
        'GBP' => [
            'symbol' => '£',
            'fallback' => (float) env('GBP_PER_EUR', 0.85),
        ],
    ],

    /*
    | US / Canada / Australia → USD. UK (GB) → GBP. Ledger stays EUR.
    */
    'country_currency' => [
        'US' => 'USD',
        'CA' => 'USD',
        'AU' => 'USD',
        'GB' => 'GBP',
        'UK' => 'GBP',
    ],

    /*
    | Local/testing override. Production uses CF-IPCountry from Cloudflare,
    | then the visitor's public IP. Examples: US, CA, AU, GB.
    */
    'fake_country' => strtoupper(trim((string) env('FAKE_CF_COUNTRY', ''))),

    /*
    | Local/testing only. usd / gbp.
    */
    'force_display' => strtolower(trim((string) env('DISPLAY_CURRENCY', ''))),
];
