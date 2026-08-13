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
        'wise_pay_url' => env('BILLING_WISE_PAY_URL') ?: 'https://wise.com/pay/business/topurlzltd',
        /*
         * Deposit crypto rails (Option A: USDT TRC20). Empty address = hidden.
         * Aligns with common withdraw USDT TRC20; other chains are opt-in via env.
         */
        'crypto' => [
            'enabled' => (bool) env('BILLING_DEPOSIT_CRYPTO_ENABLED', true),
            'note' => env('BILLING_DEPOSIT_CRYPTO_NOTE')
                ?: 'Send crypto worth the EUR amount below (EUR equivalent at confirmation). Network fees are yours. Include the REF in the memo when the network supports it.',
            'networks' => [
                [
                    'key' => 'usdt_trc20',
                    'label' => 'USDT (TRC20)',
                    'address' => env('BILLING_CRYPTO_USDT_TRC20', 'TLsBTcjhpqLYKkA5nbha3bEe9CCmpCAeqR'),
                ],
                [
                    'key' => 'usdt_bep20',
                    'label' => 'USDT (BEP20)',
                    'address' => env('BILLING_CRYPTO_USDT_BEP20', ''),
                ],
                [
                    'key' => 'btc',
                    'label' => 'Bitcoin (BTC)',
                    'address' => env('BILLING_CRYPTO_BTC', ''),
                ],
                [
                    'key' => 'binance_pay',
                    'label' => 'Binance Pay ID',
                    'address' => env('BILLING_CRYPTO_BINANCE_PAY', ''),
                ],
            ],
        ],
    ],

    /*
    | Show Apple Pay logo in payment-trust only when domain / Stripe wallets are ready.
    */
    'show_apple_pay' => (bool) env('BILLING_SHOW_APPLE_PAY', false),

    'currency' => env('BILLING_CURRENCY', 'EUR'),
    'currency_symbol' => env('BILLING_CURRENCY_SYMBOL', '€'),

    /*
    |--------------------------------------------------------------------------
    | Tax (VAT) — intentionally OFF
    |--------------------------------------------------------------------------
    |
    | Keep BILLING_TAX_ENABLED=false in production. Document totals already use
    | defensive math so flipping this on later will not break PDF generation:
    | when enabled, total = subtotal + tax − discount; when disabled, the stored
    | order total remains authoritative.
    */
    'tax' => [
        'enabled' => (bool) env('BILLING_TAX_ENABLED', false),
        'rate' => (float) env('BILLING_TAX_RATE', 0),
        'label' => env('BILLING_TAX_LABEL', 'VAT'),
    ],

    /*
    | Number series:
    |   INV  — tax invoices (sales only)
    |   RCT  — wallet deposit / top-up receipts
    |   RCPT — payment receipts & payment-failure reports (not tax invoices)
    |   CN   — credit notes / refund receipts
    |   PAY  — publisher withdrawal payout statements
    */
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

    'payment_receipt_number' => [
        'prefix' => env('BILLING_PAYMENT_RECEIPT_PREFIX', 'RCPT'),
        'pad' => (int) env('BILLING_PAYMENT_RECEIPT_PAD', 6),
    ],

    'credit_note_number' => [
        'prefix' => env('BILLING_CREDIT_NOTE_PREFIX', 'CN'),
        'pad' => (int) env('BILLING_CREDIT_NOTE_PAD', 6),
    ],

    'payout_statement_number' => [
        'prefix' => env('BILLING_PAYOUT_STATEMENT_PREFIX', 'PAY'),
        'pad' => (int) env('BILLING_PAYOUT_STATEMENT_PAD', 6),
    ],

    /*
    | Publisher site-feature / promo purchases are platform marketing products,
    | not marketplace guest-post supplies. Keep them out of the INV tax series.
    */
    'promo_feature' => [
        'issue_invoice' => (bool) env('BILLING_PROMO_FEATURE_INVOICE', false),
        'exclusion_note' => 'Site feature / promo purchases are excluded from tax invoicing.',
    ],

    'deposit_receipt_note' => env('BILLING_DEPOSIT_RECEIPT_NOTE')
        ?: 'Funds added to your prepaid wallet balance. A wallet top-up is not a supply of services, so no VAT is charged on this receipt. Tax invoices are issued when you spend the balance on an order.',

    'withdrawal_payout_note' => env('BILLING_WITHDRAWAL_PAYOUT_NOTE')
        ?: 'Payout statement confirming an external withdrawal transfer. This is not a tax invoice.',

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
    | Soft “possible duplicate” window on the approve-confirm page: flag when a
    | completed deposit for the same advertiser matches the pending amount.
    */
    'deposit_approve_duplicate_lookback_days' => (int) env('BILLING_DEPOSIT_APPROVE_DUPLICATE_LOOKBACK_DAYS', 30),

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

    /*
    |--------------------------------------------------------------------------
    | Minimum publisher withdrawal amount (EUR)
    |--------------------------------------------------------------------------
    */
    'withdrawal_min_amount' => (float) env('WITHDRAWAL_MIN_AMOUNT', 20),

    /*
    |--------------------------------------------------------------------------
    | Publisher → advertiser role move (spend earnings as advertiser)
    |--------------------------------------------------------------------------
    |
    | Moves publisher withdrawable cash into the advertiser Money wallet.
    | Bonus never moves. Fee is 0. The €20 withdrawal floor does not apply.
    |
    */
    'role_move' => [
        'min_amount' => (float) env('ROLE_MOVE_MIN_AMOUNT', 0.01),
        'max_amount' => (float) env('ROLE_MOVE_MAX_AMOUNT', 999999.99),
        'fee_percent' => (float) env('ROLE_MOVE_FEE_PERCENT', 0),
    ],

    /*
    | Signed admin email link for withdrawal mark-paid confirm (GET → confirm
    | UI → POST). Minutes until expires+signature invalidate.
    */
    'withdrawal_mark_paid_link_expire_minutes' => (int) env('BILLING_WITHDRAWAL_MARK_PAID_LINK_EXPIRE_MINUTES', 60 * 24 * 7),

    /*
    | Soft “possible duplicate” window on mark-paid confirm: same net amount
    | already completed for this user within N days.
    */
    'withdrawal_mark_paid_duplicate_lookback_days' => (int) env('BILLING_WITHDRAWAL_MARK_PAID_DUPLICATE_LOOKBACK_DAYS', 30),

    'colors' => [
        'primary' => '#0b6266',
        'accent' => '#3aaeb2',
        'muted' => '#75787B',
        'border' => '#e2e8f0',
        'text' => '#0f172a',
    ],
];
