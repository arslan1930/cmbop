<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartPricingService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class HomepageSocialCartTest extends TestCase
{
    use CreatesContentSubmissions;
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
            'site_name' => 'Homepage Cart Site',
            'site_url' => 'https://homepage-cart.example',
            'domain' => 'homepage-cart.example',
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
            'description' => 'Site offering homepage placement and social boost.',
            'verified' => true,
            'active' => 1,
            'homepage_placement_prices' => [
                1 => 0,
                7 => 25,
                30 => 0,
            ],
            'social_promotion' => [
                'facebook' => true,
                'x' => true,
            ],
        ], $overrides));
    }

    public function test_catalog_renders_homepage_radios_and_social_badges(): void
    {
        $site = $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('homepage-placement-group', $html);
        $this->assertStringContainsString('homepage-placement-radio', $html);
        $this->assertStringContainsString('id="homepage_'.$site->id.'_30"', $html);
        $this->assertStringContainsString('Homepage promotions', $html);
        $this->assertStringContainsString('<strong>Social promotions</strong>', $html);
        $this->assertStringContainsString('Facebook', $html);
    }

    public function test_add_to_cart_defaults_to_longest_free_homepage(): void
    {
        $site = $this->makeSite();
        $expected = app(CartPricingService::class)->priceForAdvertiser($site, null, 1);

        $cart = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('cart');

        $this->assertCount(1, $cart);
        $this->assertSame(30, (int) $cart[0]['homepage_days']);
        $this->assertEquals(0.0, (float) $cart[0]['homepage_price']);
        $this->assertSame(['facebook', 'x'], $cart[0]['social_channels']);
        $this->assertEquals($expected['total'], (float) $cart[0]['price']);
        $this->assertEquals($expected['article_total'], (float) $cart[0]['article_total']);
    }

    public function test_paid_only_homepage_defaults_to_none_until_selected(): void
    {
        $site = $this->makeSite([
            'domain' => 'paid-only-home.example',
            'site_url' => 'https://paid-only-home.example',
            'homepage_placement_prices' => [
                7 => 25,
                30 => 60,
            ],
        ]);

        $cart = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->json('cart');

        $this->assertNull($cart[0]['homepage_days']);
        $this->assertEquals(0.0, (float) $cart[0]['homepage_price']);
    }

    public function test_homepage_fee_is_not_discounted_by_sale(): void
    {
        $site = $this->makeSite([
            'domain' => 'home-sale.example',
            'site_url' => 'https://home-sale.example',
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ]);

        // Ensure sale columns exist / are respected the same way other tests do.
        if (! Schema::hasColumn('sites', 'custom_discount_percent')) {
            $this->markTestSkipped('custom_discount_percent column missing');
        }

        $pricing = app(CartPricingService::class)->priceForAdvertiser($site, null, 1, 7, false);

        $this->assertSame(7, $pricing['homepage_days']);
        $this->assertEquals(25.0, $pricing['homepage_price']);
        // Article portion is discounted; homepage stays full €25.
        $this->assertEquals(
            round($pricing['article_total'] + 25.0, 2),
            $pricing['total']
        );
        $this->assertLessThan($pricing['list_total'], $pricing['article_total']);
    }

    public function test_cart_identity_separates_homepage_durations(): void
    {
        $site = $this->makeSite([
            'domain' => 'home-key.example',
            'site_url' => 'https://home-key.example',
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

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $days = collect($cart)->pluck('homepage_days')->map(fn ($d) => $d === null ? null : (int) $d)->all();
        $this->assertContains(null, $days);
        $this->assertContains(7, $days);
        $this->assertEquals(25.0, (float) collect($cart)->firstWhere('homepage_days', 7)['homepage_price']);
    }

    public function test_quantity_multiplies_homepage_fee(): void
    {
        $site = $this->makeSite([
            'domain' => 'home-qty.example',
            'site_url' => 'https://home-qty.example',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), [
                'id' => $site->id,
                'homepage_days' => 7,
                'quantity' => 2,
            ])
            ->assertOk();

        $checkout = app(CartPricingService::class)->buildCheckoutItems(session('cart'));
        $line = $checkout['items'][0];
        $this->assertSame(2, (int) $line['quantity']);
        $this->assertEquals(25.0, (float) $line['homepage_price']);
        $this->assertEquals(
            round(((float) $line['price']) * 2, 2),
            (float) $line['total']
        );
        // Unit price includes the undiscounted homepage fee once per placement.
        $this->assertEquals(25.0, (float) $line['homepage_price']);
        $this->assertEquals(
            round((float) $line['list_total'] + 25.0, 2),
            (float) $line['price']
        );
    }

    public function test_expand_cart_snapshots_homepage_and_social_fields(): void
    {
        $site = $this->makeSite([
            'domain' => 'home-snap.example',
            'site_url' => 'https://home-snap.example',
        ]);

        $expanded = app(CartPricingService::class)->expandCart([[
            'id' => $site->id,
            'quantity' => 1,
            'homepage_days' => 7,
        ]]);

        $this->assertSame(7, $expanded[0]['homepage_days']);
        $this->assertEquals(25.0, (float) $expanded[0]['homepage_price']);
        $this->assertSame(['facebook', 'x'], $expanded[0]['social_channels']);
        $this->assertEquals(
            round((float) $expanded[0]['article_total'] + 25.0, 2),
            (float) $expanded[0]['price']
        );

        $item = new OrderItem([
            'price' => $expanded[0]['price'],
            'additional_price' => $expanded[0]['additional_price'],
            'homepage_price' => $expanded[0]['homepage_price'],
            'homepage_days' => $expanded[0]['homepage_days'],
            'social_channels' => $expanded[0]['social_channels'],
            'publisher_price' => $expanded[0]['publisher_price'],
        ]);

        $this->assertSame(7, (int) $item->homepage_days);
        $this->assertEquals(25.0, (float) $item->homepage_price);
        $this->assertSame(['facebook', 'x'], $item->social_channels);
        $this->assertEquals(
            round((float) $expanded[0]['publisher_price'] + 25.0, 2),
            $item->publisherPayoutAmount()
        );
    }

    public function test_wallet_checkout_persists_social_channels_on_order_item(): void
    {
        config(['content_moderation.enabled' => false]);
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin']);

        $site = $this->makeSite([
            'domain' => 'paid-social.example',
            'site_url' => 'https://paid-social.example',
        ]);
        $sub = $this->createApprovedSubmission($this->advertiser, null);
        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => Role::where('name', 'advertiser')->value('id'),
            'balance' => 500,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'homepage_days' => 7,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'SOC1',
                'publication_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $item = OrderItem::query()->latest('id')->firstOrFail();
        $this->assertSame(['facebook', 'x'], $item->enabledSocialChannels());
        $this->assertSame(7, (int) $item->homepage_days);
        $this->assertEquals(25.0, (float) $item->homepage_price);
    }

    public function test_catalog_js_wires_homepage_selection_into_buy(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('function getSelectedHomepageForSite', $js);
        $this->assertStringContainsString('homepage-placement-radio', $js);
        $this->assertStringContainsString('homepage_days', $js);
        $this->assertStringContainsString('Homepage fee is never discounted', $js);
    }
}
