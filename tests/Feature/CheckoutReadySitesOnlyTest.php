<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutReadySitesOnlyTest extends TestCase
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
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug, float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ready Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function advertiserWallet(User $advertiser, float $balance): Wallet
    {
        $roleId = Role::firstOrCreate(['name' => 'advertiser'])->id;

        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $roleId,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
        ]);
    }

    public function test_checkout_page_charges_only_ready_sites_and_lists_deferred(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $readySite = $this->activeSite($publisher, 'ready', 40);
        $pendingSite = $this->activeSite($publisher, 'pending', 55);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    [
                        'id' => $readySite->id,
                        'name' => $readySite->site_name,
                        'quantity' => 1,
                        'content_submission_id' => $sub->id,
                        'language' => 'en',
                    ],
                    [
                        'id' => $pendingSite->id,
                        'name' => $pendingSite->site_name,
                        'quantity' => 1,
                        'content_submission_id' => null,
                        'language' => 'en',
                    ],
                ],
            ])
            ->get(route('advertiser.checkout'));

        $response->assertOk();
        $response->assertSee('Paying 1 ready site', false);
        $response->assertSee('will stay in your cart', false);
        $response->assertSee('Ready sites only', false);
        $response->assertSee('Paying now', false);
        $response->assertSee('Stays in cart', false);
        $response->assertSee('Charged now', false);
        $response->assertSee('Not charged yet', false);
        // Payable total excludes the not-ready site (40 + tier fee, not 40+55).
        $response->assertDontSee('€'.$this->formatMoney(55 + 40), false);
    }

    public function test_wallet_payment_only_charges_ready_sites_and_keeps_deferred_in_cart(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $readySite = $this->activeSite($publisher, 'pay-ready', 40);
        $pendingSite = $this->activeSite($publisher, 'pay-later', 80);
        $sub = $this->createApprovedSubmission($advertiser, null);
        $this->advertiserWallet($advertiser, 500);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    [
                        'id' => $readySite->id,
                        'name' => $readySite->site_name,
                        'quantity' => 1,
                        'content_submission_id' => $sub->id,
                        'language' => 'en',
                    ],
                    [
                        'id' => $pendingSite->id,
                        'name' => $pendingSite->site_name,
                        'quantity' => 1,
                        'language' => 'en',
                    ],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'READY1',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $readySite->id => [$sub->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, Order::where('reference_code', 'READY1')->count());
        $this->assertSame(1, Order::where('reference_code', 'READY1')->where('payment_status', 'paid')->count());

        $cart = session('cart', []);
        $this->assertCount(1, $cart);
        $this->assertSame($pendingSite->id, (int) ($cart[0]['id'] ?? 0));
        $this->assertTrue(session()->missing('checkout_deferred_cart'));
    }

    public function test_process_order_rejects_when_no_sites_are_ready(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'not-ready', 40);
        $this->advertiserWallet($advertiser, 500);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'NONE01',
                'publication_mode' => 'immediate',
            ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
        ]);
        $this->assertSame(0, Order::count());
    }

    public function test_card_checkout_only_packages_ready_sites(): void
    {
        config(['content_moderation.enabled' => false]);
        config([
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $readySite = $this->activeSite($publisher, 'card-ready', 40);
        $pendingSite = $this->activeSite($publisher, 'card-later', 90);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $customerBody = json_encode([
            'id' => 'cus_test_ready',
            'object' => 'customer',
            'email' => 'test@example.com',
        ], JSON_THROW_ON_ERROR);
        $sessionBody = json_encode([
            'id' => 'cs_test_ready_only',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_ready_only',
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

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    [
                        'id' => $readySite->id,
                        'name' => $readySite->site_name,
                        'quantity' => 1,
                        'content_submission_id' => $sub->id,
                        'language' => 'en',
                    ],
                    [
                        'id' => $pendingSite->id,
                        'name' => $pendingSite->site_name,
                        'quantity' => 1,
                        'language' => 'en',
                    ],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'CARDRD',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $readySite->id => [$sub->id],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'requires_payment' => true,
                'reference_code' => 'CARDRD',
            ]);

        $package = Cache::get('pending_card_checkout:CARDRD');
        $this->assertIsArray($package);
        $this->assertCount(1, $package['lines'] ?? []);
        $this->assertSame($readySite->id, (int) ($package['lines'][0]['site_id'] ?? 0));
        $this->assertSame(0, Order::where('reference_code', 'CARDRD')->count());
        $this->assertNotEmpty(session('checkout_deferred_cart'));
        $this->assertSame($pendingSite->id, (int) (session('checkout_deferred_cart')[0]['id'] ?? 0));
    }

    public function test_card_checkout_rejects_zero_amount_without_opening_stripe(): void
    {
        config(['content_moderation.enabled' => false]);
        config([
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $freeSite = $this->activeSite($publisher, 'free-site', 0);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->never();
        ApiRequestor::setHttpClient($client);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $freeSite->id,
                    'name' => $freeSite->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'ZERO01',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $freeSite->id => [$sub->id],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
        $this->assertStringContainsString('greater than €0', (string) $response->json('message'));
        $this->assertNull(Cache::get('pending_card_checkout:ZERO01'));
        $this->assertSame(0, Order::where('reference_code', 'ZERO01')->count());
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2);
    }
}
