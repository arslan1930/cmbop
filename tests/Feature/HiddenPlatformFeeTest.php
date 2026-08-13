<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CartPricingService;
use App\Services\PlatformFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HiddenPlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = $this->role($roleName);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, float $price = 100): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Fee Test Site',
            'site_url' => 'https://fee-test.example',
            'domain' => 'fee-test.example',
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
            'description' => 'Fee test site',
        ]);
    }

    public function test_publisher_websites_show_entered_base_price_not_marked_up(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, 100);

        $this->assertSame(100.0, (float) $site->price);
        $this->assertSame(113.0, app(PlatformFeeService::class)->advertiserBase((float) $site->price));

        // Publisher listing price stays at entered base; advertiser fee is never applied here.
        $this->assertNotSame(
            (float) $site->price,
            app(PlatformFeeService::class)->advertiserBase((float) $site->price)
        );

        $this->actingAs($publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertDontSee('platform fee', false)
            ->assertDontSee('commission', false);

        // Websites table is AJAX-loaded HTML; publisher must see entered base, not fee-inflated price.
        $this->actingAs($publisher)
            ->get(route('publisher.sites.ajax'))
            ->assertOk()
            ->assertSee('€100.00', false)
            ->assertDontSee('€113.00', false);
    }

    public function test_advertiser_catalog_applies_tiered_price_without_fee_copy(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($publisher, 100);

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(113.0, $pricing['total']);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('platform fee', false)
            ->assertDontSee('commission', false);
    }

    public function test_owner_sees_entered_price_and_cannot_add_own_listing_to_cart(): void
    {
        $advertiserRole = $this->role('advertiser');
        $publisherRole = $this->role('publisher');

        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $owner->roles()->attach([$advertiserRole->id, $publisherRole->id]);

        $site = $this->siteFor($owner, 90);

        $this->assertTrue($site->isOwnedBy($owner));
        $this->assertSame(90.0, $site->catalogPricesForViewer($owner)['list']);

        $html = $this->actingAs($owner)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('base-price-display">€90.00', $html);
        $this->assertStringNotContainsString('base-price-display">€103.50', $html);
        $this->assertStringContainsString('Your listing', $html);
        $this->assertStringContainsString(Site::cannotOrderOwnListingMessage(), $html);
        $this->assertStringNotContainsString('buy-now', $html);
        $this->assertStringNotContainsString('platform fee', $html);
        $this->assertStringNotContainsString('commission', $html);

        $this->actingAs($owner)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', Site::cannotOrderOwnListingMessage());

        $this->assertEmpty(session('cart', []));
    }

    public function test_advertiser_catalog_shows_marked_up_price_for_ninety_euro_listing(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($publisher, 90);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-base-price="103.5"', $html);
        $this->assertStringContainsString('base-price-display">€103.50', $html);
        $this->assertStringNotContainsString('base-price-display">€90.00', $html);

        $payload = $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->json();

        $this->assertEquals(103.5, (float) $payload['cart'][0]['price']);
    }

    public function test_advertiser_catalog_shows_fee_inclusive_price_for_forty_euro_listing(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($publisher, 40);

        $expected = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(46.0, $expected['total']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-base-price="46"', $html);
        $this->assertStringContainsString('base-price-display">€46.00', $html);
        $this->assertStringNotContainsString('base-price-display">€40.00', $html);
        $this->assertStringContainsString('data-publisher-price="40"', $html);

        $payload = $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->json();

        $this->assertEquals(46.0, (float) $payload['cart'][0]['price']);
        $this->assertEquals(46.0, (float) $payload['cart_total']);
    }

    public function test_catalog_row_stays_fee_inclusive_if_in_memory_price_was_not_marked_up(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, 40);

        $this->assertSame(40.0, (float) $site->price);
        $pricing = $site->advertiserCatalogPricing();
        $this->assertSame(46.0, $pricing['base']);
        $this->assertSame(40.0, $pricing['publisher_price']);
        $this->assertSame(46.0, $pricing['total']);

        $site->price = 40;
        $this->assertSame(46.0, $site->advertiserCatalogPricing()['base']);
    }

    public function test_sale_floor_at_publisher_payout_matches_cart(): void
    {
        // Distinct from the owner-fee bug: a deep sale can floor the *pay* price
        // at the publisher base (€90) while the struck list stays €103.50. Cart
        // must charge that same floored €90 — not jump back to full list.
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($publisher, 90);
        $site->forceFill([
            'custom_discount_percent' => 25,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ])->save();

        $expected = app(CartPricingService::class)->priceForAdvertiser($site->fresh());
        $this->assertSame(103.5, $expected['base']);
        $this->assertSame(90.0, $expected['total']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('base-price-display">€90.00', $html);
        $this->assertStringContainsString('>€103.50<', $html);
        $this->assertStringContainsString('data-base-price="103.5"', $html);
        $this->assertStringContainsString('data-publisher-price="90"', $html);

        $payload = $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->json();

        $this->assertEquals(90.0, (float) $payload['cart'][0]['price']);
    }

    public function test_checkout_snapshots_publisher_price_and_payout_uses_it(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, 100);

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertSame(100.0, $pricing['publisher_price']);
        $this->assertSame(13.0, $pricing['platform_fee_percent']);
        $this->assertSame(113.0, $pricing['total']);

        $item = OrderItem::make([
            'price' => $pricing['total'],
            'additional_price' => 0,
            'publisher_price' => $pricing['publisher_price'],
            'platform_fee_percent' => $pricing['platform_fee_percent'],
            'platform_fee_amount' => $pricing['platform_fee_amount'],
        ]);

        $this->assertSame(100.0, $item->publisherPayoutAmount());
        $this->assertSame(13.0, $item->platformFeeAmount());
    }

    public function test_fee_tiers_match_config(): void
    {
        $fees = app(PlatformFeeService::class);

        $this->assertSame(15.0, $fees->feePercentForBase(50));
        $this->assertSame(13.0, $fees->feePercentForBase(100));
        $this->assertSame(12.0, $fees->feePercentForBase(300));
        $this->assertSame(10.0, $fees->feePercentForBase(1000));
    }
}
