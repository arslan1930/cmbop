<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company / seller details (final order invoices + PDF billing docs)
    |--------------------------------------------------------------------------
    */
    'company' => [
        'name' => env('BILLING_COMPANY_NAME') ?: env('APP_NAME', 'SEOLinkBuildings'),
        'legal_name' => env('BILLING_LEGAL_NAME') ?: 'SEOLinkBuildings Partners with (Topurlz LTD)',
        'address_lines' => array_values(array_filter([
            env('BILLING_ADDRESS_LINE1') ?: '20 Wenlock Road, London, England, N1 7GU',
            env('BILLING_ADDRESS_LINE2') ?: null,
            env('BILLING_ADDRESS_LINE3') ?: null,
            env('BILLING_ADDRESS_COUNTRY') ?: null,
        ])),
        'registration_no' => env('BILLING_REGISTRATION_NO') ?: '16607074',
        'support_email' => env('BILLING_SUPPORT_EMAIL')
            ?: env('MAIL_SUPPORT_EMAIL', 'support@seolinkbuildings.com'),
        'website_url' => env('BILLING_WEBSITE_URL') ?: env('APP_URL', 'https://seolinkbuildings.com'),
        'vat_number' => env('BILLING_VAT_NUMBER') ?: null,
        'vat_note' => env('BILLING_VAT_NOTE') ?: 'Not VAT registered – no VAT charged',
        'logo_path' => env('BILLING_LOGO_PATH') ?: 'assets/img/email-logo.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deposit / add-funds payment seller + bank details
    |--------------------------------------------------------------------------
    */
    'deposit_payment' => [
        'seller_name' => env('BILLING_DEPOSIT_SELLER_NAME') ?: 'SEOLinkBuildings Partner',
        'beneficiary' => env('BILLING_DEPOSIT_BENEFICIARY') ?: 'Topurlz Ltd',
        'bic' => env('BILLING_DEPOSIT_BIC') ?: 'TRWIBEB1XXX',
        'iban' => env('BILLING_DEPOSIT_IBAN') ?: 'BE04905543949331',
        'phone' => env('BILLING_DEPOSIT_PHONE') ?: '+447445152374',
        'address_lines' => array_values(array_filter([
            env('BILLING_DEPOSIT_ADDRESS_LINE1') ?: '20 Wenlock Road, London, England, N1 7GU',
            env('BILLING_DEPOSIT_ADDRESS_LINE2') ?: null,
            env('BILLING_DEPOSIT_ADDRESS_LINE3') ?: null,
        ])),
        'registration_no' => env('BILLING_DEPOSIT_REGISTRATION_NO') ?: '16607074',
        'vat_note' => env('BILLING_DEPOSIT_VAT_NOTE') ?: 'Not VAT registered – no VAT charged',
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

    /*
    | Wallet top-ups are not a supply of services, so deposit receipts carry no
    | tax line and are numbered in their own series rather than the sales one.
    */
    'receipt_number' => [
        'prefix' => env('BILLING_RECEIPT_PREFIX', 'RCT'),
        'pad' => (int) env('BILLING_RECEIPT_PAD', 6),
    ],

    'deposit_receipt_note' => env('BILLING_DEPOSIT_RECEIPT_NOTE')
        ?: 'Funds added to your prepaid wallet balance. A wallet top-up is not a supply of services, so no VAT is charged on this receipt. Tax invoices are issued when you spend the balance on an order.',

    'storage' => [
        'disk' => env('BILLING_DISK', 'local'),
        'directory' => 'invoices',
    ],

    'pending_verification_hours' => (int) env('BILLING_PENDING_HOURS', 24),

    /*
    | Signed admin email link for manual deposit approve-confirm (GET → confirm
    | UI → POST). Minutes until expires+signature invalidate.
    */
    'deposit_approve_link_expire_minutes' => (int) env('BILLING_DEPOSIT_APPROVE_LINK_EXPIRE_MINUTES', 60 * 24 * 7),

    /*
    |--------------------------------------------------------------------------
    | Publisher withdrawal fee (% of requested amount)
    |--------------------------------------------------------------------------
    |
    | Deducted from the withdrawal before payout (publisher receives net).
    | Default 0 — order platform markup is the primary revenue product.
    |
    */
    'withdrawal_fee_percent' => (float) env('WITHDRAWAL_FEE_PERCENT', 0),

    'colors' => [
        'primary' => '#0b6266',
        'accent' => '#3aaeb2',
        'muted' => '#75787B',
        'border' => '#e2e8f0',
        'text' => '#0f172a',
    ],
];
