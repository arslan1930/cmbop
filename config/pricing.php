<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hidden platform fee tiers (publisher base price → fee %)
    |--------------------------------------------------------------------------
    |
    | Applied only to sites.price. Sensitive topic add-ons are pass-through.
    | Advertisers see base + fee; publishers always see/get their entered base.
    | Tiers are evaluated top-to-bottom; first match wins. Use null max for open-ended.
    |
    */
    'fee_tiers' => [
        ['min' => 0, 'max' => 99.99, 'percent' => 15],
        ['min' => 100, 'max' => 299.99, 'percent' => 13],
        ['min' => 300, 'max' => 999.99, 'percent' => 12],
        ['min' => 1000, 'max' => null, 'percent' => 10],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy flat markup (pre-tier orders without snapshotted publisher_price)
    |--------------------------------------------------------------------------
    */
    'legacy_markup_rate' => 1.15,

];
