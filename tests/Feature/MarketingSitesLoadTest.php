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

class MarketingSitesLoadTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Load Me',
            'site_url' => 'https://load-me.example',
            'domain' => 'load-me.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Load sites regression',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);
    }

    public function test_marketer_user_sites_json_uses_marketing_base(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        // @json() escapes the slash: "\/marketing"
        $this->assertMatchesRegularExpression('/STAFF_BASE\s*=\s*"\\\\?\/marketing"/', $html);
        $this->assertStringContainsString("'Accept': 'application/json'", $html);
        $this->assertStringContainsString('Publisher not found', $html);
        $this->assertStringContainsString('sessionStorage.removeItem(\'selected_user\')', $html);
        $this->assertStringContainsString('QUALITY_MIN_DA', $html);
        $this->assertStringContainsString('sitesLoadMore', $html);
        $this->assertStringContainsString('const FLAT_QUEUE', $html);
        $this->assertStringContainsString('data?.meta', $html);
        $this->assertStringContainsString('if (pageNum <= 1)', $html);

        $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('publisher.id', $this->publisher->id)
            ->assertJsonPath('sites.0.domain', 'load-me.example')
            ->assertJsonStructure([
                'publisher' => ['id', 'name', 'email'],
                'sites' => [[
                    'id',
                    'needs_review',
                    'missing_market',
                    'below_quality_bar',
                    'awaits_publisher_details',
                    'details_complete',
                    'preview_thumb_url',
                    'preview_full_url',
                    'preview_fallback_urls',
                ]],
                'meta' => ['current_page', 'last_page', 'total', 'per_page'],
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_marketer_user_sites_paginates_after_fifty_rows(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            Site::create([
                'publisher_id' => $this->publisher->id,
                'site_name' => 'Paged Site '.$i,
                'site_url' => 'https://paged-'.$i.'.example',
                'domain' => 'paged-'.$i.'.example',
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => 'us',
                'language' => 'en',
                'price' => 40,
                'publication_time' => 'permanent',
                'description' => 'Pagination fixture',
                'link_type' => 'dofollow',
                'verified' => false,
                'active' => false,
            ]);
        }

        $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonCount(50, 'sites');

        $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $this->publisher->id).'?page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'sites');
    }

    public function test_missing_publisher_returns_json_404_instead_of_html(): void
    {
        $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', 999999))
            ->assertNotFound()
            ->assertJsonPath('message', 'Publisher not found')
            ->assertJsonPath('sites', []);
    }
}
