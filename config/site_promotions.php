<?php

/**
 * Publisher site promotions: featured placement, bulk discounts, timed custom discounts.
 */
return [

    'feature' => [
        'price' => (float) env('SITE_FEATURE_PRICE', 10),
        'days' => (int) env('SITE_FEATURE_DAYS', 7),
        'currency' => 'EUR',
    ],

    'bulk' => [
        'min_qty' => 3,
        'max_qty' => 5,
        'min_percent' => 10,
        'max_percent' => 15,
        'default_percent' => 10,
    ],

    'custom_discount' => [
        'min_percent' => 1,
        'max_percent' => 70,
        'max_days' => 90,
    ],
];
