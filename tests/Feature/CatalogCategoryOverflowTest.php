<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category chips must stay inside the fixed Category column — the catalog
 * keeps table overflow visible for sticky headers, so without clamps long
 * niches paint over Traffic / DR / DA (worse when CDN serves stale CSS).
 */
class CatalogCategoryOverflowTest extends TestCase
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

    public function test_critical_css_clamps_category_chips_when_stylesheet_is_stale(): void
    {
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        $this->assertStringContainsString('id="catalog-critical"', $blade);
        $this->assertStringContainsString('td.catalog-category-cell{overflow:hidden', $blade);
        $this->assertStringContainsString('.categories-wrapper{', $blade);
        $this->assertStringContainsString('overflow:hidden;min-width:0', $blade);
        $this->assertStringContainsString('.category-badge{', $blade);
        $this->assertStringContainsString('text-overflow:ellipsis', $blade);
    }

    public function test_catalog_css_clips_category_cell_and_wrapper(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertStringContainsString('td.catalog-category-cell', $css);
        $this->assertMatchesRegularExpression(
            '/td\.catalog-category-cell \{[^}]*overflow:\s*hidden;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.categories-wrapper \{[^}]*overflow:\s*hidden;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.category-badge \{[^}]*text-overflow:\s*ellipsis;/s',
            $css
        );
        // Category column widened slightly so two chips fit.
        $this->assertStringContainsString('width: 15%;', $css);
    }

    public function test_results_default_to_two_visible_category_chips(): void
    {
        $blade = (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-results.blade.php')
        );

        $this->assertStringContainsString('catalog-category-cell', $blade);
        $this->assertStringContainsString('$showLimit = 2;', $blade);
        $this->assertStringNotContainsString('$showLimit = 3;', $blade);
        $this->assertStringContainsString('extra-category d-none', $blade);
    }

    public function test_rendered_row_hides_third_niche_behind_more(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Niche Overflow Blog',
            'site_url' => 'https://niche-overflow.example',
            'domain' => 'niche-overflow.example',
            'example_url' => 'https://niche-overflow.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'Marketing',
            'categories' => [
                'News and Media',
                'Education and Jobs',
                'Lifestyle and Fashion',
                'Technology & Gadgets',
            ],
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Category overflow listing.',
            'verified' => true,
            'active' => 1,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-category-cell', $html);
        $this->assertStringContainsString('+2 more', $html);
        $this->assertStringContainsString('title="News and Media"', $html);
        $this->assertStringContainsString('title="Education and Jobs"', $html);
        $this->assertStringContainsString('title="Lifestyle and Fashion, Technology &amp; Gadgets"', $html);
        $this->assertMatchesRegularExpression(
            '/category-badge extra-category d-none[^>]*>\s*Lifestyle and Fashion/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/category-badge extra-category d-none[^>]*>\s*Technology/',
            $html
        );
    }
}
