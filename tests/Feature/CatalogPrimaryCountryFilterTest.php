<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogCountryInventory;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalog country= must match primary (scalar sites.country) only —
 * not JSON countries "contains" — so US results never show German flags.
 */
class CatalogPrimaryCountryFilterTest extends TestCase
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
        $this->advertiser->roles()->sync([$advertiserRole->id]);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->sync([$publisherRole->id]);
    }

    private function site(string $slug, string $country, array $countries, string $name): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => $country,
            'countries' => $countries,
            'language' => $country === 'us' ? 'en' : 'de',
            'languages' => [$country === 'us' ? 'en' : 'de'],
            'category' => 'marketing',
            'price' => 80,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Primary country filter fixture.',
            'verified' => true,
            'active' => true,
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 12,
        ]);
    }

    public function test_us_filter_excludes_de_primary_even_when_countries_json_includes_us(): void
    {
        $this->site('de-multi', 'de', ['de', 'us'], 'DE Primary Multi Market');
        $this->site('us-only', 'us', ['us'], 'US Primary Only');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['country' => 'us']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('US Primary Only', $html);
        $this->assertStringNotContainsString('DE Primary Multi Market', $html);
        // Flag/label for remaining rows should be United States, not Germany.
        $this->assertStringContainsString('United States', $html);
    }

    public function test_de_filter_includes_de_primary_multi_market(): void
    {
        $this->site('de-multi', 'de', ['de', 'us'], 'DE Primary Multi Market');
        $this->site('us-only', 'us', ['us'], 'US Primary Only');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['country' => 'de']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('DE Primary Multi Market', $html);
        $this->assertStringNotContainsString('US Primary Only', $html);
        $this->assertStringContainsString('Germany', $html);
    }

    public function test_multi_select_de_us_returns_both_primaries(): void
    {
        $this->site('de-multi', 'de', ['de', 'us'], 'DE Primary Multi Market');
        $this->site('us-only', 'us', ['us'], 'US Primary Only');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['country' => 'de,us']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('DE Primary Multi Market', $html);
        $this->assertStringContainsString('US Primary Only', $html);
    }

    public function test_array_country_and_search_do_not_500(): void
    {
        $this->site('de-multi', 'de', ['de', 'us'], 'DE Primary Multi Market');
        $this->site('us-only', 'us', ['us'], 'US Primary Only');

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'country' => ['de', 'us'],
                'search' => ['Primary'],
                'price_min' => ['10'],
                'da_min' => ['20'],
            ]))
            ->assertOk()
            ->assertDontSee('Array to string conversion', false)
            ->getContent();

        $this->assertStringContainsString('DE Primary Multi Market', $html);
        $this->assertStringContainsString('US Primary Only', $html);
    }

    public function test_bulk_deals_fragment_follows_primary_country_only(): void
    {
        $this->site('de-bulk', 'de', ['de', 'us'], 'DE Bulk Multi');
        $this->site('us-bulk', 'us', ['us'], 'US Bulk Only');

        $usHtml = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['country' => 'us']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('US Bulk Only', $usHtml);
        $this->assertStringNotContainsString('DE Bulk Multi', $usHtml);

        $deHtml = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['country' => 'de']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('DE Bulk Multi', $deHtml);
        $this->assertStringNotContainsString('US Bulk Only', $deHtml);
    }

    public function test_site_primary_country_code_prefers_scalar_country(): void
    {
        $site = $this->site('flag-de', 'de', ['us', 'de'], 'Flag Scalar Wins');

        $this->assertSame('de', $site->primaryCountryCode());
        $this->assertSame(
            'de',
            app(CatalogCountryInventory::class)->primaryCountryCode($site->country, $site->countries)
        );
    }

    public function test_site_filter_scope_uses_primary_country_only(): void
    {
        $this->site('de-scope', 'de', ['de', 'us'], 'DE Scope Multi');
        $us = $this->site('us-scope', 'us', ['us'], 'US Scope Only');

        $ids = Site::query()
            ->where('active', 1)
            ->tap(fn ($q) => app(CatalogCountryInventory::class)
                ->constrainQueryToPrimaryCountries($q, ['us']))
            ->pluck('id')
            ->all();

        $this->assertSame([$us->id], $ids);
    }
}
