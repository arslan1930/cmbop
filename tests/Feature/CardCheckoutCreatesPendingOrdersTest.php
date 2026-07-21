<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CardCheckoutCreatesPendingOrdersTest extends TestCase
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
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function activeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Card Test Site',
            'site_url' => 'https://card-test.example',
            'domain' => 'card-test.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100.00,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site for card checkout',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function fakeStripeCheckoutSession(string $sessionId = 'cs_test_pending_orders'): void
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
        // Saved-card flow creates/retrieves a Customer, then creates the Checkout Session.
        $client->shouldReceive('request')
            ->twice()
            ->andReturn(
                [$customerBody, 200, []],
                [$sessionBody, 200, []]
            );

        ApiRequestor::setHttpClient($client);
    }

    public function test_card_checkout_creates_pending_orders_before_stripe_redirect(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $this->fakeStripeCheckoutSession('cs_test_card_fix_2');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'price' => 9999,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'CARD42',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'requires_payment' => true,
                'reference_code' => 'CARD42',
                'session_id' => 'cs_test_card_fix_2',
            ])
            ->assertJsonStructure(['checkout_url']);

        $order = Order::where('reference_code', 'CARD42')->first();
        $this->assertNotNull($order);
        $this->assertSame('card', $order->payment_method);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame('cs_test_card_fix_2', $order->stripe_session_id);
        $this->assertSame($submission->id, $order->items()->first()->content_submission_id);
        // Cart is kept until Stripe payment succeeds so cancel can return to checkout.
        $this->assertFalse(session()->missing('cart'));
        $this->assertSame('CARD42', session('pending_card_reference'));
    }

    public function test_card_checkout_rolls_back_pending_orders_when_stripe_fails(): void
    {
        config(['content_moderation.enabled' => false]);
        config(['services.stripe.secret' => 'sk_test_fake_key_for_unit_tests']);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $client = Mockery::mock(ClientInterface::class);
        $customerBody = json_encode([
            'id' => 'cus_test_fail',
            'object' => 'customer',
            'email' => 'test@example.com',
        ], JSON_THROW_ON_ERROR);
        // Customer create succeeds; Checkout Session create fails (rollback path).
        $client->shouldReceive('request')
            ->once()
            ->andReturn([$customerBody, 200, []]);
        $client->shouldReceive('request')
            ->once()
            ->andThrow(new \Exception('stripe unavailable'));
        ApiRequestor::setHttpClient($client);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'FAIL99',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('reference_code', 'FAIL99')->count());
    }

    public function test_card_checkout_rejects_when_stripe_not_configured(): void
    {
        config(['content_moderation.enabled' => false]);
        config([
            'services.stripe.secret' => '',
            'services.stripe.key' => '',
        ]);

        $advertiser = $this->advertiser();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);
        $site = $this->activeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'sensitive_type' => null,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'reference_code' => 'NOCFG1',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Order::where('reference_code', 'NOCFG1')->count());
    }
}
