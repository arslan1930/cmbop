<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogFilterStatus;
use App\Services\Catalog\CatalogUrlQuery;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * More drawer: plain UI + bulk_deals / on_sale checkbox filters.
 * Bulk = pack program only; On sale = live custom Sale −%.
 * Option 2: Spendable bulk rail is hidden while bulk_deals is on.
 */
class CatalogMoreBulkDealsFilterTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSite(string $name, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example',
            'domain' => strtolower(str_replace(' ', '-', $name)).'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Catalog more-filter fixture.',
            'verified' => true,
            'active' => 1,
            'bulk_discount_enabled' => 0,
            'bulk_discount_percent' => null,
        ], $overrides));
    }

    public function test_bulk_and_on_sale_params_are_allowlisted(): void
    {
        $this->assertContains('bulk_deals', CatalogUrlQuery::KEYS);
        $this->assertContains('on_sale', CatalogUrlQuery::KEYS);
        $this->assertContains('bulk_deals', CatalogFilterStatus::QUERY_KEYS);
        $this->assertContains('on_sale', CatalogFilterStatus::QUERY_KEYS);
    }

    public function test_more_drawer_is_plain_with_bulk_and_on_sale_checkboxes(): void
    {
        $this->makeSite('Plain Site');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('catalog-more-drawer', $html);
        $this->assertStringNotContainsString('catalog-more-drawer__inner', $html);
        $this->assertStringContainsString('id="bulk_deals"', $html);
        $this->assertStringContainsString('Show Bulk Deals', $html);
        $this->assertStringContainsString('id="on_sale"', $html);
        $this->assertStringContainsString('Show On Sale', $html);
        $this->assertStringContainsString('type="checkbox" name="bulk_deals"', $html);
        $this->assertStringContainsString('type="checkbox" name="on_sale"', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringNotContainsString('.catalog-more-drawer__inner', $css);
    }

    public function test_bulk_deals_filter_excludes_sale_only_and_hides_rail(): void
    {
        $this->makeSite('Bulk Only Site', [
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 12,
        ]);
        $this->makeSite('Sale Only Site', [
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ]);
        $this->makeSite('Regular Listing Site');

        $filtered = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['bulk_deals' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk Only Site', $filtered);
        $this->assertStringNotContainsString('Sale Only Site', $filtered);
        $this->assertStringNotContainsString('Regular Listing Site', $filtered);
        $this->assertStringContainsString('>Bulk deals<', $filtered);
        $this->assertStringNotContainsString('data-bulk-rail', $filtered);
        $this->assertMatchesRegularExpression(
            '/type="checkbox" name="bulk_deals"[^>]*checked/s',
            $filtered
        );

        $all = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk Only Site', $all);
        $this->assertStringContainsString('Sale Only Site', $all);
        $this->assertStringContainsString('Regular Listing Site', $all);
        $this->assertStringContainsString('data-bulk-rail', $all);
    }

    public function test_on_sale_filter_shows_custom_discount_only(): void
    {
        $this->makeSite('Bulk Only Site', [
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 12,
        ]);
        $this->makeSite('Sale Only Site', [
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ]);
        $this->makeSite('Expired Sale Site', [
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDays(10),
            'custom_discount_ends_at' => now()->subDay(),
        ]);
        $this->makeSite('Both Promo Site', [
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
            'custom_discount_percent' => 25,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $filtered = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['on_sale' => '1']))
            ->assertOk()
            ->getContent();

        // Listing rows: tr.site-row data-name. The Spendable rail also uses
        // data-name and can still show bulk pack sites when on_sale is on.
        $this->assertMatchesRegularExpression('/<tr class="site-row[^"]*"[\s\S]{0,400}data-name="Sale Only Site"/', $filtered);
        $this->assertMatchesRegularExpression('/<tr class="site-row[^"]*"[\s\S]{0,400}data-name="Both Promo Site"/', $filtered);
        $this->assertDoesNotMatchRegularExpression('/<tr class="site-row[^"]*"[\s\S]{0,400}data-name="Bulk Only Site"/', $filtered);
        $this->assertDoesNotMatchRegularExpression('/<tr class="site-row[^"]*"[\s\S]{0,400}data-name="Expired Sale Site"/', $filtered);
        $this->assertStringContainsString('>On sale<', $filtered);
        $this->assertMatchesRegularExpression(
            '/type="checkbox" name="on_sale"[^>]*checked/s',
            $filtered
        );
        // On sale alone does not hide the Spendable rail.
        $this->assertStringContainsString('data-bulk-rail', $filtered);
    }

    public function test_on_sale_filter_excludes_unparseable_discount_dates(): void
    {
        $live = $this->makeSite('Live Sale Site', [
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ]);
        $leftoverEnds = $this->makeSite('Garbage Ends Site', [
            'custom_discount_percent' => 25,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ]);
        $leftoverStarts = $this->makeSite('Garbage Starts Site', [
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ]);

        DB::table('sites')->where('id', $leftoverEnds->id)->update([
            'custom_discount_ends_at' => 'not-a-date',
        ]);
        DB::table('sites')->where('id', $leftoverStarts->id)->update([
            'custom_discount_starts_at' => 'not-a-date',
        ]);

        $this->assertTrue($live->fresh()->hasActiveCustomDiscount());
        $this->assertFalse($leftoverEnds->fresh()->hasActiveCustomDiscount());
        $this->assertFalse($leftoverStarts->fresh()->hasActiveCustomDiscount());

        $filtered = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['on_sale' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-name="Live Sale Site"', $filtered);
        $this->assertStringNotContainsString('data-name="Garbage Ends Site"', $filtered);
        $this->assertStringNotContainsString('data-name="Garbage Starts Site"', $filtered);
    }

    public function test_featured_sort_does_not_promote_unparseable_featured_until(): void
    {
        $this->makeSite('Leftover Featured Site', [
            'dr' => 10,
            'featured_until' => now()->addDays(3),
        ]);
        $this->makeSite('High Dr Site', [
            'dr' => 90,
        ]);

        $leftover = Site::query()->where('site_name', 'Leftover Featured Site')->firstOrFail();
        DB::table('sites')->where('id', $leftover->id)->update([
            'featured_until' => 'not-a-date',
        ]);

        $this->assertFalse($leftover->fresh()->isFeatured());

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $highPos = strpos($html, 'data-name="High Dr Site"');
        $leftoverPos = strpos($html, 'data-name="Leftover Featured Site"');
        $this->assertNotFalse($highPos);
        $this->assertNotFalse($leftoverPos);
        $this->assertLessThan($leftoverPos, $highPos);
    }

    public function test_bulk_and_on_sale_filters_combine_with_and(): void
    {
        $this->makeSite('Bulk Only Site', [
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 12,
        ]);
        $this->makeSite('Sale Only Site', [
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ]);
        $this->makeSite('Both Promo Site', [
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
            'custom_discount_percent' => 25,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $filtered = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'bulk_deals' => '1',
                'on_sale' => '1',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Both Promo Site', $filtered);
        $this->assertStringNotContainsString('Bulk Only Site', $filtered);
        $this->assertStringNotContainsString('Sale Only Site', $filtered);
        $this->assertStringNotContainsString('data-bulk-rail', $filtered);
    }

    public function test_live_client_wires_bulk_and_on_sale_checkboxes(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        $this->assertStringContainsString("'bulk_deals'", $js);
        $this->assertStringContainsString("'on_sale'", $js);
        $this->assertStringContainsString("params.get('bulk_deals') === '1'", $js);
        $this->assertStringContainsString("params.get('on_sale') === '1'", $js);
        $this->assertStringContainsString("label: 'Bulk deals'", $js);
        $this->assertStringContainsString("label: 'On sale'", $js);
        $this->assertStringContainsString("getElementById('bulk_deals')", $js);
        $this->assertStringContainsString("getElementById('on_sale')", $js);
        $this->assertStringContainsString("el.type === 'checkbox'", $js);
        $this->assertDoesNotMatchRegularExpression(
            '/\[\'sponsored\', \'favorites_filter\', \'blacklist_filter\', \'bulk_deals\'\]/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/\[\'tag\', \'favorites_filter\', \'blacklist_filter\', \'publication_time\', \'link_type\', \'turnaround_max\'\]/',
            $js
        );
        $this->assertStringContainsString('id="bulk_deals"', $blade);
        $this->assertStringContainsString('id="on_sale"', $blade);
        $this->assertStringContainsString("'on_sale'", $blade);
    }

    public function test_bulk_deals_fragment_is_empty_when_bulk_only_filter_on(): void
    {
        $this->makeSite('Bulk Fragment Site', [
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 12,
        ]);

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['bulk_deals' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-bulk-rail', $html);
        $this->assertStringNotContainsString('Bulk Fragment Site', $html);
    }

    public function test_live_results_empty_state_treats_on_sale_as_an_active_filter(): void
    {
        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results', ['on_sale' => '1']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No sites match your filters', $html);
        $this->assertStringNotContainsString('No publishers available yet', $html);
    }

    public function test_empty_recovery_urls_preserve_bulk_and_on_sale_params(): void
    {
        $request = Request::create('/advertiser/catalog', 'GET', [
            'bulk_deals' => '1',
            'on_sale' => '1',
            'country' => 'de',
            'sponsored' => '1',
        ]);

        $kept = app(CatalogFilterStatus::class)
            ->catalogQuery($request, except: ['country', 'page']);

        $this->assertSame('1', $kept['bulk_deals'] ?? null);
        $this->assertSame('1', $kept['on_sale'] ?? null);
        $this->assertSame('sponsored', $kept['tag'] ?? null);
        $this->assertArrayNotHasKey('sponsored', $kept);
        $this->assertArrayNotHasKey('country', $kept);
    }
}
