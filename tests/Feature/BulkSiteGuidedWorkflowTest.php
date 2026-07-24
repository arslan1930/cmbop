<?php

namespace Tests\Feature;

use App\Mail\BulkSitesSeededNotification;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
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
                'sites' => [
                    ['url' => 'https://bulk-a.example', 'price' => 99],
                    ['url' => 'https://bulk-b.example', 'price' => 150.5],
                ],
                'publisher_note' => 'Mostly DE tech blogs',
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bulk_site_requests', [
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);

        $this->assertDatabaseHas('bulk_site_request_items', [
            'domain' => 'bulk-a.example',
            'price' => 99,
        ]);
        $this->assertDatabaseHas('bulk_site_request_items', [
            'domain' => 'bulk-b.example',
            'price' => 150.5,
        ]);

        $bulk = BulkSiteRequest::where('publisher_id', $this->publisher->id)->first();
        $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Publisher submitted (URL + price only)', false)
            ->assertSee('Done — add sites &amp; notify publisher', false)
            ->assertSee('https://bulk-a.example', false)
            ->assertSee('https://bulk-b.example', false);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->admin->id,
            'title' => 'New bulk sites request',
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
                'sites' => [
                    ['url' => 'https://again-a.example', 'price' => 10],
                    ['url' => 'https://again-b.example', 'price' => 20],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas('error');

        $this->assertSame(1, BulkSiteRequest::where('publisher_id', $this->publisher->id)->count());
    }

    public function test_websites_page_shows_url_price_bulk_columns(): void
    {
        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Website URL', false)
            ->assertSee('Price (€)', false)
            ->assertSee('How bulk onboarding works', false)
            ->assertSee('Our marketer', false);
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

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->publisher->id,
            'title' => '2 sites were added to Pending sites',
        ]);
    }

    public function test_marketer_done_adds_drafts_from_submitted_items_and_notifies_publisher(): void
    {
        Mail::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $itemA = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-a.example',
            'domain' => 'done-a.example',
            'price' => 80,
        ]);
        $itemB = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-b.example',
            'domain' => 'done-b.example',
            'price' => 120,
        ]);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $this->actingAs($marketer)
            ->post(route('admin.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemA->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 30,
                        'dr' => 35,
                        'traffic' => 5000,
                    ],
                    $itemB->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 40,
                        'dr' => 45,
                        'traffic' => 8000,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sites', [
            'domain' => 'done-a.example',
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'active' => false,
            'verified' => false,
            'price' => 80,
            'da' => 30,
            'dr' => 35,
            'traffic' => 5000,
        ]);
        $this->assertDatabaseHas('sites', [
            'domain' => 'done-b.example',
            'price' => 120,
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
        ]);

        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertSame('Waiting on publisher', $bulk->fresh()->statusLabel());

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->publisher->id,
            'title' => '2 sites were added to Pending sites',
        ]);

        Mail::assertQueued(BulkSitesSeededNotification::class);
    }

    public function test_marketer_done_blocked_until_all_per_site_boxes_filled(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $itemA = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://incomplete-a.example',
            'domain' => 'incomplete-a.example',
            'price' => 50,
        ]);
        $itemB = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://incomplete-b.example',
            'domain' => 'incomplete-b.example',
            'price' => 60,
        ]);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $this->actingAs($marketer)
            ->from(route('admin.bulk-site-requests.show', $bulk))
            ->post(route('admin.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemA->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 10,
                        'dr' => 12,
                        'traffic' => 100,
                    ],
                    // itemB missing entirely
                ],
            ])
            ->assertRedirect(route('admin.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('sites', ['domain' => 'incomplete-a.example']);
        $this->assertDatabaseMissing('sites', ['domain' => 'incomplete-b.example']);

        $this->actingAs($marketer)
            ->get(route('admin.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('name="items['.$itemA->id.'][language]"', false)
            ->assertSee('name="items['.$itemB->id.'][da]"', false)
            ->assertSee('Fill every Language, Country, DA, DR, and Traffic box', false);
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

    public function test_admin_gets_bell_for_each_bulk_site_as_it_is_submitted(): void
    {
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
            'seeded_at' => now(),
        ]);

        $first = $this->makeAwaitingBulkSite($bulk, 'https://bulk-one.example', 'Bulk One');
        $second = $this->makeAwaitingBulkSite($bulk, 'https://bulk-two.example', 'Bulk Two');

        $payload = [
            'exampleUrl' => 'https://bulk-one.example/guest-post',
            'categories' => [$category->name],
            'turnaround_time' => '48h',
            'publicationTime' => '1year',
            'link_type' => 'nofollow',
            'site_tag' => 'as_you_prefer',
            'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
        ];

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $first->id), $payload)
            ->assertRedirect(route('publisher.bulk-sites.complete'));

        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);

        $afterFirst = InAppNotification::where('user_id', $this->admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->get();
        $this->assertCount(1, $afterFirst);
        $this->assertStringContainsString('Bulk One', (string) $afterFirst->first()->message);
        $this->assertSame(Site::class, $afterFirst->first()->related_type);
        $this->assertSame($first->id, (int) $afterFirst->first()->related_id);

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $second->id), array_merge($payload, [
                'exampleUrl' => 'https://bulk-two.example/guest-post',
            ]))
            ->assertRedirect(route('publisher.bulk-sites.complete'));

        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);

        $adminNotes = InAppNotification::where('user_id', $this->admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $adminNotes);
        $this->assertStringContainsString('Bulk Two', (string) $adminNotes->last()->message);
        $this->assertSame($second->id, (int) $adminNotes->last()->related_id);
        $this->assertStringContainsString('/admin/sites', (string) $adminNotes->last()->action_url);
    }

    public function test_bulk_complete_uses_click_to_toggle_niche_multiselect(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
            'seeded_at' => now(),
        ]);
        $this->makeAwaitingBulkSite($bulk, 'https://niche-ui.example', 'Niche UI');

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.bulk-sites.complete'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('multi-select-wrapper', $html);
        $this->assertStringContainsString('Select niches (max 7)', $html);
        $this->assertStringContainsString('js/multi-select.js', $html);
        $this->assertStringContainsString('Click niches one by one', $html);
        $this->assertStringNotContainsString('multiple size="5"', $html);
    }

    private function makeAwaitingBulkSite(BulkSiteRequest $bulk, string $url, string $name): Site
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'example_url' => $url,
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
            'bulk_site_request_id' => $bulk->id,
        ]);

        return $site;
    }
}
