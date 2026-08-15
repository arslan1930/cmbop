<?php

namespace Tests\Feature;

use App\Http\Controllers\Advertiser\CatalogController;
use App\Models\CheckoutIntent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
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

    private function paidSession(string $ref, float $euros, string $sessionId = 'cs_test_finalize', ?int $userId = null): object
    {
        $metadata = [
            'type' => 'order_payment',
            'reference_code' => $ref,
            'expected_amount' => (string) $euros,
        ];
        if ($userId !== null) {
            $metadata['user_id'] = (string) $userId;
        }

        return (object) [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'amount_total' => (int) round($euros * 100),
            'payment_intent' => 'pi_'.substr($sessionId, -8),
            'metadata' => (object) $metadata,
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

    public function test_webhook_does_not_skip_finalize_because_another_user_already_paid_the_ref(): void
    {
        $payer = $this->makeUser('advertiser');
        $other = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $payerSite = $this->makeSite($publisher, 'payer-wh-collide.example', 80);
        $otherSite = $this->makeSite($publisher, 'other-wh-collide.example', 80);
        $ref = '222222';

        $this->pendingCardOrder($other, $otherSite, $ref, 80);
        app(OrderPaymentService::class)->markOrdersPaidFromStripeSession($ref, (object) [
            'id' => 'cs_other_already_paid',
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'amount_total' => 8000,
            'payment_intent' => 'pi_other_already_paid',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'user_id' => (string) $other->id,
                'expected_amount' => '80',
            ],
        ]);
        $this->assertSame('paid', Order::query()->where('user_id', $other->id)->where('reference_code', $ref)->value('payment_status'));

        app(OrderPaymentService::class)->storePendingCheckout(
            $ref,
            $this->package($payer, [$this->lineFor($payerSite, 80)], 80)
        );

        $this->signedWebhook([
            'id' => 'evt_payer_after_other_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_payer_after_other',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_payer_after_other',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $payer->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()->where('user_id', $payer->id)->where('reference_code', $ref)->where('payment_status', 'paid')->count());
        $this->assertSame(1, Order::query()->where('user_id', $other->id)->where('reference_code', $ref)->where('payment_status', 'paid')->count());
    }

    public function test_another_users_unfulfilled_credit_does_not_settle_this_payer(): void
    {
        $other = $this->makeUser('advertiser');
        $payer = $this->makeUser('advertiser');
        $ref = '666666';
        $payments = app(OrderPaymentService::class);

        $payments->creditUnfulfilledCardCapture($other->id, $ref, 80);

        $this->assertEqualsWithDelta(80.0, $payments->walletCreditForUnfulfillableCardCheckout($ref), 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->walletCreditForUnfulfillableCardCheckout($ref, $payer->id), 0.01);

        $this->signedWebhook([
            'id' => 'evt_payer_no_package_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_payer_no_package',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_payer_no_package',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $payer->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertEqualsWithDelta(80.0, $payments->walletCreditForUnfulfillableCardCheckout($ref, $payer->id), 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->walletCreditForUnfulfillableCardCheckout($ref, $other->id), 0.01);
        $this->assertSame(0, Order::query()->where('user_id', $payer->id)->count());
    }

    public function test_finalize_creates_order_when_another_user_already_used_the_line_key(): void
    {
        $first = $this->makeUser('advertiser');
        $second = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'shared-line.example', 80);
        $ref = '555555';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($first, [$this->lineFor($site, 80)], 80));
        $firstOrders = $payments->finalizeStripeFirstCheckout($ref, $this->paidSession($ref, 80, 'cs_first_line'));
        $this->assertCount(1, $firstOrders);

        $payments->storePendingCheckout($ref, $this->package($second, [$this->lineFor($site, 80)], 80));
        $secondOrders = $payments->finalizeStripeFirstCheckout($ref, (object) [
            'id' => 'cs_second_line',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_second_line',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => $ref,
                'user_id' => (string) $second->id,
                'expected_amount' => '80',
            ],
        ]);

        $this->assertCount(1, $secondOrders);
        $this->assertSame($second->id, (int) $secondOrders->first()->user_id);
        $this->assertSame('paid', $secondOrders->first()->payment_status);
        $this->assertNotSame(
            $firstOrders->first()->checkout_line_key,
            $secondOrders->first()->checkout_line_key
        );
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref, $second->id), 0.01);
    }

    public function test_second_card_charge_on_same_ref_creates_another_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $firstSite = $this->makeSite($publisher, 'second-charge-a.example', 80);
        $secondSite = $this->makeSite($publisher, 'second-charge-b.example', 40);
        $ref = 'REUSE-REF-1';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($firstSite, 80)], 80));
        $first = $payments->finalizeStripeFirstCheckout($ref, $this->paidSession($ref, 80, 'cs_reuse_first'));
        $this->assertCount(1, $first);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($secondSite, 40)], 40));

        $this->signedWebhook([
            'id' => 'evt_reuse_second_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_reuse_second',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 4000,
                    'payment_intent' => 'pi_reuse_second',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '40',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(2, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_status', 'paid')
            ->count());
        $this->assertSame(1, Order::query()->where('stripe_session_id', 'cs_reuse_second')->count());
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref, $advertiser->id), 0.01);
    }

    public function test_orphan_second_session_after_package_cleared_credits_wallet(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'orphan-second.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'ORPHAN-SECOND-1';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($site, 80)], 80));
        $first = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 80, 'cs_orphan_first', $advertiser->id)
        );
        $this->assertCount(1, $first);
        $this->assertNull($payments->getPendingCheckout($ref));

        $this->signedWebhook([
            'id' => 'evt_orphan_second_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_orphan_second',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_orphan_second',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_status', 'paid')
            ->count());
        $this->assertSame(0, Order::query()->where('stripe_session_id', 'cs_orphan_second')->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref, $advertiser->id), 0.01);
    }

    public function test_stale_session_after_package_overwrite_credits_and_leaves_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $firstSite = $this->makeSite($publisher, 'stale-overwrite-a.example', 80);
        $secondSite = $this->makeSite($publisher, 'stale-overwrite-b.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'STALE-OVERWRITE-1';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($firstSite, 80)], 80));
        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($secondSite, 40)], 40));

        $this->signedWebhook([
            'id' => 'evt_stale_overwrite_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_stale_overwrite',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_stale_overwrite',
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
        $this->assertSame(40.0, (float) ($payments->getPendingCheckout($ref)['amount_due'] ?? 0));
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);

        $live = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 40, 'cs_live_overwrite', $advertiser->id)
        );
        $this->assertCount(1, $live);
        $this->assertSame($secondSite->id, (int) $live->first()->items()->first()?->site_id);
        $this->assertNull($payments->getPendingCheckout($ref));
        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref, $advertiser->id), 0.01);
    }

    public function test_failed_rows_are_not_paid_by_a_later_smaller_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $staleSite = $this->makeSite($publisher, 'stale-failed-a.example', 80);
        $liveSite = $this->makeSite($publisher, 'stale-failed-b.example', 40);
        $ref = 'STALE-FAIL-1';
        $payments = app(OrderPaymentService::class);

        $stale = $this->pendingCardOrder($advertiser, $staleSite, $ref, 80);
        $stale->update(['payment_status' => 'failed']);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($liveSite, 40)], 40));
        $this->assertSame('cancelled', $stale->fresh()->status);

        $this->signedWebhook([
            'id' => 'evt_stale_fail_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_stale_fail_small',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 4000,
                    'payment_intent' => 'pi_stale_fail_small',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '40',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('failed', $stale->fresh()->payment_status);
        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertSame(1, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_status', 'paid')
            ->count());
        $this->assertSame($liveSite->id, (int) OrderItem::query()
            ->whereIn('order_id', Order::query()->where('reference_code', $ref)->where('payment_status', 'paid')->pluck('id'))
            ->value('site_id'));
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref, $advertiser->id), 0.01);
    }

    public function test_failed_rows_do_not_swallow_a_later_larger_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $staleSite = $this->makeSite($publisher, 'stale-small-fail.example', 40);
        $liveSite = $this->makeSite($publisher, 'stale-large-live.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'STALE-FAIL-2';
        $payments = app(OrderPaymentService::class);

        $stale = $this->pendingCardOrder($advertiser, $staleSite, $ref, 40);
        $stale->update(['payment_status' => 'failed']);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($liveSite, 80)], 80));

        $this->signedWebhook([
            'id' => 'evt_stale_fail_large_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_stale_fail_large',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_stale_fail_large',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertSame(1, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_status', 'paid')
            ->count());
        $this->assertSame($liveSite->id, (int) OrderItem::query()
            ->whereIn('order_id', Order::query()->where('reference_code', $ref)->where('payment_status', 'paid')->pluck('id'))
            ->value('site_id'));
        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
    }

    public function test_prior_refund_does_not_ack_a_later_orphan_capture(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'prior-refund-orphan.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 0);
        $ref = 'PRIOR-REFUND-1';
        $payments = app(OrderPaymentService::class);

        $prior = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => $ref,
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'refunded',
            'status' => 'cancelled',
            'stripe_session_id' => 'cs_prior_refund',
            'stripe_payment_intent_id' => 'pi_prior_refund',
        ]);
        OrderItem::create([
            'order_id' => $prior->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 80,
        ]);

        $this->assertEqualsWithDelta(80.0, $payments->walletCreditForUnfulfillableCardCheckout($ref, $advertiser->id), 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->walletCreditForThisCardCharge(
            $ref,
            $this->paidSession($ref, 80, 'cs_later_orphan', $advertiser->id),
            $advertiser->id
        ), 0.01);

        $this->signedWebhook([
            'id' => 'evt_later_orphan_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_later_orphan',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_later_orphan',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $payments->unfulfilledCardCreditAmount($ref, $advertiser->id), 0.01);
        $this->assertSame(0, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_status', 'paid')
            ->count());
    }

    public function test_abandoned_bonus_reserve_is_not_burned_on_later_full_card_approve(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'stale-bonus-approve.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'STALE-BONUS-1';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($site, 100)], 80, 20));
        $this->assertEqualsWithDelta(20.0, (float) $wallet->fresh()->bonus_reserved, 0.01);

        $released = $payments->releaseRecordedCheckoutBonus($advertiser->id, $ref);
        $this->assertEqualsWithDelta(20.0, $released, 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($site, 100)], 100, 0));
        $orders = $payments->finalizeStripeFirstCheckout(
            $ref,
            $this->paidSession($ref, 100, 'cs_stale_bonus_full', $advertiser->id)
        );
        $this->assertCount(1, $orders);

        app(OrderRefundService::class)->consumeReservedForSettledOrder($orders->first(), $wallet->fresh());

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
    }

    public function test_release_recorded_bonus_does_not_touch_another_refs_reserve(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'bonus-other-ref.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 40);
        $payments = app(OrderPaymentService::class);

        $wallet->reserveBonusOnly(20);
        $payments->storePendingCheckout('BONUS-REF-A', $this->package($advertiser, [$this->lineFor($site, 40)], 20, 20));
        $wallet->refresh();
        $wallet->reserveBonusOnly(20);
        $payments->storePendingCheckout('BONUS-REF-B', $this->package($advertiser, [$this->lineFor($site, 40)], 20, 20));

        $released = $payments->releaseRecordedCheckoutBonus($advertiser->id, 'BONUS-REF-A');
        $this->assertEqualsWithDelta(20.0, $released, 0.01);

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertNotNull($payments->getPendingCheckout('BONUS-REF-B'));
        $this->assertEqualsWithDelta(20.0, (float) ($payments->getPendingCheckout('BONUS-REF-B')['bonus_applied'] ?? 0), 0.01);
    }

    public function test_second_release_of_same_ref_does_not_steal_sibling_bonus(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'bonus-steal.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);

        $wallet->reserveBonusOnly(20);
        $payments->storePendingCheckout('BONUS-STEAL-A', $this->package($advertiser, [$this->lineFor($site, 40)], 20, 20));
        $this->assertEqualsWithDelta(20.0, $payments->releaseRecordedCheckoutBonus($advertiser->id, 'BONUS-STEAL-A'), 0.01);

        $wallet->refresh();
        $wallet->reserveBonusOnly(20);
        $payments->storePendingCheckout('BONUS-STEAL-B', $this->package($advertiser, [$this->lineFor($site, 40)], 20, 20));

        $this->assertEqualsWithDelta(0.0, $payments->releaseRecordedCheckoutBonus($advertiser->id, 'BONUS-STEAL-A'), 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_approving_full_card_order_does_not_burn_other_refs_bonus(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $bonusSite = $this->makeSite($publisher, 'bonus-hold.example', 40);
        $cardSite = $this->makeSite($publisher, 'full-card.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);

        $wallet->reserveBonusOnly(20);
        $payments->storePendingCheckout('HOLD-BONUS-A', $this->package($advertiser, [$this->lineFor($bonusSite, 40)], 20, 20));
        $bonusOrders = $payments->finalizeStripeFirstCheckout(
            'HOLD-BONUS-A',
            $this->paidSession('HOLD-BONUS-A', 20, 'cs_hold_bonus_a', $advertiser->id)
        );
        $this->assertCount(1, $bonusOrders);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->fresh()->bonus_reserved, 0.01);

        $payments->storePendingCheckout('FULL-CARD-B', $this->package($advertiser, [$this->lineFor($cardSite, 80)], 80, 0));
        $cardOrders = $payments->finalizeStripeFirstCheckout(
            'FULL-CARD-B',
            $this->paidSession('FULL-CARD-B', 80, 'cs_full_card_b', $advertiser->id)
        );
        $this->assertCount(1, $cardOrders);

        app(OrderRefundService::class)->consumeReservedForSettledOrder($cardOrders->first(), $wallet->fresh());

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);

        app(OrderRefundService::class)->consumeReservedForSettledOrder($bonusOrders->first(), $wallet->fresh());
        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_releasing_bonus_for_one_ref_does_not_refund_another_refs_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'bonus-expiry-cap.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);

        $wallet->reserveBonusOnly(20);
        $payments->storePendingCheckout('HOLD-EXPIRE-A', $this->package($advertiser, [$this->lineFor($site, 40)], 20, 20));

        $payments->refundBonusReservedForReference($advertiser->id, 'OTHER-EXPIRE-B', 0, collect());
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_store_package_refuses_to_overwrite_another_users_checkout(): void
    {
        $owner = $this->makeUser('advertiser');
        $intruder = $this->makeUser('advertiser');
        $ref = '333333';
        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout($ref, $this->package($owner, [], 40));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already belongs to another user');
        $payments->storePendingCheckout($ref, $this->package($intruder, [], 80));
    }

    public function test_cancel_does_not_delete_another_users_pending_package(): void
    {
        $owner = $this->makeUser('advertiser');
        $intruder = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'cancel-steal.example', 40);
        $ref = '444444';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package($owner, [
            ['site_id' => $site->id, 'price' => 40, 'content_submission_id' => 0],
        ], 40));

        $this->actingAs($intruder)
            ->withSession(['cart' => [[
                'id' => $site->id,
                'name' => $site->site_name,
                'quantity' => 1,
            ]]])
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => $ref]));

        $package = app(OrderPaymentService::class)->getPendingCheckout($ref);
        $this->assertNotNull($package);
        $this->assertSame($owner->id, (int) ($package['user_id'] ?? 0));
    }

    public function test_expired_foreign_intent_does_not_block_later_payer_store(): void
    {
        $owner = $this->makeUser('advertiser');
        $payer = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'expired-intent.example', 80);
        $ref = '777777';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($owner, [$this->lineFor($site, 80)], 80));
        CheckoutIntent::query()->where('reference_code', $ref)->update([
            'expires_at' => now()->subHour(),
        ]);
        Cache::forget(OrderPaymentService::pendingCheckoutCacheKey($ref));

        $payments->storePendingCheckout($ref, $this->package($payer, [$this->lineFor($site, 80)], 80));

        $stored = $payments->getPendingCheckout($ref);
        $this->assertNotNull($stored);
        $this->assertSame($payer->id, (int) ($stored['user_id'] ?? 0));
    }

    public function test_take_bonus_does_not_clear_another_users_intent(): void
    {
        $owner = $this->makeUser('advertiser');
        $other = $this->makeUser('advertiser');
        $ref = '888888';
        $intents = app(CheckoutIntentService::class);
        $intents->storePackage($ref, $this->package($owner, [], 20, 20));

        $this->assertSame(0.0, $intents->takeBonus($other->id, $ref));
        $this->assertEqualsWithDelta(
            20.0,
            (float) CheckoutIntent::query()->where('reference_code', $ref)->value('bonus_applied'),
            0.01
        );

        $this->assertEqualsWithDelta(20.0, $intents->takeBonus($owner->id, $ref), 0.01);
        $this->assertEqualsWithDelta(
            0.0,
            (float) CheckoutIntent::query()->where('reference_code', $ref)->value('bonus_applied'),
            0.01
        );
    }

    public function test_untyped_paid_session_with_package_materializes_orders(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'untyped-pkg.example', 80);
        $ref = 'UNTYPED-PKG-1';

        app(OrderPaymentService::class)->storePendingCheckout(
            $ref,
            $this->package($advertiser, [$this->lineFor($site, 80)], 80)
        );

        $this->signedWebhook([
            'id' => 'evt_untyped_pkg_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_untyped_pkg',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_untyped_pkg',
                    'metadata' => [
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_status', 'paid')
            ->count());
    }

    public function test_untyped_expiry_with_package_releases_bonus_without_failing_other_users_order(): void
    {
        $owner = $this->makeUser('advertiser');
        $other = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'untyped-expire-pkg.example', 40);
        $otherSite = $this->makeSite($publisher, 'untyped-expire-other.example', 80);
        $wallet = $this->advertiserWallet($owner, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'UNTYPED-EXPIRE-PKG';

        app(OrderPaymentService::class)->storePendingCheckout($ref, $this->package(
            $owner,
            [$this->lineFor($site, 40)],
            20,
            20
        ));
        $otherOrder = $this->pendingCardOrder($other, $otherSite, $ref, 80);

        $this->signedWebhook([
            'id' => 'evt_untyped_expire_pkg_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_untyped_expire_pkg',
                    'object' => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'metadata' => [
                        'reference_code' => $ref,
                        'user_id' => (string) $owner->id,
                        'bonus_applied' => '20',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertNull(app(OrderPaymentService::class)->getPendingCheckout($ref));
        $this->assertSame('pending', $otherOrder->fresh()->payment_status);

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
    }

    public function test_release_recorded_bonus_keeps_hold_when_paid_siblings_still_open(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'paid-sibling-hold.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'PAID-SIBLING-HOLD';
        $payments = app(OrderPaymentService::class);

        $payments->persistPaidCheckoutBonus($advertiser->id, $ref, 20);
        $order = $this->pendingCardOrder($advertiser, $site, $ref, 100);
        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0.0, $payments->releaseRecordedCheckoutBonus($advertiser->id, $ref), 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);

        app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'publisher rejected');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_pending_unpaid_card_rows_still_allow_abandoned_bonus_release(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'pay-again-bonus.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'PAY-AGAIN-BONUS';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($site, 100)], 80, 20));
        $this->pendingCardOrder($advertiser, $site, $ref, 100);

        $this->assertEqualsWithDelta(20.0, $payments->releaseRecordedCheckoutBonus($advertiser->id, $ref), 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_expired_ghost_intent_does_not_turn_reject_promo_into_cash(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'ghost-intent-reject.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $liveRef = 'LIVE-PROMO-REF';
        $ghostRef = 'EXPIRED-GHOST-REF';

        CheckoutIntent::query()->create([
            'user_id' => $advertiser->id,
            'reference_code' => $ghostRef,
            'bonus_applied' => 20,
            'package' => ['user_id' => $advertiser->id, 'bonus_applied' => 20],
            'expires_at' => now()->subHour(),
        ]);
        Cache::forget(CheckoutIntentService::bonusCacheKey($advertiser->id, $ghostRef));

        $this->assertEqualsWithDelta(
            0.0,
            app(CheckoutIntentService::class)->otherRecordedBonus($advertiser->id, $liveRef),
            0.01
        );

        $order = $this->pendingCardOrder($advertiser, $site, $liveRef, 100);
        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'publisher rejected');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_second_card_bonus_reserve_cannot_reapply_the_same_promo(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 20);
        $payments = app(OrderPaymentService::class);

        $this->assertEqualsWithDelta(20.0, $payments->reserveCheckoutBonus($advertiser->id, 80), 0.01);
        $this->assertEqualsWithDelta(0.0, $payments->reserveCheckoutBonus($advertiser->id, 80), 0.01);

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_in_flight_card_package_is_not_forgotten_so_later_capture_can_settle(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'inflight-wallet.example', 80);
        $ref = 'INFLIGHT-CARD-1';
        $payments = app(OrderPaymentService::class);

        $payments->storePendingCheckout($ref, $this->package($advertiser, [$this->lineFor($site, 80)], 80));
        $this->assertTrue($payments->hasInFlightCardCheckout($advertiser->id, $ref));

        $this->signedWebhook([
            'id' => 'evt_inflight_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_inflight_card',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 8000,
                    'payment_intent' => 'pi_inflight_card',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '80',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_method', 'card')
            ->where('payment_status', 'paid')
            ->count());
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref, $advertiser->id), 0.01);
    }

    public function test_stale_session_expiry_does_not_wipe_live_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'stale-expire.example', 80);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'STALE-EXPIRE-1';
        $payments = app(OrderPaymentService::class);

        $live = $this->package($advertiser, [$this->lineFor($site, 80)], 60, 20);
        $live['stripe_session_id'] = 'cs_live_package';
        $payments->storePendingCheckout($ref, $live);

        $this->signedWebhook([
            'id' => 'evt_stale_expire_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_old_superseded',
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

        $package = $payments->getPendingCheckout($ref);
        $this->assertNotNull($package);
        $this->assertSame('cs_live_package', $package['stripe_session_id'] ?? null);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);

        $this->signedWebhook([
            'id' => 'evt_live_pay_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_live_package',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'amount_total' => 6000,
                    'payment_intent' => 'pi_live_package',
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $ref,
                        'user_id' => (string) $advertiser->id,
                        'expected_amount' => '60',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, Order::query()
            ->where('user_id', $advertiser->id)
            ->where('reference_code', $ref)
            ->where('payment_method', 'card')
            ->where('payment_status', 'paid')
            ->count());
        $this->assertEqualsWithDelta(0.0, $payments->unfulfilledCardCreditAmount($ref, $advertiser->id), 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_matching_session_expiry_still_releases_live_package(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'match-expire.example', 40);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'MATCH-EXPIRE-1';
        $payments = app(OrderPaymentService::class);

        $package = $this->package($advertiser, [$this->lineFor($site, 40)], 20, 20);
        $package['stripe_session_id'] = 'cs_match_expire';
        $payments->storePendingCheckout($ref, $package);

        $this->signedWebhook([
            'id' => 'evt_match_expire_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_match_expire',
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

        $this->assertNull($payments->getPendingCheckout($ref));
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_stale_expiry_does_not_fail_retry_pending_rows(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'retry-expire.example', 80);
        $ref = 'RETRY-EXPIRE-1';

        $order = $this->pendingCardOrder($advertiser, $site, $ref, 80);
        $order->update(['stripe_session_id' => 'cs_retry_live']);

        $this->signedWebhook([
            'id' => 'evt_retry_stale_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_retry_old',
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

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('cs_retry_live', $order->fresh()->stripe_session_id);
    }

    public function test_expired_ghost_on_this_ref_does_not_release_another_refs_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'ghost-this-ref.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $payments = app(OrderPaymentService::class);

        CheckoutIntent::query()->create([
            'user_id' => $advertiser->id,
            'reference_code' => 'GHOST-THIS-A',
            'bonus_applied' => 20,
            'package' => ['user_id' => $advertiser->id, 'bonus_applied' => 20],
            'expires_at' => now()->subHour(),
        ]);
        Cache::forget(CheckoutIntentService::bonusCacheKey($advertiser->id, 'GHOST-THIS-A'));

        $payments->persistPaidCheckoutBonus($advertiser->id, 'LIVE-HOLD-B', 20);
        $order = $this->pendingCardOrder($advertiser, $site, 'LIVE-HOLD-B', 100);
        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0.0, $payments->releaseRecordedCheckoutBonus($advertiser->id, 'GHOST-THIS-A'), 0.01);
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);

        app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'publisher rejected');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_cancel_url_does_not_delete_paid_checkout_bonus_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'cancel-persist.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'CANCEL-PERSIST-1';
        $payments = app(OrderPaymentService::class);

        $payments->persistPaidCheckoutBonus($advertiser->id, $ref, 20);
        $order = $this->pendingCardOrder($advertiser, $site, $ref, 100);
        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => $ref]));

        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->recordedBonus($advertiser->id, $ref),
            0.01
        );
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);

        app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'publisher rejected');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_late_matching_expiry_keeps_paid_bonus_persist_hold(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'late-expire-persist.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(20);
        $ref = 'LATE-EXPIRE-PERSIST';
        $payments = app(OrderPaymentService::class);

        $payments->persistPaidCheckoutBonus($advertiser->id, $ref, 20);
        $order = $this->pendingCardOrder($advertiser, $site, $ref, 100);
        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
            'stripe_session_id' => 'cs_late_paid',
        ]);

        $this->signedWebhook([
            'id' => 'evt_late_expire_persist_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id' => 'cs_late_paid',
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

        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->recordedBonus($advertiser->id, $ref),
            0.01
        );
        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);

        app(OrderRefundService::class)->cancelAndRefund($order->fresh(), 'publisher rejected');

        $wallet->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_late_expiry_on_two_paid_bonus_refs_does_not_mint_reject_cash(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $firstSite = $this->makeSite($publisher, 'late-expire-a.example', 100);
        $secondSite = $this->makeSite($publisher, 'late-expire-b.example', 100);
        $wallet = $this->advertiserWallet($advertiser, 20);
        $wallet->reserveBonusOnly(10);
        $wallet->reserveBonusOnly(10);
        $payments = app(OrderPaymentService::class);

        $payments->persistPaidCheckoutBonus($advertiser->id, 'LATE-A', 10);
        $payments->persistPaidCheckoutBonus($advertiser->id, 'LATE-B', 10);
        $first = $this->pendingCardOrder($advertiser, $firstSite, 'LATE-A', 100);
        $first->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
            'stripe_session_id' => 'cs_late_a',
        ]);
        $second = $this->pendingCardOrder($advertiser, $secondSite, 'LATE-B', 100);
        $second->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
            'stripe_session_id' => 'cs_late_b',
        ]);

        foreach (['LATE-A' => 'cs_late_a', 'LATE-B' => 'cs_late_b'] as $ref => $sessionId) {
            $this->signedWebhook([
                'id' => 'evt_late_two_'.$sessionId.'_'.uniqid(),
                'object' => 'event',
                'type' => 'checkout.session.expired',
                'data' => [
                    'object' => [
                        'id' => $sessionId,
                        'object' => 'checkout.session',
                        'payment_status' => 'unpaid',
                        'metadata' => [
                            'type' => 'order_payment',
                            'reference_code' => $ref,
                            'user_id' => (string) $advertiser->id,
                            'bonus_applied' => '10',
                        ],
                    ],
                ],
            ])->assertOk();
        }

        $this->assertEqualsWithDelta(
            10.0,
            app(CheckoutIntentService::class)->recordedBonus($advertiser->id, 'LATE-A'),
            0.01
        );
        $this->assertEqualsWithDelta(
            10.0,
            app(CheckoutIntentService::class)->recordedBonus($advertiser->id, 'LATE-B'),
            0.01
        );

        app(OrderRefundService::class)->consumeReservedForSettledOrder($first->fresh(), $wallet->fresh());
        $wallet->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);

        app(OrderRefundService::class)->cancelAndRefund($second->fresh(), 'publisher rejected');

        $wallet->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(90.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_allocator_remints_ref_after_wallet_paid_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher, 'wallet-ref-reuse.example', 40);
        $ref = 'WALLET-PAID-REF';

        $order = $this->pendingCardOrder($advertiser, $site, $ref, 40);
        $order->update([
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $allocate = new ReflectionMethod(CatalogController::class, 'allocateCheckoutReferenceCode');
        $allocated = $allocate->invoke(app(CatalogController::class), $advertiser->id, $ref);

        $this->assertNotSame($ref, $allocated);
        $this->assertNotSame('', $allocated);
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
