<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSiteReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makePendingSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Pending Review Site',
            'site_url' => 'https://pending-review.example',
            'domain' => 'pending-review.example',
            'example_url' => 'https://pending-review.example/sample',
            'da' => 30,
            'dr' => 30,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Pending site description for admin review. ', 2),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ], $overrides));
    }

    public function test_notify_admins_new_site_deep_links_and_skips_awaiting_details(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');

        $draft = $this->makePendingSite($publisher, [
            'site_name' => 'Draft Site',
            'site_url' => 'https://draft.example',
            'domain' => 'draft.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $ready = $this->makePendingSite($publisher);

        $service = app(InAppNotificationService::class);
        $service->notifyAdminsNewSite($draft, 'create');
        $service->notifyAdminsNewSite($ready, 'create');

        $this->assertSame(
            0,
            InAppNotification::query()
                ->where('user_id', $admin->id)
                ->where('related_id', $draft->id)
                ->count()
        );

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->where('related_type', Site::class)
            ->where('related_id', $ready->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertSame('New site to verify', $note->title);
        $this->assertStringContainsString('needs_review=1', (string) $note->action_url);
        $this->assertStringContainsString('publisher='.$publisher->id, (string) $note->action_url);
        $this->assertStringContainsString('site='.$ready->id, (string) $note->action_url);
    }

    public function test_verify_archives_admin_site_review_notifications(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->makePendingSite($publisher);

        app(InAppNotificationService::class)->notifyAdminsNewSite($site, 'create');

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk()
            ->assertJson(['success' => true]);

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('related_type', Site::class)
            ->where('related_id', $site->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertSame(InAppNotification::STATUS_ARCHIVED, $note->status);
        $this->assertNotNull($note->archived_at);
        $this->assertFalse($site->fresh()->needsAdminReview());
    }

    public function test_activate_archives_admin_site_review_notifications(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->makePendingSite($publisher);

        app(InAppNotificationService::class)->notifyAdminsNewSite($site, 'create');

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJson(['success' => true]);

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('related_id', $site->id)
            ->first();

        $this->assertSame(InAppNotification::STATUS_ARCHIVED, $note?->status);
        $this->assertFalse($site->fresh()->needsAdminReview());
    }

    public function test_delete_archives_admin_site_review_notifications(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->makePendingSite($publisher);
        $siteId = $site->id;

        app(InAppNotificationService::class)->notifyAdminsNewSite($site, 'create');

        $this->actingAs($admin)
            ->deleteJson(route('admin.sites.destroy', $siteId), [
                'reason' => 'Does not meet marketplace quality guidelines.',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('related_id', $siteId)
            ->first();

        $this->assertSame(InAppNotification::STATUS_ARCHIVED, $note?->status);
        $this->assertDatabaseMissing('sites', ['id' => $siteId]);
    }

    public function test_queue_counts_exclude_awaiting_details_drafts(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');

        $this->makePendingSite($publisher, [
            'site_name' => 'Ready One',
            'site_url' => 'https://ready-one.example',
            'domain' => 'ready-one.example',
        ]);
        $this->makePendingSite($publisher, [
            'site_name' => 'Draft Two',
            'site_url' => 'https://draft-two.example',
            'domain' => 'draft-two.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $this->makePendingSite($publisher, [
            'site_name' => 'Already Live',
            'site_url' => 'https://live.example',
            'domain' => 'live.example',
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'unverified_sites' => 1,
            ]);
    }

    public function test_sites_index_needs_review_filter_and_user_sites_flags(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $other = $this->userWithRole('publisher');

        $ready = $this->makePendingSite($publisher);
        $this->makePendingSite($other, [
            'site_name' => 'Live Other',
            'site_url' => 'https://live-other.example',
            'domain' => 'live-other.example',
            'verified' => true,
            'active' => true,
        ]);

        $queueHtml = $this->actingAs($admin)
            ->get(route('admin.sites.index', ['needs_review' => 1]))
            ->assertOk()
            ->assertSee('Needs review queue', false)
            ->assertSee($publisher->email, false)
            ->assertDontSee($other->email, false)
            ->assertSee('1 new', false)
            ->assertSee('dropNeedsReviewQueryParam', false)
            ->getContent();

        // Detail filter must start unchecked even on the queue page.
        $this->assertMatchesRegularExpression(
            '/id="sitesNeedsReviewOnly"(?![^>]*checked)/',
            $queueHtml
        );

        // Sidebar "Sites" opens all publishers (activated ones stay findable).
        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $this->assertStringContainsString("staff_route('sites.index')", $layout);
        $this->assertStringNotContainsString("staff_route('sites.index', ['needs_review' => 1])", $layout);

        $this->actingAs($admin)
            ->getJson(route('admin.users.sites', $publisher->id))
            ->assertOk()
            ->assertJsonPath('publisher.id', $publisher->id)
            ->assertJsonPath('publisher.email', $publisher->email)
            ->assertJsonFragment([
                'id' => $ready->id,
                'needs_review' => true,
                'awaits_publisher_details' => false,
            ]);
    }

    public function test_activate_and_verify_clear_onboarding_and_stay_in_user_sites(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');

        $toActivate = $this->makePendingSite($publisher, [
            'site_name' => 'Activate Me',
            'site_url' => 'https://activate-me.example',
            'domain' => 'activate-me.example',
        ]);
        $toVerify = $this->makePendingSite($publisher, [
            'site_name' => 'Verify Me',
            'site_url' => 'https://verify-me.example',
            'domain' => 'verify-me.example',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $toActivate->id), ['verified' => 1])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $toActivate->id), ['active' => 1])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.verify', $toVerify->id), ['verified' => 1])
            ->assertOk()
            ->assertJson(['success' => true]);

        $activated = $toActivate->fresh();
        $verified = $toVerify->fresh();

        $this->assertTrue((bool) $activated->active);
        $this->assertNull($activated->onboarding_status);
        $this->assertFalse($activated->needsAdminReview());

        $this->assertTrue((bool) $verified->verified);
        $this->assertNull($verified->onboarding_status);
        $this->assertFalse($verified->needsAdminReview());

        // After decision, publisher drops out of the needs-review queue list…
        $this->actingAs($admin)
            ->get(route('admin.sites.index', ['needs_review' => 1]))
            ->assertOk()
            ->assertDontSee($publisher->email, false);

        // …but remains visible on the unfiltered Sites index (sidebar default).
        $this->actingAs($admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee($publisher->email, false);

        // …and their activated/verified sites still load via userSites for the detail view.
        $payload = $this->actingAs($admin)
            ->getJson(route('admin.users.sites', $publisher->id))
            ->assertOk()
            ->assertJsonPath('publisher.id', $publisher->id)
            ->json();

        $siteIds = collect($payload['sites'] ?? [])->pluck('id')->all();
        $this->assertContains($activated->id, $siteIds);
        $this->assertContains($verified->id, $siteIds);

        $byId = collect($payload['sites'])->keyBy('id');
        $this->assertFalse((bool) $byId[$activated->id]['needs_review']);
        $this->assertFalse((bool) $byId[$verified->id]['needs_review']);
        $this->assertTrue((bool) $byId[$activated->id]['active']);
        $this->assertTrue((bool) $byId[$verified->id]['verified']);
    }
}
