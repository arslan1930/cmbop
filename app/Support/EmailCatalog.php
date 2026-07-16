<?php

namespace App\Support;

use App\Mail\AdminManualPaymentNotification;
use App\Mail\DepositApproved;
use App\Mail\DepositRejected;
use App\Mail\DepositRequestSubmitted;
use App\Mail\LiveUrlSubmitted;
use App\Mail\ModificationRequested;
use App\Mail\NewChatMessageNotification;
use App\Mail\NewSiteNotification;
use App\Mail\OrderAccepted;
use App\Mail\OrderApprovedByAdvertiser;
use App\Mail\OrderPaymentConfirmed;
use App\Mail\OrderRejected;
use App\Mail\SiteOwnerOrderNotification;
use App\Mail\SiteStatusNotification;
use App\Mail\TrustpilotReviewRequest;
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
                'description' => 'Sent when a new advertiser/publisher completes registration.',
                'category' => 'Users',
                'mailable' => WelcomeEmail::class,
                'status' => 'ready', // template ready; wire into register when you want auto-send
                'importance' => 'Recommended: not auto-sent yet — wire into registration to improve activation.',
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
                'description' => 'Admins notified of a new wallet deposit request.',
                'category' => 'Billing',
                'mailable' => DepositRequestSubmitted::class,
                'status' => 'active',
            ],
            'deposit_approved' => [
                'name' => 'Deposit Approved',
                'description' => 'User notified when a deposit is approved.',
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
                'status' => 'ready',
                'importance' => 'High impact for social proof — template ready; not auto-sent yet (avoids changing live notification flow).',
            ],
            'password_reset' => [
                'name' => 'Password Reset',
                'description' => 'Laravel auth password reset email (framework notification).',
                'category' => 'Auth',
                'mailable' => null,
                'status' => 'framework',
                'importance' => 'Managed by Laravel auth — preview shows a branded sample; do not duplicate send logic.',
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
        if (!$class) {
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
        if (!$meta || empty($meta['mailable'])) {
            return null;
        }

        $class = $meta['mailable'];

        $order = self::sampleOrder();
        $item = self::sampleOrderItem();
        $site = self::sampleSite();
        $user = self::sampleUser();

        return match ($key) {
            'welcome' => new WelcomeEmail($user),
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
            'site_status' => new SiteStatusNotification($site, 'verified'),
            'chat_message' => new NewChatMessageNotification(
                $order,
                $user,
                'This is a sample chat message for email preview.',
                'Sample Receiver'
            ),
            'trustpilot_review' => new TrustpilotReviewRequest($user, $order),
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
