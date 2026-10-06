<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPaginationUxTest extends TestCase
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

    private function seedSites(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Site::create([
                'publisher_id' => $this->publisher->id,
                'site_name' => "Paged Catalog {$i}",
                'site_url' => "https://paged-catalog-{$i}.example",
                'domain' => "paged-catalog-{$i}.example",
                'example_url' => "https://paged-catalog-{$i}.example/sample",
                'da' => 30 + ($i % 10),
                'dr' => 40 + ($i % 10),
                'traffic' => 5000 + $i,
                'country' => 'us',
                'language' => 'en',
                'countries' => ['us'],
                'languages' => ['en'],
                'category' => 'marketing',
                'categories' => ['Marketing'],
                'price' => 100,
                'publication_time' => '7 days',
                'link_type' => 'dofollow',
                'description' => 'Pagination UX fixture.',
                'verified' => true,
                'active' => 1,
            ]);
        }
    }

    public function test_per_page_controls_listing_size_and_resets_high_pages(): void
    {
        $this->seedSites(30);

        $ten = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['per_page' => 10]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Showing', $ten);
        $this->assertStringContainsString('1–10', $ten);
        $this->assertStringContainsString('Page 1 of 3', $ten);
        $this->assertStringContainsString('id="catalogPerPage"', $ten);
        $this->assertMatchesRegularExpression(
            '/id="catalogPerPage"[^>]*>[\s\S]*value="10"[^>]*selected/',
            $ten
        );

        $fifty = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['per_page' => 50]))
            ->assertOk()
            ->getContent();
        // 30 sites fit on one page at 50 — numbered pager hides; per-page stays bottom-left.
        $this->assertStringNotContainsString('catalog-pagination__links', $fifty);
        $this->assertStringContainsString('catalog-pagination__perpage', $fifty);
        $this->assertStringContainsString('50 per page', $fifty);

        $invalid = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['per_page' => 99, 'page' => 2]))
            ->assertOk()
            ->getContent();
        // Invalid per_page → default 20; page 2 of 30 sites is 21–30.
        $this->assertMatchesRegularExpression('/data-first-item="21"/', $invalid);
        $this->assertMatchesRegularExpression('/data-last-item="30"/', $invalid);
        $this->assertStringContainsString('data-current-page="2"', $invalid);
    }

    public function test_results_fragment_honors_per_page_and_custom_links_view(): void
    {
        $this->seedSites(25);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results', ['per_page' => 10, 'page' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('11–20', $html);
        $this->assertStringContainsString('Page 2 of 3', $html);
        $this->assertStringContainsString('catalog-pagination__perpage', $html);
        $this->assertStringContainsString('id="catalogPerPage"', $html);
        $this->assertStringContainsString('catalog-pagination__pill', $html);
        $this->assertStringContainsString('data-current-page="2"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);

        $catalogPath = parse_url(route('advertiser.catalog'), PHP_URL_PATH);
        $this->assertStringContainsString($catalogPath, $html);
    }

    public function test_live_client_wires_focus_keyboard_and_per_page(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString("'per_page'", $js);
        $this->assertStringContainsString('catalogPerPage', $js);
        $this->assertStringContainsString("intent === 'page'", $js);
        $this->assertStringContainsString('card.focus', $js);
        $this->assertStringContainsString('Alt+←', $js);
        $this->assertStringContainsString("e.key !== 'ArrowLeft'", $js);
        $this->assertContains('per_page', CatalogUrlQuery::KEYS);
    }
}
