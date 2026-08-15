<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OrderPaymentService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class StripeFirstCheckoutInvariantsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_stripe_first_invariants';

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

        return $user->fresh();
    }

    private function makeSite(User $publisher, string $domain, float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Stripe '.$domain,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => $price,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Stripe first checkout site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function advertiserWallet(User $advertiser, float $bonus): Wallet
    {
        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $bonus,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function package(User $advertiser, array $lines, float $amountDue, float $bonus = 0): array
    {
        return [
            'user_id' => $advertiser->id,
            'order_total' => $amountDue + $bonus,
            'amount_due' => $amountDue,
            'bonus_applied' => $bonus,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => $lines,
        ];
    }

    private function lineFor(Site $site, float $price): array
    {
        return [
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $price,
            'sensitive_type' => null,
            'additional_price' => 0,
            'content_submission_id' => null,
            'content_link' => 'https://example.com/article',
            'anchor_text' => 'Example',
            'target_url' => 'https://example.com',
        ];
    }

    private function paidSession(string $ref, float $euros, string $sessionId = 'cs_test_finalize'): object
    {
        return (object) [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'amount_total' => (int) round($euros * 100),
            'payment_intent' => 'pi_'.substr($sessionId, -8),
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'expected_amount' => (string) $euros,
            ],
        ];
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

    public function test_finalize_is_idempotent_and_does_not_duplicate_site_rows(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $first = $this->makeSite($publisher, 'race-one.example', 40);
        $second = $this->makeSite($publisher, 'race-two.example', 60);
        $ref = 'RACE-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($first, 40),
            $this->lineFor($second, 60),
        ], 100));

        $session = $this->paidSession($ref, 100, 'cs_race_1');
        $firstPass = $payments->finalizeStripeFirstCheckout($ref, $session);
        $secondPass = $payments->finalizeStripeFirstCheckout($ref, $session);

        $this->assertCount(2, $firstPass);
        $this->assertSame(2, Order::where('reference_code', $ref)->count());
        $this->assertSame(2, OrderItem::whereIn('order_id', Order::where('reference_code', $ref)->pluck('id'))->count());
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            OrderItem::whereIn('order_id', Order::where('reference_code', $ref)->pluck('id'))
                ->pluck('site_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
        $this->assertTrue($secondPass->every(fn (Order $order) => $order->payment_status === 'paid'));
    }

    public function test_payment_intent_amount_mismatch_refuses_to_mark_paid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'pi-mismatch.example');
        $ref = 'PI-MISMATCH-1';

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

        $intent = (object) [
            'id' => 'pi_wrong_amount',
            'object' => 'payment_intent',
            'amount' => 100,
            'amount_received' => 100,
            'metadata' => [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'expected_amount' => '115',
            ],
        ];

        try {
            app(OrderPaymentService::class)->markOrdersPaidFromPaymentIntent($ref, $intent);
            $this->fail('PaymentIntent amount mismatch should refuse to mark the order paid.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_checkout_session_with_bonus_metadata_still_asserts_stripe_amount(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'session-bonus-mismatch.example');
        $ref = 'CS-BONUS-MISMATCH-1';

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

        $session = (object) [
            'id' => 'cs_bonus_mismatch',
            'object' => 'checkout.session',
            'amount_total' => 100,
            'payment_intent' => 'pi_bonus_mismatch',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'bonus_applied' => '20',
            ],
        ];

        try {
            app(OrderPaymentService::class)->markOrdersPaidFromStripeSession($ref, $session);
            $this->fail('Checkout session amount mismatch should refuse to mark the order paid.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_session_expiry_refunds_bonus_when_no_order_rows_exist(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'expire-bonus.example');
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'EXPIRE-BONUS-1';

        Cache::put('checkout_bonus:'.$advertiser->id.':'.$ref, 20, now()->addHour());
        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($site, 40)],
            20,
            20
        ));

        $this->signedWebhook([
            'id' => 'evt_expire_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_expired_bonus',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, Order::where('reference_code', $ref)->count());
        $this->assertNull(Cache::get(OrderPaymentService::pendingCheckoutCacheKey($ref)));
        $this->assertNull(Cache::get('checkout_bonus:'.$advertiser->id.':'.$ref));

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_payment_intent_webhook_materializes_stripe_first_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'pi-package.example');
        $ref = 'PI-PKG-1';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($site, 80)],
            80
        ));

        $this->signedWebhook([
            'id' => 'evt_pi_pkg_'.uniqid(),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_pkg_materialize',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 8000,
                    'amount_received' => 8000,
                    'currency' => 'eur',
                    'metadata' => [
                        'type' => 'order_payment',
                        'user_id' => (string) $advertiser->id,
                        'reference_code' => $ref,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $order = Order::where('reference_code', $ref)->where('payment_method', 'card')->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta(80.0, (float) $order->total_amount, 0.01);
        $this->assertSame($site->id, (int) $order->items()->first()?->site_id);
    }

    public function test_finalize_survives_cache_flush_via_durable_checkout_intent(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'durable-package.example', 80);
        $ref = 'DURABLE-PKG-1';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($site, 80)],
            80
        ));

        Cache::flush();
        $this->assertNull(Cache::get(OrderPaymentService::pendingCheckoutCacheKey($ref)));

        $created = app(OrderPaymentService::class)->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_durable_pkg')
        );

        $this->assertCount(1, $created);
        $order = Order::where('reference_code', $ref)->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($site->id, (int) $order->items()->first()?->site_id);
    }

    public function test_session_expiry_refunds_bonus_after_cache_flush(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $this->makeSite($publisher, 'expire-durable.example');
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'EXPIRE-DURABLE-1';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $advertiser,
            [$this->lineFor($this->makeSite($publisher, 'expire-durable-line.example'), 40)],
            20,
            20
        ));

        Cache::flush();

        $this->signedWebhook([
            'id' => 'evt_expire_durable_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_expired_durable',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'bonus_applied' => '20',
                    ],
                ],
            ],
        ])->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_second_paid_checkout_does_not_reuse_the_same_library_article(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $firstSite = $this->makeSite($publisher, 'lib-one.example', 40);
        $secondSite = $this->makeSite($publisher, 'lib-two.example', 40);
        $submission = $this->createApprovedSubmission($advertiser);
        $wallet = $this->advertiserWallet($advertiser, 0);

        $firstLine = $this->lineFor($firstSite, 40);
        $firstLine['content_submission_id'] = $submission->id;
        $secondLine = $this->lineFor($secondSite, 40);
        $secondLine['content_submission_id'] = $submission->id;

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('LIB-A', $this->package($advertiser, [$firstLine], 40));
        $payments->storePendingCheckout('LIB-B', $this->package($advertiser, [$secondLine], 40));

        $firstOrders = $payments->finalizeStripeFirstCheckout('LIB-A', $this->paidSession('LIB-A', 40, 'cs_lib_a'));
        $secondOrders = $payments->finalizeStripeFirstCheckout('LIB-B', $this->paidSession('LIB-B', 40, 'cs_lib_b'));

        $this->assertCount(1, $firstOrders);
        $this->assertSame('paid', $firstOrders->first()->payment_status);
        $this->assertCount(0, $secondOrders);

        $winner = Order::query()->where('reference_code', 'LIB-A')->first();
        $duplicate = Order::query()->where('reference_code', 'LIB-B')->first();
        $this->assertNotNull($winner);
        $this->assertNotNull($duplicate);
        $this->assertSame('paid', $winner->payment_status);
        $this->assertSame('pending', $winner->status);
        $this->assertSame('refunded', $duplicate->payment_status);
        $this->assertSame('cancelled', $duplicate->status);

        $submission->refresh();
        $this->assertSame($winner->id, (int) $submission->order_id);
        $this->assertSame(
            $submission->id,
            (int) $winner->items()->value('content_submission_id')
        );
        $this->assertNull($duplicate->items()->value('content_submission_id'));

        $wallet->refresh();
        $this->assertEqualsWithDelta(40.0, (float) $wallet->balance, 0.01);
    }

    public function test_qty_two_same_site_materializes_both_paid_card_orders(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'qty-two-card.example', 40);
        $articleA = $this->createApprovedSubmission($advertiser);
        $articleB = $this->createApprovedSubmission($advertiser);

        $firstLine = $this->lineFor($site, 40);
        $firstLine['content_submission_id'] = $articleA->id;
        $secondLine = $this->lineFor($site, 40);
        $secondLine['content_submission_id'] = $articleB->id;

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('QTY-CARD-2', $this->package($advertiser, [
            $firstLine,
            $secondLine,
        ], 80));

        $created = $payments->finalizeStripeFirstCheckout(
            'QTY-CARD-2',
            $this->paidSession('QTY-CARD-2', 80, 'cs_qty_card_2')
        );
        $again = $payments->finalizeStripeFirstCheckout(
            'QTY-CARD-2',
            $this->paidSession('QTY-CARD-2', 80, 'cs_qty_card_2')
        );

        $this->assertCount(2, $created);
        $this->assertSame(2, Order::where('reference_code', 'QTY-CARD-2')->count());
        $this->assertSame(2, OrderItem::whereIn(
            'order_id',
            Order::where('reference_code', 'QTY-CARD-2')->pluck('id')
        )->count());
        $this->assertEqualsWithDelta(
            80.0,
            (float) Order::where('reference_code', 'QTY-CARD-2')->sum('total_amount'),
            0.01
        );
        $this->assertSame(1, OrderItem::where('content_submission_id', $articleA->id)->count());
        $this->assertSame(1, OrderItem::where('content_submission_id', $articleB->id)->count());
        $this->assertTrue($created->every(fn (Order $order) => $order->payment_status === 'paid'));
        $this->assertTrue($again->every(fn (Order $order) => $order->payment_status === 'paid'));

        $articleA->refresh();
        $articleB->refresh();
        $this->assertContains((int) $articleA->order_id, $created->pluck('id')->all());
        $this->assertContains((int) $articleB->order_id, $created->pluck('id')->all());
        $this->assertNotSame((int) $articleA->order_id, (int) $articleB->order_id);
    }

    public function test_wallet_deposit_session_cannot_materialize_orders(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'wallet-collide.example', 50);
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('COLLIDE-1', $this->package($advertiser, [
            $this->lineFor($site, 50),
        ], 50));

        $walletSession = (object) [
            'id' => 'cs_wallet_collide',
            'object' => 'checkout.session',
            'amount_total' => 5000,
            'payment_intent' => 'pi_wallet_collide',
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'reference_code' => 'COLLIDE-1',
                'user_id' => (string) $advertiser->id,
                'amount' => '50',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an order payment');
        $payments->finalizeStripeFirstCheckout('COLLIDE-1', $walletSession);
    }

    public function test_wallet_deposit_session_cannot_mark_existing_card_orders_paid(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'wallet-mark-paid.example', 50);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'COLLIDE-PAID',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article',
        ]);

        $walletSession = (object) [
            'id' => 'cs_wallet_mark',
            'object' => 'checkout.session',
            'amount_total' => 5000,
            'payment_intent' => 'pi_wallet_mark',
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'reference_code' => 'COLLIDE-PAID',
                'expected_amount' => '50',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an order payment');
        app(OrderPaymentService::class)->markOrdersPaidFromStripeSession('COLLIDE-PAID', $walletSession);
    }
}
