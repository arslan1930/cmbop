<?php

namespace App\Support;

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
use App\Models\BulkSiteRequest;
use App\Models\ContentSubmission;
use App\Models\DepositRequest;
use App\Models\EmailCampaign;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\ProblemReport;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Models\Withdrawal;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

class EmailCatalog
{
    public const PREVIEW_ID = 0;

    public const PREVIEW_EMAIL = 'sample@example.com';

    public static function isPreviewUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // Only unsaved / id-0 catalog stand-ins. A real account that happens
        // to use PREVIEW_EMAIL must still get live verify URLs and role copy.
        return (int) $user->id === self::PREVIEW_ID;
    }

    public static function previewVerificationUrl(): string
    {
        return rtrim(app_public_url(), '/').'/email/verify/preview-id/preview-hash';
    }

    public static function previewUser(): User
    {
        return self::sampleUser();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'welcome' => [
                'name' => 'Welcome Email',
                'description' => 'Sent automatically after registration with a verify or catalog CTA.',
                'category' => 'Users',
                'mailable' => WelcomeEmail::class,
                'status' => 'active',
            ],
            'google_temp_password' => [
                'name' => 'Google Temporary Password',
                'description' => 'Sent once when a new account is created via Google sign-in, with a temporary password for Profile → Change Password.',
                'category' => 'Users',
                'mailable' => GoogleTempPasswordMail::class,
                'status' => 'active',
            ],
            'order_status_changed' => [
                'name' => 'Order Status Changed',
                'description' => 'Lifecycle update to Advertiser, Publisher, and Admin on status/payment changes. Skipped for advertisers when a dedicated accept/reject/live-URL email already covers the same event.',
                'category' => 'Orders',
                'mailable' => OrderStatusChanged::class,
                'status' => 'active',
            ],
            'order_payment_confirmed' => [
                'name' => 'Payment Success (legacy)',
                'description' => 'Legacy advertiser receipt after successful order payment. Prefer payment_successful_invoice when a PDF tax invoice exists.',
                'category' => 'Orders',
                'mailable' => OrderPaymentConfirmed::class,
                'status' => 'active',
            ],
            'payment_successful_invoice' => [
                'name' => 'Payment Successful (Invoice PDF)',
                'description' => 'Advertiser tax invoice email with INV PDF attached after successful payment.',
                'category' => 'Billing',
                'mailable' => PaymentSuccessfulInvoiceMail::class,
                'status' => 'active',
            ],
            'payment_failed' => [
                'name' => 'Payment Failed',
                'description' => 'Advertiser notice when order payment verification fails, with failure report PDF.',
                'category' => 'Billing',
                'mailable' => PaymentFailedMail::class,
                'status' => 'active',
            ],
            'payment_pending' => [
                'name' => 'Payment Pending Verification',
                'description' => 'Advertiser notice while a card/bank payment awaits verification (no tax invoice yet).',
                'category' => 'Billing',
                'mailable' => PaymentPendingMail::class,
                'status' => 'active',
            ],
            'refund_receipt' => [
                'name' => 'Refund Receipt',
                'description' => 'Advertiser credit-note / refund receipt email with CN PDF attached.',
                'category' => 'Billing',
                'mailable' => RefundReceiptMail::class,
                'status' => 'active',
            ],
            'order_completed' => [
                'name' => 'Order Completed',
                'description' => 'Publisher notified when advertiser approves the completed order.',
                'category' => 'Orders',
                'mailable' => OrderApprovedByAdvertiser::class,
                'status' => 'active',
            ],
            'publisher_new_order' => [
                'name' => 'Publisher Notification',
                'description' => 'Publisher alert when a new paid order is assigned to their site.',
                'category' => 'Publishers',
                'mailable' => SiteOwnerOrderNotification::class,
                'status' => 'active',
            ],
            'order_accepted' => [
                'name' => 'Order Accepted',
                'description' => 'Advertiser notified when publisher accepts an order.',
                'category' => 'Orders',
                'mailable' => OrderAccepted::class,
                'status' => 'active',
            ],
            'order_rejected' => [
                'name' => 'Order Rejected',
                'description' => 'Advertiser notified when publisher rejects an order.',
                'category' => 'Orders',
                'mailable' => OrderRejected::class,
                'status' => 'active',
            ],
            'dispute_clawback_publisher' => [
                'name' => 'Dispute Clawback (Publisher)',
                'description' => 'Publisher notified when a post-completion link-removed dispute is upheld and earnings are clawed back.',
                'category' => 'Orders',
                'mailable' => DisputeClawbackPublisher::class,
                'status' => 'active',
            ],
            'dispute_refund_advertiser' => [
                'name' => 'Dispute Refund (Advertiser)',
                'description' => 'Advertiser notified when a link-removed dispute is upheld and wallet credit is refunded.',
                'category' => 'Orders',
                'mailable' => DisputeRefundAdvertiser::class,
                'status' => 'active',
            ],
            'live_url_submitted' => [
                'name' => 'Live URL Submitted',
                'description' => 'Advertiser notified when the guest post goes live.',
                'category' => 'Orders',
                'mailable' => LiveUrlSubmitted::class,
                'status' => 'active',
            ],
            'modification_requested' => [
                'name' => 'Modification Requested',
                'description' => 'Publisher notified when advertiser requests changes.',
                'category' => 'Orders',
                'mailable' => ModificationRequested::class,
                'status' => 'active',
            ],
            'content_revision_requested' => [
                'name' => 'Content Revision Requested',
                'description' => 'Advertiser notified when publisher asks for a revised / resent article.',
                'category' => 'Orders',
                'mailable' => ContentRevisionRequested::class,
                'status' => 'active',
            ],
            'content_revision_fulfilled' => [
                'name' => 'Content Revision Fulfilled',
                'description' => 'Publisher notified when the advertiser sends a revised article.',
                'category' => 'Orders',
                'mailable' => ContentRevisionFulfilled::class,
                'status' => 'active',
            ],
            'admin_manual_payment' => [
                'name' => 'Admin Manual Payment',
                'description' => 'Admins alerted when a customer chooses a manual payment method.',
                'category' => 'Admin',
                'mailable' => AdminManualPaymentNotification::class,
                'status' => 'active',
            ],
            'deposit_submitted' => [
                'name' => 'Deposit Request Submitted',
                'description' => 'Admins notified of a new wallet deposit request, with a signed approve-confirm CTA (credits only after confirm).',
                'category' => 'Billing',
                'mailable' => DepositRequestSubmitted::class,
                'status' => 'active',
            ],
            'deposit_marked_paid' => [
                'name' => 'Deposit Reported Paid',
                'description' => 'Admins alerted when an advertiser confirms they sent the transfer, with a primary signed approve-confirm CTA.',
                'category' => 'Billing',
                'mailable' => DepositMarkedPaid::class,
                'status' => 'active',
            ],
            'deposit_approved' => [
                'name' => 'Deposit Approved',
                'description' => 'Advertiser notified when a wallet top-up settles (admin bank/Wise approve, Stripe card, or PayPal Add Funds), with receipt PDF attached.',
                'category' => 'Billing',
                'mailable' => DepositApproved::class,
                'status' => 'active',
            ],
            'deposit_refunded' => [
                'name' => 'PayPal Deposit Refunded',
                'description' => 'Advertiser notified when a PayPal Add Funds capture is refunded and the wallet credit is reversed.',
                'category' => 'Billing',
                'mailable' => DepositRefunded::class,
                'status' => 'active',
            ],
            'paypal_payment_not_completed' => [
                'name' => 'PayPal Payment Not Completed',
                'description' => 'Advertiser notified when a PayPal checkout or Add Funds payment is cancelled, declined, denied, or still under review.',
                'category' => 'Billing',
                'mailable' => PaypalPaymentNotCompleted::class,
                'status' => 'active',
            ],
            'paypal_external_payment_notice' => [
                'name' => 'PayPal External Payment Notice',
                'description' => 'Advertiser or publisher notified when PayPal refunds, reverses, or disputes a capture. Notice only — wallets are not moved automatically.',
                'category' => 'Billing',
                'mailable' => PaypalExternalPaymentNotice::class,
                'status' => 'active',
            ],
            'deposit_rejected' => [
                'name' => 'Deposit Rejected',
                'description' => 'User notified when a deposit is rejected.',
                'category' => 'Billing',
                'mailable' => DepositRejected::class,
                'status' => 'active',
            ],
            'withdrawal_request' => [
                'name' => 'Withdrawal Request',
                'description' => 'Admins notified of a new withdrawal, with a signed mark-paid confirm CTA (settles only after confirm).',
                'category' => 'Billing',
                'mailable' => WithdrawalRequestNotification::class,
                'status' => 'active',
            ],
            'withdrawal_status' => [
                'name' => 'Withdrawal Status Updated',
                'description' => 'Publisher notified when withdrawal status changes.',
                'category' => 'Billing',
                'mailable' => WithdrawalStatusUpdated::class,
                'status' => 'active',
            ],
            'withdrawal_requested_confirmation' => [
                'name' => 'Withdrawal Request Confirmation',
                'description' => 'Publisher confirmation that a withdrawal request was submitted.',
                'category' => 'Billing',
                'mailable' => WithdrawalRequestedConfirmation::class,
                'status' => 'active',
            ],
            'new_site' => [
                'name' => 'New Site Submitted',
                'description' => 'Admins notified when a publisher submits/updates a site.',
                'category' => 'Publishers',
                'mailable' => NewSiteNotification::class,
                'status' => 'active',
            ],
            'site_status' => [
                'name' => 'Site Status Notification',
                'description' => 'Publisher notified when site verification/status changes.',
                'category' => 'Publishers',
                'mailable' => SiteStatusNotification::class,
                'status' => 'active',
            ],
            'chat_message' => [
                'name' => 'New Chat Message',
                'description' => 'Counterparty notified about a new order chat message.',
                'category' => 'Orders',
                'mailable' => NewChatMessageNotification::class,
                'status' => 'active',
            ],
            'trustpilot_review' => [
                'name' => 'Trustpilot Review',
                'description' => 'Ask happy customers to leave a Trustpilot review after completed orders.',
                'category' => 'Growth',
                'mailable' => TrustpilotReviewRequest::class,
                'status' => 'active',
                'importance' => 'Auto-sent when an order status becomes completed (observer — no controller changes). Set TRUSTPILOT_REVIEW_URL.',
            ],
            'password_reset' => [
                'name' => 'Password Reset',
                'description' => 'Laravel auth password reset email (framework notification).',
                'category' => 'Auth',
                'mailable' => null,
                'status' => 'framework',
                'importance' => 'Managed by Laravel auth — preview shows a branded sample; do not duplicate send logic.',
            ],
            'email_verification' => [
                'name' => 'Email Verification',
                'description' => 'Laravel auth verification email sent on signup / resend (framework notification).',
                'category' => 'Auth',
                'mailable' => null,
                'status' => 'framework',
                'importance' => 'Managed by Laravel auth — preview shows a branded sample; the Email Center toggle does not stop verify mail.',
            ],
            'content_evaluation_result' => [
                'name' => 'Content Evaluation Result',
                'description' => 'Advertiser notified when an uploaded article is approved or needs changes.',
                'category' => 'Orders',
                'mailable' => ContentEvaluationResult::class,
                'status' => 'active',
            ],
            'site_discount_ended' => [
                'name' => 'Site Discount Ended',
                'description' => 'Publisher notified when a listing discount expires.',
                'category' => 'Publishers',
                'mailable' => SiteDiscountEnded::class,
                'status' => 'active',
            ],
            'payout_profile_updated' => [
                'name' => 'Payout Profile Updated by Support',
                'description' => 'Publisher notified when support changes their payout details.',
                'category' => 'Billing',
                'mailable' => PayoutProfileUpdatedBySupport::class,
                'status' => 'active',
            ],
            'bulk_site_request_submitted' => [
                'name' => 'Bulk Site Request Submitted',
                'description' => 'Admins notified when a publisher submits a bulk website request.',
                'category' => 'Admin',
                'mailable' => BulkSiteRequestSubmitted::class,
                'status' => 'active',
            ],
            'bulk_sites_seeded' => [
                'name' => 'Bulk Sites Seeded — Complete Details',
                'description' => 'Publisher asked to finish details after staff seed a bulk request.',
                'category' => 'Publishers',
                'mailable' => BulkSitesSeededNotification::class,
                'status' => 'active',
            ],
            'admin_assigned_site' => [
                'name' => 'Admin Assigned Site — Accept Listing',
                'description' => 'Publisher asked to accept a website staff added for them.',
                'category' => 'Publishers',
                'mailable' => AdminAssignedSiteNotification::class,
                'status' => 'active',
            ],
            'audience_campaign' => [
                'name' => 'Updates & Campaigns',
                'description' => 'Admin-composed marketing / update email to a selected audience, with a signed marketing unsubscribe footer.',
                'category' => 'Growth',
                'mailable' => AudienceCampaignMail::class,
                'status' => 'active',
            ],
            'bulk_request_cancelled' => [
                'name' => 'Bulk Website Request Cancelled',
                'description' => 'Publisher notified when staff cancel a bulk website request.',
                'category' => 'Publishers',
                'mailable' => BulkSiteRequestCancelled::class,
                'status' => 'active',
            ],
            'bulk_request_items_rejected' => [
                'name' => 'Bulk Request Sites Not Added',
                'description' => 'Publisher notified when some URLs from a bulk request were not added.',
                'category' => 'Publishers',
                'mailable' => BulkSiteItemsRejected::class,
                'status' => 'active',
            ],
            'admin_new_user' => [
                'name' => 'New User Registered',
                'description' => 'Admins notified when a new user registers.',
                'category' => 'Admin',
                'mailable' => AdminNewUserRegistered::class,
                'status' => 'active',
            ],
            'site_claim_submitted' => [
                'name' => 'Site Claim Submitted',
                'description' => 'Admins notified when a user claims ownership of a catalog listing, with a review CTA.',
                'category' => 'Admin',
                'mailable' => SiteClaimSubmitted::class,
                'status' => 'active',
            ],
            'site_claim_reviewed' => [
                'name' => 'Site Claim Reviewed',
                'description' => 'Claimer notified when their ownership claim is approved or rejected.',
                'category' => 'Publishers',
                'mailable' => SiteClaimReviewed::class,
                'status' => 'active',
            ],
            'site_claim_ownership_transferred' => [
                'name' => 'Site Claim Ownership Transferred',
                'description' => 'Previous publisher notified when an approved claim moves a listing to another account.',
                'category' => 'Publishers',
                'mailable' => SiteClaimOwnershipTransferred::class,
                'status' => 'active',
            ],
            'community_feedback_reviewed' => [
                'name' => 'Community Feedback Reviewed',
                'description' => 'Submitter notified when an admin reviews a problem report or suggestion.',
                'category' => 'Users',
                'mailable' => CommunityFeedbackReviewed::class,
                'status' => 'active',
            ],
            'website_suggestion_reviewed' => [
                'name' => 'Website Suggestion Reviewed',
                'description' => 'Submitter notified when an admin reviews a missing-website suggestion.',
                'category' => 'Users',
                'mailable' => WebsiteSuggestionReviewed::class,
                'status' => 'active',
            ],
            'publisher_add_site_reminder' => [
                'name' => 'Publisher Add-Site Reminder (day 3 / day 7)',
                'description' => 'Scheduled nudge for publishers who registered but never listed a website.',
                'category' => 'Publishers',
                'mailable' => PublisherAddSiteReminderMail::class,
                'status' => 'active',
            ],
            'deposit_reminder' => [
                'name' => 'Deposit Reminder (day 7 / day 14)',
                'description' => 'Scheduled nudge for advertisers who registered but never funded their wallet.',
                'category' => 'Advertisers',
                'mailable' => DepositReminderMail::class,
                'status' => 'active',
            ],
            'publisher_accept_nudge' => [
                'name' => 'Publisher: accept the order',
                'description' => 'Chases a publisher who has not accepted a paid order. Escalates to admin at stage 3.',
                'category' => 'Publishers',
                'mailable' => PublisherAcceptNudge::class,
                'status' => 'active',
            ],
            'publisher_publish_nudge' => [
                'name' => 'Publisher: publish the article',
                'description' => 'Due-soon and overdue reminders anchored to the turnaround time on the listing. Batches when a publisher is late on several orders.',
                'category' => 'Publishers',
                'mailable' => PublisherPublishNudge::class,
                'status' => 'active',
            ],
            'advertiser_review_nudge' => [
                'name' => 'Advertiser: review the live link',
                'description' => 'Mid-window nudge to check the live link before the order auto-completes.',
                'category' => 'Advertisers',
                'mailable' => AdvertiserReviewNudge::class,
                'status' => 'active',
            ],
            'auto_approve_reminder' => [
                'name' => 'Advertiser: 1 day left to review',
                'description' => 'Sent ~24h before auto-approve. Toggleable independently of order status emails.',
                'category' => 'Advertisers',
                'mailable' => AutoApproveReminderMail::class,
                'status' => 'active',
            ],
            'advertiser_order_stalled' => [
                'name' => 'Advertiser: your order is late',
                'description' => 'Tells the advertiser their publisher is overdue, that funds are still held, and that a refund is available.',
                'category' => 'Advertisers',
                'mailable' => AdvertiserOrderStalledNotice::class,
                'status' => 'active',
            ],
            'admin_stalled_order' => [
                'name' => 'Admin: order stalled',
                'description' => 'Escalation once a publisher has had the full reminder cadence without responding.',
                'category' => 'Admin',
                'mailable' => AdminStalledOrderAlert::class,
                'status' => 'active',
            ],
            'new_sites_digest' => [
                'name' => 'New Sites Digest (every 15 days)',
                'description' => 'New and discounted catalog listings for advertisers who have already placed a paid order.',
                'category' => 'Advertisers',
                'mailable' => NewSitesDigest::class,
                'status' => 'active',
            ],
            'weekly_activity_summary' => [
                'name' => 'Weekly Activity Summary',
                'description' => 'Weekly advertiser activity digest (scheduled).',
                'category' => 'Reports',
                'mailable' => WeeklyActivitySummary::class,
                'status' => 'active',
            ],
            'monthly_spending_summary' => [
                'name' => 'Monthly Spending Summary',
                'description' => 'Monthly advertiser spending digest (scheduled).',
                'category' => 'Reports',
                'mailable' => MonthlySpendingSummary::class,
                'status' => 'active',
            ],
            'spend_budget_alert' => [
                'name' => 'Spend Budget Alert',
                'description' => 'Soft alert when an advertiser hits 80% / 100% of their monthly spend budget, or drops below a low-balance threshold. Does not block checkout.',
                'category' => 'Billing',
                'mailable' => SpendBudgetAlertMail::class,
                'status' => 'active',
            ],
        ];
    }

    /**
     * Email Center cards, in config order. Config is the source of truth for
     * which types exist; the catalog supplies description / category / status.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function templates(): array
    {
        $catalog = self::all();
        $templates = [];

        foreach (config('email_notifications.types', []) as $key => $config) {
            $meta = $catalog[$key] ?? [];
            $templates[$key] = [
                'key' => $key,
                'name' => $meta['name'] ?? ($config['name'] ?? $key),
                'description' => $meta['description'] ?? '',
                'category' => $meta['category'] ?? 'Other',
                'mailable' => $meta['mailable'] ?? ($config['mailable'] ?? null),
                'status' => $meta['status'] ?? (! empty($config['framework']) ? 'framework' : 'active'),
                'importance' => $meta['importance'] ?? null,
                'audience' => $config['audience'] ?? 'user',
                'preference' => $config['preference'] ?? null,
                'framework' => (bool) ($config['framework'] ?? false),
                'default_enabled' => (bool) ($config['default_enabled'] ?? true),
            ];
        }

        return $templates;
    }

    public static function get(string $key): ?array
    {
        return self::templates()[$key] ?? null;
    }

    public static function keyFromMailable(?string $class): ?string
    {
        if (! $class) {
            return null;
        }

        foreach (self::all() as $key => $meta) {
            if (($meta['mailable'] ?? null) === $class) {
                return $key;
            }
        }

        return Str::of(class_basename($class))->snake()->toString();
    }

    public static function keyFromSubject(string $subject): ?string
    {
        $subject = strtolower($subject);
        $map = [
            'payment confirmed for order' => 'order_payment_confirmed',
            'payment successful' => 'payment_successful_invoice',
            'payment pending verification' => 'payment_pending',
            'payment failed' => 'payment_failed',
            'payment update for order' => 'order_status_changed',
            'welcome to' => 'welcome',
            'temporary password' => 'google_temp_password',
            'how was your experience' => 'trustpilot_review',
            'reset password' => 'password_reset',
            'password reset' => 'password_reset',
            'verify your email' => 'email_verification',
            'deposit approved' => 'deposit_approved',
            'wallet topped up' => 'deposit_approved',
            'paypal deposit refunded' => 'deposit_refunded',
            'paypal payment was not completed' => 'paypal_payment_not_completed',
            'paypal payment is under review' => 'paypal_payment_not_completed',
            'paypal refunded a completed order' => 'paypal_external_payment_notice',
            'partial paypal refund' => 'paypal_external_payment_notice',
            'paypal reversed' => 'paypal_external_payment_notice',
            'paypal buyer dispute' => 'paypal_external_payment_notice',
            'paypal dispute update' => 'paypal_external_payment_notice',
            'deposit request update' => 'deposit_rejected',
            'new deposit request' => 'deposit_submitted',
            'payment reported' => 'deposit_marked_paid',
            'new withdrawal request' => 'withdrawal_request',
            'withdrawal request received' => 'withdrawal_requested_confirmation',
            'withdrawal request ' => 'withdrawal_status',
            'new order for your site' => 'publisher_new_order',
            'manual payment required' => 'admin_manual_payment',
            'order accepted -' => 'order_accepted',
            'order rejected -' => 'order_rejected',
            'order approved by advertiser' => 'order_completed',
            'live url submitted' => 'live_url_submitted',
            'modification requested for order' => 'modification_requested',
            'publisher requested a revised article' => 'content_revision_requested',
            'revised article ready' => 'content_revision_fulfilled',
            'your article was approved' => 'content_evaluation_result',
            'article evaluation update' => 'content_evaluation_result',
            'your site discount has ended' => 'site_discount_ended',
            'your payout details were updated' => 'payout_profile_updated',
            'bulk site request from' => 'bulk_site_request_submitted',
            'your sites were added to pending sites' => 'bulk_sites_seeded',
            'please accept a website we added' => 'admin_assigned_site',
            'your bulk website request was cancelled' => 'bulk_request_cancelled',
            'we did not add' => 'bulk_request_items_rejected',
            'spend budget' => 'spend_budget_alert',
            'low wallet balance alert' => 'spend_budget_alert',
            'new site submitted for review' => 'new_site',
            'site updated - requires review' => 'new_site',
            'your site has been' => 'site_status',
            'site status update' => 'site_status',
            'your site verification' => 'site_status',
            'your site submission was not accepted' => 'site_status',
            'your site was archived' => 'site_status',
            'site claim:' => 'site_claim_submitted',
            'claim approved' => 'site_claim_reviewed',
            'claim update' => 'site_claim_reviewed',
            'ownership transferred' => 'site_claim_ownership_transferred',
            'website suggestion' => 'website_suggestion_reviewed',
            'new message regarding order' => 'chat_message',
            'refund receipt' => 'refund_receipt',
            'refund credited' => 'dispute_refund_advertiser',
            'clawback on order' => 'dispute_clawback_publisher',
            'new order #' => 'order_status_changed',
            'order #' => 'order_status_changed',
            'list your first website' => 'publisher_add_site_reminder',
            'finish setup' => 'publisher_add_site_reminder',
            'your €20 credit is waiting' => 'deposit_reminder',
            'add funds to place your first guest post' => 'deposit_reminder',
            'a paid order is waiting on you' => 'publisher_accept_nudge',
            'is still unaccepted' => 'publisher_accept_nudge',
            'waiting to be published' => 'publisher_publish_nudge',
            'due soon: publish order' => 'publisher_publish_nudge',
            'past its turnaround' => 'publisher_publish_nudge',
            'still overdue: order' => 'publisher_publish_nudge',
            'needs publishing' => 'publisher_publish_nudge',
            'your link is live' => 'advertiser_review_nudge',
            '1 day left to review order' => 'auto_approve_reminder',
            'we are chasing your order' => 'advertiser_order_stalled',
            '[admin] order' => 'admin_stalled_order',
            'new advertiser registered' => 'admin_new_user',
            'new publisher registered' => 'admin_new_user',
            'new user registered' => 'admin_new_user',
            'new sites in the catalog' => 'new_sites_digest',
            'new sites just added' => 'new_sites_digest',
            'weekly activity summary' => 'weekly_activity_summary',
            'your spending summary' => 'monthly_spending_summary',
        ];

        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($map as $needle => $key) {
            if (str_contains($subject, $needle)) {
                return $key;
            }
        }

        return null;
    }

    public static function makeMailable(string $key, array $options = []): ?Mailable
    {
        $meta = self::get($key);
        if (! $meta || empty($meta['mailable'])) {
            return null;
        }

        $class = $meta['mailable'];

        $order = self::sampleOrder();
        $item = self::sampleOrderItem();
        $site = self::sampleSite();
        $user = self::sampleUser();
        $audience = in_array($options['audience'] ?? '', ['advertiser', 'publisher', 'admin'], true)
            ? $options['audience']
            : 'advertiser';

        return match ($key) {
            'welcome' => new WelcomeEmail($user),
            'google_temp_password' => new GoogleTempPasswordMail($user, 'SampleTemp-Pass1'),
            'order_status_changed' => new OrderStatusChanged(
                order: $order,
                recipient: $user,
                audience: $audience,
                changeKind: 'status',
                previousValue: 'pending',
                newValue: 'processing',
                description: match ($audience) {
                    'publisher' => 'This order is now processing — please continue the placement.',
                    'admin' => 'Order status changed to processing (admin copy).',
                    default => 'Great news — the publisher accepted this order and work can begin.',
                },
            ),
            'order_payment_confirmed' => new OrderPaymentConfirmed($order),
            'payment_successful_invoice' => new PaymentSuccessfulInvoiceMail(self::sampleTaxInvoice()),
            'payment_failed' => new PaymentFailedMail(self::sampleFailureDocument()),
            'payment_pending' => new PaymentPendingMail($order),
            'refund_receipt' => new RefundReceiptMail(self::sampleRefundDocument()),
            'order_completed' => new OrderApprovedByAdvertiser($order, $item, $site),
            'publisher_new_order' => new SiteOwnerOrderNotification($site, [$order]),
            'order_accepted' => new OrderAccepted($order, $item, $site),
            'order_rejected' => new OrderRejected($order, $item, $site, 'Sample rejection reason for preview.'),
            'dispute_clawback_publisher' => new DisputeClawbackPublisher(self::sampleDispute(), $user, 100.0, 0.0),
            'dispute_refund_advertiser' => new DisputeRefundAdvertiser(self::sampleDispute(), $user, 115.0),
            'live_url_submitted' => new LiveUrlSubmitted($order, $item, $site, 'https://example.com/sample-live-url'),
            'modification_requested' => new ModificationRequested($order, 'Please update the anchor text.'),
            'content_revision_requested' => new ContentRevisionRequested($order, $item, $site, 'Please send a cleaner draft with the correct brand mentions.'),
            'content_revision_fulfilled' => new ContentRevisionFulfilled($order, $item, $site),
            'admin_manual_payment' => new AdminManualPaymentNotification(
                $user,
                [$order],
                'bank_transfer',
                (float) $order->total_amount
            ),
            'deposit_submitted' => new DepositRequestSubmitted(self::sampleDeposit()),
            'deposit_marked_paid' => new DepositMarkedPaid(self::sampleDeposit()),
            'deposit_approved' => new DepositApproved(self::sampleDeposit()),
            'deposit_refunded' => new DepositRefunded(self::samplePaypalRefundedDeposit()),
            'paypal_payment_not_completed' => new PaypalPaymentNotCompleted(
                $user,
                PaypalPaymentNotCompleted::KIND_CHECKOUT,
                'PP-PREVIEW',
                PaypalPaymentNotCompleted::REASON_DECLINED
            ),
            'paypal_external_payment_notice' => new PaypalExternalPaymentNotice(
                $user,
                PaypalExternalPaymentNotice::AUDIENCE_ADVERTISER,
                PaypalExternalPaymentNotice::KIND_COMPLETED_REFUND,
                'PP-PREVIEW',
                80.0,
                $order,
                'RF-PREVIEW'
            ),
            'deposit_rejected' => new DepositRejected(self::sampleDeposit()),
            'withdrawal_request' => new WithdrawalRequestNotification(self::sampleWithdrawal(), $user),
            'withdrawal_requested_confirmation' => new WithdrawalRequestedConfirmation(self::sampleWithdrawal()),
            'withdrawal_status' => new WithdrawalStatusUpdated(
                self::sampleWithdrawal(),
                'pending',
                'approved',
                'Sample approval notes for preview.'
            ),
            'new_site' => new NewSiteNotification($site, 'create'),
            'site_status' => new SiteStatusNotification(
                $site,
                'deactivated',
                null,
                'Listing deactivated due to quality or policy concerns. Contact support if you need details.'
            ),
            'chat_message' => new NewChatMessageNotification(
                $order,
                $user,
                'This is a sample chat message for email preview.',
                'Sample Receiver'
            ),
            'trustpilot_review' => new TrustpilotReviewRequest($user, $order),
            'admin_new_user' => new AdminNewUserRegistered($user, $user),
            'site_claim_submitted' => new SiteClaimSubmitted(self::sampleSiteClaim()),
            'site_claim_reviewed' => new SiteClaimReviewed(self::sampleSiteClaim('approved')),
            'site_claim_ownership_transferred' => new SiteClaimOwnershipTransferred(self::sampleSiteClaim('approved'), $user),
            'community_feedback_reviewed' => new CommunityFeedbackReviewed(self::sampleProblemReport(), 'problem'),
            'website_suggestion_reviewed' => new WebsiteSuggestionReviewed(self::sampleWebsiteSuggestion()),
            'publisher_add_site_reminder' => new PublisherAddSiteReminderMail($user, PublisherAddSiteReminderMail::STEP_DAY3),
            'deposit_reminder' => new DepositReminderMail($user, DepositReminderMail::STEP_DAY14),
            'publisher_accept_nudge' => new PublisherAcceptNudge($user, $order, $item, $site, 2, 36),
            'publisher_publish_nudge' => new PublisherPublishNudge($user, collect([
                [
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'site_name' => (string) ($site->site_name ?: 'example.com'),
                    'due_at' => now()->subDays(2),
                    'hours_overdue' => 48,
                    'overdue_label' => '2 days late',
                    'promised' => '3days',
                    'payout' => 84.0,
                ],
            ]), 2, 'preview'),
            'advertiser_review_nudge' => new AdvertiserReviewNudge($user, $order, $item, $site, now()->addDays(2)),
            'auto_approve_reminder' => new AutoApproveReminderMail($order, $item, $site, 24),
            'advertiser_order_stalled' => new AdvertiserOrderStalledNotice($user, $order, $item, $site, now()->subDays(3), 72),
            'admin_stalled_order' => new AdminStalledOrderAlert($order, $item, $site, $user, 3, 96, 'publish'),
            'new_sites_digest' => new NewSitesDigest($user, collect([
                ['site' => $site, 'price' => 90.0, 'was' => 120.0, 'discount' => 25, 'is_new' => true],
                ['site' => $site, 'price' => 140.0, 'was' => null, 'discount' => null, 'is_new' => true],
                ['site' => $site, 'price' => 210.0, 'was' => null, 'discount' => null, 'is_new' => false],
            ])),
            'weekly_activity_summary' => new WeeklyActivitySummary($user, [
                'orders' => 3,
                'spend' => 199.5,
                'completed' => 1,
                'week_key' => now()->format('o-\WW'),
            ]),
            'monthly_spending_summary' => new MonthlySpendingSummary($user, [
                'month_key' => now()->format('Y-m'),
                'month_label' => now()->format('F Y'),
                'spend' => 499.0,
                'orders' => 7,
                'aov' => 71.28,
            ]),
            'spend_budget_alert' => new SpendBudgetAlertMail($user, 'warn', [
                'has_budget' => true,
                'monthly_limit' => 500.0,
                'committed' => 420.0,
                'percent' => 84.0,
                'warn_at_percent' => 80,
                'over_warn' => true,
                'over_limit' => false,
                'low_balance' => false,
                'spendable' => 80.0,
                'low_balance_threshold' => 50.0,
            ]),
            'content_evaluation_result' => new ContentEvaluationResult(self::sampleContentSubmission(), [
                'approved' => true,
                'notify_status' => 'approved',
                'moderation_status' => 'approved',
            ]),
            'site_discount_ended' => new SiteDiscountEnded($site, $user, 20.0, now()->subDay()),
            'payout_profile_updated' => new PayoutProfileUpdatedBySupport($user, 'paypal'),
            'bulk_site_request_submitted' => new BulkSiteRequestSubmitted(
                self::sampleBulkSiteRequest(),
                rtrim(app_public_url(), '/').'/admin/bulk-site-requests/0',
                $user
            ),
            'bulk_sites_seeded' => new BulkSitesSeededNotification(self::sampleBulkSiteRequest(), 3, $user, ['example.com', 'sample-two.example']),
            'admin_assigned_site' => new AdminAssignedSiteNotification($site, $user),
            'audience_campaign' => new AudienceCampaignMail(self::sampleCampaign(), $user),
            'bulk_request_cancelled' => new BulkSiteRequestCancelled(self::sampleBulkSiteRequest(), $user, 'Sample cancellation reason for preview.'),
            'bulk_request_items_rejected' => new BulkSiteItemsRejected(
                self::sampleBulkSiteRequest(),
                $user,
                ['rejected.example'],
                'Did not meet catalog quality guidelines (preview).',
                [self::PREVIEW_ID]
            ),
            default => null,
        };
    }

    protected static function sampleUser(): User
    {
        $user = new User([
            'name' => 'Sample User',
            'email' => self::PREVIEW_EMAIL,
        ]);
        $user->id = self::PREVIEW_ID;
        $user->exists = false;
        $user->setRelation('roles', collect());

        return $user;
    }

    protected static function sampleOrder(): Order
    {
        $user = self::sampleUser();
        $order = new Order([
            'order_number' => 'ORD-PREVIEW',
            'total_amount' => 99.00,
            'subtotal' => 99.00,
            'tax' => 0,
            'payment_status' => 'paid',
            'status' => 'completed',
            'payment_method' => 'wallet',
        ]);
        $order->id = self::PREVIEW_ID;
        $order->exists = false;
        $order->user_id = $user->id;
        $order->setRelation('user', $user);
        $item = self::sampleOrderItem();
        $item->order_id = $order->id;
        $order->setRelation('items', collect([$item]));
        $order->created_at = now();

        return $order;
    }

    protected static function sampleOrderItem(): OrderItem
    {
        $item = new OrderItem([
            'site_name' => 'Sample Publisher Site',
            'site_url' => 'https://example.com',
            'price' => 138.00,
            'additional_price' => 0,
            'homepage_days' => 7,
            'homepage_price' => 25.00,
            'social_channels' => ['facebook', 'x'],
            'content_link' => 'https://example.com/content.docx',
        ]);
        $item->id = self::PREVIEW_ID;
        $item->exists = false;
        $item->setRelation('site', self::sampleSite());

        return $item;
    }

    protected static function sampleDispute(): OrderItemDispute
    {
        $order = self::sampleOrder();
        $item = self::sampleOrderItem();
        $dispute = new OrderItemDispute([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'The live article was removed after completion (preview).',
            'admin_notes' => 'Confirmed 404. Sample clawback notes for preview.',
            'publisher_debited' => 100.00,
            'advertiser_credited' => 115.00,
            'debt_created' => 0,
        ]);
        $dispute->id = self::PREVIEW_ID;
        $dispute->exists = false;
        $dispute->setRelation('order', $order);
        $dispute->setRelation('orderItem', $item);

        return $dispute;
    }

    protected static function sampleSite(): Site
    {
        $user = self::sampleUser();
        $site = new Site([
            'site_name' => 'Sample Site',
            'site_url' => 'https://example.com',
            'domain' => 'example.com',
            'publisher_id' => $user->id,
            'verified' => true,
            'active' => true,
        ]);
        $site->id = self::PREVIEW_ID;
        $site->exists = false;
        $site->setRelation('publisher', $user);

        return $site;
    }

    protected static function sampleSiteClaim(string $status = 'pending'): SiteClaim
    {
        $site = self::sampleSite();
        $user = self::sampleUser();
        $claim = new SiteClaim([
            'site_id' => $site->id,
            'claimer_id' => $user->id,
            'website_name' => $site->site_name ?: 'Sample Site',
            'website_url' => $site->site_url ?: 'https://example.com',
            'domain' => $site->domain ?: 'example.com',
            'name_matches' => true,
            'proof_message' => 'Sample ownership proof for email preview.',
            'contact_email' => $user->email,
            'status' => $status,
            'admin_notes' => $status === 'approved' ? 'Verified via domain email (preview).' : null,
        ]);
        $claim->id = self::PREVIEW_ID;
        $claim->exists = false;
        $claim->setRelation('site', $site);
        $claim->setRelation('claimer', $user);

        return $claim;
    }

    protected static function sampleProblemReport(): ProblemReport
    {
        $user = self::sampleUser();
        $report = new ProblemReport([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'subject' => 'Checkout button on mobile',
            'message' => 'Sample problem report for email preview.',
            'status' => 'resolved',
            'admin_notes' => 'Fixed the mobile CTA (preview).',
        ]);
        $report->id = self::PREVIEW_ID;
        $report->exists = false;
        $report->setRelation('user', $user);

        return $report;
    }

    protected static function sampleWebsiteSuggestion(): WebsiteSuggestion
    {
        $user = self::sampleUser();
        $suggestion = new WebsiteSuggestion([
            'user_id' => $user->id,
            'website_name' => 'Sample Tech Blog',
            'website_url' => 'https://sample-tech.example',
            'domain' => 'sample-tech.example',
            'status' => 'accepted',
            'admin_notes' => 'We will try to add this listing (preview).',
        ]);
        $suggestion->id = self::PREVIEW_ID;
        $suggestion->exists = false;
        $suggestion->setRelation('user', $user);

        return $suggestion;
    }

    protected static function sampleDeposit(): DepositRequest
    {
        $user = self::sampleUser();
        $deposit = new DepositRequest([
            'user_id' => $user->id,
            'amount' => 100,
            'status' => 'approved',
            'payment_method' => 'bank_transfer',
            'reference_code' => 'DEP-PREVIEW',
        ]);
        $deposit->id = self::PREVIEW_ID;
        $deposit->exists = false;
        $deposit->created_at = now();
        $deposit->updated_at = now();
        $deposit->approved_at = now();
        $deposit->rejected_at = now();
        $deposit->setRelation('user', $user);

        return $deposit;
    }

    protected static function samplePaypalRefundedDeposit(): DepositRequest
    {
        $deposit = self::sampleDeposit();
        $deposit->payment_method = 'paypal';
        $deposit->status = 'refunded';
        $deposit->paypal_response = [
            'refund' => [
                'id' => 'RF-PREVIEW',
                'amount' => 100,
                'debited' => 100,
                'debt_created' => 0,
            ],
        ];

        return $deposit;
    }

    protected static function sampleTaxInvoice(): Invoice
    {
        $user = self::sampleUser();
        $order = self::sampleOrder();
        $invoice = new Invoice([
            'invoice_number' => 'INV-PREVIEW-000001',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'total_amount' => 99.00,
            'subtotal' => 99.00,
            'tax_amount' => 0,
            'currency' => 'EUR',
            'customer_name' => $user->name,
            'customer_email' => $user->email,
        ]);
        $invoice->id = self::PREVIEW_ID;
        $invoice->exists = false;
        $invoice->setRelation('user', $user);
        $invoice->setRelation('order', $order);

        return $invoice;
    }

    protected static function sampleFailureDocument(): Invoice
    {
        $user = self::sampleUser();
        $order = self::sampleOrder();
        $invoice = new Invoice([
            'invoice_number' => 'RCPT-PREVIEW-000001',
            'type' => Invoice::TYPE_PAYMENT_FAILURE,
            'status' => Invoice::STATUS_FAILED,
            'total_amount' => 99.00,
            'subtotal' => 99.00,
            'tax_amount' => 0,
            'currency' => 'EUR',
            'notes' => 'Sample payment failure for preview.',
            'meta' => ['failure_reason' => 'Card declined (preview).'],
            'customer_name' => $user->name,
            'customer_email' => $user->email,
        ]);
        $invoice->id = self::PREVIEW_ID;
        $invoice->exists = false;
        $invoice->setRelation('user', $user);
        $invoice->setRelation('order', $order);

        return $invoice;
    }

    protected static function sampleRefundDocument(): Invoice
    {
        $user = self::sampleUser();
        $order = self::sampleOrder();
        $parent = self::sampleTaxInvoice();
        $invoice = new Invoice([
            'invoice_number' => 'CN-PREVIEW-000001',
            'type' => Invoice::TYPE_REFUND_RECEIPT,
            'status' => Invoice::STATUS_REFUNDED,
            'total_amount' => 99.00,
            'subtotal' => 99.00,
            'tax_amount' => 0,
            'currency' => 'EUR',
            'notes' => 'Sample refund for preview.',
            'meta' => ['refund_reason' => 'Publisher rejected (preview).'],
            'customer_name' => $user->name,
            'customer_email' => $user->email,
        ]);
        $invoice->id = self::PREVIEW_ID;
        $invoice->exists = false;
        $invoice->setRelation('user', $user);
        $invoice->setRelation('order', $order);
        $invoice->setRelation('parentInvoice', $parent);

        return $invoice;
    }

    protected static function sampleWithdrawal(): Withdrawal
    {
        $user = self::sampleUser();
        $withdrawal = new Withdrawal([
            'user_id' => $user->id,
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'status' => 'pending',
            'payment_method' => 'paypal',
        ]);
        $withdrawal->id = self::PREVIEW_ID;
        $withdrawal->exists = false;
        $withdrawal->created_at = now();
        $withdrawal->updated_at = now();
        $withdrawal->processed_at = now();
        $withdrawal->setRelation('user', $user);

        return $withdrawal;
    }

    protected static function sampleContentSubmission(): ContentSubmission
    {
        $user = self::sampleUser();
        $submission = new ContentSubmission([
            'user_id' => $user->id,
            'title' => 'Sample guest post for preview',
            'original_filename' => 'sample-article.docx',
            'moderation_status' => 'approved',
        ]);
        $submission->id = self::PREVIEW_ID;
        $submission->exists = false;
        $submission->setRelation('user', $user);

        return $submission;
    }

    protected static function sampleBulkSiteRequest(): BulkSiteRequest
    {
        $user = self::sampleUser();
        $request = new BulkSiteRequest([
            'publisher_id' => $user->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
            'publisher_note' => 'Sample bulk request for email preview.',
        ]);
        $request->id = self::PREVIEW_ID;
        $request->exists = false;
        $request->setRelation('publisher', $user);
        $request->setRelation('items', collect());

        return $request;
    }

    protected static function sampleCampaign(): EmailCampaign
    {
        $campaign = new EmailCampaign([
            'name' => 'Sample campaign',
            'subject' => 'Sample platform update',
            'body_html' => '<p>This is a sample campaign body for Email Center preview.</p>',
            'audience' => 'advertisers',
            'cta_label' => 'Open catalog',
            'cta_url' => rtrim(app_public_url(), '/').'/advertiser/catalog',
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);
        $campaign->id = self::PREVIEW_ID;
        $campaign->exists = false;

        return $campaign;
    }
}
