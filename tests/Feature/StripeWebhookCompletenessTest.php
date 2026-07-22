<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteFeaturePurchase;
use App\Models\StripeWebhookLog;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeWebhookCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_stripe_completeness';

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

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Stripe Feature Site',
            'site_url' => 'https://stripe-feature.example',
            'domain' => 'stripe-feature.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Stripe feature site description. ', 3),
            'verified' => true,
            'active' => true,
        ]);
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

    public function test_failed_webhook_can_be_retried_after_orders_exist(): void
    {
        $eventId = 'evt_retry_'.uniqid();
        $ref = 'REF-RETRY-1';

        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_retry',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_retry',
                    'amount_total' => 11500,
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertStatus(500);

        $log = StripeWebhookLog::where('event_id', $eventId)->first();
        $this->assertNotNull($log);
        $this->assertFalse((bool) $log->processed);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 115,
        ]);

        $this->signedWebhook($event)->assertOk()->assertJsonPath('status', 'success');

        $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->signedWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');
    }

    public function test_payment_intent_succeeded_credits_wallet_once(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $roleId = Wallet::advertiserRoleId();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $eventId = 'evt_pi_wallet_'.uniqid();
        $piId = 'pi_wallet_'.uniqid();
        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $piId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 5000,
                    'amount_received' => 5000,
                    'currency' => 'eur',
                    'metadata' => [
                        'type' => 'wallet_deposit',
                        'user_id' => (string) $advertiser->id,
                        'amount' => '50.00',
                        'reference_code' => 'DEP-PI-50',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertEquals(50.0, (float) $wallet->fresh()->balance);

        $this->signedWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertEquals(50.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 1);
    }

    public function test_payment_intent_succeeded_marks_orders_paid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ref = 'REF-PI-ORDER';

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 115,
        ]);

        $event = [
            'id' => 'evt_pi_order_'.uniqid(),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_order_'.uniqid(),
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 11500,
                    'amount_received' => 11500,
                    'currency' => 'eur',
                    'metadata' => [
                        'type' => 'order_payment',
                        'user_id' => (string) $advertiser->id,
                        'reference_code' => $ref,
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_site_feature_checkout_session_applies_feature_idempotently(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $sessionId = 'cs_feature_'.uniqid();
        $eventId = 'evt_feature_'.uniqid();

        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_feature',
                    'amount_total' => 2500,
                    'metadata' => [
                        'type' => 'site_feature',
                        'site_id' => (string) $site->id,
                        'user_id' => (string) $publisher->id,
                        'price' => '25',
                        'days' => '7',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $site->refresh();
        $this->assertNotNull($site->featured_until);
        $this->assertDatabaseHas('site_feature_purchases', [
            'site_id' => $site->id,
            'user_id' => $publisher->id,
            'stripe_session_id' => $sessionId,
            'payment_method' => 'stripe',
        ]);

        $until = $site->featured_until->copy();

        // Force retry of same logical payment via a new event that reuses session id
        // (duplicate event_id is skipped; new event with same session must stay idempotent).
        $event2 = $event;
        $event2['id'] = 'evt_feature_again_'.uniqid();
        $this->signedWebhook($event2)->assertOk();

        $this->assertEquals(
            $until->timestamp,
            $site->fresh()->featured_until->timestamp
        );
        $this->assertSame(1, SiteFeaturePurchase::where('stripe_session_id', $sessionId)->count());
    }
}
