<?php

use App\Mail\AdminAssignedSiteNotification;
use App\Mail\AdminManualPaymentNotification;
use App\Mail\AdminNewUserRegistered;
use App\Mail\AdminStalledOrderAlert;
use App\Mail\AdvertiserOrderStalledNotice;
use App\Mail\AdvertiserReviewNudge;
use App\Mail\AudienceCampaignMail;
use App\Mail\AutoApproveReminderMail;
use App\Mail\BulkSiteItemsRejected;
use App\Mail\BulkSiteRequestCancelled;
use App\Mail\BulkSiteRequestSubmitted;
use App\Mail\BulkSitesSeededNotification;
use App\Mail\CommunityFeedbackReviewed;
use App\Mail\ContentEvaluationResult;
use App\Mail\ContentRevisionFulfilled;
use App\Mail\ContentRevisionRequested;
use App\Mail\DepositApproved;
use App\Mail\DepositMarkedPaid;
use App\Mail\DepositRefunded;
use App\Mail\DepositRejected;
use App\Mail\DepositReminderMail;
use App\Mail\DepositRequestSubmitted;
use App\Mail\DisputeClawbackPublisher;
use App\Mail\DisputeRefundAdvertiser;
use App\Mail\GoogleTempPasswordMail;
use App\Mail\LiveUrlSubmitted;
use App\Mail\ModificationRequested;
use App\Mail\MonthlySpendingSummary;
use App\Mail\NewChatMessageNotification;
use App\Mail\NewSiteNotification;
use App\Mail\NewSitesDigest;
use App\Mail\OrderAccepted;
use App\Mail\OrderApprovedByAdvertiser;
use App\Mail\OrderPaymentConfirmed;
use App\Mail\OrderRejected;
use App\Mail\OrderStatusChanged;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentPendingMail;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\PayoutProfileUpdatedBySupport;
use App\Mail\PaypalExternalPaymentNotice;
use App\Mail\PaypalPaymentNotCompleted;
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherAddSiteReminderMail;
use App\Mail\PublisherPublishNudge;
use App\Mail\RefundReceiptMail;
use App\Mail\SiteClaimOwnershipTransferred;
use App\Mail\SiteClaimReviewed;
use App\Mail\SiteClaimSubmitted;
use App\Mail\SiteDiscountEnded;
use App\Mail\SiteOwnerOrderNotification;
use App\Mail\SiteStatusNotification;
use App\Mail\SpendBudgetAlertMail;
use App\Mail\TrustpilotReviewRequest;
use App\Mail\WebsiteSuggestionReviewed;
use App\Mail\WeeklyActivitySummary;
use App\Mail\WelcomeEmail;
use App\Mail\WithdrawalRequestedConfirmation;
use App\Mail\WithdrawalRequestNotification;
use App\Mail\WithdrawalStatusUpdated;

/**
 * Central registry for platform email notifications.
 * Existing Mail::send call sites keep working; PlatformMailable enforces
 * settings, preferences, and duplicate prevention.
 */
return [

    'brand' => [
        'name' => env('APP_NAME', 'SEOLinkBuildings'),
        // Optional absolute override. Leave empty to resolve via mail_brand_logo_url().
        'logo_url' => env('MAIL_LOGO_URL'),
        // Public path for the email wordmark (white-bg Final B lockup).
        'logo_path' => env('MAIL_LOGO_PATH', 'assets/img/email-logo.png'),
        'website_url' => env('APP_URL', 'https://seolinkbuildings.com'),
        'support_email' => env('MAIL_SUPPORT_EMAIL', env('ADMIN_EMAIL', 'support@seolinkbuildings.com')),
        'reply_to' => env('MAIL_REPLY_TO_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'sender_email' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'sender_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'SEOLinkBuildings')),
        'copyright' => '© '.date('Y').' '.env('APP_NAME', 'SEOLinkBuildings').'. All rights reserved.',
        'social' => [
            'twitter' => env('SOCIAL_TWITTER_URL'),
            'linkedin' => env('SOCIAL_LINKEDIN_URL'),
            'facebook' => env('SOCIAL_FACEBOOK_URL'),
        ],
    ],

    'dedupe_window_minutes' => 10,

    /*
    | Queue connection for PlatformMailable (ShouldQueue).
    | Follows the app queue connection so checkout does not block on SMTP; set
    | MAIL_QUEUE_CONNECTION=sync for environments running without a worker.
    | Mail lands on the "emails" queue, so the worker must include it:
    | php artisan queue:work --queue=default,emails
    */
    'queue_connection' => env('MAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),

    'queue' => env('MAIL_QUEUE_NAME', 'emails'),

    /*
    | Shared hosting cannot keep `queue:work` resident, so the scheduler drains
    | the backlog every minute instead. Set MAIL_QUEUE_AUTO_DRAIN=false where a
    | dedicated worker (Horizon, supervisor) already consumes these queues.
    */
    'auto_drain' => (bool) env('MAIL_QUEUE_AUTO_DRAIN', true),

    /*
    | Drop queued mail older than this instead of delivering stale news when a
    | neglected backlog finally gets consumed. 0 disables the cap.
    */
    'max_age_hours' => (int) env('MAIL_MAX_AGE_HOURS', 24),

    /*
    | Campaign mail may sit behind a longer backlog than transactional mail.
    | 0 disables the campaign-specific cap (falls through to not dropping).
    */
    'campaign_max_age_hours' => (int) env('MAIL_CAMPAIGN_MAX_AGE_HOURS', 72),

    /*
    | Signed marketing unsubscribe links (GET confirm + POST one-click).
    */
    'unsubscribe_expire_days' => (int) env('MAIL_UNSUBSCRIBE_EXPIRE_DAYS', 30),

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
            'mailable' => WelcomeEmail::class,
            'default_enabled' => true,
        ],
        'google_temp_password' => [
            'name' => 'Google Temporary Password',
            'audience' => 'user',
            'preference' => 'security_alerts',
            'mailable' => GoogleTempPasswordMail::class,
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
        'email_verification' => [
            'name' => 'Email Verification',
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
            'mailable' => OrderStatusChanged::class,
            'default_enabled' => true,
        ],

        // —— Content evaluation ——
        'content_evaluation_result' => [
            'name' => 'Content Evaluation Result',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => ContentEvaluationResult::class,
            'default_enabled' => true,
        ],

        // —— Advertiser / order ——
        'order_payment_confirmed' => [
            'name' => 'Payment Successful',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => OrderPaymentConfirmed::class,
            'default_enabled' => true,
        ],
        'payment_successful_invoice' => [
            'name' => 'Payment Successful – Invoice Attached',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => PaymentSuccessfulInvoiceMail::class,
            'default_enabled' => true,
        ],
        'payment_failed' => [
            'name' => 'Payment Failed',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => PaymentFailedMail::class,
            'default_enabled' => true,
        ],
        'payment_pending' => [
            'name' => 'Payment Pending Verification',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => PaymentPendingMail::class,
            'default_enabled' => true,
        ],
        'refund_receipt' => [
            'name' => 'Refund Receipt',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => RefundReceiptMail::class,
            'default_enabled' => true,
        ],
        'order_accepted' => [
            'name' => 'Order Accepted',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => OrderAccepted::class,
            'default_enabled' => true,
        ],
        'order_rejected' => [
            'name' => 'Order Rejected',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => OrderRejected::class,
            'default_enabled' => true,
        ],
        'live_url_submitted' => [
            'name' => 'Guest Post Published',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => LiveUrlSubmitted::class,
            'default_enabled' => true,
        ],
        'modification_requested' => [
            'name' => 'Revision Requested',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => ModificationRequested::class,
            'default_enabled' => true,
        ],
        'content_revision_requested' => [
            'name' => 'Content Revision Requested',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => ContentRevisionRequested::class,
            'default_enabled' => true,
        ],
        'content_revision_fulfilled' => [
            'name' => 'Content Revision Fulfilled',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => ContentRevisionFulfilled::class,
            'default_enabled' => true,
        ],
        'order_completed' => [
            'name' => 'Order Completed',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => OrderApprovedByAdvertiser::class,
            'default_enabled' => true,
        ],
        'dispute_clawback_publisher' => [
            'name' => 'Dispute Clawback (Publisher)',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => DisputeClawbackPublisher::class,
            'default_enabled' => true,
        ],
        'dispute_refund_advertiser' => [
            'name' => 'Dispute Refund (Advertiser)',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => DisputeRefundAdvertiser::class,
            'default_enabled' => true,
        ],
        'trustpilot_review' => [
            'name' => 'Trustpilot Review Request',
            'audience' => 'advertiser',
            'preference' => 'review_requests',
            'mailable' => TrustpilotReviewRequest::class,
            'default_enabled' => true,
        ],

        // —— Publisher ——
        'publisher_new_order' => [
            'name' => 'New Order Received',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => SiteOwnerOrderNotification::class,
            'default_enabled' => true,
        ],
        'site_status' => [
            'name' => 'Site Status Notification',
            'audience' => 'publisher',
            'preference' => 'system_updates',
            'mailable' => SiteStatusNotification::class,
            'default_enabled' => true,
        ],
        'site_discount_ended' => [
            'name' => 'Site Discount Ended',
            'audience' => 'publisher',
            'preference' => 'system_updates',
            'mailable' => SiteDiscountEnded::class,
            'default_enabled' => true,
        ],
        'withdrawal_status' => [
            'name' => 'Withdrawal Status Updated',
            'audience' => 'publisher',
            'preference' => 'payment_emails',
            'mailable' => WithdrawalStatusUpdated::class,
            'default_enabled' => true,
        ],
        'withdrawal_requested_confirmation' => [
            'name' => 'Withdrawal Request Confirmation',
            'audience' => 'publisher',
            'preference' => 'payment_emails',
            'mailable' => WithdrawalRequestedConfirmation::class,
            'default_enabled' => true,
        ],
        'payout_profile_updated' => [
            'name' => 'Payout Profile Updated by Support',
            'audience' => 'publisher',
            'preference' => 'payment_emails',
            'mailable' => PayoutProfileUpdatedBySupport::class,
            'default_enabled' => true,
        ],

        // —— Admin ——
        'admin_manual_payment' => [
            'name' => 'Admin Manual Payment',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => AdminManualPaymentNotification::class,
            'default_enabled' => true,
        ],
        'deposit_submitted' => [
            'name' => 'Deposit Request Submitted',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => DepositRequestSubmitted::class,
            'default_enabled' => true,
        ],
        'deposit_marked_paid' => [
            'name' => 'Deposit Reported Paid',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => DepositMarkedPaid::class,
            'default_enabled' => true,
        ],
        'withdrawal_request' => [
            'name' => 'Withdrawal Request',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => WithdrawalRequestNotification::class,
            'default_enabled' => true,
        ],
        'new_site' => [
            'name' => 'New Site Submitted',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => NewSiteNotification::class,
            'default_enabled' => true,
        ],
        'bulk_site_request_submitted' => [
            'name' => 'Bulk Site Request Submitted',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => BulkSiteRequestSubmitted::class,
            'default_enabled' => true,
        ],
        'bulk_sites_seeded' => [
            'name' => 'Bulk Sites Seeded — Complete Details',
            'audience' => 'publisher',
            'preference' => null,
            'mailable' => BulkSitesSeededNotification::class,
            'default_enabled' => true,
        ],
        'admin_assigned_site' => [
            'name' => 'Admin Assigned Site — Accept Listing',
            'audience' => 'publisher',
            'preference' => null,
            'mailable' => AdminAssignedSiteNotification::class,
            'default_enabled' => true,
        ],
        'admin_new_user' => [
            'name' => 'New User Registered',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => AdminNewUserRegistered::class,
            'default_enabled' => true,
        ],
        'site_claim_submitted' => [
            'name' => 'Site Claim Submitted',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => SiteClaimSubmitted::class,
            'default_enabled' => true,
        ],
        'site_claim_reviewed' => [
            'name' => 'Site Claim Reviewed',
            'audience' => 'user',
            'preference' => 'system_updates',
            'mailable' => SiteClaimReviewed::class,
            'default_enabled' => true,
        ],
        'site_claim_ownership_transferred' => [
            'name' => 'Site Claim Ownership Transferred',
            'audience' => 'publisher',
            'preference' => 'system_updates',
            'mailable' => SiteClaimOwnershipTransferred::class,
            'default_enabled' => true,
        ],
        'community_feedback_reviewed' => [
            'name' => 'Community Feedback Reviewed',
            'audience' => 'user',
            'preference' => 'system_updates',
            'mailable' => CommunityFeedbackReviewed::class,
            'default_enabled' => true,
        ],
        'website_suggestion_reviewed' => [
            'name' => 'Website Suggestion Reviewed',
            'audience' => 'user',
            'preference' => 'system_updates',
            'mailable' => WebsiteSuggestionReviewed::class,
            'default_enabled' => true,
        ],

        // —— Billing (user) ——
        'deposit_approved' => [
            'name' => 'Deposit Approved / Wallet Top-up',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => DepositApproved::class,
            'default_enabled' => true,
        ],
        'deposit_refunded' => [
            'name' => 'PayPal Deposit Refunded',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => DepositRefunded::class,
            'default_enabled' => true,
        ],
        'paypal_payment_not_completed' => [
            'name' => 'PayPal Payment Not Completed',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => PaypalPaymentNotCompleted::class,
            'default_enabled' => true,
        ],
        'paypal_external_payment_notice' => [
            'name' => 'PayPal External Payment Notice',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => PaypalExternalPaymentNotice::class,
            'default_enabled' => true,
        ],
        'deposit_rejected' => [
            'name' => 'Deposit Rejected',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => DepositRejected::class,
            'default_enabled' => true,
        ],

        // —— Communication ——
        'chat_message' => [
            'name' => 'New Chat Message',
            'audience' => 'user',
            'preference' => 'chat_emails',
            'mailable' => NewChatMessageNotification::class,
            'default_enabled' => true,
        ],

        // —— Admin campaigns / updates ——
        'audience_campaign' => [
            'name' => 'Updates & Campaigns',
            'audience' => 'user',
            'preference' => 'marketing_emails',
            'mailable' => AudienceCampaignMail::class,
            'default_enabled' => true,
        ],

        // —— Publisher onboarding (scheduled) ——
        'bulk_request_cancelled' => [
            'name' => 'Bulk Website Request Cancelled',
            'audience' => 'publisher',
            'preference' => 'system_updates',
            'mailable' => BulkSiteRequestCancelled::class,
            'default_enabled' => true,
        ],
        'bulk_request_items_rejected' => [
            'name' => 'Bulk Request Sites Not Added',
            'audience' => 'publisher',
            'preference' => null,
            'mailable' => BulkSiteItemsRejected::class,
            'default_enabled' => true,
        ],
        'publisher_add_site_reminder' => [
            'name' => 'Publisher Add-Site Reminder (day 3 / day 7)',
            'audience' => 'publisher',
            'preference' => 'marketing_emails',
            'mailable' => PublisherAddSiteReminderMail::class,
            'default_enabled' => true,
        ],

        // —— Advertiser onboarding (scheduled) ——
        'deposit_reminder' => [
            'name' => 'Deposit Reminder (day 7 / day 14)',
            'audience' => 'advertiser',
            'preference' => 'marketing_emails',
            'mailable' => DepositReminderMail::class,
            'default_enabled' => true,
        ],

        // —— Order reminder cadences (scheduled) ——
        // Filed under order_emails, not marketing: these are about work the
        // recipient owes on an order they were paid for or paid to place.
        'publisher_accept_nudge' => [
            'name' => 'Publisher: accept the order',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => PublisherAcceptNudge::class,
            'default_enabled' => true,
        ],
        'publisher_publish_nudge' => [
            'name' => 'Publisher: publish the article (due / overdue)',
            'audience' => 'publisher',
            'preference' => 'order_emails',
            'mailable' => PublisherPublishNudge::class,
            'default_enabled' => true,
        ],
        'advertiser_review_nudge' => [
            'name' => 'Advertiser: review the live link',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => AdvertiserReviewNudge::class,
            'default_enabled' => true,
        ],
        // Independent of order_status_changed so ops can silence lifecycle
        // fan-out without killing the 24h auto-approve warning.
        'auto_approve_reminder' => [
            'name' => 'Advertiser: 1 day left to review (auto-approve)',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => AutoApproveReminderMail::class,
            'default_enabled' => true,
        ],
        'advertiser_order_stalled' => [
            'name' => 'Advertiser: your order is late',
            'audience' => 'advertiser',
            'preference' => 'order_emails',
            'mailable' => AdvertiserOrderStalledNotice::class,
            'default_enabled' => true,
        ],
        // No preference key: an escalation is a work item, and an admin who
        // silenced these would silently strand refunds.
        'admin_stalled_order' => [
            'name' => 'Admin: order stalled and needs a decision',
            'audience' => 'admin',
            'preference' => null,
            'mailable' => AdminStalledOrderAlert::class,
            'default_enabled' => true,
        ],

        // —— Digests (scheduled) ——
        'new_sites_digest' => [
            'name' => 'New Sites Digest (every 15 days)',
            'audience' => 'advertiser',
            'preference' => 'marketing_emails',
            'mailable' => NewSitesDigest::class,
            'default_enabled' => true,
        ],
        'weekly_activity_summary' => [
            'name' => 'Weekly Activity Summary',
            'audience' => 'advertiser',
            'preference' => 'weekly_summary',
            'mailable' => WeeklyActivitySummary::class,
            'default_enabled' => true,
        ],
        'monthly_spending_summary' => [
            'name' => 'Monthly Spending Summary',
            'audience' => 'advertiser',
            'preference' => 'monthly_summary',
            'mailable' => MonthlySpendingSummary::class,
            'default_enabled' => true,
        ],
        'spend_budget_alert' => [
            'name' => 'Spend Budget Alert',
            'audience' => 'advertiser',
            'preference' => 'payment_emails',
            'mailable' => SpendBudgetAlertMail::class,
            'default_enabled' => true,
        ],
    ],
];
