<?php

/**
 * Central registry for platform email notifications.
 * Existing Mail::send call sites keep working; PlatformMailable enforces
 * settings, preferences, and duplicate prevention.
 */
return [

    'brand' => [
        'name' => env('APP_NAME', 'SEOLinkBuildings'),
        'logo_url' => env('MAIL_LOGO_URL', 'https://seolinkbuildings.com/assets/img/logo1.png'),
        'website_url' => env('APP_URL', 'https://seolinkbuildings.com'),
        'support_email' => env('MAIL_SUPPORT_EMAIL', env('ADMIN_EMAIL', 'support@seolinkbuildings.com')),
        'reply_to' => env('MAIL_REPLY_TO_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'sender_email' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'sender_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'SEOLinkBuildings')),
        'copyright' => '© ' . date('Y') . ' ' . env('APP_NAME', 'SEOLinkBuildings') . '. All rights reserved.',
        'social' => [
            'twitter' => env('SOCIAL_TWITTER_URL'),
            'linkedin' => env('SOCIAL_LINKEDIN_URL'),
            'facebook' => env('SOCIAL_FACEBOOK_URL'),
        ],
    ],

    'dedupe_window_minutes' => 10,

    /*
    | Queue connection for PlatformMailable (ShouldQueue).
    | Default "sync" keeps current environments working without a worker.
    | Set MAIL_QUEUE_CONNECTION=database (or redis) in production with queue:work.
    */
    'queue_connection' => env('MAIL_QUEUE_CONNECTION', 'sync'),

    /*
    | Preference keys users can toggle (security cannot be disabled).
    */
    'preference_keys' => [
        'order_emails' => ['label' => 'Order Emails', 'default' => true],
        'payment_emails' => ['label' => 'Payment Emails', 'default' => true],
        'chat_emails' => ['label' => 'Chat Emails', 'default' => true],
        'marketing_emails' => ['label' => 'Marketing Emails', 'default' => true],
        'weekly_summary' => ['label' => 'Weekly Activity Summary', 'default' => true],
        'monthly_summary' => ['label' => 'Monthly Spending Summary', 'default' => true],
        'review_requests' => ['label' => 'Review Requests', 'default' => true],
        'system_updates' => ['label' => 'System Updates', 'default' => true],
        'security_alerts' => ['label' => 'Security Alerts', 'default' => true, 'locked' => true],
    ],

    /*
    | Notification types. preference = which user pref gate applies (null = always if admin-enabled).
    | audience: advertiser|publisher|admin|user
    */
    'types' => [

        // —— Auth / account ——
        'welcome' => [
            'name' => 'Welcome Email',
            'audience' => 'user',
            'preference' => 'system_updates',
            'mailable' => \App\Mail\WelcomeEmail::class,
            'default_enabled' => true,
        ],
        'password_reset' => [
            'name' => 'Password Reset',
            'audience' => 'user',
            'preference' => 'security_alerts',
            'mailable' => null,
            'default_enabled' => true,
            'framework' => true,
        ],

        // —— Order lifecycle (fan-out to Advertiser, Publisher, Marketing, Admin) ——
        'order_status_changed' => [
            'name' => 'Order Status Changed',
            'audience' => 'user',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\OrderStatusChanged::class,
            'default_enabled' => true,
        ],

        // —— Content evaluation ——
        'content_evaluation_result' => [
            'name' => 'Content Evaluation Result',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\ContentEvaluationResult::class,
            'default_enabled' => true,
        ],

        // —— Advertiser / order ——
        'order_payment_confirmed' => [
            'name' => 'Payment Successful',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => \App\Mail\OrderPaymentConfirmed::class,
            'default_enabled' => true,
        ],
        'order_accepted' => [
            'name' => 'Order Accepted',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\OrderAccepted::class,
            'default_enabled' => true,
        ],
        'order_rejected' => [
            'name' => 'Order Rejected',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\OrderRejected::class,
            'default_enabled' => true,
        ],
        'live_url_submitted' => [
            'name' => 'Guest Post Published',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\LiveUrlSubmitted::class,
            'default_enabled' => true,
        ],
        'modification_requested' => [
            'name' => 'Revision Requested',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\ModificationRequested::class,
            'default_enabled' => true,
        ],
        'order_completed' => [
            'name' => 'Order Completed',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\OrderApprovedByAdvertiser::class,
            'default_enabled' => true,
        ],
        'trustpilot_review' => [
            'name' => 'Trustpilot Review Request',
            'audience' => 'advertiser',
            'preference' => 'review_requests',
            'mailable' => \App\Mail\TrustpilotReviewRequest::class,
            'default_enabled' => true,
        ],

        // —— Publisher ——
        'publisher_new_order' => [
            'name' => 'New Order Received',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => \App\Mail\SiteOwnerOrderNotification::class,
            'default_enabled' => true,
        ],
        'site_status' => [
            'name' => 'Site Status Notification',
            'audience' => 'publisher',
            'preference' => 'system_updates',
            'mailable' => \App\Mail\SiteStatusNotification::class,
            'default_enabled' => true,
        ],
        'withdrawal_status' => [
            'name' => 'Withdrawal Status Updated',
            'audience' => 'publisher',
            'preference' => 'payment_emails',
            'mailable' => \App\Mail\WithdrawalStatusUpdated::class,
            'default_enabled' => true,
        ],

        // —— Admin ——
        'admin_manual_payment' => [
            'name' => 'Admin Manual Payment',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => \App\Mail\AdminManualPaymentNotification::class,
            'default_enabled' => true,
        ],
        'deposit_submitted' => [
            'name' => 'Deposit Request Submitted',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => \App\Mail\DepositRequestSubmitted::class,
            'default_enabled' => true,
        ],
        'withdrawal_request' => [
            'name' => 'Withdrawal Request',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => \App\Mail\WithdrawalRequestNotification::class,
            'default_enabled' => true,
        ],
        'new_site' => [
            'name' => 'New Site Submitted',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => \App\Mail\NewSiteNotification::class,
            'default_enabled' => true,
        ],
        'admin_new_user' => [
            'name' => 'New User Registered',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => \App\Mail\AdminNewUserRegistered::class,
            'default_enabled' => true,
        ],

        // —— Billing (user) ——
        'deposit_approved' => [
            'name' => 'Deposit Approved',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => \App\Mail\DepositApproved::class,
            'default_enabled' => true,
        ],
        'deposit_rejected' => [
            'name' => 'Deposit Rejected',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => \App\Mail\DepositRejected::class,
            'default_enabled' => true,
        ],

        // —— Communication ——
        'chat_message' => [
            'name' => 'New Chat Message',
            'audience' => 'user',
            'preference' => 'chat_emails',
            'mailable' => \App\Mail\NewChatMessageNotification::class,
            'default_enabled' => true,
        ],

        // —— Admin campaigns / updates ——
        'audience_campaign' => [
            'name' => 'Updates & Campaigns',
            'audience' => 'user',
            'preference' => 'marketing_emails',
            'mailable' => \App\Mail\AudienceCampaignMail::class,
            'default_enabled' => true,
        ],

        // —— Digests (scheduled) ——
        'weekly_activity_summary' => [
            'name' => 'Weekly Activity Summary',
            'audience' => 'advertiser',
            'preference' => 'weekly_summary',
            'mailable' => \App\Mail\WeeklyActivitySummary::class,
            'default_enabled' => true,
        ],
        'monthly_spending_summary' => [
            'name' => 'Monthly Spending Summary',
            'audience' => 'advertiser',
            'preference' => 'monthly_summary',
            'mailable' => \App\Mail\MonthlySpendingSummary::class,
            'default_enabled' => true,
        ],
    ],
];
