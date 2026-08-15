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
            ->assertSee('bulkUrlPriceBody', false)
            ->assertSee('Bulk Import (Agency)', false)
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
            ->assertRedirect(route('publisher.websites', ['status' => 'pending']))
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

        $publisherController = file_get_contents(app_path('Http/Controllers/Publisher/BulkSiteRequestController.php'));
        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $publisherController);
        $this->assertStringContainsString('openBlockingBulkRequest', $publisherController);
    }

    public function test_websites_page_shows_url_price_bulk_columns(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Website URL', false)
            ->assertSee('Price (€)', false)
            ->assertSee('How bulk onboarding works', false)
            ->assertSee('Our marketer', false)
            ->assertSee('modal-xl', false)
            ->assertSee('bulk-url-price-row__summary', false)
            ->assertSee('data-bulk-url-price-chip', false)
            ->assertSee('name="sites[0][url]"', false)
            ->assertSee('name="sites[0][price]"', false)
            ->assertSee('id="bulkRequestModal"', false)
            ->assertSee('id="bulkRequestForm"', false)
            ->assertSee('id="bulkUrlPriceError"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/id="bulkRequestForm"[^>]*novalidate/', $html);
        $this->assertMatchesRegularExpression('/<details class="bulk-url-price-row"\s+open/', $html);
        $css = file_get_contents(public_path('assets/css/publisher-websites.css'));
        $this->assertStringContainsString('#bulkRequestModal .bulk-url-price-row__fields', $css);
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
        $seedNote = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', '2 sites were added to Pending sites')
            ->first();
        $this->assertNotNull($seedNote);
        $this->assertStringContainsString('status=pending', (string) $seedNote->action_url);
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

        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $this->actingAs($marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemA->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 30,
                        'dr' => 35,
                        'traffic' => 5000,
                        'categories' => $category->name,
                    ],
                    $itemB->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 40,
                        'dr' => 45,
                        'traffic' => 8000,
                        'categories' => $category->name,
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

        $siteA = Site::where('domain', 'done-a.example')->firstOrFail();
        $this->assertContains($category->name, $siteA->categories ?? []);

        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertSame('Waiting on publisher', $bulk->fresh()->statusLabel());

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->publisher->id,
            'title' => '2 sites were added to Pending sites',
        ]);
        $doneNote = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', '2 sites were added to Pending sites')
            ->first();
        $this->assertNotNull($doneNote);
        $this->assertStringContainsString('status=pending', (string) $doneNote->action_url);

        Mail::assertQueued(BulkSitesSeededNotification::class);
    }

    public function test_marketer_can_submit_one_complete_block_and_leave_others_pending(): void
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
            'site_url' => 'https://partial-a.example',
            'domain' => 'partial-a.example',
            'price' => 50,
        ]);
        $itemB = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://partial-b.example',
            'domain' => 'partial-b.example',
            'price' => 60,
        ]);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $this->actingAs($marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemA->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 10,
                        'dr' => 12,
                        'traffic' => 100,
                        'categories' => $category->name,
                    ],
                    // itemB left empty for later
                ],
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success')
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'still pending'));

        $this->assertDatabaseHas('sites', [
            'domain' => 'partial-a.example',
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $this->assertDatabaseMissing('sites', ['domain' => 'partial-b.example']);
        $this->assertNull($itemB->fresh()->site_id);
        $this->assertNotNull($itemA->fresh()->site_id);
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);

        // Niches required on a started block: metrics alone are not enough for Done.
        $this->actingAs($marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemB->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 11,
                        'dr' => 13,
                        'traffic' => 200,
                    ],
                ],
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors('items.'.$itemB->id.'.categories')
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('sites', ['domain' => 'partial-b.example']);

        $this->actingAs($marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('name="items['.$itemB->id.'][language]"', false)
            ->assertSee('name="items['.$itemB->id.'][da]"', false)
            ->assertSee('name="items['.$itemB->id.'][categories]"', false)
            ->assertSee('one row, several, or all at once', false)
            ->assertSee('the rest stay here until you fill them', false)
            ->assertDontSee('name="items['.$itemA->id.'][language]"', false);
    }

    public function test_marketer_done_rejects_partially_filled_block_even_with_another_complete(): void
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
            'site_url' => 'https://mix-a.example',
            'domain' => 'mix-a.example',
            'price' => 50,
        ]);
        $itemB = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mix-b.example',
            'domain' => 'mix-b.example',
            'price' => 60,
        ]);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $this->actingAs($marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemA->id => [
                        'language' => strtolower($language->code),
                        'country' => strtolower($country->code),
                        'da' => 10,
                        'dr' => 12,
                        'traffic' => 100,
                        'categories' => $category->name,
                    ],
                    $itemB->id => [
                        'language' => strtolower($language->code),
                        'da' => 11,
                    ],
                ],
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('sites', ['domain' => 'mix-a.example']);
        $this->assertDatabaseMissing('sites', ['domain' => 'mix-b.example']);
    }

    public function test_admin_can_verify_awaiting_details_site(): void
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
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertFalse($site->awaitsPublisherDetails());
    }

    public function test_publisher_completing_details_moves_to_details_complete_not_admin_queue(): void
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
            'category' => $category->name,
            'categories' => [$category->name],
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
                // Niches are marketing-owned; publisher must not be required to resubmit them.
                'categories' => ['Hacked Niche'],
                'turnaround_time' => '48h',
                'publicationTime' => '1year',
                'link_type' => 'nofollow',
                'site_tag' => 'as_you_prefer',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
            ])
            ->assertRedirect(route('publisher.bulk-sites.review'))
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertSame(Site::ONBOARDING_DETAILS_COMPLETE, $site->onboarding_status);
        $this->assertFalse($site->isReadyForAdminReview());
        $this->assertFalse((bool) $site->active);
        $this->assertContains($category->name, $site->categories ?? []);
        $this->assertNotContains('Hacked Niche', $site->categories ?? []);
        // Bulk stays open until Review & submit.
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertSame(0, InAppNotification::where('user_id', $this->admin->id)->count());
    }

    public function test_review_submit_moves_sites_to_admin_queue_and_bells(): void
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
            'turnaround_time' => '48h',
            'publicationTime' => '1year',
            'link_type' => 'nofollow',
            'site_tag' => 'as_you_prefer',
            'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
        ];

        $first->update([
            'category' => $category->name,
            'categories' => [$category->name],
        ]);
        $second->update([
            'category' => $category->name,
            'categories' => [$category->name],
        ]);

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $first->id), $payload)
            ->assertRedirect(route('publisher.bulk-sites.complete'));

        $this->assertSame(Site::ONBOARDING_DETAILS_COMPLETE, $first->fresh()->onboarding_status);
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertSame(0, InAppNotification::where('user_id', $this->admin->id)->count());

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $second->id), array_merge($payload, [
                'exampleUrl' => 'https://bulk-two.example/guest-post',
            ]))
            ->assertRedirect(route('publisher.bulk-sites.review'));

        $this->assertSame(Site::ONBOARDING_DETAILS_COMPLETE, $second->fresh()->onboarding_status);
        $this->assertSame(0, InAppNotification::where('user_id', $this->admin->id)->count());

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.review.submit'), ['submit_all' => 1])
            ->assertRedirect(route('publisher.websites', ['status' => 'pending']))
            ->assertSessionHas('success');

        $this->assertSame(Site::ONBOARDING_READY_FOR_REVIEW, $first->fresh()->onboarding_status);
        $this->assertSame(Site::ONBOARDING_READY_FOR_REVIEW, $second->fresh()->onboarding_status);
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);

        $adminNotes = InAppNotification::where('user_id', $this->admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $adminNotes);
        $this->assertStringContainsString('/admin/sites', (string) $adminNotes->last()->action_url);
    }

    public function test_websites_page_exposes_paste_urls_helper(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();
        $bulkJs = file_get_contents(public_path('assets/js/publisher-websites-bulk.js'));

        $this->assertStringContainsString('bulkPasteUrls', $html);
        $this->assertStringContainsString('Fill rows from paste', $html);
        $this->assertStringContainsString('bulkSheetFile', $html);
        $this->assertStringContainsString('Upload sheet (CSV / TSV)', $html);
        $this->assertStringContainsString('Sample CSV', $html);
        $this->assertStringContainsString('Import URL + price', $html);
        $this->assertStringContainsString('publisher-websites-bulk.js', $html);
        $this->assertStringContainsString('parseUrlPriceImport', $bulkJs);
        $this->assertStringContainsString('__bulkParseUrlPriceImport', $bulkJs);
        $this->assertStringContainsString('isNumericToken', $bulkJs);
        $this->assertStringContainsString('lineToCells', $bulkJs);
        $this->assertStringContainsString('url,price', $bulkJs);
        $this->assertStringContainsString('Paste into the box, then click Fill rows', $html);
        $this->assertStringNotContainsString('Prices stay empty — fill € per row after pasting.', $html);
        // Bare prices must not be treated as hosts (URL("https://99") → 0.0.0.99).
        $this->assertStringContainsString('isNumericToken(u)', $bulkJs);
    }

    public function test_publisher_can_submit_bulk_from_url_price_pairs(): void
    {
        Mail::fake();

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://sheet-a.example', 'price' => 80],
                    ['url' => 'https://sheet-b.example', 'price' => 120.5],
                    ['url' => 'https://sheet-c.example', 'price' => 99],
                ],
            ])
            ->assertRedirect(route('publisher.websites', ['status' => 'pending']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bulk_site_request_items', [
            'domain' => 'sheet-a.example',
            'price' => 80,
        ]);
        $this->assertDatabaseHas('bulk_site_request_items', [
            'domain' => 'sheet-b.example',
            'price' => 120.5,
        ]);
        $this->assertSame(3, BulkSiteRequestItem::query()->count());
    }

    public function test_bulk_complete_shows_marketer_niches_as_readonly(): void
    {
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
            'seeded_at' => now(),
        ]);
        $site = $this->makeAwaitingBulkSite($bulk, 'https://niche-ui.example', 'Niche UI');
        $site->update([
            'category' => $category->name,
            'categories' => [$category->name],
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.bulk-sites.complete'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Niches (set by our team)', $html);
        $this->assertStringContainsString(e($category->name), $html);
        $this->assertStringNotContainsString('name="categories"', $html);
        $this->assertStringNotContainsString('multi-select-wrapper', $html);
        $this->assertStringNotContainsString('js/multi-select.js', $html);
    }

    private function makeAwaitingBulkSite(BulkSiteRequest $bulk, string $url, string $name): Site
    {
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

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
            'category' => $category->name,
            'categories' => [$category->name],
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
