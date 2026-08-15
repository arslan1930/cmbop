<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutSystemFixTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();
        parent::tearDown();
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug = 'fix', float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function fakeStripeCheckoutSession(string $sessionId = 'cs_test_fix'): void
    {
        config([
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $customerBody = json_encode([
            'id' => 'cus_test_'.substr($sessionId, -8),
            'object' => 'customer',
            'email' => 'test@example.com',
            'livemode' => false,
        ], JSON_THROW_ON_ERROR);

        $sessionBody = json_encode([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
            'payment_status' => 'unpaid',
            'mode' => 'payment',
            'metadata' => [],
        ], JSON_THROW_ON_ERROR);

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')
            ->twice()
            ->andReturn(
                [$customerBody, 200, []],
                [$sessionBody, 200, []]
            );
        ApiRequestor::setHttpClient($client);
    }

    public function test_wallet_checkout_from_content_library_session(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'wallet', 50);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $advRole = Role::where('name', 'advertiser')->first();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'WAL1',
                'publication_mode' => 'immediate',
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, Order::where('reference_code', 'WAL1')->count());
        $this->assertSame($sub->id, OrderItem::first()->content_submission_id);
        $this->assertNotNull($sub->fresh()->order_id);
        $this->assertTrue(session()->missing('cart'));
        $this->assertTrue(session()->missing('checkout_content_submission_id'));
    }

    public function test_stripe_cancel_releases_article_and_restores_checkout(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'cancel', 40);
        $sub = $this->createApprovedSubmission($advertiser, null);
        $this->fakeStripeCheckoutSession('cs_test_cancel');

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'CAN1',
                'publication_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        // Stripe-first (Add Funds style): article stays free until payment succeeds.
        $this->assertNull($sub->fresh()->order_id);
        $this->assertTrue($sub->fresh()->canBeOrdered());
        $this->assertNotNull(Cache::get('pending_card_checkout:CAN1'));

        $page = $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'CAN1']));

        $page->assertOk();
        $this->assertNull($sub->fresh()->order_id);
        $this->assertTrue($sub->fresh()->canBeOrdered());
        $this->assertSame(0, Order::where('reference_code', 'CAN1')->count());
        $this->assertNull(Cache::get('pending_card_checkout:CAN1'));
        $this->assertNotEmpty(session('cart'));
        $this->assertSame($sub->id, session('checkout_content_submission_id'));
    }

    public function test_stripe_cancel_does_not_restore_a_site_that_left_the_catalog(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'cancel-hidden', 40);
        $sub = $this->createApprovedSubmission($advertiser, null);
        $this->fakeStripeCheckoutSession('cs_test_cancel_hidden');

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'CANH1',
                'publication_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $site->update(['verified' => false]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'CANH1']))
            ->assertRedirect(route('advertiser.catalog'));

        $this->assertEmpty(session('cart', []));
    }

    public function test_checkout_page_resolves_library_article_from_cart_without_session_key(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'ui', 40);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'price' => 46,
                ]],
            ])
            ->get(route('advertiser.checkout'));

        $response->assertOk();
        $response->assertDontSee('Approved article for this order', false);
        $response->assertDontSee('contentSubmissionWizard', false);
        $response->assertSee($sub->title ?: $sub->original_filename, false);
        $response->assertSee('order-summary-article', false);
        $response->assertSee('Article history', false);
        $response->assertSee('Uploaded', false);
        $response->assertSee('3. Payment', false);
        $response->assertSee('2. Publication', false);
    }

    public function test_library_order_redirects_to_catalog_for_article_market(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $sub = $this->createApprovedSubmission($advertiser, null);

        $response = $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $sub));

        $response->assertRedirect(route('advertiser.catalog', [
            'content_submission_id' => $sub->id,
        ]));
        // No language filter: an article may be placed on a site in any language.
        $this->assertStringNotContainsString('language=', (string) $response->headers->get('Location'));
        $response->assertSessionHas('success');
        $this->assertSame($sub->id, session('checkout_content_submission_id'));
        $this->assertTrue((bool) session('ordering_from_library'));
    }

    public function test_one_article_is_charged_once_and_the_other_site_stays_in_the_cart(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $this->fundAdvertiserWallet($advertiser);
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'a', 40);
        $siteB = $this->activeSite($publisher, 'b', 50);
        $sub = $this->createApprovedSubmission($advertiser, null);

        // Both cart lines point at the same article. One article covers one site,
        // so rather than failing the whole payment the second site is deferred:
        // the ready one is charged and the other waits in the cart for its own
        // article. Pay from the wallet — bank/Wise/crypto fund the wallet first.
        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1, 'content_submission_id' => $sub->id],
                    ['id' => $siteB->id, 'name' => $siteB->site_name, 'quantity' => 1, 'content_submission_id' => $sub->id],
                ],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'SAFE1',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $siteA->id => [$sub->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        // Charged once, for the site that had the article.
        $this->assertSame(1, OrderItem::where('content_submission_id', $sub->id)->count());
        $this->assertSame(1, OrderItem::where('site_id', $siteA->id)->count());
        $this->assertSame(0, OrderItem::where('site_id', $siteB->id)->count());

        // The unpaid site is handed back rather than silently dropped.
        $cart = session('cart');
        $this->assertIsArray($cart);
        $this->assertCount(1, $cart);
        $this->assertSame($siteB->id, (int) $cart[0]['id']);
    }

    public function test_bank_wise_and_crypto_are_sent_to_the_wallet_first(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'a', 40);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1, 'content_submission_id' => $sub->id],
                ],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'SAFE2',
                'publication_mode' => 'immediate',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'fund_wallet_first']);
        $this->assertStringContainsString(
            'wallet',
            strtolower((string) $response->json('message'))
        );
        $this->assertNotNull($response->json('redirect_url'));

        // Nothing is charged and the article stays free to order.
        $this->assertSame(0, OrderItem::where('content_submission_id', $sub->id)->count());
        $this->assertNull($sub->fresh()->order_id);
    }

    public function test_expired_article_cannot_be_ordered(): void
    {
        $advertiser = $this->advertiser();
        $sub = $this->createApprovedSubmission($advertiser, null);
        $sub->update(['expires_at' => now()->subDay()]);

        $this->assertFalse($sub->fresh()->canBeOrdered());
    }

    public function test_publication_mode_constant_is_immediate(): void
    {
        $this->assertSame('immediate', ContentSubmission::MODE_IMMEDIATE);
    }

    public function test_card_checkout_mints_new_ref_when_another_user_already_paid_it(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $owner = $this->advertiser();
        $payer = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'taken-ref', 40);
        $sub = $this->createApprovedSubmission($payer, null);

        Order::create([
            'user_id' => $owner->id,
            'order_number' => '654321',
            'reference_code' => '555555',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);

        $this->fakeStripeCheckoutSession('cs_test_taken_ref');

        $response = $this->actingAs($payer)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => '555555',
                'publication_mode' => 'immediate',
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $minted = (string) $response->json('reference_code');
        $this->assertNotSame('555555', $minted);
        $this->assertNotNull(Cache::get('pending_card_checkout:'.$minted));
        $this->assertNull(Cache::get('pending_card_checkout:555555'));
    }

    public function test_card_checkout_mints_new_ref_when_this_user_already_paid_it(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $payer = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'own-paid-ref', 40);
        $sub = $this->createApprovedSubmission($payer, null);

        Order::create([
            'user_id' => $payer->id,
            'order_number' => '654322',
            'reference_code' => '666666',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);

        $this->fakeStripeCheckoutSession('cs_test_own_paid_ref');

        $response = $this->actingAs($payer)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => '666666',
                'publication_mode' => 'immediate',
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $minted = (string) $response->json('reference_code');
        $this->assertNotSame('666666', $minted);
        $this->assertNotNull(Cache::get('pending_card_checkout:'.$minted));
        $this->assertNull(Cache::get('pending_card_checkout:666666'));
    }

    public function test_wallet_checkout_refuses_in_flight_card_package_on_same_ref(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'card-then-wallet', 40);
        $sub = $this->createApprovedSubmission($advertiser, null);
        $this->fakeStripeCheckoutSession('cs_test_card_then_wallet');

        $advRole = Role::where('name', 'advertiser')->first();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'CARDW1',
                'publication_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull(Cache::get('pending_card_checkout:CARDW1'));

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'CARDW1',
                'publication_mode' => 'immediate',
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertNotNull(Cache::get('pending_card_checkout:CARDW1'));
        $this->assertSame(0, Order::where('reference_code', 'CARDW1')->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(500.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
    }

    public function test_wallet_checkout_cancels_leftover_failed_card_rows_for_the_same_sites(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'wallet-kills-retry', 40);
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $advRole = Role::where('name', 'advertiser')->first();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $failed = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'FAIL-RETRY-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $failed->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'content_submission_id' => $sub->id,
            'price' => 40,
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'WALLET2',
                'publication_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $failed->refresh();
        $this->assertSame('cancelled', $failed->status);
        $this->assertSame('failed', $failed->payment_status);
        $this->assertSame(1, Order::query()->where('reference_code', 'WALLET2')->where('payment_status', 'paid')->count());

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.retry-payment', $failed->id))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_retry_payment_refuses_when_a_later_checkout_already_paid_the_listing(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'retry-already-paid', 40);
        $sub = $this->createApprovedSubmission($advertiser, $site->id);

        $paid = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PAID-LATER-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $paid->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'content_submission_id' => $sub->id,
            'price' => 40,
        ]);

        $failed = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'FAIL-STILL-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $failed->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'content_submission_id' => $sub->id,
            'price' => 40,
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.retry-payment', $failed->id))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertSame('failed', $failed->fresh()->payment_status);
        $this->assertSame('pending', $failed->fresh()->status);
    }

    public function test_wallet_checkout_abandons_overlapping_card_package(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'wallet-kills-package', 40);
        $sub = $this->createApprovedSubmission($advertiser, $site->id);
        $advRole = Role::where('name', 'advertiser')->first();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $payments = app(OrderPaymentService::class);
        $payments->storePendingCheckout('CARD-PKG-1', [
            'user_id' => $advertiser->id,
            'reference_code' => 'CARD-PKG-1',
            'order_total' => 40,
            'amount_due' => 40,
            'bonus_applied' => 0,
            'schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'lines' => [[
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 40,
                'content_submission_id' => $sub->id,
                'content_link' => 'https://example.com/a',
            ]],
            'stripe_session_id' => 'cs_open_card_pkg',
        ]);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'WALLET-PKG-2',
                'publication_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull($payments->getPendingCheckout('CARD-PKG-1'));
        $this->assertSame(1, Order::query()->where('payment_status', 'paid')->count());

        $created = $payments->finalizeStripeFirstCheckout('CARD-PKG-1', (object) [
            'id' => 'cs_open_card_pkg',
            'object' => 'checkout.session',
            'amount_total' => 4000,
            'payment_intent' => 'pi_open_card_pkg',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'CARD-PKG-1',
                'user_id' => (string) $advertiser->id,
                'expected_amount' => '40',
            ],
        ]);

        $this->assertCount(0, $created);
        $this->assertSame(1, Order::query()->where('payment_status', 'paid')->count());
        $wallet->refresh();
        $this->assertEqualsWithDelta(500.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $wallet->reserved_balance, 0.01);
    }
}
