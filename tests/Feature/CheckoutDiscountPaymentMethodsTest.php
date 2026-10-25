<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartPricingService;
use App\Services\OrderPaymentService;
use App\Services\PaypalCheckoutService;
use App\Services\StripeCustomerService;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutDiscountPaymentMethodsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'content_moderation.enabled' => false,
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => $advertiserRole->id,
            'balance' => 2000,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  list<string>  $sessionIds
     */
    private function fakeStripeCheckoutSessions(array $sessionIds): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $returns = [];
        foreach ($sessionIds as $sessionId) {
            $returns[] = [json_encode([
                'id' => 'cus_test_'.substr($sessionId, -8),
                'object' => 'customer',
                'email' => 'test@example.com',
                'livemode' => false,
            ], JSON_THROW_ON_ERROR), 200, []];
            $returns[] = [json_encode([
                'id' => $sessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/c/pay/'.$sessionId,
                'payment_status' => 'unpaid',
                'mode' => 'payment',
                'metadata' => [],
            ], JSON_THROW_ON_ERROR), 200, []];
        }

        $client->shouldReceive('request')
            ->times(count($returns))
            ->andReturn(...$returns);

        ApiRequestor::setHttpClient($client);
    }

    private function makeSite(string $slug, float $price, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Pay '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 8000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Payment discount listing.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    private function saleSite(string $slug = 'sale10'): Site
    {
        return $this->makeSite($slug, 100, [
            'custom_discount_percent' => 10,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);
    }

    private function bulkSite(string $slug = 'bulk10'): Site
    {
        return $this->makeSite($slug, 100, [
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 10,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cartFor(Site $site, int $quantity = 1, array $submissionIds = []): array
    {
        $line = [
            'id' => $site->id,
            'name' => $site->site_name,
            'quantity' => $quantity,
            'price' => 9999,
        ];
        if ($submissionIds !== []) {
            $line['content_submission_id'] = $submissionIds[0];
            $line['content_submission_ids'] = $submissionIds;
        }
        if ($quantity >= 3) {
            $line['bulk_pack'] = true;
        }

        return [$line];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return array<string, mixed>
     */
    private function checkoutPayload(string $method, string $ref, Site $site, array $submissionIds, array $extra = []): array
    {
        return array_merge([
            'payment_method' => $method,
            'reference_code' => $ref,
            'publication_mode' => 'immediate',
            'content_submissions' => [
                $site->id => $submissionIds,
            ],
        ], $extra);
    }

    public function test_advertiser_catalog_and_publisher_mysites_show_sale_and_bulk(): void
    {
        $sale = $this->saleSite('panel-sale');
        $bulk = $this->bulkSite('panel-bulk');
        $salePricing = app(CartPricingService::class)->priceForAdvertiser($sale);
        $this->assertSame(113.0, $salePricing['list_total']);
        $this->assertSame(101.7, $salePricing['total']);

        $advertiserHtml = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('base-price-display">€101.70', $advertiserHtml);
        $this->assertStringContainsString('€113.00', $advertiserHtml);
        $this->assertStringContainsString('10% off', $advertiserHtml);

        $publisherHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('€100.00', $publisherHtml);
        $this->assertStringContainsString('€90.00', $publisherHtml);
        $this->assertStringContainsString('−10%', $publisherHtml);
        $this->assertStringContainsString('Off your list (€100.00 → €90.00)', $publisherHtml);
        $this->assertStringContainsString((string) $bulk->site_name, $publisherHtml);
        $this->assertStringContainsString('3–4 / 5–9 / 10+', $publisherHtml);
    }

    public function test_card_checkout_charges_sale_price_not_list(): void
    {
        $site = $this->saleSite('card-sale');
        $sub = $this->createApprovedSubmission($this->advertiser, $site->id);
        $this->fakeStripeCheckoutSessions(['cs_test_sale10']);

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 1, [$sub->id])])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('card', 'CARDSALE', $site, [$sub->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('amount_due', 101.7);

        $this->assertSame(10170, StripePaymentService::toCents(101.7));

        $package = app(OrderPaymentService::class)->getPendingCheckout('CARDSALE');
        $this->assertNotNull($package);
        $this->assertEquals(101.7, (float) $package['amount_due']);
        $this->assertEquals(101.7, (float) $package['order_total']);
        $this->assertEquals(101.7, (float) ($package['lines'][0]['price'] ?? 0));
        $this->assertSame(0, Order::where('reference_code', 'CARDSALE')->count());
    }

    public function test_saved_card_charges_sale_cents(): void
    {
        $site = $this->saleSite('saved-sale');
        $sub = $this->createApprovedSubmission($this->advertiser, $site->id);

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('payWithSavedCard')
                ->once()
                ->andReturnUsing(function ($user, $paymentMethodId, $amountCents) {
                    $this->assertSame('pm_test_visa', $paymentMethodId);
                    $this->assertSame(10170, $amountCents);

                    return [
                        'status' => 'succeeded',
                        'payment_intent_id' => 'pi_sale_ok',
                        'client_secret' => 'pi_sale_ok_secret',
                        'amount_received' => $amountCents,
                    ];
                });
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 1, [$sub->id])])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('card', 'SAVEDSALE', $site, [$sub->id], [
                'payment_method_id' => 'pm_test_visa',
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $item = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('reference_code', 'SAVEDSALE'))
            ->firstOrFail();
        $this->assertEquals(101.7, (float) $item->price);
        $this->assertEquals(100.0, (float) $item->publisher_price);
        $this->assertEquals(1.7, (float) $item->platform_fee_amount);
        $this->assertEquals(101.7, (float) Order::where('reference_code', 'SAVEDSALE')->value('total_amount'));
    }

    public function test_paypal_checkout_charges_sale_price_not_list(): void
    {
        $site = $this->saleSite('pp-sale');
        $sub = $this->createApprovedSubmission($this->advertiser, $site->id);

        $this->mock(PaypalCheckoutService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('browserCallbackUrl')->andReturn('https://example.test/paypal');
            $mock->shouldReceive('createOrder')
                ->once()
                ->withArgs(function ($amountDue) {
                    $this->assertEquals(101.7, $amountDue);

                    return true;
                })
                ->andReturn([
                    'id' => 'PO-SALE10',
                    'approve_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=PO-SALE10',
                ]);
        });

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 1, [$sub->id])])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('paypal', 'PPSALE', $site, [$sub->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('amount_due', 101.7)
            ->assertJsonPath('paypal_order_id', 'PO-SALE10');

        $package = app(OrderPaymentService::class)->getPendingCheckout('PPSALE');
        $this->assertNotNull($package);
        $this->assertEquals(101.7, (float) $package['amount_due']);
        $this->assertEquals(101.7, (float) ($package['lines'][0]['price'] ?? 0));
        $this->assertSame(0, Order::where('reference_code', 'PPSALE')->count());
    }

    public function test_bank_wise_and_crypto_suggest_sale_total_then_wallet(): void
    {
        $site = $this->saleSite('manual-sale');
        $sub = $this->createApprovedSubmission($this->advertiser, $site->id);

        foreach (['bank', 'wise', 'crypto'] as $method) {
            $this->actingAs($this->advertiser)
                ->withSession(['cart' => $this->cartFor($site, 1, [$sub->id])])
                ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload($method, 'MAN'.strtoupper($method), $site, [$sub->id]))
                ->assertStatus(422)
                ->assertJsonPath('code', 'fund_wallet_first')
                ->assertJsonPath('suggested_amount', 101.7);

            $this->assertSame(0, Order::where('reference_code', 'MAN'.strtoupper($method))->count());
        }
    }

    public function test_card_and_paypal_charge_bulk_pack_unit(): void
    {
        $site = $this->bulkSite('pay-bulk');
        $slots = [
            $this->createApprovedSubmission($this->advertiser, $site->id, 0, 'bulk one', 'https://example.com/pb1'),
            $this->createApprovedSubmission($this->advertiser, $site->id, 1, 'bulk two', 'https://example.com/pb2'),
            $this->createApprovedSubmission($this->advertiser, $site->id, 2, 'bulk three', 'https://example.com/pb3'),
        ];
        $ids = array_map(fn ($s) => $s->id, $slots);
        $expected = app(CartPricingService::class)->priceForAdvertiser($site, null, 3);
        $this->assertSame(101.7, $expected['total']);
        $packTotal = 305.1;

        $this->fakeStripeCheckoutSessions(['cs_test_bulk']);

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 3, $ids)])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('card', 'CARDBULK', $site, $ids))
            ->assertOk()
            ->assertJsonPath('amount_due', $packTotal);

        $cardPackage = app(OrderPaymentService::class)->getPendingCheckout('CARDBULK');
        $this->assertNotNull($cardPackage);
        $this->assertCount(3, $cardPackage['lines'] ?? []);
        foreach ($cardPackage['lines'] as $line) {
            $this->assertEquals(101.7, (float) ($line['price'] ?? 0));
        }

        $this->mock(PaypalCheckoutService::class, function ($mock) use ($packTotal) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('browserCallbackUrl')->andReturn('https://example.test/paypal');
            $mock->shouldReceive('createOrder')
                ->once()
                ->withArgs(fn ($amountDue) => (float) $amountDue === $packTotal)
                ->andReturn([
                    'id' => 'PO-BULK10',
                    'approve_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=PO-BULK10',
                ]);
        });

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 3, $ids)])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('paypal', 'PPBULK', $site, $ids))
            ->assertOk()
            ->assertJsonPath('amount_due', $packTotal);
    }

    public function test_every_rail_charges_full_list_when_there_is_no_discount(): void
    {
        $site = $this->makeSite('plain', 100);
        $expected = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(113.0, $expected['total']);

        $walletSub = $this->createApprovedSubmission($this->advertiser, $site->id, 0, 'plain wallet', 'https://example.com/plain-w');
        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 1, [$walletSub->id])])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('wallet', 'WALPLAIN', $site, [$walletSub->id]))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertEquals(113.0, (float) Order::where('reference_code', 'WALPLAIN')->value('total_amount'));

        $cardSub = $this->createApprovedSubmission($this->advertiser, $site->id, 1, 'plain card', 'https://example.com/plain-c');
        $this->fakeStripeCheckoutSessions(['cs_test_plain']);
        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 1, [$cardSub->id])])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('card', 'CARDPLAIN', $site, [$cardSub->id]))
            ->assertOk()
            ->assertJsonPath('amount_due', 113);

        $paypalSub = $this->createApprovedSubmission($this->advertiser, $site->id, 2, 'plain paypal', 'https://example.com/plain-p');
        $this->mock(PaypalCheckoutService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('browserCallbackUrl')->andReturn('https://example.test/paypal');
            $mock->shouldReceive('createOrder')
                ->once()
                ->withArgs(fn ($amountDue) => (float) $amountDue === 113.0)
                ->andReturn([
                    'id' => 'PO-PLAIN',
                    'approve_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=PO-PLAIN',
                ]);
        });
        $this->actingAs($this->advertiser)
            ->withSession(['cart' => $this->cartFor($site, 1, [$paypalSub->id])])
            ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload('paypal', 'PPPLAIN', $site, [$paypalSub->id]))
            ->assertOk()
            ->assertJsonPath('amount_due', 113);

        $manualSub = $this->createApprovedSubmission($this->advertiser, $site->id, 3, 'plain manual', 'https://example.com/plain-m');
        foreach (['bank', 'wise', 'crypto'] as $method) {
            $this->actingAs($this->advertiser)
                ->withSession(['cart' => $this->cartFor($site, 1, [$manualSub->id])])
                ->postJson(route('advertiser.checkout.process'), $this->checkoutPayload($method, 'PLN'.strtoupper($method), $site, [$manualSub->id]))
                ->assertStatus(422)
                ->assertJsonPath('suggested_amount', 113);
        }
    }
}
