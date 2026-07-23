<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherMySitesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => "O'Reilly News",
            'site_url' => 'https://oreilly-news.example',
            'domain' => 'oreilly-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'description' => "It's a publisher site with apostrophes and \"quotes\".",
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_my_sites_page_and_ajax_table_render(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
        ]);

        $page = $this->actingAs($this->publisher)->get(route('publisher.websites'));
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('function fetchSites', $html);
        $this->assertStringContainsString('window.loadSites = fetchSites', $html);
        $this->assertStringContainsString("$(document).on('click', '.action-view'", $html);
        $this->assertStringContainsString("$(document).on('click', '.btn-delete'", $html);
        $this->assertStringContainsString('sitesFilterPending', $html);
        $this->assertStringContainsString('sitesFilterActive', $html);
        $this->assertStringContainsString('sitesStatusFilter', $html);
        $this->assertSame(1, substr_count($html, 'const claimCard'));

        $ajax = $this->actingAs($this->publisher)->get(route('publisher.sites.ajax', ['status' => 'active']));
        $ajax->assertOk();
        $ajaxHtml = $ajax->getContent();
        $this->assertTrue(
            str_contains($ajaxHtml, "O'Reilly News") || str_contains($ajaxHtml, 'O&#039;Reilly News'),
            'Ajax table should include the site name'
        );
        $this->assertStringContainsString('btn-edit', $ajaxHtml);
        $this->assertStringNotContainsString('<script', $ajaxHtml);
        $this->assertStringContainsString('🇺🇸', $ajaxHtml);
        $this->assertStringContainsString('sitesStatusMeta', $ajaxHtml);
        $this->assertStringContainsString('site-row-preview', $ajaxHtml);
        $this->assertStringContainsString('data-label="Preview"', $ajaxHtml);
        $this->assertStringContainsString('>Preview</th>', $ajaxHtml);
        $this->assertStringContainsString('site-row-metrics', $ajaxHtml);
        $this->assertStringContainsString('btn-icon-quiet', $ajaxHtml);
        $this->assertStringContainsString('btn-edit', $ajaxHtml);
        $this->assertStringNotContainsString('btn-warning', $ajaxHtml);
        $this->assertStringNotContainsString('btn-outline-success', $ajaxHtml);
    }

    public function test_ajax_row_shows_screenshot_preview_when_present(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'screenshot_thumb_path' => 'sites/screenshots/thumb-demo.jpg',
            'screenshot_path' => 'sites/screenshots/demo.jpg',
        ]);

        $ajaxHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-row-preview', $ajaxHtml);
        $this->assertStringContainsString('storage/sites/screenshots/thumb-demo.jpg', $ajaxHtml);
        $this->assertStringContainsString('alt="O&#039;Reilly News preview"', $ajaxHtml);
    }

    public function test_ajax_filters_pending_and_active_sites(): void
    {
        $pending = $this->makeSite([
            'site_name' => 'Pending Site',
            'site_url' => 'https://pending-site.example',
            'domain' => 'pending-site.example',
            'verified' => false,
            'active' => false,
        ]);
        $active = $this->makeSite([
            'site_name' => 'Active Site',
            'site_url' => 'https://active-site.example',
            'domain' => 'active-site.example',
            'verified' => true,
            'active' => true,
        ]);

        $pendingHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Pending Site', $pendingHtml);
        $this->assertStringNotContainsString('Active Site', $pendingHtml);
        $this->assertStringContainsString('data-pending="1"', $pendingHtml);
        $this->assertStringContainsString('data-active="1"', $pendingHtml);

        $activeHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Active Site', $activeHtml);
        $this->assertStringNotContainsString('Pending Site', $activeHtml);
        $this->assertTrue($pending->id !== $active->id);
    }
}
