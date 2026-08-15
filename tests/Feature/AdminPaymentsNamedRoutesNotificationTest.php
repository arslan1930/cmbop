<?php

namespace Tests\Feature;

use App\Mail\OrderPaymentConfirmed;
use App\Mail\OrderStatusChanged;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\RefundReceiptMail;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminPaymentsNamedRoutesNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Payments Notify Site',
            'site_url' => 'https://payments-notify.example',
            'domain' => 'payments-notify.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 115,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Payments notification site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, Site $site, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PAY-NOTIFY-'.random_int(1000, 9999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
        ]);

        return $order->fresh(['items']);
    }

    private function assertNoCustomerMail(User $advertiser): void
    {
        foreach ([
            PaymentSuccessfulInvoiceMail::class,
            OrderPaymentConfirmed::class,
            OrderStatusChanged::class,
            PaymentFailedMail::class,
            RefundReceiptMail::class,
        ] as $mailable) {
            Mail::assertNotQueued($mailable, fn ($mail) => $mail->hasTo($advertiser->email));
            Mail::assertNotSent($mailable, fn ($mail) => $mail->hasTo($advertiser->email));
        }
    }

    public function test_payments_page_uses_named_route_placeholders(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('PAYMENTS_DATA', $html);
        $this->assertStringContainsString('PAYMENTS_UPDATE', $html);
        $this->assertStringContainsString('PAYMENTS_EXPORT', $html);
        $this->assertStringContainsString('ORDERS_SHOW', $html);
        $this->assertStringContainsString('id="update_notes"', $html);
        $this->assertStringContainsString('id="update_payment_reference"', $html);
        $this->assertStringContainsString('allowed.length', $html);
        $this->assertStringContainsString('function paymentUrl(', $html);
        $this->assertStringContainsString('__ID__', $html);
        $this->assertStringContainsString('payments\\/__ID__\\/update-status', $html);
        $this->assertStringContainsString('orders\\/__ID__', $html);
        $updateJson = json_encode(route('admin.payments.updateStatus', ['id' => '__ID__'], false), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->assertStringContainsString($updateJson, $html);
        $this->assertStringNotContainsString(
            json_encode(route('admin.payments.updateStatus', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
            $html
        );

        $this->assertStringNotContainsString("/admin/payments/' + orderId", $html);
        $this->assertStringNotContainsString("url: '/admin/payments/data'", $html);
        $this->assertStringNotContainsString('href="/admin/orders/\' + order.id', $html);
        $this->assertStringNotContainsString('`/admin/payments/${', $html);
        $this->assertStringContainsString('sendNotification ? 1 : 0', $html);
    }

    public function test_mark_paid_without_send_notification_field_still_emails_customer(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->makeOrder($advertiser, $this->makeSite($publisher));

        Mail::fake();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('paid', $order->fresh()->payment_status);

        Mail::assertQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($advertiser->email));
    }

    public function test_mark_paid_with_send_notification_false_skips_customer_mail_and_keeps_publisher(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->makeOrder($advertiser, $this->makeSite($publisher));

        Mail::fake();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
                'send_notification' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNoCustomerMail($advertiser);

        Mail::assertQueued(OrderStatusChanged::class, fn (OrderStatusChanged $mail) => $mail->hasTo($publisher->email));

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => InAppNotificationService::TYPE_ORDER_CREATED,
            'related_id' => $order->id,
        ]);
    }

    public function test_mark_failed_with_send_notification_false_skips_customer_in_app(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->makeOrder($advertiser, $this->makeSite($publisher));

        Mail::fake();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
                'send_notification' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertNoCustomerMail($advertiser);

        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_PAYMENT_FAILED,
            'related_id' => $order->id,
        ]);
    }

    public function test_invalid_send_notification_is_unprocessable_not_a_server_error(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher')));

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
                'send_notification' => 'maybe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['send_notification']);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_form_encoded_false_string_skips_customer_mail(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->makeOrder($advertiser, $this->makeSite($publisher));

        Mail::fake();

        $this->actingAs($admin)
            ->post(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
                'send_notification' => 'false',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNoCustomerMail($advertiser);
    }

    public function test_payments_data_ignores_array_search_and_invalid_dates(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher')), [
            'order_number' => 'PAY-ARRAY-1',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', [
                'search' => ['injected'],
                'payment_status' => ['paid'],
                'date_from' => 'not-a-date',
                'date_to' => ['2026-01-01'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => 'PAY-ARRAY-1']);
    }

    public function test_mark_refunded_with_send_notification_false_still_credits_wallet(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = $this->makeOrder($advertiser, $this->makeSite($publisher), [
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        Mail::fake();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
                'send_notification' => false,
                'notes' => 'Silent refund',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(125.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertNoCustomerMail($advertiser);

        $refundBell = InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_PAYMENT_RECEIVED)
            ->where('title', 'like', '%back to your wallet%')
            ->exists();
        $this->assertFalse($refundBell);
    }
}
