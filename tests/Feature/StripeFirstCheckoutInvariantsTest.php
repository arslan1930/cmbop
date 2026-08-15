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

    public function test_finalize_skips_listing_that_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'left-catalog.example', 80);
        $live = $this->makeSite($publisher, 'still-live.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'LEFT-CATALOG-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
            $this->lineFor($live, 40),
        ], 120));

        $hidden->update(['verified' => false, 'active' => false]);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 120, 'cs_left_catalog')
        );

        $this->assertCount(1, $created);
        $this->assertSame($live->id, (int) $created->first()->items()->first()?->site_id);
        $this->assertSame(0, OrderItem::query()->where('site_id', $hidden->id)->count());

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_finalize_creates_no_orders_when_every_line_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'all-left-catalog.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'ALL-LEFT-CATALOG-1';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
        ], 60, 20));

        $hidden->update(['verified' => false, 'active' => false]);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 60, 'cs_all_left_catalog')
        );

        $this->assertCount(0, $created);
        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(60.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
        $this->assertEqualsWithDelta(60.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_mark_paid_skips_legacy_order_when_listing_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'legacy-hidden.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'LEGACY-HIDDEN-1';

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

        $site->update(['verified' => false, 'active' => false]);

        $session = $this->paidSession($ref, 80, 'cs_legacy_hidden');
        $session->metadata->bonus_applied = '20';
        $session->metadata->order_total = '100';

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession($ref, $session);

        $this->assertCount(0, $paid);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->status);

        $wallet->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            80.0,
            app(OrderPaymentService::class)->unfulfilledCardCreditAmount($ref),
            0.01
        );
    }

    public function test_taken_content_library_line_is_refunded_once_not_double_credited(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $takenSite = $this->makeSite($publisher, 'taken-article.example', 80);
        $hidden = $this->makeSite($publisher, 'taken-hidden.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 0);

        $prior = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PRIOR-TAKEN-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $priorItem = OrderItem::create([
            'order_id' => $prior->id,
            'site_id' => $takenSite->id,
            'site_name' => $takenSite->site_name,
            'site_url' => $takenSite->site_url,
            'content_link' => 'https://example.com/prior',
            'price' => 80,
        ]);
        $submission = $this->createApprovedSubmission($advertiser, $takenSite->id);
        $submission->update([
            'order_id' => $prior->id,
            'order_item_id' => $priorItem->id,
        ]);

        $ref = 'TAKEN-ARTICLE-1';
        $payments = app(OrderPaymentService::class);
        $takenLine = $this->lineFor($takenSite, 80);
        $takenLine['content_submission_id'] = $submission->id;
        $payments->storePendingCheckout($ref, $this->package($advertiser, [
            $takenLine,
            $this->lineFor($hidden, 40),
        ], 120));

        $hidden->update(['verified' => false, 'active' => false]);

        $created = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 120, 'cs_taken_article')
        );

        $this->assertCount(0, $created);
        $refunded = Order::query()
            ->where('reference_code', $ref)
            ->where('payment_status', 'refunded')
            ->get();
        $this->assertCount(1, $refunded);
        $this->assertSame('cancelled', $refunded->first()->status);
        $this->assertSame($prior->id, (int) $submission->fresh()->order_id);

        $wallet->refresh();
        $this->assertEqualsWithDelta(120.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(40.0, $payments->unfulfilledCardCreditAmount($ref), 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->refundedCardOrderAmount($ref), 0.01);
        $this->assertEqualsWithDelta(120.0, $payments->walletCreditForUnfulfillableCardCheckout($ref), 0.01);
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

    public function test_webhook_settles_without_retry_when_every_line_left_the_catalog(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $hidden = $this->makeSite($publisher, 'webhook-left-catalog.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'WH-LEFT-CATALOG-1';
        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package($advertiser, [
            $this->lineFor($hidden, 80),
        ], 80));

        $hidden->update(['verified' => false, 'active' => false]);

        $this->signedWebhook([
            'id' => 'evt_left_catalog_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_wh_left_catalog',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_wh_left',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, Order::query()->where('reference_code', $ref)->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_webhook_settles_when_content_library_line_was_already_taken(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'webhook-taken.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);

        $prior = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PRIOR-WH-TAKEN',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);
        $submission->update(['order_id' => $prior->id]);

        $ref = 'WH-TAKEN-ARTICLE-1';
        $line = $this->lineFor($site, 80);
        $line['content_submission_id'] = $submission->id;
        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package($advertiser, [$line], 80));

        $this->signedWebhook([
            'id' => 'evt_taken_article_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_wh_taken_article',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_wh_taken',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('refunded', Order::query()->where('reference_code', $ref)->value('payment_status'));
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, app(OrderPaymentService::class)->unfulfilledCardCreditAmount($ref), 0.01);
    }

    public function test_card_settlement_does_not_pay_another_users_colliding_reference(): void
    {
        $payer = $this->makeUser('advertiser');
        $other = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $payerSite = $this->makeSite($publisher, 'payer-collide.example', 80);
        $otherSite = $this->makeSite($publisher, 'other-collide.example', 80);
        $ref = '123456';

        $payerOrder = $this->pendingCardOrder($payer, $payerSite, $ref, 80);
        $otherOrder = $this->pendingCardOrder($other, $otherSite, $ref, 80);

        $session = (object) [
            'id' => 'cs_collide_payer',
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'amount_total' => 8000,
            'payment_intent' => 'pi_collide_payer',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'user_id' => (string) $payer->id,
                'expected_amount' => '80',
            ],
        ];

        $paid = app(OrderPaymentService::class)->markOrdersPaidFromStripeSession($ref, $session);

        $this->assertSame(1, $paid->count());
        $this->assertSame('paid', $payerOrder->fresh()->payment_status);
        $this->assertSame('pending', $otherOrder->fresh()->payment_status);
    }

    public function test_card_expiry_does_not_fail_another_users_colliding_reference(): void
    {
        $payer = $this->makeUser('advertiser');
        $other = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $payerSite = $this->makeSite($publisher, 'payer-expire.example', 80);
        $otherSite = $this->makeSite($publisher, 'other-expire.example', 80);
        $ref = '654321';

        $payerOrder = $this->pendingCardOrder($payer, $payerSite, $ref, 80);
        $otherOrder = $this->pendingCardOrder($other, $otherSite, $ref, 80);

        app(OrderPaymentService::class)->markOrdersFailedFromReference(
            $ref,
            'Checkout session expired',
            $payer->id
        );

        $this->assertSame('failed', $payerOrder->fresh()->payment_status);
        $this->assertSame('pending', $otherOrder->fresh()->payment_status);
    }

    public function test_card_settlement_without_user_id_refuses_ambiguous_reference(): void
    {
        $first = $this->makeUser('advertiser');
        $second = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $firstSite = $this->makeSite($publisher, 'ambig-a.example', 80);
        $secondSite = $this->makeSite($publisher, 'ambig-b.example', 80);
        $ref = '111111';

        $this->pendingCardOrder($first, $firstSite, $ref, 80);
        $this->pendingCardOrder($second, $secondSite, $ref, 80);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order payment reference is ambiguous without user_id');

        app(OrderPaymentService::class)->markOrdersPaidFromStripeSession($ref, (object) [
            'id' => 'cs_ambig',
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'amount_total' => 8000,
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'expected_amount' => '80',
            ],
        ]);
    }

    private function pendingCardOrder(User $advertiser, Site $site, string $ref, float $amount): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => $amount,
            'tax' => 0,
            'total_amount' => $amount,
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
            'price' => $amount,
        ]);

        return $order;
    }
}
