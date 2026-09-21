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

        $statusStart = strpos($blade, 'catalog-cart-status');
        $this->assertNotFalse($statusStart);
        $chunk = substr($blade, $statusStart, 700);
        $this->assertStringContainsString('in your cart', $chunk);
        $this->assertStringNotContainsString('openCart()', $chunk);
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
    }
}
