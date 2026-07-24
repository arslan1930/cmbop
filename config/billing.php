<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company / seller details (shown on PDF invoices)
    |--------------------------------------------------------------------------
    */
    'company' => [
        'name' => env('BILLING_COMPANY_NAME', env('APP_NAME', 'SEOLinkBuildings')),
        'legal_name' => env('BILLING_LEGAL_NAME', 'SEOLinkBuildings'),
        'address_lines' => array_values(array_filter([
            env('BILLING_ADDRESS_LINE1', '71-75 Shelton Street'),
            env('BILLING_ADDRESS_LINE2', 'Covent Garden'),
            env('BILLING_ADDRESS_LINE3', 'London WC2H 9JQ'),
            env('BILLING_ADDRESS_COUNTRY', 'United Kingdom'),
        ])),
        'support_email' => env('BILLING_SUPPORT_EMAIL', env('MAIL_SUPPORT_EMAIL', 'support@seolinkbuildings.com')),
        'website_url' => env('BILLING_WEBSITE_URL', env('APP_URL', 'https://seolinkbuildings.com')),
        'vat_number' => env('BILLING_VAT_NUMBER'),
        'logo_path' => env('BILLING_LOGO_PATH', 'assets/img/logo1.png'),
    ],

    'currency' => env('BILLING_CURRENCY', 'EUR'),
    'currency_symbol' => env('BILLING_CURRENCY_SYMBOL', '€'),

    /*
    | Future-ready tax defaults (0 = no tax applied today).
    */
    'tax' => [
        'enabled' => (bool) env('BILLING_TAX_ENABLED', false),
        'rate' => (float) env('BILLING_TAX_RATE', 0),
        'label' => env('BILLING_TAX_LABEL', 'VAT'),
    ],

    'invoice_number' => [
        'prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),
        'pad' => (int) env('BILLING_INVOICE_PAD', 6),
    ],

    'storage' => [
        'disk' => env('BILLING_DISK', 'local'),
        'directory' => 'invoices',
    ],

    'pending_verification_hours' => (int) env('BILLING_PENDING_HOURS', 24),

    'colors' => [
        'primary' => '#0b6266',
        'accent' => '#3aaeb2',
        'muted' => '#75787B',
        'border' => '#e2e8f0',
        'text' => '#0f172a',
    ],
];
