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

class MarketingOpsScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Ops Scope Site',
            'site_url' => 'https://ops-scope.example',
            'domain' => 'ops-scope.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Scope test site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_marketer_cannot_verify_or_activate_sites(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->marketer)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertForbidden();

        $this->actingAs($this->marketer)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertForbidden();

        $site->refresh();
        $this->assertFalse((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
    }

    public function test_admin_can_still_verify_and_activate_sites(): void
    {
        $site = $this->makeSite();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
    }

    public function test_marketer_is_blocked_from_non_ops_admin_tools(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('admin.site-ratings.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->get(route('admin.community.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->get(route('admin.activity-logs.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertForbidden();

        $this->actingAs($this->marketer)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertForbidden();

        $this->actingAs($this->marketer)
            ->get(route('admin.users.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->get(route('admin.payments'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_marketer_uses_marketing_url_prefix_not_admin(): void
    {
        $this->assertStringContainsString('/marketing/', route('marketing.dashboard', [], false));
        $this->assertStringNotContainsString('/admin/', route('marketing.sites.index', [], false));

        $this->actingAs($this->marketer)
            ->get('/admin/dashboard')
            ->assertRedirect('/marketing/dashboard');

        $this->actingAs($this->marketer)
            ->get('/admin/sites')
            ->assertRedirect('/marketing/sites');
    }

    public function test_marketer_can_open_ops_pages_and_sites_ui_hides_verify_active(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Marketing workspace', false)
            ->assertSee('My task history', false)
            ->assertDontSee('Activity History', false);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('CAN_VERIFY_SITES = false', $html);
        $this->assertStringContainsString('CAN_TOGGLE_ACTIVE = false', $html);
        $this->assertStringContainsString('CAN_DELETE_PENDING_SITES = true', $html);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index'))
            ->assertOk();

        // Enrichment remains reachable by URL for ops, but is not linked in the marketing UI.
        $this->actingAs($this->marketer)
            ->get(route('marketing.site-enrichment.index'))
            ->assertOk();
    }

    public function test_marketer_nav_excludes_ratings_community_activity(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('admin.site-ratings.index'), $html);
        $this->assertStringNotContainsString(route('admin.community.index'), $html);
        $this->assertStringNotContainsString(route('admin.activity-logs.index'), $html);
        $this->assertStringContainsString(route('marketing.sites.index'), $html);
        $this->assertStringContainsString(route('marketing.bulk-site-requests.index'), $html);
        $this->assertStringNotContainsString(route('marketing.site-enrichment.index'), $html);
        $this->assertStringNotContainsString('>Enrichment</span>', $html);
        $this->assertStringContainsString(route('marketing.history'), $html);
        $this->assertStringContainsString('role-shell-marketing', $html);
    }

    public function test_marketer_can_open_and_update_site_edit_page(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Pending Edit Target',
            'site_url' => 'https://pending-edit.example',
            'domain' => 'pending-edit.example',
            'price' => 99.5,
            'description' => 'Publisher will replace this later with enough characters',
        ]);

        $this->assertFileExists(resource_path('views/admin/site-edit.blade.php'));

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Fill metrics & geo')
            ->assertSee('Publisher already provided URL and price', false)
            ->assertSee('https://pending-edit.example', false)
            ->assertSee('€99.50', false)
            ->assertDontSee('name="description"', false)
            ->assertDontSee('name="example_url"', false)
            ->assertDontSee('name="site_url"', false)
            ->assertDontSee('name="price"', false)
            ->assertSee('name="language"', false)
            ->assertSee('name="da"', false)
            ->getContent();

        unset($html);

        $sitesHtml = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('IS_MARKETING_EDITOR = true', $sitesHtml);
        $this->assertStringContainsString("\${STAFF_BASE}/sites/\${site.id}/edit", $sitesHtml);
        $this->assertStringContainsString('site-row-preview', $sitesHtml);
        $this->assertStringContainsString('--site-preview-ratio: 16 / 10', $sitesHtml);
        $this->assertStringContainsString('sitePreviewPaths', $sitesHtml);
        $this->assertStringNotContainsString('site-thumbnail', $sitesHtml);
        $this->assertStringNotContainsString('editSiteMarketingSlim', $sitesHtml);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'site_name' => 'Hacked Name',
                'site_url' => 'https://hacked.example',
                'price' => 1,
                'description' => 'Hacked description that marketers must not set',
                'da' => 33,
                'dr' => 44,
                'traffic' => 5000,
                'language' => 'de',
                'country' => 'de',
            ])
            ->assertRedirect(route('marketing.sites.edit', $site->id));

        $site->refresh();
        $this->assertSame('Pending Edit Target', $site->site_name);
        $this->assertSame('https://pending-edit.example', $site->site_url);
        $this->assertEquals(99.5, (float) $site->price);
        $this->assertSame('Publisher will replace this later with enough characters', $site->description);
        $this->assertSame(33, (int) $site->da);
        $this->assertSame(44, (int) $site->dr);
        $this->assertSame(5000, (int) $site->traffic);
        $this->assertSame('de', $site->language);
        $this->assertSame('de', $site->country);
        $this->assertSame(['de'], $site->languages);
        $this->assertSame(['de'], $site->countries);
    }

    public function test_admin_edit_page_still_shows_full_form(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Admin Full Edit',
            'site_url' => 'https://admin-full-edit.example',
            'domain' => 'admin-full-edit.example',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Edit site', false)
            ->assertSee('name="site_name"', false)
            ->assertSee('name="site_url"', false)
            ->assertSee('name="description"', false)
            ->assertSee('name="example_url"', false)
            ->assertDontSee('Publisher already provided URL and price', false);
    }

    public function test_site_edit_falls_back_to_sites_ui_when_blade_missing(): void
    {
        $site = $this->makeSite();
        $path = resource_path('views/admin/site-edit.blade.php');
        $backup = $path.'.bak-test';

        $this->assertTrue(rename($path, $backup));

        try {
            $this->actingAs($this->marketer)
                ->get(route('marketing.sites.edit', $site->id))
                ->assertRedirect(route('marketing.sites.index', [
                    'publisher' => $site->publisher_id,
                    'edit_site' => $site->id,
                ]));
        } finally {
            rename($backup, $path);
        }
    }
}
