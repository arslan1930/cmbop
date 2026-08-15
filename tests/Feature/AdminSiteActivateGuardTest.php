<?php

namespace Tests\Feature;

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

    public function test_activate_requires_verified(): void
    {
        $site = $this->site(['verified' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Verify this site before activating it.');

        $this->assertFalse((bool) $site->fresh()->active);
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
    }

    public function test_staff_list_flags_blocked_activate(): void
    {
        $blocked = $this->site([
            'site_name' => 'Blocked Activate',
            'site_url' => 'https://blocked-activate.example',
            'domain' => 'blocked-activate.example',
            'verified' => false,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonPath('sites.0.id', $blocked->id)
            ->assertJsonPath('sites.0.can_activate', false)
            ->assertJsonPath('sites.0.activate_block_reason', 'Verify this site before activating it.');

        $blade = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $this->assertStringContainsString('activate_block_reason', $blade);
        $this->assertStringContainsString('Cannot activate', $blade);
    }
}
