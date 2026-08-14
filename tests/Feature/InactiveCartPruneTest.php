<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InactiveCartPruneTest extends TestCase
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

    private function makeSite(string $slug, bool $active, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
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
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => $active,
        ], $overrides));
    }

    public function test_cart_get_prunes_inactive_sites_and_reports_them_once(): void
    {
        $active = $this->makeSite('keep-active', true);
        $inactive = $this->makeSite('gone-inactive', false);

        $sessionCart = [
            [
                'id' => $active->id,
                'name' => $active->site_name,
                'price' => 40,
                'quantity' => 1,
            ],
            [
                'id' => $inactive->id,
                'name' => $inactive->site_name,
                'price' => 55,
                'quantity' => 2,
            ],
        ];

        $first = $this->actingAs($this->advertiser)
            ->withSession(['cart' => $sessionCart])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->assertJsonPath('removed_inactive_count', 1)
            ->assertJsonPath('removed_inactive.0', $inactive->site_name)
            ->assertJsonPath('cart_count', 1);

        $cart = $first->json('cart');
        $this->assertCount(1, $cart);
        $this->assertSame($active->id, (int) $cart[0]['id']);
        $this->assertCount(1, session('cart'));
        $this->assertSame($active->id, (int) session('cart')[0]['id']);

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->assertJsonPath('removed_inactive_count', 0)
            ->assertJsonPath('removed_inactive', [])
            ->assertJsonPath('cart_count', 1);
    }

    public function test_cart_count_prunes_inactive_before_badge_total(): void
    {
        $active = $this->makeSite('badge-active', true);
        $inactive = $this->makeSite('badge-inactive', false);

        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $active->id, 'name' => $active->site_name, 'price' => 40, 'quantity' => 1],
                    ['id' => $inactive->id, 'name' => $inactive->site_name, 'price' => 55, 'quantity' => 3],
                ],
            ])
            ->getJson(route('advertiser.cart.count'))
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertCount(1, session('cart'));
        $this->assertSame($active->id, (int) session('cart')[0]['id']);
    }

    public function test_checkout_with_only_inactive_cart_clears_and_redirects(): void
    {
        $inactive = $this->makeSite('checkout-only-inactive', false);

        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $inactive->id,
                    'name' => $inactive->site_name,
                    'price' => 55,
                    'quantity' => 1,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertRedirect(route('advertiser.catalog'))
            ->assertSessionHas('error');

        $this->assertEmpty(session('cart', []));
    }

    public function test_cart_get_prunes_unverified_and_archived_sites(): void
    {
        $live = $this->makeSite('keep-live', true);
        $unverified = $this->makeSite('still-active-unverified', true, ['verified' => false]);
        $archived = $this->makeSite('archived-listing', false, ['archived_at' => now()]);

        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $live->id, 'name' => $live->site_name, 'price' => 40, 'quantity' => 1],
                    ['id' => $unverified->id, 'name' => $unverified->site_name, 'price' => 55, 'quantity' => 1],
                    ['id' => $archived->id, 'name' => $archived->site_name, 'price' => 60, 'quantity' => 1],
                ],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->assertJsonPath('removed_inactive_count', 2)
            ->assertJsonPath('cart_count', 1);

        $this->assertCount(1, session('cart'));
        $this->assertSame($live->id, (int) session('cart')[0]['id']);
    }

    public function test_advertiser_layout_toasts_removed_inactive_payload(): void
    {
        $html = file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('removed_inactive', $html);
        $this->assertStringContainsString('was deactivated and removed from your cart.', $html);
        $this->assertStringContainsString('showToast', $html);
    }
}
