<?php

namespace App\Support;

use App\Mail\AdminManualPaymentNotification;
use App\Mail\AdminNewUserRegistered;
use App\Mail\AdminStalledOrderAlert;
use App\Mail\AdvertiserOrderStalledNotice;
use App\Mail\AdvertiserReviewNudge;
use App\Mail\AutoApproveReminderMail;
use App\Mail\ContentRevisionFulfilled;
use App\Mail\ContentRevisionRequested;
use App\Mail\DepositApproved;
use App\Mail\DepositMarkedPaid;
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
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherAddSiteReminderMail;
use App\Mail\PublisherPublishNudge;
use App\Mail\RefundReceiptMail;
use App\Mail\SiteClaimOwnershipTransferred;
use App\Mail\SiteClaimReviewed;
use App\Mail\SiteClaimSubmitted;
use App\Mail\SiteOwnerOrderNotification;
use App\Mail\SiteStatusNotification;
use App\Mail\SpendBudgetAlertMail;
use App\Mail\TrustpilotReviewRequest;
use App\Mail\WeeklyActivitySummary;
use App\Mail\WelcomeEmail;
use App\Mail\WithdrawalRequestedConfirmation;
use App\Mail\WithdrawalRequestNotification;
use App\Mail\WithdrawalStatusUpdated;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

class EmailCatalog
{
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
                'description' => 'Advertiser notified when a wallet top-up settles (admin bank/Wise approve or Stripe card), with receipt PDF attached.',
                'category' => 'Billing',
                'mailable' => DepositApproved::class,
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

    public static function get(string $key): ?array
    {
        $all = self::all();

        return $all[$key] ?? null;
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
            'payment confirmed' => 'order_payment_confirmed',
            'welcome' => 'welcome',
            'temporary password' => 'google_temp_password',
            'trustpilot' => 'trustpilot_review',
            'reset password' => 'password_reset',
            'deposit approved' => 'deposit_approved',
            'deposit rejected' => 'deposit_rejected',
            'withdrawal' => 'withdrawal_status',
            'new order' => 'publisher_new_order',
            'order accepted' => 'order_accepted',
            'live url' => 'live_url_submitted',
        ];

        foreach ($map as $needle => $key) {
            if (str_contains($subject, $needle)) {
                return $key;
            }
        }

        return null;
    }

    public static function makeMailable(string $key): ?Mailable
    {
        $meta = self::get($key);
        if (! $meta || empty($meta['mailable'])) {
            return null;
        }

        $class = $meta['mailable'];

        // Billing previews must not query orders/sites. A missing orders
        // table (or a failed sampleOrder) would 500 Email Center. Samples
        // are in-memory (id 0) so preview/test-send cannot mint a live
        // signed settle/approve CTA or reconcile a real PAY row.
        if (in_array($key, ['withdrawal_request', 'withdrawal_requested_confirmation', 'withdrawal_status'], true)) {
            $user = self::sampleUser();

            return match ($key) {
                'withdrawal_request' => new WithdrawalRequestNotification(self::sampleWithdrawal(), $user),
                'withdrawal_requested_confirmation' => new WithdrawalRequestedConfirmation(self::sampleWithdrawal()),
                default => new WithdrawalStatusUpdated(
                    self::sampleWithdrawal(),
                    'pending',
                    'completed',
                    'Sample approval notes for preview.'
                ),
            };
        }

        if (in_array($key, ['deposit_submitted', 'deposit_marked_paid', 'deposit_approved', 'deposit_rejected'], true)) {
            return match ($key) {
                'deposit_submitted' => new DepositRequestSubmitted(self::sampleDeposit()),
                'deposit_marked_paid' => new DepositMarkedPaid(self::sampleDeposit()),
                'deposit_approved' => new DepositApproved(self::sampleDeposit()),
                default => new DepositRejected(self::sampleDeposit()),
            };
        }

        if (in_array($key, ['payment_successful_invoice', 'payment_failed', 'refund_receipt'], true)) {
            return match ($key) {
                'payment_successful_invoice' => new PaymentSuccessfulInvoiceMail(self::sampleTaxInvoice()),
                'payment_failed' => new PaymentFailedMail(self::sampleFailureDocument()),
                default => new RefundReceiptMail(self::sampleRefundDocument()),
            };
        }

        $order = self::sampleOrder();
        $item = self::sampleOrderItem();
        $site = self::sampleSite();
        $user = self::sampleUser();

        return match ($key) {
            'welcome' => new WelcomeEmail($user),
            'google_temp_password' => new GoogleTempPasswordMail($user, 'SampleTemp-Pass1'),
            'order_status_changed' => new OrderStatusChanged(
                order: $order,
                recipient: $user,
                audience: 'advertiser',
                changeKind: 'status',
                previousValue: 'pending',
                newValue: 'processing',
                description: 'Great news — the publisher accepted this order and work can begin.',
            ),
            'order_payment_confirmed' => new OrderPaymentConfirmed($order),
            'payment_pending' => new PaymentPendingMail($order),
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
            default => null,
        };
    }

    protected static function sampleUser(): User
    {
        $user = new User([
            'name' => 'Sample User',
            'email' => 'sample@example.com',
        ]);
        $user->id = 0;

        return $user;
    }

    protected static function sampleOrder(): Order
    {
        $order = Order::query()->with(['user', 'items.site'])->latest('id')->first();
        if ($order) {
            return $order;
        }

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
        $order->id = 0;
        $order->user_id = $user->id ?? 0;
        $order->setRelation('user', $user);
        $order->setRelation('items', collect());
        $order->created_at = now();

        return $order;
    }

    protected static function sampleOrderItem(): OrderItem
    {
        $item = OrderItem::query()->with('site')->latest('id')->first();
        if ($item) {
            return $item;
        }

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
        $item->setRelation('site', self::sampleSite());

        return $item;
    }

    protected static function sampleDispute(): OrderItemDispute
    {
        $order = self::sampleOrder();
        $item = self::sampleOrderItem();
        $dispute = new OrderItemDispute([
            'order_id' => $order->id ?? 0,
            'order_item_id' => $item->id ?? 0,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'The live article was removed after completion (preview).',
            'admin_notes' => 'Confirmed 404. Sample clawback notes for preview.',
            'publisher_debited' => 100.00,
            'advertiser_credited' => 115.00,
            'debt_created' => 0,
        ]);
        $dispute->id = 0;
        $dispute->setRelation('order', $order);
        $dispute->setRelation('orderItem', $item);

        return $dispute;
    }

    protected static function sampleSite(): Site
    {
        $site = Site::query()->with('publisher')->latest('id')->first();
        if ($site) {
            return $site;
        }

        $user = self::sampleUser();
        $site = new Site([
            'site_name' => 'Sample Site',
            'site_url' => 'https://example.com',
            'publisher_id' => $user->id ?? 0,
            'verified' => true,
            'active' => true,
        ]);
        $site->id = 0;
        $site->setRelation('publisher', $user);

        return $site;
    }

    protected static function sampleSiteClaim(string $status = 'pending'): SiteClaim
    {
        $claim = SiteClaim::query()->with(['site', 'claimer'])->latest('id')->first();
        if ($claim) {
            return $claim;
        }

        $site = self::sampleSite();
        $user = self::sampleUser();
        $claim = new SiteClaim([
            'site_id' => $site->id,
            'claimer_id' => $user->id ?? 0,
            'website_name' => $site->site_name ?: 'Sample Site',
            'website_url' => $site->site_url ?: 'https://example.com',
            'domain' => $site->domain ?: 'example.com',
            'name_matches' => true,
            'proof_message' => 'Sample ownership proof for email preview.',
            'contact_email' => $user->email ?? 'sample@example.com',
            'status' => $status,
            'admin_notes' => $status === 'approved' ? 'Verified via domain email (preview).' : null,
        ]);
        $claim->id = 0;
        $claim->setRelation('site', $site);
        $claim->setRelation('claimer', $user);

        return $claim;
    }

    protected static function sampleDeposit(): DepositRequest
    {
        $deposit = new DepositRequest([
            'amount' => 100,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'reference_code' => 'DEP-PREVIEW',
        ]);
        $deposit->id = 0;
        $deposit->created_at = now();
        $deposit->updated_at = now();
        $deposit->approved_at = now();
        $deposit->rejected_at = now();
        $deposit->setRelation('user', self::sampleUser());

        return $deposit;
    }

    protected static function sampleTaxInvoice(): Invoice
    {
        $user = self::sampleUser();
        $order = self::samplePreviewOrder();
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
        $invoice->id = 0;
        $invoice->setRelation('user', $user);
        $invoice->setRelation('order', $order);

        return $invoice;
    }

    protected static function sampleFailureDocument(): Invoice
    {
        $user = self::sampleUser();
        $order = self::samplePreviewOrder();
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
        $invoice->id = 0;
        $invoice->setRelation('user', $user);
        $invoice->setRelation('order', $order);

        return $invoice;
    }

    protected static function sampleRefundDocument(): Invoice
    {
        $user = self::sampleUser();
        $order = self::samplePreviewOrder();
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
        $invoice->id = 0;
        $invoice->setRelation('user', $user);
        $invoice->setRelation('order', $order);
        $invoice->setRelation('parentInvoice', $parent);

        return $invoice;
    }

    protected static function samplePreviewOrder(): Order
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
        $order->id = 0;
        $order->user_id = 0;
        $order->setRelation('user', $user);
        $order->setRelation('items', collect());
        $order->created_at = now();

        return $order;
    }

    protected static function sampleWithdrawal(): Withdrawal
    {
        $withdrawal = new Withdrawal([
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'status' => 'pending',
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'publisher@example.com'],
        ]);
        $withdrawal->id = 0;
        $withdrawal->created_at = now();
        $withdrawal->updated_at = now();
        $withdrawal->processed_at = now();
        $withdrawal->setRelation('user', self::sampleUser());

        return $withdrawal;
    }
}
