<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteFeaturePurchase;
use App\Models\StripeWebhookLog;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OrderPaymentService;
use App\Services\Wallet\WalletLedgerService;
use App\Services\WalletStripeDepositService;
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

    private function bindDepositServiceRecoveringSession(object $session): void
    {
        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $session) extends WalletStripeDepositService
            {
                public function __construct(WalletLedgerService $ledger, private object $recoveredSession)
                {
                    parent::__construct($ledger);
                }

                public function fetchCheckoutSessionForPaymentIntent(string $paymentIntentId): ?object
                {
                    return $this->recoveredSession;
                }
            }
        );
    }

    private function bindDepositServiceRecoveringPaymentIntent(object $intent): void
    {
        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $intent) extends WalletStripeDepositService
            {
                public function __construct(WalletLedgerService $ledger, private object $recoveredIntent)
                {
                    parent::__construct($ledger);
                }

                public function fetchPaymentIntent(string $paymentIntentId): ?object
                {
                    return $this->recoveredIntent;
                }
            }
        );
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

    public function test_untyped_payment_intent_with_add_funds_session_reference_credits_wallet(): void
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

        $eventId = 'evt_pi_untyped_sref_'.uniqid();
        $piId = 'pi_untyped_sref_wh_'.uniqid();
        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $piId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 4000,
                    'amount_received' => 4000,
                    'currency' => 'eur',
                    'metadata' => [
                        'user_id' => (string) $advertiser->id,
                        'amount' => '40.00',
                        'reference_code' => 'DEP-PI-SREF-40',
                        'session_reference' => 'deposit_webhook_untyped_40',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 1);
        $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
    }

    public function test_untyped_payment_intent_without_session_reference_is_ignored(): void
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

        $eventId = 'evt_pi_untyped_ignore_'.uniqid();
        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_untyped_ignore_'.uniqid(),
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 4000,
                    'amount_received' => 4000,
                    'currency' => 'eur',
                    'metadata' => [
                        'user_id' => (string) $advertiser->id,
                        'amount' => '40.00',
                        'reference_code' => 'DEP-PI-IGNORE',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertEquals(0.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 0);
        $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
    }

    public function test_untyped_payment_intent_credits_wallet_when_checkout_session_is_recovered(): void
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

        $sessionId = 'cs_recovered_wallet_'.uniqid();
        $piId = 'pi_recovered_wallet_'.uniqid();
        $this->bindDepositServiceRecoveringSession((object) [
            'id' => $sessionId,
            'payment_status' => 'unpaid',
            'amount_total' => 4000,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '40.00',
                'reference_code' => 'DEP-RECOVERED-40',
                'session_reference' => 'deposit_recovered_wallet_40',
            ],
        ]);

        $eventId = 'evt_pi_recovered_wallet_'.uniqid();
        $this->signedWebhook([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $piId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 4000,
                    'amount_received' => 4000,
                    'currency' => 'eur',
                    'metadata' => [],
                ],
            ],
        ])->assertOk();

        $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
        $this->assertSame($piId, DepositRequest::query()
            ->where('stripe_session_id', $sessionId)
            ->value('stripe_payment_intent_id'));
        $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
    }

    public function test_untyped_payment_intent_settles_order_when_checkout_session_is_recovered(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ref = 'REF-RECOVERED-ORDER';

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

        $this->bindDepositServiceRecoveringSession((object) [
            'id' => 'cs_recovered_order_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 11500,
            'metadata' => (object) [
                'type' => 'order_payment',
                'user_id' => (string) $advertiser->id,
                'reference_code' => $ref,
            ],
        ]);

        $eventId = 'evt_pi_recovered_order_'.uniqid();
        $this->signedWebhook([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_recovered_order_'.uniqid(),
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 11500,
                    'amount_received' => 11500,
                    'currency' => 'eur',
                    'metadata' => [],
                ],
            ],
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
    }

    public function test_untyped_payment_intent_is_retried_when_session_lookup_fails(): void
    {
        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class)) extends WalletStripeDepositService
            {
                public function fetchCheckoutSessionForPaymentIntent(string $paymentIntentId): ?object
                {
                    throw new \RuntimeException('Stripe list sessions failed');
                }
            }
        );

        $eventId = 'evt_pi_lookup_fail_'.uniqid();
        $this->signedWebhook([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_lookup_fail_'.uniqid(),
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 4000,
                    'amount_received' => 4000,
                    'currency' => 'eur',
                    'metadata' => [],
                ],
            ],
        ])->assertStatus(500);

        $this->assertFalse((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
    }

    public function test_untyped_payment_intent_is_retried_when_checkout_session_is_not_visible_yet(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_retry_empty_session']);
        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class)) extends WalletStripeDepositService
            {
                public function fetchCheckoutSessionForPaymentIntent(string $paymentIntentId): ?object
                {
                    return null;
                }
            }
        );

        $eventId = 'evt_pi_session_not_visible_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'pi_session_not_visible_'.uniqid(),
                        'object' => 'payment_intent',
                        'status' => 'succeeded',
                        'amount' => 4000,
                        'amount_received' => 4000,
                        'currency' => 'eur',
                        'metadata' => [],
                    ],
                ],
            ])->assertStatus(500);

            $this->assertFalse((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_wallet_session_without_payment_intent_is_retried_when_stripe_is_configured(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_wait_for_pi_wh']);
        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class)) extends WalletStripeDepositService
            {
                protected function lookupPaymentIntentIdForSession(string $sessionId): string
                {
                    return '';
                }
            }
        );

        $advertiser = $this->makeUser('advertiser');
        $eventId = 'evt_cs_wait_pi_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_wait_pi_'.uniqid(),
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'amount_total' => 4000,
                        'metadata' => [
                            'type' => 'wallet_deposit',
                            'user_id' => (string) $advertiser->id,
                            'amount' => '40.00',
                            'session_reference' => 'deposit_wait_pi_wh_40',
                        ],
                    ],
                ],
            ])->assertStatus(500);

            $this->assertFalse((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
            $this->assertDatabaseCount('deposit_requests', 0);
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_untyped_session_credits_wallet_when_live_session_has_type(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_refresh_untyped_cs']);

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

        $sessionId = 'cs_refresh_untyped_'.uniqid();
        $piId = 'pi_refresh_untyped_'.uniqid();
        $fresh = (object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => $piId,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '40.00',
            ],
        ];

        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $fresh) extends WalletStripeDepositService
            {
                public function __construct(WalletLedgerService $ledger, private object $freshSession)
                {
                    parent::__construct($ledger);
                }

                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    return $this->freshSession;
                }
            }
        );

        $eventId = 'evt_cs_refresh_untyped_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => $sessionId,
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'payment_intent' => $piId,
                        'amount_total' => 4000,
                        'metadata' => [],
                    ],
                ],
            ])->assertOk();

            $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
            $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_untyped_session_with_reference_code_credits_wallet_from_payment_intent(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_pi_recover_cs']);

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

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'UNTYPED-REF-WALLET-PI',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
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
            'price' => 40,
        ]);

        $piId = 'pi_untyped_cs_wallet_'.uniqid();
        $intent = (object) [
            'id' => $piId,
            'status' => 'succeeded',
            'amount' => 4000,
            'amount_received' => 4000,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '40.00',
            ],
        ];

        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $intent) extends WalletStripeDepositService
            {
                public function __construct(WalletLedgerService $ledger, private object $recoveredIntent)
                {
                    parent::__construct($ledger);
                }

                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    return null;
                }

                public function fetchPaymentIntent(string $paymentIntentId): ?object
                {
                    return $this->recoveredIntent;
                }

                public function fetchCheckoutSessionForPaymentIntent(string $paymentIntentId): ?object
                {
                    return null;
                }
            }
        );

        $eventId = 'evt_cs_pi_wallet_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_untyped_ref_wallet_'.uniqid(),
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'payment_intent' => $piId,
                        'amount_total' => 4000,
                        'metadata' => [
                            'reference_code' => $order->reference_code,
                            'user_id' => (string) $advertiser->id,
                        ],
                    ],
                ],
            ])->assertOk();

            $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
            $this->assertSame('pending', $order->fresh()->payment_status);
            $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_untyped_wallet_session_does_not_settle_a_colliding_order_package(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_wallet_vs_package']);

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
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ref = 'WALLET-PKG-COLLIDE';

        app(OrderPaymentService::class)->storePendingCheckout($ref, [
            'user_id' => $advertiser->id,
            'order_total' => 40,
            'amount_due' => 40,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [[
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 40,
                'content_submission_id' => null,
                'content_link' => 'https://example.com/article',
            ]],
        ]);

        $piId = 'pi_wallet_pkg_collide_'.uniqid();
        $intent = (object) [
            'id' => $piId,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 4000,
            'amount_received' => 4000,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '40.00',
                'reference_code' => $ref,
                'session_reference' => 'deposit_'.str_repeat('ab', 16),
            ],
        ];

        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $intent) extends WalletStripeDepositService
            {
                public function __construct(WalletLedgerService $ledger, private object $recoveredIntent)
                {
                    parent::__construct($ledger);
                }

                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    return null;
                }

                public function fetchPaymentIntent(string $paymentIntentId): ?object
                {
                    return $this->recoveredIntent;
                }

                public function fetchCheckoutSessionForPaymentIntent(string $paymentIntentId): ?object
                {
                    return null;
                }
            }
        );

        $eventId = 'evt_wallet_pkg_collide_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_wallet_pkg_collide',
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'payment_intent' => $piId,
                        'amount_total' => 4000,
                        'metadata' => [
                            'reference_code' => $ref,
                            'user_id' => (string) $advertiser->id,
                        ],
                    ],
                ],
            ])->assertOk();

            $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
            $this->assertSame(0, Order::query()
                ->where('user_id', $advertiser->id)
                ->where('reference_code', $ref)
                ->where('payment_status', 'paid')
                ->count());
            $this->assertNotNull(app(OrderPaymentService::class)->getPendingCheckout($ref));
            $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_untyped_session_is_retried_when_live_session_lookup_fails(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_refresh_cs_fail']);
        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class)) extends WalletStripeDepositService
            {
                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    throw new \RuntimeException('Stripe session retrieve failed');
                }
            }
        );

        $eventId = 'evt_cs_refresh_fail_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_refresh_fail_'.uniqid(),
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'amount_total' => 4000,
                        'metadata' => [],
                    ],
                ],
            ])->assertStatus(500);

            $this->assertFalse((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_untyped_session_credits_wallet_when_session_has_user_and_payment_intent_has_type(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_split_meta_cs']);

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

        $piId = 'pi_split_meta_'.uniqid();
        $intent = (object) [
            'id' => $piId,
            'status' => 'succeeded',
            'amount' => 4000,
            'amount_received' => 4000,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
            ],
        ];

        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $intent) extends WalletStripeDepositService
            {
                public function __construct(WalletLedgerService $ledger, private object $recoveredIntent)
                {
                    parent::__construct($ledger);
                }

                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    return null;
                }

                public function fetchPaymentIntent(string $paymentIntentId): ?object
                {
                    return $this->recoveredIntent;
                }

                public function fetchCheckoutSessionForPaymentIntent(string $paymentIntentId): ?object
                {
                    return null;
                }
            }
        );

        $eventId = 'evt_cs_split_meta_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_split_meta_'.uniqid(),
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'payment_intent' => $piId,
                        'amount_total' => 4000,
                        'metadata' => [
                            'user_id' => (string) $advertiser->id,
                            'amount' => '40.00',
                        ],
                    ],
                ],
            ])->assertOk();

            $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
            $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_wallet_payment_intent_without_user_id_credits_from_checkout_session(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_pi_missing_user']);

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

        $sessionId = 'cs_pi_missing_user_'.uniqid();
        $piId = 'pi_missing_user_'.uniqid();
        $session = (object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => $piId,
            'metadata' => (object) [
                'user_id' => (string) $advertiser->id,
                'amount' => '40.00',
            ],
        ];

        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $session) extends WalletStripeDepositService
            {
                public function __construct(WalletLedgerService $ledger, private object $recoveredSession)
                {
                    parent::__construct($ledger);
                }

                public function fetchCheckoutSessionForPaymentIntent(string $paymentIntentId): ?object
                {
                    return $this->recoveredSession;
                }
            }
        );

        $eventId = 'evt_pi_missing_user_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $piId,
                        'object' => 'payment_intent',
                        'status' => 'succeeded',
                        'amount' => 4000,
                        'amount_received' => 4000,
                        'currency' => 'eur',
                        'metadata' => [
                            'type' => 'wallet_deposit',
                        ],
                    ],
                ],
            ])->assertOk();

            $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
            $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_untyped_paid_session_without_payment_intent_is_retried_when_stripe_is_configured(): void
    {
        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_untyped_wait_pi']);
        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class)) extends WalletStripeDepositService
            {
                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    return null;
                }
            }
        );

        $eventId = 'evt_cs_untyped_no_pi_'.uniqid();
        try {
            $this->signedWebhook([
                'id' => $eventId,
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_untyped_no_pi_'.uniqid(),
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'amount_total' => 4000,
                        'metadata' => [],
                    ],
                ],
            ])->assertStatus(500);

            $this->assertFalse((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_untyped_session_with_add_funds_session_reference_credits_wallet(): void
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

        $eventId = 'evt_cs_untyped_sref_'.uniqid();
        $sessionId = 'cs_untyped_sref_wh_'.uniqid();
        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_untyped_sref_cs_wh_'.uniqid(),
                    'amount_total' => 4000,
                    'metadata' => [
                        'user_id' => (string) $advertiser->id,
                        'amount' => '40.00',
                        'reference_code' => 'DEP-CS-SREF-40',
                        'session_reference' => 'deposit_webhook_cs_untyped_40',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertEquals(40.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 1);
        $this->assertTrue((bool) StripeWebhookLog::where('event_id', $eventId)->value('processed'));
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

    public function test_site_feature_session_with_wrong_amount_credits_charged_amount(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $event = [
            'id' => 'evt_feature_cheap_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_feature_cheap',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_feature_cheap',
                    'amount_total' => 100,
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
        $this->assertNull($site->fresh()->featured_until);
        $this->assertDatabaseHas('site_feature_purchases', [
            'site_id' => $site->id,
            'user_id' => $publisher->id,
            'stripe_session_id' => 'cs_feature_cheap',
            'payment_method' => 'stripe_credit',
        ]);
        $this->assertEqualsWithDelta(
            1.0,
            (float) Wallet::query()->where('user_id', $publisher->id)->value('balance'),
            0.01
        );
    }

    public function test_site_feature_unpaid_or_missing_status_is_rejected(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        foreach (['unpaid', null] as $status) {
            $event = [
                'id' => 'evt_feature_unpaid_'.uniqid(),
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_feature_unpaid_'.uniqid(),
                        'object' => 'checkout.session',
                        'payment_status' => $status,
                        'payment_intent' => 'pi_feature_unpaid',
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

            $this->signedWebhook($event)->assertStatus(500);
        }

        $this->assertNull($site->fresh()->featured_until);
        $this->assertSame(0, SiteFeaturePurchase::where('site_id', $site->id)->count());
    }

    public function test_site_feature_owner_mismatch_credits_wallet_and_acks(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $payer = $this->makeUser('publisher');
        $newOwner = $this->makeUser('publisher');
        $site = $this->makeSite($payer);
        $site->update(['publisher_id' => $newOwner->id]);

        $roleId = Wallet::publisherRoleId();
        $wallet = Wallet::create([
            'user_id' => $payer->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $sessionId = 'cs_feature_mismatch_'.uniqid();
        $event = [
            'id' => 'evt_feature_mismatch_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_feature_mismatch',
                    'amount_total' => 2500,
                    'metadata' => [
                        'type' => 'site_feature',
                        'site_id' => (string) $site->id,
                        'user_id' => (string) $payer->id,
                        'price' => '25',
                        'days' => '7',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertNull($site->fresh()->featured_until);
        $this->assertEquals(25.0, (float) $wallet->fresh()->balance);

        $event['id'] = 'evt_feature_mismatch_again_'.uniqid();
        $this->signedWebhook($event)->assertOk();

        $this->assertEquals(25.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, SiteFeaturePurchase::where('stripe_session_id', $sessionId)->count());
        $this->assertDatabaseHas('site_feature_purchases', [
            'site_id' => $site->id,
            'user_id' => $payer->id,
            'stripe_session_id' => $sessionId,
            'payment_method' => 'stripe_credit',
        ]);
    }

    public function test_site_feature_cancelled_bulk_credits_wallet_and_acks(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $site->forceFill(['bulk_site_request_id' => $bulk->id])->save();

        $roleId = Wallet::publisherRoleId();
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $sessionId = 'cs_feature_leftover_'.uniqid();
        $event = [
            'id' => 'evt_feature_leftover_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_feature_leftover',
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
        $this->assertNull($site->fresh()->featured_until);
        $this->assertEquals(25.0, (float) $wallet->fresh()->balance);

        $event['id'] = 'evt_feature_leftover_again_'.uniqid();
        $this->signedWebhook($event)->assertOk();

        $this->assertEquals(25.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, SiteFeaturePurchase::where('stripe_session_id', $sessionId)->count());
        $this->assertDatabaseHas('site_feature_purchases', [
            'site_id' => $site->id,
            'user_id' => $publisher->id,
            'stripe_session_id' => $sessionId,
            'payment_method' => 'stripe_credit',
        ]);
    }

    public function test_site_feature_deleted_listing_credits_wallet_and_acks(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $siteId = $site->id;
        $roleId = Wallet::publisherRoleId();
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $site->delete();

        $sessionId = 'cs_feature_deleted_'.uniqid();
        $event = [
            'id' => 'evt_feature_deleted_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_feature_deleted',
                    'amount_total' => 2500,
                    'metadata' => [
                        'type' => 'site_feature',
                        'site_id' => (string) $siteId,
                        'user_id' => (string) $publisher->id,
                        'price' => '25',
                        'days' => '7',
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertEquals(25.0, (float) $wallet->fresh()->balance);

        $event['id'] = 'evt_feature_deleted_again_'.uniqid();
        $this->signedWebhook($event)->assertOk();

        $this->assertEquals(25.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, SiteFeaturePurchase::where('stripe_session_id', $sessionId)->count());
    }

    public function test_unpaid_order_checkout_session_is_rejected(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'UNPAID-ORDER-SESSION',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
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
            'price' => 80,
        ]);

        $event = [
            'id' => 'evt_order_unpaid_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_order_unpaid',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'payment_intent' => 'pi_order_unpaid',
                    'amount_total' => 8000,
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $order->reference_code,
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertStatus(500);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_untyped_session_with_reference_code_does_not_settle_orders(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'UNTYPED-REF-SESSION',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
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
            'price' => 80,
        ]);

        $event = [
            'id' => 'evt_untyped_ref_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_untyped_ref',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_untyped_ref',
                    'amount_total' => 8000,
                    'metadata' => [
                        'reference_code' => $order->reference_code,
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_untyped_expired_session_does_not_fail_orders(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'UNTYPED-EXPIRE-REF',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
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
            'price' => 80,
        ]);

        $event = [
            'id' => 'evt_untyped_expire_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_untyped_expire',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'metadata' => [
                        'reference_code' => $order->reference_code,
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertOk();
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_order_payment_intent_without_succeeded_status_is_rejected(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PI-NOT-SUCCEEDED',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
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
            'price' => 80,
        ]);

        $event = [
            'id' => 'evt_pi_requires_'.uniqid(),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_requires_action',
                    'object' => 'payment_intent',
                    'status' => 'requires_action',
                    'amount' => 8000,
                    'amount_received' => 0,
                    'currency' => 'eur',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $order->reference_code,
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ];

        $this->signedWebhook($event)->assertStatus(500);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_order_payment_intent_without_reference_code_settles_from_checkout_session(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ref = 'ORD-PI-NO-REF-'.uniqid();

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
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
            'price' => 80,
        ]);

        $this->bindDepositServiceRecoveringSession((object) [
            'id' => 'cs_order_ref_from_cs_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 8000,
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'user_id' => (string) $advertiser->id,
            ],
        ]);

        $this->signedWebhook([
            'id' => 'evt_order_pi_no_ref_'.uniqid(),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_order_no_ref_'.uniqid(),
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 8000,
                    'amount_received' => 8000,
                    'currency' => 'eur',
                    'metadata' => [
                        'type' => 'order_payment',
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_order_payment_session_without_reference_code_settles_from_payment_intent(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ref = 'ORD-CS-NO-REF-'.uniqid();

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
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
            'price' => 80,
        ]);

        $piId = 'pi_order_ref_from_pi_'.uniqid();
        $this->bindDepositServiceRecoveringPaymentIntent((object) [
            'id' => $piId,
            'status' => 'succeeded',
            'amount' => 8000,
            'amount_received' => 8000,
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'user_id' => (string) $advertiser->id,
            ],
        ]);

        $this->signedWebhook([
            'id' => 'evt_order_cs_no_ref_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_order_no_ref_'.uniqid(),
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'payment_intent' => $piId,
                    'amount_total' => 8000,
                    'metadata' => [
                        'type' => 'order_payment',
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_site_feature_session_missing_site_id_applies_from_live_session(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_feature_refresh']);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $sessionId = 'cs_feature_refresh_'.uniqid();

        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $site->id, $publisher->id) extends WalletStripeDepositService
            {
                public function __construct(
                    WalletLedgerService $ledger,
                    private int $siteId,
                    private int $userId
                ) {
                    parent::__construct($ledger);
                }

                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    return (object) [
                        'id' => $sessionId,
                        'payment_status' => 'paid',
                        'amount_total' => 2500,
                        'metadata' => (object) [
                            'type' => 'site_feature',
                            'site_id' => (string) $this->siteId,
                            'user_id' => (string) $this->userId,
                            'price' => '25',
                            'days' => '7',
                        ],
                    ];
                }
            }
        );

        try {
            $this->signedWebhook([
                'id' => 'evt_feature_refresh_'.uniqid(),
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => $sessionId,
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'payment_intent' => 'pi_feature_refresh',
                        'amount_total' => 2500,
                        'metadata' => [
                            'type' => 'site_feature',
                        ],
                    ],
                ],
            ])->assertOk();

            $this->assertNotNull($site->fresh()->featured_until);
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_site_feature_session_missing_site_id_applies_from_payment_intent(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_feature_pi_meta']);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $sessionId = 'cs_feature_pi_meta_'.uniqid();
        $piId = 'pi_feature_meta_'.uniqid();

        $this->app->instance(
            WalletStripeDepositService::class,
            new class(app(WalletLedgerService::class), $site->id, $publisher->id) extends WalletStripeDepositService
            {
                public function __construct(
                    WalletLedgerService $ledger,
                    private int $siteId,
                    private int $userId
                ) {
                    parent::__construct($ledger);
                }

                public function refreshCheckoutSession(string $sessionId): ?object
                {
                    return null;
                }

                public function fetchPaymentIntent(string $paymentIntentId): ?object
                {
                    return (object) [
                        'id' => $paymentIntentId,
                        'status' => 'succeeded',
                        'metadata' => (object) [
                            'type' => 'site_feature',
                            'site_id' => (string) $this->siteId,
                            'user_id' => (string) $this->userId,
                            'price' => '25',
                            'days' => '7',
                        ],
                    ];
                }
            }
        );

        try {
            $this->signedWebhook([
                'id' => 'evt_feature_pi_meta_'.uniqid(),
                'object' => 'event',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => $sessionId,
                        'object' => 'checkout.session',
                        'payment_status' => 'paid',
                        'payment_intent' => $piId,
                        'amount_total' => 2500,
                        'metadata' => [
                            'type' => 'site_feature',
                        ],
                    ],
                ],
            ])->assertOk();

            $this->assertNotNull($site->fresh()->featured_until);
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }

    public function test_site_feature_payment_intent_applies_feature_from_checkout_session(): void
    {
        config([
            'site_promotions.feature.price' => 25,
            'site_promotions.feature.days' => 7,
        ]);

        $previousSecret = config('services.stripe.secret');
        config(['services.stripe.secret' => 'sk_test_feature_pi_route']);

        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $sessionId = 'cs_feature_from_pi_'.uniqid();
        $piId = 'pi_feature_route_'.uniqid();

        $this->bindDepositServiceRecoveringSession((object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 2500,
            'metadata' => (object) [
                'type' => 'site_feature',
                'site_id' => (string) $site->id,
                'user_id' => (string) $publisher->id,
                'price' => '25',
                'days' => '7',
            ],
        ]);

        try {
            $this->signedWebhook([
                'id' => 'evt_feature_pi_route_'.uniqid(),
                'object' => 'event',
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $piId,
                        'object' => 'payment_intent',
                        'status' => 'succeeded',
                        'amount' => 2500,
                        'amount_received' => 2500,
                        'currency' => 'eur',
                        'metadata' => [
                            'type' => 'site_feature',
                            'site_id' => (string) $site->id,
                            'user_id' => (string) $publisher->id,
                        ],
                    ],
                ],
            ])->assertOk();

            $this->assertNotNull($site->fresh()->featured_until);
        } finally {
            config(['services.stripe.secret' => $previousSecret]);
        }
    }
}
