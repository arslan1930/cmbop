<?php

namespace Tests\Feature;

use App\Mail\SiteStatusNotification;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSiteActivateGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

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
            'site_name' => 'Activate Guard Site',
            'site_url' => 'https://activate-guard.example',
            'domain' => 'activate-guard.example',
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
            'description' => str_repeat('Activate guard listing description. ', 3),
            'verified' => true,
            'active' => false,
        ], $overrides));
    }

    public function test_admin_activate_verifies_unverified_review_ready_site(): void
    {
        $site = $this->site([
            'verified' => false,
            'onboarding_status' => null,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true);

        $fresh = $site->fresh();
        $this->assertTrue((bool) $fresh->active);
        $this->assertTrue((bool) $fresh->verified);
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.activated')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.approved')->count());
    }

    public function test_activate_rejects_cancelled_bulk_leftover(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $site = $this->site([
            'verified' => true,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'bulk_site_request_id' => $bulk->id,
        ]);

        $this->assertFalse($site->marketingCanActivate());
        $this->assertFalse($site->needsAdminReview());
        $this->assertFalse($site->canBeActivated());

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This listing is from a cancelled bulk request and cannot be activated.');

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_verify_completes_bulk_when_publisher_no_longer_owes_work(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
        ]);
        $site = $this->site([
            'site_name' => 'Awaiting Verify',
            'site_url' => 'https://awaiting-verify.example',
            'domain' => 'awaiting-verify.example',
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'bulk_site_request_id' => $bulk->id,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $site->site_url,
            'domain' => $site->domain,
            'price' => 40,
            'site_id' => $site->id,
        ]);

        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
    }

    public function test_unverify_restores_bulk_draft_to_complete_details(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
        ]);
        $site = $this->site([
            'site_name' => 'Undo Verify',
            'site_url' => 'https://undo-verify.example',
            'domain' => 'undo-verify.example',
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'bulk_site_request_id' => $bulk->id,
            'category' => 'Pending',
            'categories' => null,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $site->site_url,
            'domain' => $site->domain,
            'price' => 40,
            'site_id' => $site->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

        $this->assertTrue((bool) $site->fresh()->verified);
        $this->assertNull($site->fresh()->onboarding_status);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), [
                'verified' => 0,
                'reason' => 'Sent back so the publisher can finish niches.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $site->fresh();
        $this->assertFalse((bool) $fresh->verified);
        $this->assertSame(Site::ONBOARDING_AWAITING_DETAILS, $fresh->onboarding_status);
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
    }

    public function test_marketer_activate_completes_bulk_when_publisher_no_longer_owes_work(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
        ]);
        $site = $this->site([
            'site_name' => 'Awaiting Activate',
            'site_url' => 'https://awaiting-activate.example',
            'domain' => 'awaiting-activate.example',
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'bulk_site_request_id' => $bulk->id,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $site->site_url,
            'domain' => $site->domain,
            'price' => 40,
            'site_id' => $site->id,
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertFalse(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );
    }

    public function test_marketer_can_activate_unverified_review_queue_site(): void
    {
        $site = $this->site([
            'verified' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $this->assertTrue($site->marketingCanActivate());
        $this->assertTrue($site->needsAdminReview());

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true);

        $fresh = $site->fresh();
        $this->assertTrue((bool) $fresh->active);
        $this->assertTrue((bool) $fresh->verified);
        $this->assertTrue($fresh->isCatalogVisible());
        $this->assertNull($fresh->onboarding_status);
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.activated')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.approved')->count());

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', false);

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.activated')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.approved')->count());
        Mail::assertQueued(SiteStatusNotification::class, 1);
    }

    public function test_reverify_does_not_email_or_log_again(): void
    {
        $site = $this->site(['verified' => false, 'active' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', true);

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.approved')->count());
        Mail::assertQueued(SiteStatusNotification::class, 1);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', false);

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.approved')->count());
        Mail::assertQueued(SiteStatusNotification::class, 1);
    }

    public function test_marketer_list_allows_activate_for_unverified_review_site(): void
    {
        $site = $this->site([
            'verified' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $this->actingAs($this->marketer)
            ->getJson(route('marketing.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('sites.0.id', $site->id)
            ->assertJsonPath('sites.0.can_activate', true)
            ->assertJsonPath('sites.0.activate_block_reason', null);
    }

    public function test_activate_requires_marketplace_country(): void
    {
        $site = $this->site([
            'country' => '',
            'countries' => [],
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_activate_rejects_archived_site(): void
    {
        $site = $this->site([
            'archived_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This site is archived and cannot be activated.');

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_activate_rejects_awaiting_details(): void
    {
        $site = $this->site([
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
        $this->assertTrue($site->fresh()->awaitsPublisherDetails());
    }

    public function test_ready_verified_site_can_be_activated(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_array_active_zero_deactivates_instead_of_activating(): void
    {
        $site = $this->site(['active' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), [
                'active' => ['0'],
                'reason' => 'Keep this listing off the catalog for now.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', false);

        $this->assertFalse((bool) $site->fresh()->active);
    }

    public function test_array_verified_zero_does_not_approve(): void
    {
        $site = $this->site(['verified' => false, 'active' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), [
                'verified' => ['0'],
                'reason' => 'Still waiting on listing details from the publisher.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse((bool) $site->fresh()->verified);
    }

    public function test_verify_save_failure_returns_json_instead_of_leaking(): void
    {
        $src = (string) file_get_contents(app_path('Http/Controllers/Admin/SiteController.php'));
        $this->assertStringContainsString('Could not update verification.', $src);
        $this->assertStringContainsString('Failed to update site verification', $src);
        $this->assertStringNotContainsString('database/sql/', $src);
        $this->assertStringNotContainsString('fix_sites_onboarding_status.sql', $src);
        $this->assertStringNotContainsString('add_sites_status_reason.sql', $src);
    }

    public function test_marketer_can_activate_unverified_legacy_queue_site(): void
    {
        $site = $this->site([
            'verified' => false,
            'onboarding_status' => null,
        ]);

        $this->assertTrue($site->needsAdminReview());
        $this->assertTrue($site->isReviewReadyForStaffGoLive());
        $this->assertTrue($site->marketingCanActivate());

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true);

        $fresh = $site->fresh();
        $this->assertTrue((bool) $fresh->active);
        $this->assertTrue((bool) $fresh->verified);
    }

    public function test_staff_list_flags_blocked_activate(): void
    {
        $blocked = $this->site([
            'site_name' => 'Blocked Activate',
            'site_url' => 'https://blocked-activate.example',
            'domain' => 'blocked-activate.example',
            'verified' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('sites.0.id', $blocked->id)
            ->assertJsonPath('sites.0.can_activate', false)
            ->assertJsonPath('sites.0.activate_block_reason', 'Publisher has not finished listing details.');

        $blade = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $this->assertStringContainsString('activate_block_reason', $blade);
        $this->assertStringContainsString('Cannot activate', $blade);
    }

    public function test_non_english_brief_does_not_block_verify_or_activate(): void
    {
        $site = $this->site([
            'site_name' => 'German Brief Site',
            'site_url' => 'https://german-brief.example',
            'domain' => 'german-brief.example',
            'verified' => false,
            'description' => 'Ein deutscher Verlag für Gastbeiträge mit klarer Zielgruppe und vielen Lesern in der Region.',
        ]);
        $this->assertFalse($site->descriptionLooksLikeEnglish());

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->active);

        $activated = ActivityLog::query()
            ->where('action', 'site.activated')
            ->where('subject_id', $site->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($activated);
        $this->assertTrue((bool) data_get($activated->properties, 'non_english_brief_warned'));

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), [
                'active' => 0,
                'reason' => 'Taking this listing off the catalog for now.',
            ])
            ->assertOk();

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_marketing_activate_unverified_non_review_ready_asks_admin_to_verify(): void
    {
        $site = $this->site([
            'verified' => false,
            'active' => false,
            'onboarding_status' => 'legacy_hold',
        ]);

        $this->actingAs($this->marketer)
            ->postJson(route('marketing.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This listing is not review-ready. Ask an admin to Verify it.');

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Verify this site before activating it.');
    }

    public function test_staff_list_flags_whether_the_brief_looks_english(): void
    {
        $german = $this->site([
            'site_name' => 'German Flag Site',
            'site_url' => 'https://german-flag.example',
            'domain' => 'german-flag.example',
            'description' => 'Ein deutscher Verlag für Gastbeiträge mit klarer Zielgruppe und vielen Lesern in der Region.',
        ]);
        $english = $this->site([
            'site_name' => 'English Flag Site',
            'site_url' => 'https://english-flag.example',
            'domain' => 'english-flag.example',
            'description' => 'This listing is for your audience and the publishers who write with them about guest posts.',
        ]);

        $sites = $this->actingAs($this->admin)
            ->getJson(route('admin.users.sites', $this->publisher->id))
            ->assertOk()
            ->json('sites');

        $byId = collect($sites)->keyBy('id');
        $this->assertFalse((bool) $byId[$german->id]['description_looks_english']);
        $this->assertTrue((bool) $byId[$english->id]['description_looks_english']);
        $this->assertNotSame('', $byId[$german->id]['description_excerpt']);

        $adminBlade = (string) file_get_contents(resource_path('views/admin/sites.blade.php'));
        $mktBlade = (string) file_get_contents(resource_path('views/marketing/dashboard.blade.php'));
        $this->assertStringContainsString('slbConfirmActivate', $adminBlade);
        $this->assertStringContainsString('slbConfirmActivate', $mktBlade);
        $this->assertStringContainsString('data-description-english', $adminBlade);
        $this->assertStringContainsString('data-description-english', $mktBlade);
        $this->assertStringContainsString('/edit#description', $adminBlade);
        $this->assertStringContainsString('/edit#description', $mktBlade);
        $this->assertStringNotContainsString('Promise.resolve(true)', $adminBlade);
    }
}
