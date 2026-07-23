<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\StripeWebhookLog;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\OrderPaymentService;
use App\Services\SiteDescriptionSanitizer;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuditSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_audit_security';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Audit Site',
            'site_url' => 'https://audit-site.example',
            'domain' => 'audit-site.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Safe site description text. ', 3),
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    private function signedWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
            ],
            $payload
        );
    }

    public function test_site_description_sanitizer_strips_event_handlers_and_javascript_urls(): void
    {
        $sanitizer = new SiteDescriptionSanitizer;
        $clean = $sanitizer->sanitize(
            '<p onclick="alert(1)">Hello</p><a href="javascript:alert(2)">x</a><a href="https://ok.example">safe</a>'
        );

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('https://ok.example', $clean);
        $this->assertStringContainsString('Hello', $clean);
    }

    public function test_site_safe_description_html_cleans_legacy_rows(): void
    {
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, [
            'description' => '<p onmouseover="alert(1)">Legacy XSS</p>',
        ]);

        // Bypass mutator path: force raw DB-style value then read via helper
        $site->forceFill(['description' => '<p onmouseover="alert(1)">Legacy XSS</p>'])->saveQuietly();

        $html = $site->fresh()->safeDescriptionHtml();
        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertStringContainsString('Legacy XSS', $html);
    }

    public function test_publisher_cannot_reject_completed_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $advertiserRoleId = Wallet::advertiserRoleId();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiserRoleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REJ-DONE-1',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 100,
        ]);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'Trying to reject after completion should fail hard.',
            ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_stripe_amount_mismatch_refuses_finalize(): void
    {
        $payments = app(OrderPaymentService::class);
        $ref = 'MISMATCH-1';
        $payments->storePendingCheckout($ref, [
            'user_id' => 1,
            'order_total' => 50,
            'amount_due' => 50,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [],
        ]);

        $session = (object) [
            'id' => 'cs_mismatch',
            'object' => 'checkout.session',
            'amount_total' => 999900,
            'payment_intent' => 'pi_mismatch',
            'metadata' => (object) [
                'expected_amount' => '50',
                'type' => 'order_payment',
                'reference_code' => $ref,
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        $payments->finalizeStripeFirstCheckout($ref, $session);
    }

    public function test_webhook_materializes_stripe_first_checkout_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ref = 'PKG-WEBHOOK-1';

        app(OrderPaymentService::class)->storePendingCheckout($ref, [
            'user_id' => $advertiser->id,
            'order_total' => 100,
            'amount_due' => 100,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [[
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 100,
                'sensitive_type' => null,
                'additional_price' => 0,
                'content_submission_id' => null,
                'content_link' => 'https://example.com/article',
                'anchor_text' => 'Example',
                'target_url' => 'https://example.com',
            ]],
        ]);

        $event = [
            'id' => 'evt_pkg_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_pkg_webhook',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_pkg_webhook',
                    'amount_total' => 10000,
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'expected_amount' => '100',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();

        $order = Order::where('reference_code', $ref)->where('payment_method', 'card')->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(100.0, (float) $order->total_amount);

        $log = StripeWebhookLog::where('event_id', $event['id'])->first();
        $this->assertNotNull($log);
        $this->assertTrue((bool) $log->processed);
    }

    public function test_admin_cannot_cancel_completed_withdrawal(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisherRoleId = Wallet::publisherRoleId();
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisherRoleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 50,
            'fee' => 0,
            'net_amount' => 50,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.update-status', $withdrawal->id), [
                'status' => 'cancelled',
            ])
            ->assertStatus(400);

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame(0.0, (float) Wallet::where('user_id', $publisher->id)->first()->balance);
    }

    public function test_finalize_rejects_package_user_mismatch(): void
    {
        $payments = app(OrderPaymentService::class);
        $ref = 'USER-MISMATCH-1';
        $payments->storePendingCheckout($ref, [
            'user_id' => 11,
            'order_total' => 50,
            'amount_due' => 50,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [],
        ]);

        $session = (object) [
            'id' => 'cs_user_mismatch',
            'object' => 'checkout.session',
            'amount_total' => 5000,
            'payment_intent' => 'pi_user_mismatch',
            'metadata' => (object) [
                'expected_amount' => '50',
                'user_id' => '22',
                'type' => 'order_payment',
                'reference_code' => $ref,
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not belong');
        $payments->finalizeStripeFirstCheckout($ref, $session);
    }

    public function test_marketing_cannot_approve_site_claim(): void
    {
        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $this->actingAs($marketer)
            ->postJson(route('admin.community.claims.approve', 1), [
                'admin_notes' => 'should be forbidden',
            ])
            ->assertForbidden();
    }
}
