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

    public function test_cart_get_keeps_unverified_live_sites_and_prunes_archived(): void
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
            ->assertJsonPath('removed_inactive_count', 1)
            ->assertJsonPath('cart_count', 2);

        $ids = collect(session('cart'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$live->id, $unverified->id], $ids);
    }

    public function test_catalog_page_prunes_hidden_sites_from_banner(): void
    {
        $live = $this->makeSite('catalog-keep', true);
        $inactive = $this->makeSite('catalog-inactive', false);

        $html = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $live->id, 'name' => $live->site_name, 'price' => 40, 'quantity' => 2],
                    ['id' => $inactive->id, 'name' => $inactive->site_name, 'price' => 55, 'quantity' => 2],
                ],
            ])
            ->get(route('advertiser.catalog'))
            ->assertOk();

        $html->assertSee('You have <strong>1 site · 2 placements</strong>', false);
        $html->assertSee($inactive->site_name, false);
        $html->assertSee('no longer available and was removed from your cart', false);

        $this->assertCount(1, session('cart'));
        $this->assertSame($live->id, (int) session('cart')[0]['id']);
    }

    public function test_advertiser_header_prunes_hidden_sites_outside_catalog(): void
    {
        $live = $this->makeSite('dash-keep', true);
        $unverified = $this->makeSite('dash-inactive', false);

        $html = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $live->id, 'name' => $live->site_name, 'price' => 40, 'quantity' => 1],
                    ['id' => $unverified->id, 'name' => $unverified->site_name, 'price' => 55, 'quantity' => 2],
                ],
            ])
            ->get(route('advertiser.dashboard'))
            ->assertOk();

        $this->assertCount(1, session('cart'));
        $this->assertSame($live->id, (int) session('cart')[0]['id']);
        $this->assertMatchesRegularExpression('/id="cartBadge"[^>]*>1</', $html->getContent());
        $html->assertSee('was deactivated and removed from your cart.', false);
        $html->assertSee($unverified->site_name, false);
    }

    public function test_advertiser_layout_toasts_removed_inactive_payload(): void
    {
        $html = file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('removed_inactive', $html);
        $this->assertStringContainsString('was deactivated and removed from your cart.', $html);
        $this->assertStringContainsString('showToast', $html);
    }

    public function test_add_to_cart_404_prunes_hidden_siblings(): void
    {
        $live = $this->makeSite('add-keep', true);
        $unverified = $this->makeSite('add-inactive', false);

        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $live->id, 'name' => $live->site_name, 'price' => 40, 'quantity' => 1],
                    ['id' => $unverified->id, 'name' => $unverified->site_name, 'price' => 55, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.cart.add'), ['id' => $unverified->id])
            ->assertNotFound()
            ->assertJsonPath('error', 'Site not found or inactive.');

        $this->assertCount(1, session('cart'));
        $this->assertSame($live->id, (int) session('cart')[0]['id']);
    }

    public function test_save_cart_drops_hidden_and_invalid_ids(): void
    {
        $live = $this->makeSite('save-keep', true);
        $unverified = $this->makeSite('save-inactive', false);

        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $unverified->id, 'name' => $unverified->site_name, 'price' => 55, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.cart.save'), [
                'cart' => [
                    ['id' => $live->id, 'name' => $live->site_name, 'price' => 40, 'quantity' => 1],
                    ['id' => $unverified->id, 'name' => $unverified->site_name, 'price' => 55, 'quantity' => 1],
                    ['id' => 0, 'name' => 'Broken line', 'price' => 10, 'quantity' => 1],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart_count', 1);

        $this->assertCount(1, session('cart'));
        $this->assertSame($live->id, (int) session('cart')[0]['id']);
    }
}
