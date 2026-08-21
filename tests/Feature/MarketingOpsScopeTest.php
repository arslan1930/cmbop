<?php

namespace Tests\Feature;

use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteDescriptionRules;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\CountryLanguageSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->active);
        $this->assertTrue((bool) $site->verified);
    }

    public function test_marketer_cannot_open_admin_records_sheet(): void
    {
        $this->actingAs($this->marketer)
            ->get(route('admin.sites.records'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->admin)
            ->get(route('admin.sites.records'))
            ->assertOk();
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

        $this->actingAs($this->marketer)
            ->get('/admin/staff-handbook')
            ->assertRedirect('/marketing/staff-handbook');
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

    public function test_marketer_cannot_enrich_or_unlock_live_site(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Live Enrich Lock',
            'site_url' => 'https://live-enrich-lock.example',
            'domain' => 'live-enrich-lock.example',
            'verified' => true,
            'active' => true,
            'metrics_manual' => true,
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.enrich', $site->id))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.allow-api-metrics', $site->id))
            ->assertForbidden();

        $this->assertTrue((bool) $site->fresh()->metrics_manual);
    }

    public function test_marketer_queue_stale_skips_live_listings(): void
    {
        Queue::fake();
        $this->makeSite([
            'site_name' => 'Live Stale',
            'site_url' => 'https://live-stale.example',
            'domain' => 'live-stale.example',
            'verified' => true,
            'active' => true,
            'metrics_fetched_at' => null,
            'screenshot_path' => null,
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.site-enrichment.queue-stale'), ['limit' => 5])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 0);

        Queue::assertNotPushed(EnrichSiteJob::class);
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
            ->assertSee('Fix the URL, price, description, or metrics if needed', false)
            ->assertSee('Metrics, geo, and niche-only saves do not email the publisher.', false)
            ->assertSee('Below the quality bar', false)
            ->assertSee('https://pending-edit.example', false)
            ->assertSee('name="description"', false)
            ->assertSee('data-site-description-editor', false)
            ->assertDontSee('Description stays with the publisher', false)
            ->assertSee('name="site_name"', false)
            ->assertSee('name="site_url"', false)
            ->assertSee('name="example_url"', false)
            ->assertSee('name="price"', false)
            ->assertSee('name="language"', false)
            ->assertSee('name="da"', false)
            ->assertSee('name="categories"', false)
            ->assertSee('name="site_image"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('js-staff-activate-blocked', false)
            ->assertDontSee('js-staff-verify', false)
            ->assertDontSee('Verify / activate are admin-only.', false)
            ->assertSee('assets/js/staff-site-status.js', false)
            ->assertSee('Leave it empty to keep the current brief', false)
            ->getContent();

        $descriptionPos = strpos($html, 'data-site-description-editor');
        $imagePos = strpos($html, 'id="site_image"');
        $this->assertNotFalse($descriptionPos);
        $this->assertNotFalse($imagePos);
        $this->assertLessThan($imagePos, $descriptionPos, 'Description editor must sit above the cover image.');
        $this->assertSame(
            1,
            preg_match_all('/id="description"(?![\\w-])/', $html),
            'Activate #description hash must hit exactly one target.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<textarea[^>]*id="description-input"[^>]*\\srequired/i',
            $html,
            'Pending marketing save must not HTML5-block an empty brief.'
        );

        unset($html);

        $sitesHtml = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('IS_MARKETING_EDITOR = true', $sitesHtml);
        $this->assertStringContainsString('${STAFF_BASE}/sites/${site.id}/edit', $sitesHtml);
        $this->assertStringContainsString("site.archived) ? 'View' : 'Edit'", $sitesHtml);
        $this->assertStringContainsString('/edit#description', $sitesHtml);
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
                'description' => 'This listing is for your audience and the publishers who write guest posts here.',
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
        $this->assertSame(
            'This listing is for your audience and the publishers who write guest posts here.',
            $site->description
        );
        $this->assertSame(33, (int) $site->da);
        $this->assertSame(44, (int) $site->dr);
        $this->assertSame(5000, (int) $site->traffic);
        $this->assertSame('de', $site->language);
        $this->assertSame('de', $site->country);
        $this->assertSame(['de'], $site->languages);
        $this->assertSame(['de'], $site->countries);
        $this->assertContains($category->name, $site->categories ?? []);
    }

    public function test_marketer_can_save_french_listing_without_blaming_the_url(): void
    {
        $this->seed(CountryLanguageSeeder::class);

        $travel = Category::query()->where('name', 'Travel & Tourism')->first()
            ?? Category::query()->firstOrFail();
        $fashion = Category::query()->where('name', 'Fashion & Luxury')->value('name')
            ?? $travel->name;
        $beauty = Category::query()->where('name', 'Beauty & Skincare')->value('name')
            ?? $travel->name;

        $site = $this->makeSite([
            'site_name' => 'Le Blog Beauté',
            'site_url' => 'https://old-beaute.example',
            'domain' => 'old-beaute.example',
            'example_url' => 'https://old-beaute.example/sample',
            'da' => 16,
            'dr' => 28,
            'traffic' => 3,
            'country' => 'fr',
            'language' => 'fr',
            'price' => 70,
            'description' => '',
        ]);

        $brief = 'This listing is for your audience and the publishers who write guest posts here.';

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $site->id))
            ->put(route('marketing.sites.update', $site->id), [
                'site_name' => 'Le Blog Beauté',
                'site_url' => 'https://leblogbeaute.fr',
                'example_url' => 'https://leblogbeaute.fr/',
                'price' => 70,
                'description' => $brief,
                'da' => 16,
                'dr' => 28,
                'traffic' => 3,
                'language' => 'fr',
                'country' => 'fr',
                'categories' => $travel->name.'|'.$fashion.'|'.$beauty,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]))
            ->assertSessionDoesntHaveErrors('site_url');

        $site->refresh();
        $this->assertSame('https://leblogbeaute.fr', $site->site_url);
        $this->assertSame('leblogbeaute.fr', $site->domain);
        $this->assertSame('https://leblogbeaute.fr/', $site->example_url);
        $this->assertSame($brief, $site->description);
        $this->assertSame(16, (int) $site->da);
        $this->assertFalse($site->marketingCanActivate());

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('js-staff-activate-blocked', false)
            ->assertSee('Below the quality bar', false)
            ->assertSee('data-site-description-editor', false);
    }

    public function test_marketer_pending_update_keeps_brief_when_quill_posts_empty_html(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Quill Empty Target',
            'site_url' => 'https://quill-empty.example',
            'domain' => 'quill-empty.example',
            'description' => 'Publisher will replace this later with enough characters',
        ]);
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => $site->price,
                'description' => '<p><br></p>',
                'da' => 41,
                'dr' => 42,
                'traffic' => 6000,
                'language' => 'de',
                'country' => 'de',
                'categories' => $category->name,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]));

        $site->refresh();
        $this->assertSame('Publisher will replace this later with enough characters', $site->description);
        $this->assertSame(41, (int) $site->da);
        $this->assertSame(42, (int) $site->dr);
        $this->assertSame(6000, (int) $site->traffic);
    }

    public function test_marketer_pending_update_allows_unchanged_short_brief(): void
    {
        $short = 'Too short to pass min chars';
        $site = $this->makeSite([
            'site_name' => 'Short Unchanged Target',
            'site_url' => 'https://short-unchanged.example',
            'domain' => 'short-unchanged.example',
            'description' => $short,
        ]);
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => $site->price,
                'description' => '<p>'.$short.'</p>',
                'da' => 36,
                'dr' => 37,
                'traffic' => 4000,
                'language' => 'de',
                'country' => 'de',
                'categories' => $category->name,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]));

        $site->refresh();
        $this->assertSame($short, SiteDescriptionRules::plainText((string) $site->description));
        $this->assertSame(36, (int) $site->da);
    }

    public function test_pending_edit_page_survives_array_old_description(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Old Input Target',
            'site_url' => 'https://old-input-brief.example',
            'domain' => 'old-input-brief.example',
        ]);

        $this->actingAs($this->marketer)
            ->withSession([
                '_old_input' => [
                    'description' => ['<p>Poisoned description</p>'],
                    'site_name' => ['Poisoned Name'],
                ],
            ])
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('data-site-description-editor', false)
            ->assertDontSee('htmlspecialchars(): Argument #1', false)
            ->assertDontSee('TypeError', false);
    }

    public function test_marketer_pending_update_rejects_short_description(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Short Brief Target',
            'site_url' => 'https://short-brief.example',
            'domain' => 'short-brief.example',
            'description' => 'Publisher will replace this later with enough characters',
        ]);
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $site->id))
            ->put(route('marketing.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => $site->price,
                'description' => 'Too short',
                'da' => 33,
                'dr' => 44,
                'traffic' => 5000,
                'language' => 'de',
                'country' => 'de',
                'categories' => $category->name,
            ])
            ->assertRedirect(route('marketing.sites.edit', $site->id))
            ->assertSessionHasErrors('description');

        $this->assertSame(
            'Publisher will replace this later with enough characters',
            $site->fresh()->description
        );
    }

    public function test_admin_edit_page_still_shows_full_form(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Admin Full Edit',
            'site_url' => 'https://admin-full-edit.example',
            'domain' => 'admin-full-edit.example',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Edit site', false)
            ->assertSee('name="site_name"', false)
            ->assertSee('name="site_url"', false)
            ->assertSee('name="description"', false)
            ->assertSee('id="description-input"', false)
            ->assertSee('data-site-description-editor', false)
            ->assertSee('cdn.quilljs.com/1.3.6/quill.js', false)
            ->assertSee('assets/js/site-description-editor.js', false)
            ->assertSee('name="example_url"', false)
            ->assertSee('js-staff-verify', false)
            ->assertSee('js-staff-activate', false)
            ->assertDontSee('Verify / activate are admin-only.', false)
            ->assertDontSee('Publisher already provided URL and price', false)
            ->assertDontSee('Fix the URL, price, description, or metrics if needed', false)
            ->assertDontSee('Marketing cannot change it', false)
            ->assertSee('assets/js/staff-site-status.js', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<textarea[^>]+id="description-input"[^>]+hidden/i',
            $html,
            'Admin must be able to type a brief even when Quill JS does not load.'
        );
        $this->assertSame(
            1,
            preg_match_all('/id="description"(?![\\w-])/', $html),
            'Admin edit must not duplicate id="description".'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<textarea[^>]*id="description-input"[^>]*\\srequired/i',
            $html,
            'Admin save must not HTML5-block an empty brief.'
        );
        $descriptionPos = strpos($html, 'id="description-input"');
        $imagePos = strpos($html, 'id="site_image"');
        $this->assertNotFalse($descriptionPos);
        $this->assertNotFalse($imagePos);
        $this->assertLessThan($imagePos, $descriptionPos, 'Admin description must sit above the cover image.');
    }

    public function test_admin_edit_page_shows_unverify_and_deactivate_on_live_site(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Admin Live Status',
            'site_url' => 'https://admin-live-status.example',
            'domain' => 'admin-live-status.example',
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('js-staff-verify', false)
            ->assertSee('js-staff-deactivate', false)
            ->assertDontSee('js-staff-activate', false)
            ->assertDontSee('Verify / activate are admin-only.', false);
    }

    public function test_marketer_sees_description_editor_on_live_site(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Live Locked Site',
            'site_url' => 'https://live-locked.example',
            'domain' => 'live-locked.example',
            'da' => 55,
            'verified' => true,
            'active' => true,
            'description' => 'Publisher brief stays visible on the locked marketing view.',
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Edit description', false)
            ->assertSee('You can still update the advertiser-facing description.', false)
            ->assertSee('https://live-locked.example', false)
            ->assertSee('name="description"', false)
            ->assertSee('data-site-description-editor', false)
            ->assertSee('Save description', false)
            ->assertSee('js-staff-deactivate', false)
            ->assertDontSee('js-staff-verify', false)
            ->assertDontSee('name="site_url"', false)
            ->assertDontSee('name="price"', false)
            ->assertDontSee('name="da"', false)
            ->getContent();

        $this->assertSame(
            1,
            preg_match_all('/id="description"(?![\\w-])/', $html),
            'Live marketing edit must not wrap the brief in a second id="description".'
        );
    }

    public function test_marketer_can_update_description_on_live_or_verified_site(): void
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
            'description' => 'Original live listing brief that marketers can replace.',
        ]);
        $verified = $this->makeSite([
            'site_name' => 'Already Verified',
            'site_url' => 'https://already-verified.example',
            'domain' => 'already-verified.example',
            'da' => 61,
            'price' => 210,
            'verified' => true,
            'active' => false,
            'description' => 'Original verified listing brief that marketers can replace.',
        ]);

        foreach ([$live, $verified] as $site) {
            $originalDa = (int) $site->da;
            $originalUrl = $site->site_url;
            $nextBrief = 'This listing is for your audience and the publishers who write guest posts here.';

            $this->actingAs($this->marketer)
                ->put(route('marketing.sites.update', $site->id), [
                    'site_name' => 'Should Not Stick',
                    'site_url' => 'https://should-not-stick.example',
                    'price' => 1,
                    'description' => $nextBrief,
                    'da' => 11,
                    'dr' => 12,
                    'traffic' => 100,
                    'language' => 'de',
                    'country' => 'de',
                    'categories' => $category->name,
                ])
                ->assertRedirect(route('marketing.sites.index', [
                    'publisher' => $site->publisher_id,
                    'site' => $site->id,
                ]));

            $site->refresh();
            $this->assertSame($originalUrl, $site->site_url);
            $this->assertSame($originalDa, (int) $site->da);
            $this->assertSame($nextBrief, $site->description);
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
            ->assertSee('View site', false)
            ->assertSee('This listing is archived. Marketing cannot change it.', false)
            ->assertDontSee('name="site_url"', false)
            ->assertDontSee('Save description', false)
            ->assertDontSee('data-site-description-editor', false);

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

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $site->id))
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
            ->assertSessionHasErrors('save')
            ->assertSessionDoesntHaveErrors('site_url');

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
        $this->assertStringContainsString('goes to staff review', $html);
        $this->assertStringContainsString('Catalog Activate is not automatic', $html);
        $this->assertStringNotContainsString('Activate/Deactivate stay staff catalog controls after accept', $html);
    }

    public function test_marketing_create_page_uses_verify_first_copy(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertSee('Add site for publisher', false)
            ->assertSee('admin verifies first', false)
            ->assertSee('You Activate only after that', false)
            ->assertSee('DA ≥ 30', false)
            ->assertDontSee('Activate / Deactivate as usual', false)
            ->assertSee('id="selectedLanguage"', false)
            ->assertSee('Site image must be under', false)
            ->assertSee('data-site-description-editor', false)
            ->assertSee('name="description"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<select[^>]+id="language"[^>]*disabled/', $html);
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
