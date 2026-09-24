<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteImageUpload;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class MarketingNicheGroupAndImageFixTest extends TestCase
{
    use CreatesBlogUploads;
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
        Category::flushNicheLookupCache();
        Storage::fake('public');

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
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Tech Group Site',
            'site_url' => 'https://tech-group.example',
            'domain' => 'tech-group.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'Technology',
            'categories' => null,
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Group niche regression',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_technology_group_alias_resolves_to_technology_gadgets(): void
    {
        $resolved = Category::resolveNicheNames('Technology');
        $this->assertSame([], $resolved['unknown']);
        $this->assertSame(['Technology & Gadgets'], $resolved['resolved']);
    }

    public function test_marketer_can_save_technology_group_as_niche(): void
    {
        $site = $this->makeSite();
        $file = $this->fakeBlogUpload('cover.jpg', 800, 500);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'da' => 41,
                'dr' => 52,
                'traffic' => 18000,
                'language' => 'en',
                'country' => 'us',
                // Group label (also what urlencoded truncation of "Technology & Gadgets" becomes).
                'categories' => 'Technology',
                'site_image' => $file,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]))
            ->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertContains('Technology & Gadgets', $site->categories ?? []);
        $this->assertNotEmpty($site->site_image);
        Storage::disk('public')->assertExists($site->site_image);
    }

    public function test_marketer_can_save_ampersand_niche_with_image(): void
    {
        $site = $this->makeSite([
            'domain' => 'amp-niche.example',
            'site_url' => 'https://amp-niche.example',
        ]);
        $file = $this->fakeBlogUpload('amp-cover.png', 640, 400);

        $this->actingAs($this->marketer)
            ->put(route('marketing.sites.update', $site->id), [
                'da' => 22,
                'dr' => 33,
                'traffic' => 9000,
                'language' => 'en',
                'country' => 'us',
                'categories' => 'Technology & Gadgets|Business & Finance',
                'site_image' => $file,
            ])
            ->assertRedirect(route('marketing.sites.index', [
                'publisher' => $site->publisher_id,
                'site' => $site->id,
            ]))
            ->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertContains('Technology & Gadgets', $site->categories ?? []);
        $this->assertContains('Business & Finance', $site->categories ?? []);
        $this->assertNotEmpty($site->site_image);
    }

    public function test_bulk_done_accepts_technology_group_and_ampersand_niche(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://bulk-tech.example',
            'domain' => 'bulk-tech.example',
            'price' => 55,
        ]);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => 'en',
                        'country' => 'us',
                        'da' => 30,
                        'dr' => 40,
                        'traffic' => 12000,
                        'categories' => 'Technology|Business & Finance',
                        'example_url' => 'https://bulk-tech.example/sample-article',
                        'turnaround_time' => '3days',
                        'publication_time' => 'permanent',
                        'link_type' => 'dofollow',
                        'site_tag' => 'as_you_prefer',
                        'description' => 'Guest posts on this website stay published and the link remains dofollow for advertisers.',
                        'site_image' => $this->fakeBlogUpload('cover.jpg', 80, 80),
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = Site::where('domain', 'bulk-tech.example')->first();
        $this->assertNotNull($site);
        $this->assertContains('Technology & Gadgets', $site->categories ?? []);
        $this->assertContains('Business & Finance', $site->categories ?? []);
    }

    public function test_bulk_done_form_uses_multipart_enctype(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://enctype-check.example',
            'domain' => 'enctype-check.example',
            'price' => 40,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="bulkDoneForm"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    public function test_site_edit_documents_10mb_image_limit(): void
    {
        $site = $this->makeSite([
            'domain' => 'limit-copy.example',
            'site_url' => 'https://limit-copy.example',
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->getContent();

        $maxKb = SiteImageUpload::maxKilobytes();
        $maxMb = SiteImageUpload::maxMegabytesLabel();
        $this->assertStringContainsString('data-max-kb="'.$maxKb.'"', $html);
        $this->assertStringContainsString('up to '.$maxMb, $html);
        // Prefill resolves legacy category "Technology" → Technology & Gadgets (Blade-escaped).
        $this->assertTrue(
            str_contains($html, 'Technology &amp; Gadgets') || str_contains($html, 'Technology & Gadgets'),
            'Expected Technology & Gadgets niche option or prefill on the edit page'
        );
    }
}
