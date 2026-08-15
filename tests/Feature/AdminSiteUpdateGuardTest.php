<?php

namespace Tests\Feature;

use App\Mail\SiteStatusNotification;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteDescriptionRules;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminSiteUpdateGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Guard Site',
            'site_url' => 'https://guard-site.example',
            'domain' => 'guard-site.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Admin update guard listing description. ', 3),
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_update_cannot_flip_active_or_verified(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Guard Site',
                'site_url' => 'https://guard-site.example',
                'da' => 40,
                'dr' => 42,
                'traffic' => 15000,
                'active' => 0,
                'verified' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
    }

    public function test_update_cannot_activate_or_verify_a_pending_site(): void
    {
        $site = $this->site([
            'site_name' => 'Pending Guard',
            'site_url' => 'https://pending-guard.example',
            'domain' => 'pending-guard.example',
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'da' => 41,
                'active' => 1,
                'verified' => 1,
            ])
            ->assertOk();

        $site->refresh();
        $this->assertFalse((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
        $this->assertSame(41, (int) $site->da);
    }

    public function test_update_rejects_duplicate_domain(): void
    {
        $this->site();
        $other = $this->site([
            'site_name' => 'Other Guard',
            'site_url' => 'https://other-guard.example',
            'domain' => 'other-guard.example',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $other->id), [
                'site_url' => 'https://www.guard-site.example/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('other-guard.example', $other->fresh()->domain);
    }

    public function test_update_rejects_retarget_onto_pending_bulk_domain(): void
    {
        $site = $this->site();
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://pending-bulk.example',
            'domain' => 'pending-bulk.example',
            'price' => 40,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://www.pending-bulk.example/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('guard-site.example', $site->fresh()->domain);
    }

    public function test_update_rejects_url_change_on_bulk_attached_draft(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
        ]);
        $site = $this->site([
            'site_name' => 'Bulk Draft',
            'site_url' => 'https://bulk-draft.example',
            'domain' => 'bulk-draft.example',
            'verified' => false,
            'active' => false,
            'bulk_site_request_id' => $bulk->id,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://bulk-draft.example',
            'domain' => 'bulk-draft.example',
            'price' => 40,
            'site_id' => $site->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://retargeted-draft.example',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('bulk-draft.example', $site->fresh()->domain);
        $this->assertSame('bulk-draft.example', BulkSiteRequestItem::query()->where('site_id', $site->id)->value('domain'));
    }

    public function test_update_allows_metrics_when_site_already_matches_leftover_pending_domain(): void
    {
        $site = $this->site([
            'site_name' => 'Leftover Domain',
            'site_url' => 'https://leftover-pending.example',
            'domain' => 'leftover-pending.example',
        ]);
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://leftover-pending.example',
            'domain' => 'leftover-pending.example',
            'price' => 40,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://www.leftover-pending.example',
                'da' => 55,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('leftover-pending.example', $site->domain);
        $this->assertSame(55, (int) $site->da);
    }

    public function test_update_rejects_trailing_dot_duplicate_domain(): void
    {
        $this->site();
        $other = $this->site([
            'site_name' => 'Other Guard',
            'site_url' => 'https://other-guard.example',
            'domain' => 'other-guard.example',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $other->id), [
                'site_url' => 'https://www.guard-site.example./path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('other-guard.example', $other->fresh()->domain);
    }

    public function test_update_ignores_array_shaped_domain_field(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'domain' => ['poison-domain.example'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('guard-site.example', $site->fresh()->domain);
        $this->assertNull(Site::where('domain', 'array')->first());
    }

    public function test_update_rejects_invalid_metrics(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'da' => 101,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['da']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'dr' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dr']);

        $this->assertSame(40, (int) $site->fresh()->da);
        $this->assertSame(42, (int) $site->fresh()->dr);
    }

    public function test_update_array_language_and_description_do_not_500(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'language' => ['de'],
                'description' => ['Too short'],
            ])
            ->assertStatus(422);

        $this->assertSame('de', $site->fresh()->language);
        $this->assertStringContainsString('Admin update guard', (string) $site->fresh()->description);
    }

    public function test_update_rejects_short_description_when_sent(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'description' => 'Too short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_update_rejects_invalid_country_language_pair(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'country' => 'de',
                'language' => 'en',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['language']);

        $this->assertSame('de', $site->fresh()->language);
    }

    public function test_partial_metrics_update_still_succeeds(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Renamed Guard',
                'da' => 55,
                'dr' => 60,
                'traffic' => 20000,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('Renamed Guard', $site->site_name);
        $this->assertSame(55, (int) $site->da);
        $this->assertSame(60, (int) $site->dr);
        $this->assertSame(20000, (int) $site->traffic);
        $this->assertTrue((bool) $site->metrics_manual);
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
    }

    public function test_update_keeps_existing_site_image_path_string(): void
    {
        $site = $this->site([
            'site_image' => 'sites/existing.jpg',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => 'sites/existing.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('sites/existing.jpg', $site->fresh()->site_image);
    }

    public function test_update_ignores_array_shaped_site_image_path(): void
    {
        $site = $this->site([
            'site_image' => 'sites/existing.jpg',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => ['sites/evil.jpg'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('sites/existing.jpg', $site->fresh()->site_image);
        $this->assertNull(Site::where('site_image', 'Array')->first());
    }

    public function test_update_accepts_free_text_link_type_from_dedicated_editor(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'link_type' => 'Guest Post',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Guest Post', $site->fresh()->link_type);
        $this->assertTrue(Site::ensureLinkTypeColumn());
    }

    public function test_update_syncs_categories_json_from_category_field(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => 'Business & Finance',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('Business & Finance', $site->category);
        $this->assertSame(['Business & Finance'], $site->categories);
    }

    public function test_update_keeps_secondary_niches_when_primary_category_is_resent(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News', 'Business & Finance'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => 'News',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('News', $site->category);
        $this->assertSame(['News', 'Business & Finance'], $site->categories);
    }

    public function test_update_replaces_primary_niche_without_dropping_others(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News', 'Technology & Gadgets'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => 'Business & Finance',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('Business & Finance', $site->category);
        $this->assertSame(['Business & Finance', 'Technology & Gadgets'], $site->categories);
    }

    public function test_update_ignores_blank_category_and_keeps_niches(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News', 'Business & Finance'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'category' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('News', $site->category);
        $this->assertSame(['News', 'Business & Finance'], $site->categories);
    }

    public function test_update_keeps_categories_when_category_is_omitted(): void
    {
        $site = $this->site([
            'category' => 'News',
            'categories' => ['News'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'da' => 55,
            ])
            ->assertOk();

        $site->refresh();
        $this->assertSame(55, (int) $site->da);
        $this->assertSame('News', $site->category);
        $this->assertSame(['News'], $site->categories);
    }

    public function test_update_clears_empty_country_and_language(): void
    {
        $site = $this->site([
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'country' => '',
                'language' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue(blank($site->country));
        $this->assertTrue(blank($site->language));
        $this->assertEmpty($site->countries);
        $this->assertEmpty($site->languages);
    }

    public function test_update_clears_empty_description_and_example_url(): void
    {
        $site = $this->site([
            'description' => str_repeat('Admin update guard listing description. ', 3),
            'example_url' => 'https://guard-site.example/sample',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'description' => '',
                'example_url' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue(blank($site->description));
        $this->assertTrue(blank($site->example_url));
    }

    public function test_update_rejects_description_over_word_max(): void
    {
        $site = $this->site();
        $tooLong = implode(' ', array_fill(0, SiteDescriptionRules::MAX_WORDS + 1, 'word'));

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'description' => $tooLong,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);

        $this->assertSame(
            str_repeat('Admin update guard listing description. ', 3),
            $site->fresh()->description
        );
    }

    public function test_update_rejects_array_shaped_homepage_fee(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->from(route('admin.sites.edit', $site->id))
            ->put(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'placement_offers_form' => 1,
                'homepage' => ['7' => '1'],
                'price_homepage' => ['7' => ['25']],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('price_homepage.7');

        $this->assertNull($site->fresh()->homepage_placement_prices);
    }

    public function test_edit_page_survives_array_homepage_price_old_input(): void
    {
        $site = $this->site([
            'homepage_placement_prices' => ['7' => 25],
        ]);

        $this->actingAs($this->admin)
            ->withSession([
                '_old_input' => [
                    'price_homepage' => ['7' => ['25']],
                    'language' => ['de'],
                    'country' => ['de'],
                    'categories' => 1,
                ],
            ])
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Edit site', false)
            ->assertDontSee('htmlspecialchars', false)
            ->assertSee('value="25"', false)
            ->assertDontSee('type="url"', false)
            ->assertSee('type="text" id="site_url"', false);
    }

    public function test_update_ignores_array_shaped_category(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'category' => ['News', 'Tech'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('News', $site->fresh()->category);
    }

    public function test_update_rejects_array_shaped_geo_and_description_without_500(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'country' => [''],
                'language' => [['de']],
                'description' => ['Poisoned description that is long enough to look real.'],
                'link_type' => ['dofollow'],
            ])
            ->assertStatus(422);

        $site->refresh();
        $this->assertSame('de', $site->country);
        $this->assertSame('de', $site->language);
        $this->assertSame(
            str_repeat('Admin update guard listing description. ', 3),
            $site->description
        );
        $this->assertSame('dofollow', $site->link_type);
    }

    public function test_update_accepts_nested_country_array(): void
    {
        $site = $this->site([
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'country' => [['de']],
                'language' => 'de',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('de', $site->country);
        $this->assertSame(['de'], $site->countries);
    }

    public function test_update_rejects_port_duplicate_domain(): void
    {
        $this->site();
        $other = $this->site([
            'site_name' => 'Other Guard',
            'site_url' => 'https://other-guard.example',
            'domain' => 'other-guard.example',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $other->id), [
                'site_url' => 'https://www.guard-site.example:443/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('other-guard.example', $other->fresh()->domain);
    }

    public function test_update_persists_url_host_not_posted_domain(): void
    {
        $this->site();
        $other = $this->site([
            'site_name' => 'Other Guard',
            'site_url' => 'https://other-guard.example',
            'domain' => 'other-guard.example',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $other->id), [
                'site_url' => 'https://brand-new-guard.example/path',
                'domain' => 'guard-site.example',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $other->refresh();
        $this->assertSame('brand-new-guard.example', $other->domain);
        $this->assertSame('https://brand-new-guard.example/path', $other->site_url);
        $this->assertSame(1, Site::where('domain', 'guard-site.example')->count());
    }

    public function test_update_rejects_legacy_www_domain_collision(): void
    {
        $this->site([
            'site_name' => 'Www Guard',
            'site_url' => 'https://www.legacy-www.example',
            'domain' => 'www.legacy-www.example',
        ]);
        $other = $this->site([
            'site_name' => 'Other Guard',
            'site_url' => 'https://other-guard.example',
            'domain' => 'other-guard.example',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $other->id), [
                'site_url' => 'https://legacy-www.example/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('other-guard.example', $other->fresh()->domain);
    }

    public function test_update_replaces_cover_only_after_save(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/old-cover.jpg', 'old');
        $site = $this->site([
            'site_image' => 'sites/old-cover.jpg',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.sites.edit', $site->id))
            ->put(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => UploadedFile::fake()->image('new-cover.jpg', 40, 40),
            ])
            ->assertRedirect();

        $site->refresh();
        $this->assertNotSame('sites/old-cover.jpg', $site->site_image);
        $this->assertTrue(Storage::disk('public')->exists((string) $site->site_image));
        $this->assertFalse(Storage::disk('public')->exists('sites/old-cover.jpg'));
    }

    public function test_update_strips_url_userinfo_and_rejects_ftp(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://user:secret@guard-site.example/path',
                'example_url' => 'https://user:secret@guard-site.example/sample',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('https://guard-site.example/path', $site->site_url);
        $this->assertSame('https://guard-site.example/sample', $site->example_url);
        $this->assertSame('guard-site.example', $site->domain);
        $this->assertStringNotContainsString('secret', (string) $site->site_url);
        $this->assertStringNotContainsString('secret', (string) $site->example_url);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'ftp://guard-site.example/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('https://guard-site.example/path', $site->fresh()->site_url);
    }

    public function test_update_rejects_invalid_example_url_without_clearing(): void
    {
        $site = $this->site([
            'example_url' => 'https://guard-site.example/sample',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://guard-site.example/path',
                'example_url' => 'javascript:alert(1)',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['example_url']);

        $this->assertSame('https://guard-site.example/sample', $site->fresh()->example_url);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://guard-site.example/path',
                'example_url' => 'ftp://guard-site.example/sample',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['example_url']);

        $this->assertSame('https://guard-site.example/sample', $site->fresh()->example_url);
    }

    public function test_update_can_clear_example_url(): void
    {
        $site = $this->site([
            'example_url' => 'https://guard-site.example/sample',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://guard-site.example/path',
                'example_url' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($site->fresh()->example_url);
    }

    public function test_update_ignores_posted_domain_without_site_url(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'domain' => 'stolen-domain.example',
                'da' => 41,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('guard-site.example', $site->domain);
        $this->assertSame('https://guard-site.example', $site->site_url);
        $this->assertSame(41, (int) $site->da);
        $this->assertNull(Site::where('domain', 'stolen-domain.example')->first());
    }

    public function test_update_accepts_protocol_relative_and_host_port_urls(): void
    {
        $site = $this->site([
            'example_url' => 'https://guard-site.example/sample',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => '//guard-site.example/path',
                'example_url' => '//guard-site.example/sample',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('https://guard-site.example/path', $site->site_url);
        $this->assertSame('https://guard-site.example/sample', $site->example_url);
        $this->assertSame('guard-site.example', $site->domain);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'guard-site.example:8080/x',
                'example_url' => 'guard-site.example:8080/sample',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame('https://guard-site.example:8080/x', $site->site_url);
        $this->assertSame('https://guard-site.example:8080/sample', $site->example_url);
        $this->assertSame('guard-site.example', $site->domain);
    }

    public function test_update_ignores_remote_or_non_sites_image_path(): void
    {
        $site = $this->site([
            'site_image' => 'sites/existing.jpg',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => 'https://evil.example/phish.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('sites/existing.jpg', $site->fresh()->site_image);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => 'avatars/other.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('sites/existing.jpg', $site->fresh()->site_image);
        $this->assertNull(Site::where('site_image', 'https://evil.example/phish.jpg')->first());
    }

    public function test_update_ignores_other_listing_cover_path(): void
    {
        $site = $this->site([
            'site_image' => 'sites/existing.jpg',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => 'sites/other-cover.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('sites/existing.jpg', $site->fresh()->site_image);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'site_image' => 'sites/malware.php',
            ])
            ->assertOk();

        $this->assertSame('sites/existing.jpg', $site->fresh()->site_image);
    }

    public function test_update_rejects_localhost_and_ip_hosts(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://localhost/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://127.0.0.1/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://127.1/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://guard-site.example:0/path',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_url']);

        $this->assertSame('https://guard-site.example', $site->fresh()->site_url);
    }

    public function test_update_rejects_example_url_on_other_domain(): void
    {
        $site = $this->site([
            'example_url' => 'https://guard-site.example/sample',
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_url' => 'https://guard-site.example/path',
                'example_url' => 'https://other-host.example/sample',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['example_url']);

        $this->assertSame('https://guard-site.example/sample', $site->fresh()->example_url);
    }

    public function test_update_does_not_email_for_country_language_case_only_change(): void
    {
        Mail::fake();

        $site = $this->site([
            'country' => 'DE',
            'language' => 'DE',
            'countries' => ['DE'],
            'languages' => ['DE'],
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Guard Site',
                'site_url' => 'https://guard-site.example',
                'da' => 40,
                'dr' => 42,
                'traffic' => 15000,
                'price' => 80,
                'country' => 'de',
                'language' => 'de',
            ])
            ->assertOk()
            ->assertJsonPath('email_sent', false);

        Mail::assertNotQueued(SiteStatusNotification::class);
        Mail::assertNothingOutgoing();
        $site->refresh();
        $this->assertSame('de', $site->country);
        $this->assertSame('de', $site->language);
    }
}
