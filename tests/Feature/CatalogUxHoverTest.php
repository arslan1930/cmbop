<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\PlatformFeeService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogUxHoverTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $this->advertiser->roles()->attach($advRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        $n = uniqid();

        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'UX Hover '.$n,
            'site_url' => 'https://ux-hover-'.$n.'.example',
            'domain' => 'ux-hover-'.$n.'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'categories' => ['Marketing'],
            'price' => 120,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Catalog UX hover fixture.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_details_opens_only_from_the_dedicated_control(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString("closest('.expand-arrow')", $js);
        $this->assertStringContainsString('function toggleExpandRow', $js);
        $this->assertStringContainsString('function toggleCardDetails', $js);
        $this->assertStringContainsString('.catalog-card-details-toggle', $js);
        $this->assertStringContainsString('[data-catalog-open-details]', $js);
        $this->assertStringContainsString('data-catalog-open-section', $js);
        $this->assertStringContainsString('function inventoryFromHtml', $js);
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertDoesNotMatchRegularExpression(
            '/max-width:\s*1499\.98px[\s\S]{0,400}catalog-details-toggle__label/',
            $css
        );
        $this->assertStringNotContainsString('Whole-row click toggles Details', $js);
        $this->assertStringNotContainsString('Mobile cards: same body-click toggle', $js);
        $this->assertStringNotContainsString('function catalogActionClick', $js);
        $this->assertStringNotContainsString("closest('tr.site-row')", $js);
    }

    public function test_glass_tip_does_not_auto_adopt_catalog_listing_cells(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/glass-tip.js'));

        $this->assertStringContainsString('#catalogResults, .catalog-results-card', $js);
        $this->assertStringContainsString('!NATIVELY_INTERACTIVE[el.tagName]', $js);
        $this->assertStringContainsString("hasAttribute('data-no-tip')", $js);
    }

    public function test_closed_rows_show_language_and_link_type_without_hover_titles(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-meta-chip--language', $html);
        $this->assertStringContainsString('German', $html);
        $this->assertStringContainsString('DoFollow', $html);
        $this->assertStringNotContainsString('title="German links on this placement"', $html);
        $this->assertStringNotContainsString('title="DoFollow links on this placement"', $html);
        $this->assertStringNotContainsString('Browse verified publishers', $html);
        $this->assertStringNotContainsString('fw-semibold">Catalog</h2>', $html);
        $this->assertStringContainsString('Browse publisher listings', $html);
        $this->assertStringContainsString('expand-arrow catalog-details-toggle', $html);
        $this->assertStringContainsString('catalog-card-details-toggle', $html);
    }

    public function test_live_search_demotes_the_filter_submit_button(): void
    {
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('is-live-search', $html);
        $this->assertStringContainsString('id="applyFiltersBtn"', $html);
        $this->assertStringContainsString('Applies as you type', $html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*btn-cta-secondary[^"]*" id="applyFiltersBtn"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*btn-primary[^"]*" id="applyFiltersBtn"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="applyFiltersBtn"[^>]*>[\s\S]*?Apply/',
            $html
        );
    }

    public function test_cart_status_does_not_repeat_open_cart(): void
    {
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        $statusStart = strpos($blade, 'id="catalogCartBanner"');
        $this->assertNotFalse($statusStart);
        $elseAt = strpos($blade, '@else', $statusStart);
        $this->assertNotFalse($elseAt);
        $filled = substr($blade, $statusStart, $elseAt - $statusStart);
        $this->assertStringContainsString('in your cart', $filled);
        $this->assertStringNotContainsString('openCart()', $filled);
        $this->assertStringContainsString('onclick="openCart()"', $blade);
    }

    public function test_from_price_uses_the_filtered_set_not_the_current_page(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeSite([
                'site_name' => 'Expensive '.$i,
                'site_url' => 'https://expensive-'.$i.'.example',
                'domain' => 'expensive-'.$i.'.example',
                'price' => 400,
                'dr' => 90,
            ]);
        }
        $this->makeSite([
            'site_name' => 'Cheap Listing',
            'site_url' => 'https://cheap-listing.example',
            'domain' => 'cheap-listing.example',
            'price' => 40,
            'dr' => 10,
        ]);

        $from = app(PlatformFeeService::class)->advertiserBase(40.0);
        $fromAttr = number_format($from, 2, '.', '');

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['per_page' => 10, 'sort' => 'dr_desc']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-inventory-from="'.$fromAttr.'"', $html);
        $this->assertStringContainsString('catalog-inventory-from', $html);
        $this->assertStringNotContainsString('Cheap Listing', $html);
    }

    public function test_leftover_junk_language_and_link_type_do_not_break_catalog_chips(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Leftover Chip Site',
            'site_url' => 'https://leftover-chip.example',
            'domain' => 'leftover-chip.example',
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'languages' => 'not-json',
            'language' => 'de',
            'link_type' => '???',
            'country' => '??',
            'countries' => 'not-json',
            'categories' => 'not-json',
            'sensitive_prices' => 'not-json',
            'homepage_placement_prices' => 'not-json',
            'social_promotion' => 'not-json',
            'turnaround_time' => '???',
            'publication_time' => 'not-a-duration',
            'featured_until' => 'not-a-date',
            'custom_discount_percent' => 40,
            'custom_discount_starts_at' => 'not-a-date',
            'custom_discount_ends_at' => 'not-a-date',
            'screenshot_path' => 'not-a-path',
            'example_url' => 'javascript:alert(1)',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Leftover Chip Site', $html);
        $this->assertStringContainsString('German', $html);
        $this->assertStringNotContainsString('NOT-JSON', $html);
        $this->assertStringNotContainsString('not-json', $html);
        $this->assertStringNotContainsString('???', $html);
        $this->assertStringNotContainsString('not-a-duration', $html);
        $this->assertStringNotContainsString('48 hours', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('media/not-a-path', $html);
        $this->assertStringNotContainsString('/storage/not-a-path', $html);
        $this->assertStringNotContainsString('catalog-tile--preview', $html);
        $this->assertStringNotContainsString('site-chip--social', $html);
        $this->assertStringNotContainsString('Social promotions', $html);
        $this->assertStringNotContainsString('site-chip--featured', $html);
        $this->assertStringNotContainsString('site-chip--sale', $html);

        $chips = view('advertiser.partials.catalog-meta-chips', [
            'site' => $site->fresh(),
        ])->render();
        $this->assertStringContainsString('German', $chips);
        $this->assertStringNotContainsString('DoFollow', $chips);
        $this->assertStringNotContainsString('NOT-JSON', $chips);
        $this->assertStringNotContainsString('???', $chips);

        $fragment = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Leftover Chip Site', $fragment);
        $this->assertStringContainsString('German', $fragment);
        $this->assertStringNotContainsString('NOT-JSON', $fragment);
        $this->assertStringNotContainsString('???', $fragment);
        $this->assertStringNotContainsString('not-a-duration', $fragment);
    }

    public function test_leftover_metric_junk_does_not_500_catalog(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Leftover Metric Site',
            'site_url' => 'https://leftover-metric.example',
            'domain' => 'leftover-metric.example',
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'da' => '???',
            'dr' => 'not-json',
            'traffic' => '???',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Leftover Metric Site']))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->getContent();

        $this->assertStringContainsString('Leftover Metric Site', $html);
        $this->assertStringNotContainsString('???', $html);
        $this->assertStringNotContainsString('not-json', $html);
    }

    public function test_leftover_bulk_hostile_url_does_not_paint_in_rail(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Leftover Bulk Hostile',
            'site_url' => 'https://leftover-bulk-hostile.example',
            'domain' => 'leftover-bulk-hostile.example',
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'site_url' => 'javascript:alert(1)',
            'bulk_discount_percent' => 'not-json',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Leftover Bulk Hostile', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('???', $html);
    }

    public function test_leftover_cart_junk_does_not_500_wizard_pay(): void
    {
        $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => 'not-json',
                'guest_post_wizard' => ['language' => 'en', 'country' => 'de'],
            ])
            ->get(route('advertiser.wizard.pay'))
            ->assertRedirect(route('advertiser.wizard.publishers'));
    }

    public function test_leftover_cart_session_junk_does_not_500_or_mark_in_cart(): void
    {
        $this->makeSite([
            'site_name' => 'Leftover Cart Site',
            'site_url' => 'https://leftover-cart.example',
            'domain' => 'leftover-cart.example',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->withSession(['cart' => 'not-json'])
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->getContent();

        $this->assertStringContainsString('Leftover Cart Site', $html);
        $this->assertStringNotContainsString('is-in-cart', $html);
        $this->assertStringNotContainsString('>In cart</span>', $html);

        $fragment = $this->actingAs($this->advertiser)
            ->withSession(['cart' => [null, '???', ['id' => 'not-a-id'], ['name' => 'no-id']]])
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->assertDontSee('SQLSTATE')
            ->getContent();

        $this->assertStringContainsString('Leftover Cart Site', $fragment);
        $this->assertStringNotContainsString('is-in-cart', $fragment);
    }

    public function test_leftover_cart_junk_does_not_500_add_to_cart_or_count(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Leftover Add Cart Site',
            'site_url' => 'https://leftover-add-cart.example',
            'domain' => 'leftover-add-cart.example',
        ]);

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => 'not-json'])
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => [null, '???', ['name' => 'no-id']]])
            ->getJson(route('advertiser.cart.count'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonMissingPath('exception');

        $this->actingAs($this->advertiser)
            ->withSession(['cart' => 'not-json'])
            ->postJson(route('advertiser.cart.configure'), [
                'id' => $site->id,
                'new_homepage_days' => 'none',
            ])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_leftover_hostile_site_url_is_not_put_in_cart_json(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Leftover Hostile Cart Url',
            'site_url' => 'https://leftover-hostile-cart.example',
            'domain' => 'leftover-hostile-cart.example',
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'site_url' => 'javascript:alert(1)',
            'homepage_placement_prices' => 'not-json',
            'sensitive_prices' => '???',
        ]);

        $payload = $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $site->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();

        $json = json_encode($payload);
        $this->assertStringNotContainsString('javascript:alert', (string) $json);
        $this->assertStringNotContainsString('???', (string) $json);
        $this->assertStringNotContainsString('not-json', (string) $json);
    }

    public function test_leftover_cart_junk_does_not_500_wizard_catalog_chrome(): void
    {
        $this->makeSite([
            'site_name' => 'Leftover Wizard Chrome Site',
            'site_url' => 'https://leftover-wizard-chrome.example',
            'domain' => 'leftover-wizard-chrome.example',
            'language' => 'en',
            'languages' => ['en'],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [null, '???', ['quantity' => 'not-a-qty']],
                'guest_post_wizard' => [
                    'language' => 'en',
                    'country' => ['not-json'],
                    'categories' => 'not-json',
                ],
            ])
            ->get(route('advertiser.catalog', ['wizard' => 1, 'language' => 'en']))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->getContent();

        $this->assertStringContainsString('Place a guest post', $html);
        $this->assertStringContainsString('Leftover Wizard Chrome Site', $html);
        $this->assertStringNotContainsString('not-json', $html);
        $this->assertStringNotContainsString('???', $html);
    }

    public function test_leftover_rating_and_hostile_claim_url_do_not_break_catalog(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Leftover Trust Claim',
            'site_url' => 'https://leftover-trust-claim.example',
            'domain' => 'leftover-trust-claim.example',
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'site_url' => 'javascript:alert(1)',
            'rating_avg' => '???',
            'rating_count' => 'not-json',
            'completed_orders_count' => '???',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Leftover Trust Claim']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Leftover Trust Claim', $html);
        $this->assertStringContainsString('btn-claim-site', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('site-trust-chip', $html);
        $this->assertStringNotContainsString('???', $html);
        $this->assertStringNotContainsString('not-json', $html);
    }
}
