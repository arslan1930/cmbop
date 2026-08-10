<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0 end-to-end contracts for catalog category filtering + badges.
 *
 * These fail on master (comma explode / badge split) and pass once the shared
 * parser is wired into listing + row rendering.
 */
class CatalogCategoryParamContractTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
        Category::flushNicheLookupCache();

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

    private function site(string $name, string $domain, array $categories, ?string $legacyCategory = null): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 50,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => $legacyCategory ?? $categories[0],
            'categories' => $categories,
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'Category param contract fixture.',
            'verified' => true,
            'active' => 1,
        ]);
    }

    public function test_catalog_picker_options_come_from_categories_table(): void
    {
        $names = Category::catalogPickerNames();

        $this->assertNotEmpty($names);
        foreach (Category::NICHES_CONTAINING_COMMA as $niche) {
            $this->assertContains($niche, $names);
        }

        $dbNames = Category::query()->orderBy('name')->pluck('name')->all();
        sort($dbNames);
        $sortedPicker = $names;
        sort($sortedPicker);
        $this->assertSame($dbNames, $sortedPicker, 'Catalog picker must equal DB category names.');
    }

    public function test_category_query_with_comma_niche_matches_exact_site_not_halves(): void
    {
        $hit = $this->site(
            'Comma Niche Hit',
            'comma-niche-hit.example',
            ['Marketing, PR & Advertising']
        );
        $missMarketing = $this->site(
            'Only Marketing Legacy',
            'only-marketing.example',
            ['Business & Finance'],
            'Marketing'
        );
        $missPr = $this->site(
            'Only PR Half',
            'only-pr.example',
            ['Legal Services'],
            'PR & Advertising'
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Comma Niche Hit', $html);
        $this->assertStringContainsString('data-id="'.$hit->id.'"', $html);
        $this->assertStringNotContainsString('Only Marketing Legacy', $html);
        $this->assertStringNotContainsString('Only PR Half', $html);
        $this->assertStringNotContainsString('data-id="'.$missMarketing->id.'"', $html);
        $this->assertStringNotContainsString('data-id="'.$missPr->id.'"', $html);
    }

    public function test_pipe_multi_category_query_keeps_comma_niche(): void
    {
        $health = $this->site(
            'Health Site',
            'health-pipe.example',
            ['Health & Wellness']
        );
        $marketing = $this->site(
            'Marketing Pipe Site',
            'marketing-pipe.example',
            ['Marketing, PR & Advertising']
        );
        $other = $this->site(
            'Other Niche Site',
            'other-pipe.example',
            ['Technology & Gadgets']
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Health & Wellness|Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Health Site', $html);
        $this->assertStringContainsString('Marketing Pipe Site', $html);
        $this->assertStringNotContainsString('Other Niche Site', $html);
        $this->assertStringContainsString('data-id="'.$health->id.'"', $html);
        $this->assertStringContainsString('data-id="'.$marketing->id.'"', $html);
        $this->assertStringNotContainsString('data-id="'.$other->id.'"', $html);
    }

    public function test_legacy_comma_separated_known_niches_still_filter(): void
    {
        $health = $this->site(
            'Legacy Health',
            'legacy-health.example',
            ['Health & Wellness']
        );
        $marketing = $this->site(
            'Legacy Marketing',
            'legacy-marketing.example',
            ['Marketing, PR & Advertising']
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                // Bookmarked pre-pipe URL: two known niches joined with commas.
                'category' => 'Health & Wellness,Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Legacy Health', $html);
        $this->assertStringContainsString('Legacy Marketing', $html);
        $this->assertStringContainsString('data-id="'.$health->id.'"', $html);
        $this->assertStringContainsString('data-id="'.$marketing->id.'"', $html);
    }

    public function test_badge_keeps_comma_niche_as_one_pill(): void
    {
        $this->site(
            'Badge Comma Niche',
            'badge-comma.example',
            ['Marketing, PR & Advertising']
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/category-badge[^>]*>\s*Marketing, PR &amp; Advertising\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*Marketing\s*</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/category-badge[^>]*>\s*PR &amp; Advertising\s*</',
            $html
        );
    }

    public function test_catalog_page_exposes_category_names_and_pipe_hint_for_js(): void
    {
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('categoryNames:', $html);
        // Blade attribute escapes &; @json / Js::from may hex-escape it in config.
        $this->assertTrue(
            str_contains($html, 'Marketing, PR &amp; Advertising')
            || str_contains($html, 'Marketing, PR \u0026 Advertising')
            || str_contains($html, 'Marketing, PR \\u0026 Advertising'),
            'Comma niche must appear in picker markup or categoryNames config.'
        );
        // Hydrate must use the shared splitter, not raw split(',').
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('CatalogCategoryParam', $js);
        $this->assertStringContainsString('CatalogCategoryParam.split', $js);
        $this->assertStringContainsString('CatalogCategoryParam.join', $js);
        $this->assertStringContainsString('CatalogCategoryParam.canonicalize', $js);
        // Step 1.1: category hidden field uses join('|'); country/language stay comma.
        $this->assertStringContainsString("id === 'selectedCategory'", $js);
        $this->assertStringContainsString('CatalogCategoryParam.join(map[id])', $js);
        $this->assertStringContainsString("map[id].join(',')", $js);
    }

    public function test_legacy_comma_category_url_is_canonicalized_to_pipe_in_hidden_field(): void
    {
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'category' => 'Health & Wellness,Marketing, PR & Advertising',
            ]))
            ->assertOk()
            ->getContent();

        $canonical = 'Health &amp; Wellness|Marketing, PR &amp; Advertising';
        $this->assertMatchesRegularExpression(
            '/id="selectedCategory"[^>]*value="'.preg_quote($canonical, '/').'"'
            .'|value="'.preg_quote($canonical, '/').'"[^>]*id="selectedCategory"/',
            $html
        );
        $this->assertStringContainsString('categoryParam:', $html);
        // Config JSON may unicode-escape &.
        $this->assertTrue(
            str_contains($html, 'Health & Wellness|Marketing, PR')
            || str_contains($html, 'Health \u0026 Wellness|Marketing, PR')
            || str_contains($html, 'Health \\u0026 Wellness|Marketing, PR'),
            'CatalogConfig.categoryParam must be pipe-canonicalized on the server.'
        );
    }

    public function test_controller_build_listing_does_not_explode_category_on_comma(): void
    {
        $src = (string) file_get_contents(app_path('Http/Controllers/Advertiser/CatalogController.php'));
        $this->assertStringContainsString('Category::parseCatalogCategoryParam', $src);
        $this->assertStringNotContainsString(
            "explode(',', (string) \$request->category)",
            $src
        );
        $this->assertStringNotContainsString(
            "explode(',', \$request->category)",
            $src
        );
    }
}
