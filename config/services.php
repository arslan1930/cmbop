<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'google' => [
        'client_id' => trim((string) env('GOOGLE_CLIENT_ID', '')),
        'client_secret' => trim((string) env('GOOGLE_CLIENT_SECRET', '')),
        // Prefer an explicit callback URI. At runtime Socialite overrides this with the
        // current request host when it differs (avoids bouncing users to localhost).
        'redirect' => trim((string) (env('GOOGLE_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/google/callback')),
    ],

    'trustpilot' => [
        // Public profile: what visitors read. Used for the footer trust link.
        'review_url' => env('TRUSTPILOT_REVIEW_URL', 'https://www.trustpilot.com/review/seolinkbuildings.com'),
        // Write-a-review form: where we send customers we are asking for feedback.
        'evaluate_url' => env('TRUSTPILOT_EVALUATE_URL', 'https://www.trustpilot.com/evaluate/seolinkbuildings.com'),
    ],
];
