<?php

namespace App\Support;

use App\Mail\AdminManualPaymentNotification;
use App\Mail\AdminNewUserRegistered;
use App\Mail\AdminStalledOrderAlert;
use App\Mail\AdvertiserOrderStalledNotice;
use App\Mail\AdvertiserReviewNudge;
use App\Mail\AutoApproveReminderMail;
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
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherAddSiteReminderMail;
use App\Mail\PublisherPublishNudge;
use App\Mail\SiteOwnerOrderNotification;
use App\Mail\SiteStatusNotification;
use App\Mail\TrustpilotReviewRequest;
use App\Mail\WeeklyActivitySummary;
use App\Mail\WelcomeEmail;
use App\Mail\WithdrawalRequestNotification;
use App\Mail\WithdrawalStatusUpdated;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
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
                'description' => 'Lifecycle update sent to Advertiser, Publisher, Marketing, and Admin on every status/payment change.',
                'category' => 'Orders',
                'mailable' => OrderStatusChanged::class,
                'status' => 'active',
            ],
            'order_payment_confirmed' => [
                'name' => 'Payment Success',
                'description' => 'Advertiser receipt after successful order payment.',
                'category' => 'Orders',
                'mailable' => OrderPaymentConfirmed::class,
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
                'description' => 'Admins notified of a new publisher withdrawal.',
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
            'order_completed' => new OrderApprovedByAdvertiser($order, $item, $site),
            'publisher_new_order' => new SiteOwnerOrderNotification($site, [$order]),
            'order_accepted' => new OrderAccepted($order, $item, $site),
            'order_rejected' => new OrderRejected($order, $item, $site, 'Sample rejection reason for preview.'),
            'live_url_submitted' => new LiveUrlSubmitted($order, $item, $site, 'https://example.com/sample-live-url'),
            'modification_requested' => new ModificationRequested($order, 'Please update the anchor text.'),
            'admin_manual_payment' => new AdminManualPaymentNotification(
                $user,
                [$order],
                'bank_transfer',
                (float) $order->total_amount
            ),
            'deposit_submitted' => new DepositRequestSubmitted(self::sampleDeposit()),
            'deposit_marked_paid' => new DepositMarkedPaid(self::sampleDeposit()),
            'deposit_approved' => new DepositApproved(self::sampleDeposit()),
            'deposit_rejected' => new DepositRejected(self::sampleDeposit()),
            'withdrawal_request' => new WithdrawalRequestNotification(self::sampleWithdrawal(), $user),
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
            default => null,
        };
    }

    protected static function sampleUser(): User
    {
        return User::query()->first() ?? new User([
            'name' => 'Sample User',
            'email' => 'sample@example.com',
        ]);
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
            'price' => 99.00,
        ]);
        $item->setRelation('site', self::sampleSite());

        return $item;
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

    protected static function sampleDeposit(): DepositRequest
    {
        $deposit = DepositRequest::query()->with('user')->latest('id')->first();
        if ($deposit) {
            return $deposit;
        }

        $deposit = new DepositRequest([
            'amount' => 100,
            'status' => 'pending',
            'payment_method' => 'bank_transfer',
            'reference_code' => 'DEP-PREVIEW',
        ]);
        $deposit->id = 1;
        $deposit->created_at = now();
        $deposit->updated_at = now();
        $deposit->approved_at = now();
        $deposit->rejected_at = now();
        $deposit->setRelation('user', self::sampleUser());

        return $deposit;
    }

    protected static function sampleWithdrawal(): Withdrawal
    {
        $withdrawal = Withdrawal::query()->with('user')->latest('id')->first();
        if ($withdrawal) {
            return $withdrawal;
        }

        $withdrawal = new Withdrawal([
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'status' => 'pending',
            'payment_method' => 'paypal',
        ]);
        $withdrawal->id = 1;
        $withdrawal->created_at = now();
        $withdrawal->updated_at = now();
        $withdrawal->processed_at = now();
        $withdrawal->setRelation('user', self::sampleUser());

        return $withdrawal;
    }
}
