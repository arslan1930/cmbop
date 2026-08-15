<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherSitesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $otherPublisher;

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

        $this->otherPublisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->otherPublisher->roles()->attach($role->id);
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Sites Page Blog',
            'site_url' => 'https://sites-page-blog.example',
            'domain' => 'sites-page-blog.example',
            'example_url' => 'https://sites-page-blog.example/post',
            'da' => 40,
            'dr' => 45,
            'traffic' => 5000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'category' => $category->name,
            'categories' => [$category->name],
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'turnaround_time' => '3days',
            'description' => str_repeat('Publisher sites page test description. ', 4),
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_ajax_paginates_and_filters_without_script_tags(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeSite($this->publisher, [
                'site_name' => "Site {$i}",
                'site_url' => "https://site-{$i}.example",
                'domain' => "site-{$i}.example",
                'verified' => $i % 2 === 0,
                'active' => $i % 2 === 0,
            ]);
        }

        $page1 = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'all']))
            ->assertOk();

        $html = $page1->getContent();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('page=2', $html);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('With admin')
            ->assertDontSee('Verified · live');
    }

    public function test_update_price_keeps_verified_active(): void
    {
        $site = $this->makeSite($this->publisher, [
            'verified' => true,
            'active' => true,
            'price' => 100,
        ]);

        $this->actingAs($this->publisher)->put(route('publisher.sites.update', $site->id), [
            'exampleUrl' => $site->example_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'country' => $site->country,
            'language' => $site->language,
            'categories' => $site->categories,
            'price' => 150,
            'turnaround_time' => $site->turnaround_time,
            'publicationTime' => $site->publication_time,
            'link_type' => $site->link_type,
            'siteDescription' => $site->description,
            'site_tag' => 'as_you_prefer',
        ])->assertRedirect();

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertTrue((bool) $site->active);
        $this->assertEquals(150, (float) $site->price);
    }

    public function test_update_rejects_short_description(): void
    {
        $site = $this->makeSite($this->publisher);
        $original = $site->description;

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->put(route('publisher.sites.update', $site->id), [
                'exampleUrl' => $site->example_url,
                'da' => $site->da,
                'dr' => $site->dr,
                'traffic' => $site->traffic,
                'country' => $site->country,
                'language' => $site->language,
                'categories' => $site->categories,
                'price' => $site->price,
                'turnaround_time' => $site->turnaround_time,
                'publicationTime' => $site->publication_time,
                'link_type' => $site->link_type,
                'siteDescription' => 'Too short',
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('siteDescription');

        $this->assertSame($original, $site->fresh()->description);
    }

    public function test_update_rejects_array_shaped_description_without_500(): void
    {
        $site = $this->makeSite($this->publisher);
        $original = $site->description;

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->put(route('publisher.sites.update', $site->id), [
                'exampleUrl' => $site->example_url,
                'da' => $site->da,
                'dr' => $site->dr,
                'traffic' => $site->traffic,
                'country' => [['de']],
                'language' => $site->language,
                'categories' => [1, [$site->category]],
                'price' => $site->price,
                'turnaround_time' => $site->turnaround_time,
                'publicationTime' => $site->publication_time,
                'link_type' => $site->link_type,
                'siteDescription' => ['<p>Poisoned description</p>'],
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('siteDescription');

        $this->assertSame($original, $site->fresh()->description);
    }

    public function test_update_category_resets_verification(): void
    {
        $site = $this->makeSite($this->publisher, [
            'verified' => true,
            'active' => true,
        ]);

        $otherCategory = Category::query()->where('name', '!=', $site->category)->firstOrFail();

        $this->actingAs($this->publisher)->put(route('publisher.sites.update', $site->id), [
            'exampleUrl' => $site->example_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'country' => $site->country,
            'language' => $site->language,
            'categories' => [$otherCategory->name],
            'price' => $site->price,
            'turnaround_time' => $site->turnaround_time,
            'publicationTime' => $site->publication_time,
            'link_type' => $site->link_type,
            'siteDescription' => $site->description,
            'site_tag' => 'as_you_prefer',
        ])->assertRedirect()
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertFalse((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
    }

    public function test_claim_accepts_url_without_scheme(): void
    {
        $site = $this->makeSite($this->otherPublisher, [
            'site_name' => 'Claimable Blog',
            'site_url' => 'https://claimable-blog.example',
            'domain' => 'claimable-blog.example',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.claim'), [
                'website_url' => 'claimable-blog.example',
                'website_name' => 'Claimable Blog',
                'proof_message' => str_repeat('I own this domain and can prove it. ', 3),
                'contact_email' => $this->publisher->email,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_edit_data_returns_lean_payload(): void
    {
        $site = $this->makeSite($this->publisher);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.edit-data', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('site.id', $site->id)
            ->assertJsonPath('site.is_live', true)
            ->assertJsonMissingPath('site.created_at');
    }

    public function test_archive_and_unarchive_site(): void
    {
        $site = $this->makeSite($this->publisher, [
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.archive', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertNotNull($site->archived_at);
        $this->assertTrue((bool) $site->active);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'archived']))
            ->assertOk()
            ->assertSee('Archived');

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.unarchive', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertNull($site->archived_at);
        $this->assertTrue((bool) $site->active);
    }

    public function test_unarchive_restores_active_unverified_site(): void
    {
        $site = $this->makeSite($this->publisher, [
            'verified' => false,
            'active' => true,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.archive', $site->id))
            ->assertOk();

        $site->refresh();
        $this->assertTrue((bool) $site->active);
        $this->assertNotNull($site->archived_at);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.unarchive', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertNull($site->archived_at);
        $this->assertTrue((bool) $site->active);
        $this->assertFalse((bool) $site->verified);
    }

    public function test_archive_does_not_force_paused_site_live_on_restore(): void
    {
        $site = $this->makeSite($this->publisher, [
            'verified' => true,
            'active' => false,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.archive', $site->id))
            ->assertOk();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.unarchive', $site->id))
            ->assertOk();

        $site->refresh();
        $this->assertNull($site->archived_at);
        $this->assertTrue((bool) $site->verified);
        $this->assertFalse((bool) $site->active);
    }

    public function test_pending_site_cannot_be_archived(): void
    {
        $site = $this->makeSite($this->publisher, [
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.archive', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($site->fresh()->archived_at);
    }

    public function test_store_own_duplicate_domain_message(): void
    {
        $this->makeSite($this->publisher, [
            'site_url' => 'https://mine.example',
            'domain' => 'mine.example',
        ]);

        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Mine Again',
                'siteUrl' => 'https://mine.example',
                'exampleUrl' => 'https://mine.example/post',
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => 'de',
                'language' => 'de',
                'categories' => [$category->name],
                'price' => 50,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Duplicate domain validation description. ', 4),
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('siteUrl');

        $errors = session('errors')->get('siteUrl');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('already added', $errors[0]);
        $this->assertStringNotContainsString('another publisher', implode(' ', $errors));
    }

    public function test_store_rejects_www_variant_of_own_domain(): void
    {
        $this->makeSite($this->publisher, [
            'site_url' => 'https://www.mine-www.example',
            'domain' => 'www.mine-www.example',
        ]);

        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Mine Www Again',
                'siteUrl' => 'https://mine-www.example',
                'exampleUrl' => 'https://mine-www.example/post',
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => 'de',
                'language' => 'de',
                'categories' => [$category->name],
                'price' => 50,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Duplicate www domain validation description. ', 4),
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('siteUrl');

        $this->assertStringContainsString('already added', session('errors')->first('siteUrl'));
        $this->assertDatabaseMissing('sites', ['domain' => 'mine-www.example']);
    }

    public function test_store_accepts_html_checkbox_on_for_sensitive_crypto(): void
    {
        $category = Category::query()->firstOrFail();
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Crypto Sensitive Blog',
                'siteUrl' => 'https://crypto-sensitive.example',
                'exampleUrl' => 'https://crypto-sensitive.example/post',
                'da' => 40,
                'dr' => 40,
                'traffic' => 12000,
                'country' => strtolower($country->code),
                'language' => strtolower($language->code),
                'categories' => [$category->name],
                'price' => 80,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Crypto sensitive topic listing description. ', 4),
                'site_tag' => 'as_you_prefer',
                // Browser checkbox default value (without value="1")
                'sensitive' => ['crypto' => 'on'],
                'price_sensitive' => ['crypto' => 25],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $site = Site::where('domain', 'crypto-sensitive.example')->first();
        $this->assertNotNull($site);
        $this->assertSame(25.0, (float) ($site->sensitive_prices['crypto'] ?? 0));
    }

    public function test_store_rejects_domain_pending_on_open_bulk(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->otherPublisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://pending-mysites.example',
            'domain' => 'pending-mysites.example',
            'price' => 40,
        ]);

        $category = Category::query()->firstOrFail();

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Pending Clash',
                'siteUrl' => 'https://www.pending-mysites.example',
                'exampleUrl' => 'https://www.pending-mysites.example/post',
                'da' => 10,
                'dr' => 10,
                'traffic' => 100,
                'country' => 'de',
                'language' => 'de',
                'categories' => [$category->name],
                'price' => 50,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Pending bulk occupancy description. ', 4),
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('siteUrl');

        $this->assertStringContainsString(
            'Already in an open bulk request',
            (string) session('errors')->first('siteUrl')
        );
        $this->assertDatabaseMissing('sites', ['domain' => 'pending-mysites.example']);
        $this->assertDatabaseMissing('sites', ['domain' => 'www.pending-mysites.example']);
    }

    public function test_promotions_wallet_top_up_points_to_publisher_balance(): void
    {
        $this->actingAs($this->publisher)
            ->getJson(route('publisher.promotions.wallet'))
            ->assertOk()
            ->assertJsonPath('top_up_url', route('publisher.balance'));
    }
}
