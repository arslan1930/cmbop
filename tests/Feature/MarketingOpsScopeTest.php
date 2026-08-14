<?php

namespace Tests\Feature;

use App\Mail\SiteStatusNotification;
use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_marketer_cannot_verify_but_can_activate_sites(): void
    {
        $site = $this->makeSite([
            'da' => 30,
            'dr' => 30,
            'traffic' => 10000,
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertForbidden();

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
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

    public function test_admin_cannot_activate_awaiting_details_site(): void
    {
        $site = $this->makeSite([
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'active' => false,
            'verified' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $site->refresh();
        $this->assertFalse((bool) $site->active);
        $this->assertTrue($site->awaitsPublisherDetails());
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
        $this->assertStringContainsString('CAN_TOGGLE_ACTIVE = true', $html);
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
        $this->assertStringContainsString('dashboard\\/queue-counts', $html);
        $this->assertStringContainsString('refreshAdminQueueBadges', $html);
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

        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Fill metrics, geo & niches')
            ->assertSee('Fix the URL, price, or metrics if needed', false)
            ->assertSee('Metrics, geo, and niche-only saves do not email the publisher.', false)
            ->assertSee('Below the quality bar', false)
            ->assertSee('https://pending-edit.example', false)
            ->assertDontSee('name="description"', false)
            ->assertSee('name="site_name"', false)
            ->assertSee('name="site_url"', false)
            ->assertSee('name="example_url"', false)
            ->assertSee('name="price"', false)
            ->assertSee('name="language"', false)
            ->assertSee('name="da"', false)
            ->assertSee('name="categories"', false)
            ->assertSee('name="site_image"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->getContent();

        unset($html);

        $sitesHtml = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('IS_MARKETING_EDITOR = true', $sitesHtml);
        $this->assertStringContainsString('${STAFF_BASE}/sites/${site.id}/edit', $sitesHtml);
        $this->assertStringContainsString('site-row-preview', $sitesHtml);
        $this->assertStringContainsString('sitePreviewPaths', $sitesHtml);
        $this->assertStringContainsString('initSitePreviewZoom', $sitesHtml);
        $this->assertStringContainsString('preview_thumb_url', $sitesHtml);

        // Preview sizing moved from an inline block into the shared stylesheet.
        $this->assertStringContainsString('staff-sites.css', $sitesHtml);
        $staffCss = file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('--site-preview-ratio: 16 / 10', $staffCss);
        $this->assertStringNotContainsString('site-thumbnail', $sitesHtml);
        $this->assertStringNotContainsString('editSiteMarketingSlim', $sitesHtml);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'site_name' => 'Corrected Name',
                'site_url' => 'https://corrected-edit.example',
                'example_url' => 'https://corrected-edit.example/sample',
                'price' => 120,
                'description' => 'Hacked description that marketers must not set',
                'da' => 33,
                'dr' => 44,
                'traffic' => 5000,
                'language' => 'de',
                'country' => 'de',
                'categories' => $category->name,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]));

        $site->refresh();
        $this->assertSame('Corrected Name', $site->site_name);
        $this->assertSame('https://corrected-edit.example', $site->site_url);
        $this->assertSame('corrected-edit.example', $site->domain);
        $this->assertSame('https://corrected-edit.example/sample', $site->example_url);
        $this->assertEquals(120, (float) $site->price);
        $this->assertSame('Publisher will replace this later with enough characters', $site->description);
        $this->assertSame(33, (int) $site->da);
        $this->assertSame(44, (int) $site->dr);
        $this->assertSame(5000, (int) $site->traffic);
        $this->assertSame('de', $site->language);
        $this->assertSame('de', $site->country);
        $this->assertSame(['de'], $site->languages);
        $this->assertSame(['de'], $site->countries);
        $this->assertContains($category->name, $site->categories ?? []);
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
            ->assertDontSee('Publisher already provided URL and price', false)
            ->assertDontSee('Fix the URL, price, or metrics if needed', false)
            ->assertDontSee('Marketing cannot change it', false);
    }

    public function test_marketer_sees_read_only_edit_page_for_live_site(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Live Locked Site',
            'site_url' => 'https://live-locked.example',
            'domain' => 'live-locked.example',
            'da' => 55,
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('View site', false)
            ->assertSee('This listing is live, verified, or archived. Marketing cannot change it.', false)
            ->assertSee('https://live-locked.example', false)
            ->assertDontSee('name="site_url"', false)
            ->assertDontSee('name="price"', false)
            ->assertDontSee('name="da"', false)
            ->assertDontSee('Save listing', false);
    }

    public function test_marketer_cannot_update_live_or_verified_site(): void
    {
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $live = $this->makeSite([
            'site_name' => 'Already Live',
            'site_url' => 'https://already-live.example',
            'domain' => 'already-live.example',
            'da' => 60,
            'price' => 200,
            'verified' => false,
            'active' => true,
        ]);
        $verified = $this->makeSite([
            'site_name' => 'Already Verified',
            'site_url' => 'https://already-verified.example',
            'domain' => 'already-verified.example',
            'da' => 61,
            'price' => 210,
            'verified' => true,
            'active' => false,
        ]);

        foreach ([$live, $verified] as $site) {
            $originalDa = (int) $site->da;
            $originalUrl = $site->site_url;

            $this->actingAs($this->marketer)
                ->put(route('marketing.sites.update', $site->id), [
                    'site_name' => 'Should Not Stick',
                    'site_url' => 'https://should-not-stick.example',
                    'price' => 1,
                    'da' => 11,
                    'dr' => 12,
                    'traffic' => 100,
                    'language' => 'de',
                    'country' => 'de',
                    'categories' => $category->name,
                ])
                ->assertRedirect(route('marketing.sites.edit', $site->id))
                ->assertSessionHasErrors('site_url');

            $this->actingAs($this->marketer)
                ->putJson(route('marketing.sites.update', $site->id), [
                    'site_name' => 'Should Not Stick',
                    'site_url' => 'https://should-not-stick.example',
                    'price' => 1,
                    'da' => 11,
                    'dr' => 12,
                    'traffic' => 100,
                    'language' => 'de',
                    'country' => 'de',
                    'categories' => $category->name,
                ])
                ->assertForbidden()
                ->assertJsonPath('success', false);

            $site->refresh();
            $this->assertSame($originalUrl, $site->site_url);
            $this->assertSame($originalDa, (int) $site->da);
        }
    }

    public function test_marketer_cannot_update_archived_site(): void
    {
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $site = $this->makeSite([
            'site_name' => 'Archived Pending',
            'site_url' => 'https://archived-pending.example',
            'domain' => 'archived-pending.example',
            'da' => 40,
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('This listing is live, verified, or archived. Marketing cannot change it.', false)
            ->assertDontSee('name="site_url"', false);

        $this->actingAs($this->marketer)
            ->putJson(route('marketing.sites.update', $site->id), [
                'site_name' => 'Should Not Stick',
                'site_url' => 'https://should-not-stick.example',
                'price' => 1,
                'da' => 11,
                'dr' => 12,
                'traffic' => 100,
                'language' => 'de',
                'country' => 'de',
                'categories' => $category->name,
            ])
            ->assertForbidden();

        $this->assertSame('https://archived-pending.example', $site->fresh()->site_url);
        $this->assertSame(40, (int) $site->fresh()->da);
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

    public function test_admin_site_edit_back_links_to_publisher_sites_not_previous_url(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Back Link Site',
            'site_url' => 'https://back-link.example',
            'domain' => 'back-link.example',
        ]);

        $expectedBack = staff_route('sites.index', [
            'publisher' => $site->publisher_id,
            'site' => $site->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('href="'.e($expectedBack).'"', false)
            ->assertDontSee('url()->previous', false);

        // After a save redirect, Back must still target the publisher panel (not loop on edit).
        $this->actingAs($this->admin)
            ->from(route('admin.sites.edit', $site->id))
            ->put(route('admin.sites.update', $site->id), [
                'site_name' => 'Back Link Site',
                'site_url' => 'https://back-link.example',
                'da' => 40,
                'dr' => 45,
                'traffic' => 12000,
            ])
            ->assertRedirect(route('admin.sites.edit', $site->id));

        $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('href="'.e($expectedBack).'"', false);
    }

    public function test_handbook_includes_marketing_catalog_ops_section(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.staff-handbook'))
            ->assertOk()
            ->assertSee('Marketing catalog ops', false)
            ->assertSee('DA ≥ 30', false)
            ->assertSee('do not email the publisher', false)
            ->assertSee('flat review queue', false)
            ->getContent();

        $this->assertStringNotContainsString('messages.staff_handbook_section6', $html);
    }

    public function test_marketing_niche_or_metrics_save_does_not_email_publisher(): void
    {
        Mail::fake();
        $category = Category::query()->where('name', 'News')->first()
            ?? Category::query()->firstOrFail();
        $site = $this->makeSite([
            'site_name' => 'Notify Skip Site',
            'site_url' => 'https://notify-skip.example',
            'domain' => 'notify-skip.example',
            'category' => $category->name,
            'categories' => [$category->name],
            'price' => 40,
        ]);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'da' => 41,
                'dr' => 52,
                'traffic' => 18000,
                'language' => 'en',
                'country' => 'us',
                'categories' => $category->name,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]));

        Mail::assertNothingOutgoing();
    }

    public function test_marketing_price_change_emails_publisher(): void
    {
        Mail::fake();
        $category = Category::query()->where('name', 'News')->first()
            ?? Category::query()->firstOrFail();
        $site = $this->makeSite([
            'site_name' => 'Notify Price Site',
            'site_url' => 'https://notify-price.example',
            'domain' => 'notify-price.example',
            'category' => $category->name,
            'categories' => [$category->name],
            'price' => 40,
        ]);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'da' => 20,
                'dr' => 20,
                'traffic' => 1000,
                'language' => 'en',
                'country' => 'us',
                'categories' => $category->name,
                'price' => 99,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]));

        Mail::assertQueued(SiteStatusNotification::class, function (SiteStatusNotification $mail) use ($site) {
            return $mail->hasTo($site->publisher->email) && $mail->action === 'update';
        });
    }

    public function test_admin_metrics_change_still_emails_publisher(): void
    {
        Mail::fake();
        $site = $this->makeSite([
            'site_name' => 'Admin Notify Site',
            'site_url' => 'https://admin-notify.example',
            'domain' => 'admin-notify.example',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'da' => 55,
                'dr' => 20,
                'traffic' => 1000,
            ])
            ->assertRedirect(route('admin.sites.edit', $site->id));

        Mail::assertQueued(SiteStatusNotification::class, function (SiteStatusNotification $mail) use ($site) {
            return $mail->hasTo($site->publisher->email) && $mail->action === 'update';
        });
    }
}
