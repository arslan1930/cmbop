<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BulkSiteGuidedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    public function test_websites_page_shows_guided_bulk_request_not_live_table(): void
    {
        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Add New Website', false)
            ->assertSee('I want to add many sites', false)
            ->assertSee('bulkRequestModal', false)
            ->assertDontSee('liveBulkForm', false)
            ->assertDontSee('liveBulkFill25Btn', false);
    }

    public function test_publisher_can_submit_bulk_request(): void
    {
        Mail::fake();

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.request'), [
                'estimated_count' => 12,
                'publisher_note' => 'Mostly DE tech blogs',
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bulk_site_requests', [
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 12,
        ]);
    }

    public function test_publisher_cannot_open_second_bulk_request(): void
    {
        BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 10,
        ]);

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.request'), [
                'estimated_count' => 15,
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas('error');

        $this->assertSame(1, BulkSiteRequest::where('publisher_id', $this->publisher->id)->count());
    }

    public function test_admin_can_seed_draft_sites_hidden_from_catalog(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 5,
            'sheet_sent_at' => now(),
        ]);

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        $rows = implode("\n", [
            'https://seed-one.example,99,40,45,12000,'.$language->code.','.$country->code.',Seed One',
            'https://seed-two.example,150,50,55,20000,'.$language->code.','.$country->code,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.seed', $bulk), ['rows' => $rows])
            ->assertRedirect()
            ->assertSessionHas('success');

        $one = Site::where('domain', 'seed-one.example')->first();
        $this->assertNotNull($one);
        $this->assertFalse((bool) $one->active);
        $this->assertFalse((bool) $one->verified);
        $this->assertSame(Site::ONBOARDING_AWAITING_DETAILS, $one->onboarding_status);
        $this->assertSame($this->publisher->id, (int) $one->publisher_id);
        $this->assertSame(99.0, (float) $one->price);
        $this->assertSame(45, (int) $one->dr);

        $this->assertSame(0, Site::where('active', 1)->where('domain', 'seed-one.example')->count());
    }

    public function test_admin_cannot_verify_awaiting_details_site(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Draft',
            'site_url' => 'https://draft.example',
            'domain' => 'draft.example',
            'example_url' => 'https://draft.example/x',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Pending',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Placeholder description text. ', 3),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertStatus(422);

        $this->assertFalse((bool) $site->fresh()->verified);
    }

    public function test_publisher_completing_details_moves_to_review(): void
    {
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Finish Me',
            'site_url' => 'https://finish-me.example',
            'domain' => 'finish-me.example',
            'example_url' => 'https://finish-me.example',
            'da' => 20,
            'dr' => 25,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Pending',
            'price' => 80,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Please replace this placeholder with a real site description (at least 50 characters) before submitting for review.',
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'as_you_prefer' => true,
        ]);

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 5,
            'seeded_at' => now(),
        ]);
        $site->bulk_site_request_id = $bulk->id;
        $site->save();

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $site->id), [
                'exampleUrl' => 'https://finish-me.example/guest-post',
                'categories' => [$category->name],
                'turnaround_time' => '48h',
                'publicationTime' => '1year',
                'link_type' => 'nofollow',
                'site_tag' => 'as_you_prefer',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
            ])
            ->assertRedirect(route('publisher.bulk-sites.complete'))
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertSame(Site::ONBOARDING_READY_FOR_REVIEW, $site->onboarding_status);
        $this->assertFalse((bool) $site->active);
        $this->assertContains($category->name, $site->categories ?? []);
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
    }
}
