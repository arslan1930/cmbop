<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CartPricingService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensitivePriceCartTest extends TestCase
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

    private function makeSiteWithSensitive(): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Sensitive Topic Site',
            'site_url' => 'https://sensitive-topic.example',
            'domain' => 'sensitive-topic.example',
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
            'description' => 'Site with crypto and CBD add-on pricing for advertisers.',
            'verified' => true,
            'active' => 1,
            'sensitive_prices' => [
                'crypto' => 25,
                'CBD' => 40,
            ],
        ]);
    }

    public function test_catalog_renders_sensitive_price_radios_with_totals(): void
    {
        $site = $this->makeSiteWithSensitive();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('sensitive-prices-group', $html);
        $this->assertStringContainsString('data-type="crypto"', $html);
        $this->assertStringContainsString('data-additional-price="25"', $html);
        $this->assertStringContainsString('name="sensitive_prices_'.$site->id.'"', $html);
        $this->assertStringContainsString('base-price-display', $html);
    }

    public function test_add_to_cart_includes_sensitive_add_on_in_price_and_payload(): void
    {
        $site = $this->makeSiteWithSensitive();
        $expected = app(CartPricingService::class)->priceForAdvertiser($site, 'crypto', 1);

        $response = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'sensitive_type' => 'crypto',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $cart = $response->json('cart');
        $this->assertCount(1, $cart);
        $this->assertSame('crypto', $cart[0]['sensitive_type']);
        $this->assertSame($site->domain, $cart[0]['domain']);
        $this->assertSame(40, $cart[0]['da']);
        $this->assertSame(45, $cart[0]['dr']);
        $this->assertEquals(25.0, (float) $cart[0]['additional_price']);
        $this->assertEquals($expected['total'], (float) $cart[0]['price']);
        $this->assertEquals($expected['total'], (float) $response->json('cart_total'));

        $sessionLine = session('cart')[0];
        $this->assertSame('crypto', $sessionLine['sensitive_type']);
        $this->assertEquals(25.0, (float) $sessionLine['additional_price']);
        $this->assertEquals($expected['total'], (float) $sessionLine['price']);
    }

    public function test_cbd_type_is_matched_case_insensitively(): void
    {
        $site = $this->makeSiteWithSensitive();
        $expected = app(CartPricingService::class)->priceForAdvertiser($site, 'cbd', 1);

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'sensitive_type' => 'cbd',
            ])
            ->assertOk()
            ->json();

        $this->assertSame('CBD', $payload['cart'][0]['sensitive_type']);
        $this->assertEquals(40.0, (float) $payload['cart'][0]['additional_price']);
        $this->assertEquals($expected['total'], (float) $payload['cart'][0]['price']);
    }

    public function test_cart_get_reprices_sensitive_lines_from_live_listing(): void
    {
        $site = $this->makeSiteWithSensitive();
        $expected = app(CartPricingService::class)->priceForAdvertiser($site, 'crypto', 1);

        // Stale client payload: sensitive topic kept, but price left at base-only.
        $payload = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'price' => 50,
                    'base_price' => 50,
                    'additional_price' => 0,
                    'sensitive_type' => 'crypto',
                    'quantity' => 1,
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $this->assertSame('crypto', $payload['cart'][0]['sensitive_type']);
        $this->assertSame($site->domain, $payload['cart'][0]['domain']);
        $this->assertSame(40, $payload['cart'][0]['da']);
        $this->assertSame(45, $payload['cart'][0]['dr']);
        $this->assertEquals(25.0, (float) $payload['cart'][0]['additional_price']);
        $this->assertEquals($expected['total'], (float) $payload['cart'][0]['price']);
    }

    public function test_cart_keeps_missing_da_dr_null_instead_of_zero(): void
    {
        $site = $this->makeSiteWithSensitive();
        $site->forceFill(['da' => 0, 'dr' => 0])->save();

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
            ])
            ->assertOk()
            ->json();

        $this->assertNull($payload['cart'][0]['da']);
        $this->assertNull($payload['cart'][0]['dr']);
        $this->assertSame('sensitive-topic.example', $payload['cart'][0]['domain']);
    }

    public function test_catalog_js_reads_sensitive_selection_from_dom_on_buy(): void
    {
        $js = file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function getSelectedSensitiveForSite', $js);
        $this->assertStringContainsString('function syncSensitiveSelectionUi', $js);
        $this->assertStringContainsString('getSelectedSensitiveForSite(id)', $js);
        $this->assertStringContainsString('function catalogApplyDiscount', $js);
        $this->assertStringContainsString('catalogDiscountPercentForSite', $js);

        // Matched on data-site-id rather than the radio name: the table row and
        // the card are separate groups, and the visible one sets the price.
        $this->assertStringContainsString(
            'input.sensitive-price-checkbox[data-site-id="',
            $js
        );
        $this->assertStringContainsString('function catalogVisibleFirst', $js);
    }

    public function test_each_layout_keeps_its_own_selected_add_on(): void
    {
        $site = $this->makeSiteWithSensitive();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Sharing one radio name across the table row and the card made them a
        // single group, so the card rendered with nothing selected while the
        // hidden table row held the checked default.
        $this->assertStringContainsString('name="sensitive_prices_'.$site->id.'"', $html);
        $this->assertStringContainsString('name="sensitive_prices_card_'.$site->id.'"', $html);

        // Both groups must offer a default, or the visible one starts blank.
        $this->assertSame(2, preg_match_all(
            '/<input[^>]*sensitive-price-checkbox[^>]*data-type="none"[^>]*checked/',
            $html
        ));
    }

    public function test_catalog_marks_discount_percent_on_sensitive_controls(): void
    {
        $site = $this->makeSiteWithSensitive();
        $site->forceFill([
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ])->save();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Advertiser list price for €100 publisher base is €113 (13% fee).
        $this->assertStringContainsString('data-discount-percent="20"', $html);
        $this->assertStringContainsString('data-base-price="113"', $html);
        // 20% of €113 = €90.40, but discounts are fee-absorbing only → floored at €100
        $this->assertStringContainsString('data-total-price="100"', $html);
        // Crypto +€25 list 138 − 20% = 110.4, floored at publisher payout 125
        $this->assertStringContainsString('data-total-price="125"', $html);
        $this->assertStringContainsString('base-price-display">€100.00', $html);
        unset($site);
    }

    public function test_add_to_cart_applies_custom_discount_with_sensitive_add_on(): void
    {
        $site = $this->makeSiteWithSensitive();
        $site->forceFill([
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ])->save();

        $expected = app(CartPricingService::class)->priceForAdvertiser($site, 'crypto', 1);

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'sensitive_type' => 'crypto',
            ])
            ->assertOk()
            ->json();

        $this->assertSame('crypto', $payload['cart'][0]['sensitive_type']);
        $this->assertEquals(25.0, (float) $payload['cart'][0]['additional_price']);
        // 20% of 138 = 110.4, floored at publisher payout 125
        $this->assertEquals(125.0, (float) $payload['cart'][0]['price']);
        $this->assertEquals($expected['total'], (float) $payload['cart'][0]['price']);
    }
}
