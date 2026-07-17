<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutSystemFixTest extends TestCase
{
    use RefreshDatabase;
    use CreatesContentSubmissions;

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
            'site_name' => 'Site ' . $slug,
            'site_url' => 'https://' . $slug . '.example',
            'domain' => $slug . '.example',
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
        config(['services.stripe.secret' => 'sk_test_fake_key_for_unit_tests']);

        $body = json_encode([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/' . $sessionId,
            'payment_status' => 'unpaid',
            'mode' => 'payment',
            'metadata' => [],
        ], JSON_THROW_ON_ERROR);

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->andReturn([$body, 200, []]);
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

        $this->assertNotNull($sub->fresh()->order_id);
        $this->assertFalse($sub->fresh()->canBeOrdered());

        $page = $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'CAN1']));

        $page->assertOk();
        $this->assertNull($sub->fresh()->order_id);
        $this->assertTrue($sub->fresh()->canBeOrdered());
        $this->assertSame('cancelled', Order::where('reference_code', 'CAN1')->first()->status);
        $this->assertNotEmpty(session('cart'));
        $this->assertSame($sub->id, session('checkout_content_submission_id'));
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
        $response->assertSee('Approved article', false);
        $response->assertSee($sub->title ?: $sub->original_filename, false);
    }

    public function test_library_rejects_scheduled_order_without_date(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'sched-bad', 40);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $response = $this->actingAs($advertiser)->from(route('advertiser.content-library'))
            ->post(route('advertiser.content-library.order'), [
                'content_submission_id' => $sub->id,
                'site_ids' => [$site->id],
                'anchor_text' => 'valid anchor text',
                'target_url' => 'https://example.com/page',
                'publication_mode' => 'scheduled',
            ]);

        $response->assertRedirect(route('advertiser.content-library'));
        $response->assertSessionHas('error');
        $this->assertNull(session('cart'));
    }

    public function test_null_safe_resolve_when_content_map_missing_second_site(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->activeSite($publisher, 'a', 40);
        $siteB = $this->activeSite($publisher, 'b', 50);
        $sub = $this->createApprovedSubmission($advertiser, null);

        // Cart has content_submission_id; request map is incomplete — should still succeed via cart/session.
        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $siteA->id, 'name' => $siteA->site_name, 'quantity' => 1, 'content_submission_id' => $sub->id],
                    ['id' => $siteB->id, 'name' => $siteB->site_name, 'quantity' => 1, 'content_submission_id' => $sub->id],
                ],
                'checkout_content_submission_id' => $sub->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'SAFE1',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $siteA->id => [$sub->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, OrderItem::where('content_submission_id', $sub->id)->count());
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
}
