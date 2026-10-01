<?php

namespace Tests\Feature;

use App\Mail\BulkSitesReadyForPublisherReview;
use App\Mail\BulkSitesSeededNotification;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\MarketingOpsQueues;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class BulkReviewUndoTest extends TestCase
{
    use CreatesBlogUploads;
    use RefreshDatabase;

    private User $publisher;

    private User $admin;

    private User $marketer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

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
            'name' => 'Admin Avery',
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Marketer Casey',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);
    }

    /**
     * @return list<array{0:string,1:User}>
     */
    private function staffActors(): array
    {
        return [
            ['admin', $this->admin],
            ['marketing', $this->marketer],
        ];
    }

    /**
     * @return array{0:BulkSiteRequest,1:BulkSiteRequestItem}
     */
    private function makePendingBulk(string $prefix): array
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://'.$prefix.'.example',
            'domain' => $prefix.'.example',
            'price' => 40,
        ]);

        return [$bulk, $item];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function completeRow(BulkSiteRequestItem $item): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        return [
            $item->id => [
                'language' => strtolower($language->code),
                'country' => strtolower($country->code),
                'da' => 30,
                'dr' => 35,
                'traffic' => 5000,
                'example_url' => 'https://example.com/sample-article',
                'turnaround_time' => '3days',
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'site_tag' => 'as_you_prefer',
                'description' => 'Guest posts on this website stay published and the link remains dofollow for advertisers.',
                'categories' => $category->name,
                'site_image' => $this->fakeBlogUpload('cover.jpg', 80, 80),
            ],
        ];
    }

    private function sendForReview(User $staff, string $prefix, BulkSiteRequest $bulk, BulkSiteRequestItem $item): Site
    {
        $this->actingAs($staff)
            ->post(route($prefix.'.bulk-site-requests.done', $bulk), [
                'done_mode' => 'review',
                'items' => $this->completeRow($item),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        return Site::query()->where('domain', $item->domain)->firstOrFail();
    }

    public function test_undo_review_then_publish_now_for_admin_and_marketer(): void
    {
        foreach ($this->staffActors() as [$prefix, $user]) {
            Mail::fake();
            [$bulk, $item] = $this->makePendingBulk($prefix.'-undo');
            $site = $this->sendForReview($user, $prefix, $bulk, $item);

            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertSee(route($prefix.'.bulk-site-requests.undo-review', $bulk, false), false)
                ->assertDontSee(route($prefix.'.bulk-site-requests.publish-now', $bulk, false), false)
                ->assertSee('Publisher reviewing', false)
                ->assertSee('id="bulk-site-row-'.$site->id.'"', false)
                ->assertSee('data-bulk-draft-select-all', false)
                ->assertSee('data-bulk-draft-row', false)
                ->assertSee('fa-folder-open', false)
                ->assertDontSee('>Open</a>', false);

            $this->actingAs($this->publisher)
                ->get(route('publisher.bulk-sites.review'))
                ->assertOk()
                ->assertSee($item->domain, false);

            $this->actingAs($user)
                ->post(route($prefix.'.bulk-site-requests.undo-review', $bulk))
                ->assertRedirect()
                ->assertSessionHas('success');

            $site->refresh();
            $this->assertFalse((bool) $site->active);
            $this->assertSame(Site::ONBOARDING_STAFF_HOLD, $site->onboarding_status);
            $this->assertTrue($site->isBulkReadyToPublishNow());
            $this->assertSame(BulkSiteRequest::STATUS_SEEDED, $bulk->fresh()->status);

            $this->actingAs($this->publisher)
                ->get(route('publisher.bulk-sites.review'))
                ->assertOk()
                ->assertDontSee($item->domain, false);

            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertSee(route($prefix.'.bulk-site-requests.publish-now', $bulk, false), false)
                ->assertSee('Ready to publish', false);

            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.index'))
                ->assertOk()
                ->assertSee(route($prefix.'.bulk-site-requests.show', $bulk), false);

            Mail::assertQueued(BulkSitesReadyForPublisherReview::class, 1);
            Mail::assertNotQueued(BulkSitesSeededNotification::class);

            $this->actingAs($user)
                ->post(route($prefix.'.bulk-site-requests.publish-now', $bulk))
                ->assertRedirect()
                ->assertSessionHas('success');

            $site->refresh();
            $this->assertTrue((bool) $site->active);
            $this->assertFalse((bool) $site->verified);
            $this->assertNull($site->onboarding_status);
            $this->assertNotNull($site->publisher_accepted_at);
            $this->assertTrue(Site::query()->catalogVisible()->whereKey($site->id)->exists());
            $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
            Mail::assertQueued(BulkSitesSeededNotification::class, 1);

            $this->actingAs($user)
                ->get(route($prefix.'.bulk-site-requests.show', $bulk))
                ->assertOk()
                ->assertDontSee('id="bulk-site-row-'.$site->id.'"', false)
                ->assertSee('No draft sites on this request', false);
        }
    }

    public function test_undo_rejected_after_publisher_accept(): void
    {
        Mail::fake();
        [$bulk, $item] = $this->makePendingBulk('accepted-undo');
        $site = $this->sendForReview($this->admin, 'admin', $bulk, $item);

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.review.submit'), [
                'site_ids' => [$site->id],
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.undo-review', $bulk))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_undo_rejected_after_publisher_edit(): void
    {
        Mail::fake();
        [$bulk, $item] = $this->makePendingBulk('edited-undo');
        $site = $this->sendForReview($this->admin, 'admin', $bulk, $item);

        $this->actingAs($this->publisher)
            ->post(route('publisher.bulk-sites.complete.store', $site->id), [
                'exampleUrl' => 'https://edited-undo.example/guest-post',
                'turnaround_time' => '48h',
                'publicationTime' => '1year',
                'link_type' => 'nofollow',
                'site_tag' => 'as_you_prefer',
                'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
            ])
            ->assertRedirect();

        $this->assertSame(Site::ONBOARDING_READY_FOR_REVIEW, $site->fresh()->onboarding_status);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.undo-review', $bulk))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_publish_now_rejected_while_still_in_review(): void
    {
        Mail::fake();
        [$bulk, $item] = $this->makePendingBulk('still-review');
        $this->sendForReview($this->admin, 'admin', $bulk, $item);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.publish-now', $bulk))
            ->assertRedirect()
            ->assertSessionHas('error');

        $site = Site::query()->where('domain', $item->domain)->firstOrFail();
        $this->assertFalse((bool) $site->active);
        $this->assertSame(Site::ONBOARDING_DETAILS_COMPLETE, $site->onboarding_status);
    }

    public function test_cancelled_batch_rejects_undo_and_publish(): void
    {
        Mail::fake();
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.undo-review', $bulk))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.publish-now', $bulk))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_waiting_on_marketer_includes_undone_hold(): void
    {
        Mail::fake();
        [$bulk, $item] = $this->makePendingBulk('hold-queue');
        $this->sendForReview($this->admin, 'admin', $bulk, $item);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.undo-review', $bulk))
            ->assertRedirect();

        $this->assertTrue(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );
        $this->assertFalse(
            MarketingOpsQueues::bulkWaitingOnPublisher()->whereKey($bulk->id)->exists()
        );
    }

    public function test_deactivated_live_bulk_site_is_not_ready_to_publish(): void
    {
        Mail::fake();
        [$bulk, $item] = $this->makePendingBulk('was-live');
        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.done', $bulk), [
                'done_mode' => 'publish',
                'items' => $this->completeRow($item),
            ])
            ->assertRedirect();

        $site = Site::query()->where('domain', $item->domain)->firstOrFail();
        $this->assertTrue((bool) $site->active);
        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);

        $site->forceFill(['active' => false])->save();
        $this->assertFalse($site->fresh()->isBulkReadyToPublishNow());
        $this->assertFalse(
            MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists()
        );
    }
}
