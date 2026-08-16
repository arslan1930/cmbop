<?php

namespace Tests\Feature;

use App\Mail\AdminManualPaymentNotification;
use App\Mail\AdminStalledOrderAlert;
use App\Mail\AdvertiserOrderStalledNotice;
use App\Mail\AdvertiserReviewNudge;
use App\Mail\AutoApproveReminderMail;
use App\Mail\LiveUrlSubmitted;
use App\Mail\ModificationRequested;
use App\Mail\OrderAccepted;
use App\Mail\OrderApprovedByAdvertiser;
use App\Mail\OrderPaymentConfirmed;
use App\Mail\OrderRejected;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentPendingMail;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherPublishNudge;
use App\Mail\RefundReceiptMail;
use App\Mail\SiteOwnerOrderNotification;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderEmailDeepLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    private Order $order;

    private OrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->advertiser = $this->userWithRole('advertiser');
        $this->publisher = $this->userWithRole('publisher');

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Deep Link Site',
            'site_url' => 'https://deep-link.example',
            'domain' => 'deep-link.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1500,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Order email deep link fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-DEEP-1',
            'reference_code' => 'REF-DEEP-1',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'status' => 'processing',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);

        $this->item = OrderItem::create([
            'order_id' => $this->order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://deep-link.example/article',
            'live_url' => 'https://deep-link.example/live-post',
            'price' => 100,
            'additional_price' => 0,
            'status' => 'review',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function assertAdvertiserOrderDeepLink(string $html): void
    {
        $this->assertStringContainsString('/advertiser/orders', $html);
        $this->assertStringContainsString('focus=order', $html);
        $this->assertStringContainsString('order='.$this->order->id, $html);
    }

    private function assertPublisherTasksDeepLink(string $html): void
    {
        $this->assertStringContainsString('/publisher/tasks', $html);
        $this->assertStringContainsString('focus=order', $html);
        $this->assertStringContainsString('order='.$this->order->id, $html);
    }

    public function test_advertiser_order_emails_deep_link_to_the_order(): void
    {
        $cases = [
            (new LiveUrlSubmitted($this->order, $this->item, $this->site, $this->item->live_url))->render(),
            (new AutoApproveReminderMail($this->order, $this->item, $this->site, 24))->render(),
            (new AdvertiserReviewNudge($this->advertiser, $this->order, $this->item, $this->site, now()->addDay()))->render(),
            (new AdvertiserOrderStalledNotice($this->advertiser, $this->order, $this->item, $this->site, now()->subDay(), 36))->render(),
            (new OrderAccepted($this->order, $this->item, $this->site))->render(),
            (new OrderPaymentConfirmed($this->order->load(['user', 'items'])))->render(),
            (new PaymentPendingMail($this->order->load('user')))->render(),
        ];

        foreach ($cases as $html) {
            $this->assertAdvertiserOrderDeepLink($html);
        }
    }

    public function test_payment_confirmation_emails_use_public_host_ctas(): void
    {
        $confirmed = (new OrderPaymentConfirmed($this->order->load(['user', 'items'])))->render();
        $this->assertAdvertiserOrderDeepLink($confirmed);
        $this->assertStringNotContainsString(
            "route('advertiser.orders'",
            (string) file_get_contents(resource_path('views/emails/order-payment-confirmed.blade.php'))
        );

        $admin = (new AdminManualPaymentNotification($this->advertiser, [$this->order], 'bank', 40))->render();
        $this->assertStringContainsString('/admin/payments', $admin);
        $this->assertStringNotContainsString(
            "route('admin.payments'",
            (string) file_get_contents(resource_path('views/emails/admin-manual-payment-notification.blade.php'))
        );

        $stalled = (new AdminStalledOrderAlert(
            $this->order,
            $this->item,
            $this->site,
            $this->publisher,
            3,
            96,
            'accept'
        ))->render();
        $this->assertStringContainsString('/admin/orders/'.$this->order->id, $stalled);
        $this->assertStringContainsString('refund the advertiser', $stalled);
        $this->assertStringNotContainsString(
            "route('admin.orders.show'",
            (string) file_get_contents(app_path('Mail/AdminStalledOrderAlert.php'))
        );

        $rejected = (new OrderRejected($this->order, $this->item, $this->site, 'Publisher declined the brief.'))->render();
        $this->assertStringContainsString('/advertiser/catalog', $rejected);
        $this->assertStringNotContainsString(
            "route('advertiser.catalog'",
            (string) file_get_contents(resource_path('views/emails/publisher/order_rejected.blade.php'))
        );
    }

    public function test_publisher_order_emails_deep_link_to_tasks_order(): void
    {
        $cases = [
            (new PublisherAcceptNudge($this->publisher, $this->order, $this->item, $this->site, 2, 36))->render(),
            (new PublisherPublishNudge($this->publisher, collect([[
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'site_name' => $this->site->site_name,
                'due_at' => now()->subDay(),
                'hours_overdue' => 24,
                'overdue_label' => '24h late',
                'promised' => '3days',
                'payout' => 85.0,
            ]]), 2, 'deep-link-test'))->render(),
            (new ModificationRequested($this->order, 'Please fix the anchor text.'))->render(),
            (new OrderApprovedByAdvertiser($this->order, $this->item, $this->site))->render(),
            (new SiteOwnerOrderNotification($this->site, [$this->order]))->render(),
        ];

        foreach ($cases as $html) {
            $this->assertPublisherTasksDeepLink($html);
        }

        $this->assertStringNotContainsString(
            "route('publisher.tasks'",
            (string) file_get_contents(resource_path('views/emails/advertiser/order_approved_publisher.blade.php'))
        );
    }

    public function test_billing_emails_deep_link_when_order_is_present(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->advertiser->id,
            'order_id' => $this->order->id,
            'invoice_number' => 'INV-DEEP-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $this->advertiser->name,
            'customer_email' => $this->advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'order_number' => $this->order->order_number,
            'line_items' => [
                ['description' => 'Guest post', 'quantity' => 1, 'unit_price' => 100, 'total' => 100],
            ],
            'billing_snapshot' => [],
        ]);

        $successful = (new PaymentSuccessfulInvoiceMail($invoice->fresh(['user', 'order'])))->render();
        $this->assertAdvertiserOrderDeepLink($successful);

        $failed = Invoice::create([
            'user_id' => $this->advertiser->id,
            'order_id' => $this->order->id,
            'invoice_number' => 'INV-DEEP-FAIL-1',
            'type' => Invoice::TYPE_PAYMENT_FAILURE,
            'status' => Invoice::STATUS_FAILED,
            'invoice_date' => now(),
            'customer_name' => $this->advertiser->name,
            'customer_email' => $this->advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'order_number' => $this->order->order_number,
            'notes' => 'Card declined',
            'line_items' => [
                ['description' => 'Guest post', 'quantity' => 1, 'unit_price' => 100, 'total' => 100],
            ],
            'billing_snapshot' => [],
        ]);
        $failedHtml = (new PaymentFailedMail($failed->fresh(['user', 'order'])))->render();
        $this->assertAdvertiserOrderDeepLink($failedHtml);
        $this->assertStringContainsString('payment_status=failed', $failedHtml);

        $refund = Invoice::create([
            'user_id' => $this->advertiser->id,
            'order_id' => $this->order->id,
            'invoice_number' => 'INV-DEEP-REF-1',
            'type' => Invoice::TYPE_REFUND_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $this->advertiser->name,
            'customer_email' => $this->advertiser->email,
            'currency' => 'EUR',
            'subtotal' => -100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => -100,
            'payment_method' => 'wallet',
            'payment_status' => 'refunded',
            'order_number' => $this->order->order_number,
            'notes' => 'Refund processed.',
            'line_items' => [
                ['description' => 'Refund', 'quantity' => 1, 'unit_price' => -100, 'total' => -100],
            ],
            'billing_snapshot' => [],
        ]);
        $refundHtml = (new RefundReceiptMail($refund->fresh(['user', 'order'])))->render();
        $this->assertAdvertiserOrderDeepLink($refundHtml);
    }
}
