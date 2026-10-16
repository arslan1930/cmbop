<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site-column Homepage / social / Sensitive chips must wrap inside Site.
 * Table overflow stays visible for sticky headers, so a nowrap tag row
 * paints over Category (“+N more”).
 */
class CatalogSiteTagSlotWrapTest extends TestCase
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

    public function test_critical_css_wraps_site_tag_slot_when_stylesheet_is_stale(): void
    {
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        $this->assertStringContainsString('id="catalog-critical"', $blade);
        $this->assertStringContainsString('td.catalog-site-cell{max-width:0;min-width:0}', $blade);
        $this->assertStringContainsString('.catalog-site-stack{overflow:hidden', $blade);
        $this->assertStringContainsString('.catalog-site-tag-slot{display:flex;flex-wrap:wrap', $blade);
        $this->assertStringContainsString('.catalog-site-tag-slot .site-chip{max-width:100%', $blade);
    }

    public function test_catalog_css_wraps_tag_slot_and_clamps_site_column(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertMatchesRegularExpression(
            '/td\.catalog-site-cell \{[^}]*max-width:\s*0;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-site-stack \{[^}]*overflow:\s*hidden;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-site-tag-slot \{[^}]*flex-wrap:\s*wrap;/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-site-tag-slot \{[^}]*flex-wrap:\s*nowrap;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-site-tag-slot \.site-chip \{[^}]*text-overflow:\s*ellipsis;/s',
            $css
        );
    }

    public function test_desktop_and_mobile_rows_share_the_wrapping_tag_slot(): void
    {
        $blade = (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-results.blade.php')
        );

        $this->assertSame(2, substr_count($blade, 'class="catalog-site-tag-slot"'));
        $this->assertStringContainsString('catalog-site-cell', $blade);
        $this->assertStringContainsString('catalog-mobile-card', $blade);
    }

    public function test_rendered_row_keeps_placement_chips_inside_the_site_tag_slot(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Finchannel Wrap Blog',
            'site_url' => 'https://finchannel-wrap.example',
            'domain' => 'finchannel-wrap.example',
            'example_url' => 'https://finchannel-wrap.example/sample',
            'da' => 72,
            'dr' => 68,
            'traffic' => 102000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'Business & Finance',
            'categories' => [
                'Business & Finance',
                'Crypto & Blockchain',
                'News and Media',
                'Technology & Gadgets',
                'Education and Jobs',
                'Lifestyle and Fashion',
                'Marketing',
            ],
            'price' => 148,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Long Site-column chip row must wrap, not cover Category.',
            'verified' => true,
            'active' => 1,
            'homepage_placement_prices' => ['7' => 25],
            'social_promotion' => [
                'facebook' => true,
                'instagram' => true,
                'x' => true,
            ],
            'sensitive_prices' => [
                'crypto' => 140,
                'trading' => 150,
                'cbd' => 158.2,
                'forex' => 160,
            ],
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-site-cell', $html);
        $this->assertStringContainsString('catalog-site-tag-slot', $html);
        $this->assertStringContainsString('site-chip--homepage', $html);
        $this->assertStringContainsString('catalog-social-icons', $html);
        $this->assertStringContainsString('site-chip--sensitive', $html);
        $this->assertStringContainsString('Sensitive topics from', $html);
        $this->assertStringContainsString('catalog-category-cell', $html);
        $this->assertStringContainsString('+5 more', $html);

        $this->assertMatchesRegularExpression(
            '/catalog-site-tag-slot[\s\S]*?site-chip--homepage[\s\S]*?catalog-social-icons[\s\S]*?site-chip--sensitive[\s\S]*?catalog-category-cell/',
            $html
        );
    }
}
