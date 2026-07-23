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

    public function test_my_sites_page_and_ajax_table_render(): void
    {
        Site::create([
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
        $this->assertSame(1, substr_count($html, 'const claimCard'));

        $ajax = $this->actingAs($this->publisher)->get(route('publisher.sites.ajax'));
        $ajax->assertOk();
        $ajaxHtml = $ajax->getContent();
        $this->assertTrue(
            str_contains($ajaxHtml, "O'Reilly News") || str_contains($ajaxHtml, 'O&#039;Reilly News'),
            'Ajax table should include the site name'
        );
        $this->assertStringContainsString('btn-edit', $ajaxHtml);
        $this->assertStringNotContainsString('<script', $ajaxHtml);
        $this->assertStringContainsString('🇺🇸', $ajaxHtml);
    }
}
