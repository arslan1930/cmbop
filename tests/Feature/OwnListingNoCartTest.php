<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnListingNoCartTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name]);
    }

    /**
     * Dual-role user currently shopping as advertiser.
     */
    private function dualRoleOwner(): User
    {
        $advertiserRole = $this->role('advertiser');
        $publisherRole = $this->role('publisher');

        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $owner->roles()->attach([$advertiserRole->id, $publisherRole->id]);

        return $owner->fresh();
    }

    private function advertiser(): User
    {
        $role = $this->role('advertiser');
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, float $price, array $overrides = []): Site
    {
        $slug = 'own-'.substr(md5($publisher->id.'-'.$price.'-'.json_encode($overrides)), 0, 8);

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Own Listing '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'price' => $price,
            'active' => true,
            'verified' => true,
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'link_type' => 'dofollow',
            'category' => 'Technology',
            'publication_time' => '3',
            'description' => 'Own listing test site',
        ], $overrides));
    }

    public function test_owner_sees_entered_forty_euro_price_without_add_to_cart(): void
    {
        $owner = $this->dualRoleOwner();
        $site = $this->siteFor($owner, 40);

        $html = $this->actingAs($owner)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('base-price-display">€40.00', $html);
        $this->assertStringNotContainsString('base-price-display">€46.00', $html);
        $this->assertStringContainsString('Your listing', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/buy-now[^>]*data-id="'.$site->id.'"/',
            $html
        );
    }

    public function test_other_advertiser_still_sees_fee_inclusive_price_and_can_add(): void
    {
        $owner = $this->dualRoleOwner();
        $shopper = $this->advertiser();
        $site = $this->siteFor($owner, 40);

        $html = $this->actingAs($shopper)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-base-price="46"', $html);
        $this->assertStringContainsString('base-price-display">€46.00', $html);
        $this->assertStringNotContainsString('Your listing', $html);

        $payload = $this->actingAs($shopper)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->json();

        $this->assertEquals(46.0, (float) $payload['cart'][0]['price']);
    }

    public function test_cart_get_prunes_own_listings(): void
    {
        $owner = $this->dualRoleOwner();
        $otherPublisher = User::factory()->create();
        $own = $this->siteFor($owner, 40);
        $other = $this->siteFor($otherPublisher, 40, [
            'site_name' => 'Someone Else Site',
            'site_url' => 'https://someone-else.example',
            'domain' => 'someone-else.example',
        ]);

        $first = $this->actingAs($owner)
            ->withSession([
                'cart' => [
                    ['id' => $own->id, 'name' => $own->site_name, 'price' => 40, 'quantity' => 1],
                    ['id' => $other->id, 'name' => $other->site_name, 'price' => 46, 'quantity' => 1],
                ],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->assertJsonPath('removed_owned_count', 1)
            ->assertJsonPath('removed_owned.0', $own->site_name)
            ->assertJsonPath('cart_count', 1);

        $cart = $first->json('cart');
        $this->assertCount(1, $cart);
        $this->assertSame($other->id, (int) $cart[0]['id']);
        $this->assertCount(1, session('cart'));
    }

    public function test_checkout_with_only_own_listing_clears_and_redirects(): void
    {
        $owner = $this->dualRoleOwner();
        $own = $this->siteFor($owner, 90);

        $this->actingAs($owner)
            ->withSession([
                'cart' => [[
                    'id' => $own->id,
                    'name' => $own->site_name,
                    'price' => 90,
                    'quantity' => 1,
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertRedirect(route('advertiser.catalog'))
            ->assertSessionHas('error');

        $this->assertEmpty(session('cart', []));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_bulk_rail_omits_own_listings(): void
    {
        $owner = $this->dualRoleOwner();
        $otherPublisher = User::factory()->create();
        $own = $this->siteFor($owner, 100, [
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
            'dr' => 80,
        ]);
        $other = $this->siteFor($otherPublisher, 100, [
            'site_name' => 'Other Bulk Site',
            'site_url' => 'https://other-bulk.example',
            'domain' => 'other-bulk.example',
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
            'dr' => 70,
        ]);

        $ownerHtml = $this->actingAs($owner)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/bulk-deal-card__cta[^>]*data-id="'.$own->id.'"/',
            $ownerHtml
        );
        $this->assertMatchesRegularExpression(
            '/bulk-deal-card__cta[^>]*data-id="'.$other->id.'"/',
            $ownerHtml
        );
        $this->assertStringContainsString('Other Bulk Site', $ownerHtml);
        $this->assertStringContainsString('Add 3 to cart', $ownerHtml);

        $shopper = $this->advertiser();
        $shopperHtml = $this->actingAs($shopper)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString((string) $own->site_name, $shopperHtml);
        $this->assertStringContainsString('Add 3 to cart', $shopperHtml);
        $this->assertStringContainsString('data-id="'.$own->id.'"', $shopperHtml);
    }

    public function test_saved_sites_shows_your_listing_instead_of_order(): void
    {
        $owner = $this->dualRoleOwner();
        $site = $this->siteFor($owner, 40);
        UserFavorite::create(['user_id' => $owner->id, 'site_id' => $site->id]);

        $html = $this->actingAs($owner)
            ->get(route('advertiser.saved-sites', ['tab' => 'favorites']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Your listing · €40.00', $html);
        $this->assertStringNotContainsString('Order · €46.00', $html);
        $this->assertStringNotContainsString('Order · €40.00', $html);
        $this->assertStringContainsString(route('publisher.websites'), $html);
    }

    public function test_dashboard_recommended_excludes_own_listings(): void
    {
        $owner = $this->dualRoleOwner();
        $otherPublisher = User::factory()->create();
        $own = $this->siteFor($owner, 40, [
            'dr' => 99,
            'traffic' => 999999,
            'site_name' => 'My Super Site',
            'site_url' => 'https://my-super.example',
            'domain' => 'my-super.example',
        ]);
        $other = $this->siteFor($otherPublisher, 40, [
            'dr' => 50,
            'traffic' => 5000,
            'site_name' => 'Recommended Other',
            'site_url' => 'https://recommended-other.example',
            'domain' => 'recommended-other.example',
        ]);

        $html = $this->actingAs($owner)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('my-super.example', $html);
        $this->assertStringContainsString(route('advertiser.catalog', ['site' => $other->id]), $html);
        $this->assertSame(0, substr_count($html, route('advertiser.catalog', ['site' => $own->id])));
    }

    public function test_layout_toasts_removed_owned_payload(): void
    {
        $html = file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('removed_owned', $html);
        $this->assertStringContainsString('is your listing and was removed from your cart.', $html);
    }
}
