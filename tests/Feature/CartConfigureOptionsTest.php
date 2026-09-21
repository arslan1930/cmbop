<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CartPricingService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartConfigureOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

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
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Configure Cart Site',
            'site_url' => 'https://configure-cart.example',
            'domain' => 'configure-cart.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Site with homepage and sensitive cart options.',
            'verified' => true,
            'active' => 1,
            'homepage_placement_prices' => [
                1 => 0,
                7 => 25,
                30 => 0,
            ],
            'sensitive_prices' => [
                'crypto' => 25,
                'CBD' => 40,
            ],
        ], $overrides));
    }

    public function test_add_to_cart_payload_includes_options_and_counts(): void
    {
        $site = $this->makeSite();

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('site_count', 1)
            ->assertJsonPath('placement_count', 1)
            ->assertJsonPath('cart_count', 1)
            ->json();

        $line = $payload['cart'][0];
        $this->assertSame(30, (int) $line['homepage_days']);
        $this->assertNotEmpty($line['homepage_options']);
        $this->assertTrue(collect($line['homepage_options'])->contains(
            fn ($opt) => (int) $opt['days'] === 30 && ! empty($opt['free'])
        ));
        $this->assertTrue(collect($line['sensitive_options'])->contains(
            fn ($opt) => $opt['type'] === 'CBD' && (float) $opt['price'] === 40.0
        ));
    }

    public function test_configure_can_drop_auto_included_free_homepage(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk();

        $cart = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.configure'), [
                'id' => $site->id,
                'sensitive_type' => '',
                'homepage_days' => 30,
                'new_sensitive_type' => '',
                'new_homepage_days' => 'none',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('cart');

        $expected = app(CartPricingService::class)->priceForAdvertiser($site, null, 1, 'none', false);

        $this->assertCount(1, $cart);
        $this->assertNull($cart[0]['homepage_days']);
        $this->assertEquals(0.0, (float) $cart[0]['homepage_price']);
        $this->assertEquals($expected['total'], (float) $cart[0]['price']);
    }

    public function test_configure_can_add_sensitive_topic_without_leaving_the_cart(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk();

        $cart = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.configure'), [
                'id' => $site->id,
                'sensitive_type' => '',
                'homepage_days' => 'none',
                'new_sensitive_type' => 'CBD',
                'new_homepage_days' => 'none',
            ])
            ->assertOk()
            ->json('cart');

        $expected = app(CartPricingService::class)->priceForAdvertiser($site, 'CBD', 1, 'none', false);

        $this->assertCount(1, $cart);
        $this->assertSame('CBD', $cart[0]['sensitive_type']);
        $this->assertEquals(40.0, (float) $cart[0]['additional_price']);
        $this->assertEquals($expected['total'], (float) $cart[0]['price']);
    }

    public function test_configure_merges_when_options_collide_with_another_line(): void
    {
        $site = $this->makeSite([
            'domain' => 'merge-cart.example',
            'site_url' => 'https://merge-cart.example',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 7,
            ])
            ->assertOk();

        $this->assertCount(2, session('cart'));

        $cart = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.configure'), [
                'id' => $site->id,
                'sensitive_type' => '',
                'homepage_days' => 'none',
                'new_sensitive_type' => '',
                'new_homepage_days' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('site_count', 1)
            ->assertJsonPath('placement_count', 2)
            ->json('cart');

        $this->assertCount(1, $cart);
        $this->assertSame(7, (int) $cart[0]['homepage_days']);
        $this->assertSame(2, (int) $cart[0]['quantity']);
    }

    public function test_configure_rejects_invalid_homepage_without_mutating(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
            ])
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.configure'), [
                'id' => $site->id,
                'sensitive_type' => '',
                'homepage_days' => 'none',
                'new_homepage_days' => 99,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $line = session('cart')[0];
        $this->assertArrayHasKey('homepage_days', $line);
        $this->assertNull($line['homepage_days']);
    }

    public function test_clear_cart_returns_empty_payload(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.clear'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart_count', 0)
            ->assertJsonPath('site_count', 0)
            ->assertJsonPath('placement_count', 0)
            ->assertJsonPath('cart', []);

        $this->assertSame([], session('cart', []));
    }

    public function test_quantity_add_reports_sites_and_placements_separately(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 'none',
                'quantity' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('site_count', 1)
            ->assertJsonPath('placement_count', 3)
            ->assertJsonPath('cart_count', 3);
    }
}
