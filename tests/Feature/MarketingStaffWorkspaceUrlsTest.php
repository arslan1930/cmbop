<?php

namespace Tests\Feature;

use App\Mail\BulkSiteRequestSubmitted;
use App\Mail\NewSiteNotification;
use App\Mail\SiteClaimSubmitted;
use App\Models\BulkSiteRequest;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Support\StaffWorkspace;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingStaffWorkspaceUrlsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketer = $this->userWithRole('marketing');
        $this->publisher = $this->userWithRole('publisher');
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function pendingSite(): Site
    {
        return Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Staff URL Site',
            'site_url' => 'https://staff-url.example',
            'domain' => 'staff-url.example',
            'example_url' => 'https://staff-url.example/sample',
            'da' => 30,
            'dr' => 30,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Staff workspace URL review site. ', 2),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
    }

    public function test_staff_ops_url_rewrites_search_and_bulk_but_not_claims(): void
    {
        $search = '/admin/sites?needs_review=1&publisher=9&site=4';
        $bulk = '/admin/bulk-site-requests/12';
        $claim = '/admin/community?tab=claims&status=pending';
        $verify = '/admin/sites/4/verify';
        $records = '/admin/sites/records';
        $recordsExport = '/admin/sites/records/export';

        $this->assertSame(
            '/marketing/sites?needs_review=1&publisher=9&site=4',
            staff_ops_url_for($this->marketer, $search)
        );
        $this->assertSame('/marketing/bulk-site-requests/12', staff_ops_url_for($this->marketer, $bulk));
        $this->assertSame($claim, staff_ops_url_for($this->marketer, $claim));
        $this->assertSame($verify, staff_ops_url_for($this->marketer, $verify));
        $this->assertSame($records, staff_ops_url_for($this->marketer, $records));
        $this->assertSame($recordsExport, staff_ops_url_for($this->marketer, $recordsExport));
        $this->assertSame($search, staff_ops_url_for($this->admin, $search));
        $this->assertSame($bulk, staff_ops_url_for($this->admin, $bulk));
        $this->assertFalse(StaffWorkspace::isMarketingOpsPath('community'));
        $this->assertFalse(StaffWorkspace::isMarketingOpsPath('sites/records'));
        $this->assertFalse(StaffWorkspace::isMarketingOpsPath('sites/records/export'));
        $this->assertFalse(StaffWorkspace::isMarketingOpsPath('sitesomething'));
        $this->assertTrue(StaffWorkspace::isMarketingOpsPath('sites'));
        $this->assertTrue(StaffWorkspace::isMarketingOpsPath('sites/4/edit'));
    }

    public function test_new_site_bell_and_email_use_workspace_search_url(): void
    {
        $site = $this->pendingSite();

        app(InAppNotificationService::class)->notifyAdminsNewSite($site, 'create');

        $adminNote = InAppNotification::query()
            ->where('user_id', $this->admin->id)
            ->where('related_type', Site::class)
            ->where('related_id', $site->id)
            ->first();
        $marketingNote = InAppNotification::query()
            ->where('user_id', $this->marketer->id)
            ->where('related_type', Site::class)
            ->where('related_id', $site->id)
            ->first();

        $this->assertNotNull($adminNote);
        $this->assertNotNull($marketingNote);
        $this->assertStringContainsString('/admin/sites', (string) $adminNote->action_url);
        $this->assertStringContainsString('needs_review=1', (string) $adminNote->action_url);
        $this->assertStringContainsString('publisher='.$this->publisher->id, (string) $adminNote->action_url);
        $this->assertStringContainsString('site='.$site->id, (string) $adminNote->action_url);
        $this->assertStringContainsString('/marketing/sites', (string) $marketingNote->action_url);
        $this->assertStringContainsString('needs_review=1', (string) $marketingNote->action_url);
        $this->assertStringNotContainsString('/admin/sites', (string) $marketingNote->action_url);

        $adminHtml = (new NewSiteNotification($site, 'create', $this->admin))->render();
        $marketingHtml = (new NewSiteNotification($site, 'create', $this->marketer))->render();
        $this->assertStringContainsString('/admin/sites', $adminHtml);
        $this->assertStringContainsString('needs_review=1', $adminHtml);
        $this->assertStringContainsString('/marketing/sites', $marketingHtml);
        $this->assertStringContainsString('needs_review=1', $marketingHtml);
        $this->assertStringNotContainsString('/admin/sites', $marketingHtml);
    }

    public function test_bulk_bell_and_email_use_workspace_show_url(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
        ]);

        app(InAppNotificationService::class)->notifyStaffBulkSiteRequestSubmitted($bulk);

        $adminNote = InAppNotification::query()
            ->where('user_id', $this->admin->id)
            ->where('related_type', BulkSiteRequest::class)
            ->where('related_id', $bulk->id)
            ->first();
        $marketingNote = InAppNotification::query()
            ->where('user_id', $this->marketer->id)
            ->where('related_type', BulkSiteRequest::class)
            ->where('related_id', $bulk->id)
            ->first();

        $this->assertStringContainsString('/admin/bulk-site-requests/'.$bulk->id, (string) $adminNote?->action_url);
        $this->assertStringContainsString('/marketing/bulk-site-requests/'.$bulk->id, (string) $marketingNote?->action_url);

        $adminHtml = (new BulkSiteRequestSubmitted($bulk, $this->admin))->render();
        $marketingHtml = (new BulkSiteRequestSubmitted($bulk, $this->marketer))->render();
        $this->assertStringContainsString('/admin/bulk-site-requests/'.$bulk->id, $adminHtml);
        $this->assertStringContainsString('/marketing/bulk-site-requests/'.$bulk->id, $marketingHtml);
        $this->assertStringNotContainsString('/admin/bulk-site-requests/', $marketingHtml);
        $this->assertSame('bulk-request-'.$bulk->id.':staff:'.$this->admin->id, (new BulkSiteRequestSubmitted($bulk, $this->admin))->dedupeKey);
        $this->assertSame('bulk-request-'.$bulk->id.':staff:'.$this->marketer->id, (new BulkSiteRequestSubmitted($bulk, $this->marketer))->dedupeKey);
    }

    public function test_claim_bell_and_email_stay_admin_only_on_community(): void
    {
        $site = $this->pendingSite();
        $claimer = $this->userWithRole('publisher');
        $claim = SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Registrar and CMS access match the listing name.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);

        app(InAppNotificationService::class)->notifyAdminsSiteClaimSubmitted($claim);

        $adminNote = InAppNotification::query()
            ->where('user_id', $this->admin->id)
            ->where('title', 'New site ownership claim')
            ->first();
        $this->assertNotNull($adminNote);
        $this->assertStringContainsString('/admin/community', (string) $adminNote->action_url);
        $this->assertStringContainsString('tab=claims', (string) $adminNote->action_url);

        $this->assertSame(
            0,
            InAppNotification::query()
                ->where('user_id', $this->marketer->id)
                ->where('title', 'New site ownership claim')
                ->count()
        );

        $html = (new SiteClaimSubmitted($claim))->render();
        $this->assertStringContainsString('/admin/community', $html);
        $this->assertStringContainsString('tab=claims', $html);
        $this->assertStringNotContainsString('/marketing/community', $html);
    }

    public function test_inbox_rewrites_legacy_admin_ops_urls_for_marketers(): void
    {
        $legacy = InAppNotification::create([
            'user_id' => $this->marketer->id,
            'audience' => InAppNotification::AUDIENCE_ADMIN,
            'type' => InAppNotificationService::TYPE_SYSTEM,
            'category' => InAppNotificationService::CATEGORY_SYSTEM,
            'title' => 'Legacy bulk request',
            'message' => 'Stored before per-recipient URLs.',
            'status' => InAppNotification::STATUS_UNREAD,
            'action_label' => 'Open',
            'action_url' => '/admin/bulk-site-requests/44',
        ]);
        $claimLegacy = InAppNotification::create([
            'user_id' => $this->marketer->id,
            'audience' => InAppNotification::AUDIENCE_ADMIN,
            'type' => InAppNotificationService::TYPE_SYSTEM,
            'category' => InAppNotificationService::CATEGORY_SYSTEM,
            'title' => 'Legacy claim',
            'status' => InAppNotification::STATUS_UNREAD,
            'action_url' => '/admin/community?tab=claims&status=pending',
        ]);

        $this->assertSame('/admin/bulk-site-requests/44', $legacy->action_url);
        $this->assertSame('/marketing/bulk-site-requests/44', $legacy->actionUrlFor($this->marketer));
        $this->assertSame('/admin/community?tab=claims&status=pending', $claimLegacy->actionUrlFor($this->marketer));

        $payload = $this->actingAs($this->marketer)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->json('notifications');

        $byTitle = collect($payload)->keyBy('title');
        $this->assertSame('/marketing/bulk-site-requests/44', $byTitle['Legacy bulk request']['action_url']);
        $this->assertSame('/admin/community?tab=claims&status=pending', $byTitle['Legacy claim']['action_url']);
    }

    public function test_marketer_admin_search_and_bulk_links_redirect_with_query(): void
    {
        $site = $this->pendingSite();
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);

        $this->actingAs($this->marketer)
            ->get('/admin/sites?needs_review=1&publisher='.$this->publisher->id.'&site='.$site->id)
            ->assertRedirect('/marketing/sites?needs_review=1&publisher='.$this->publisher->id.'&site='.$site->id);

        $this->actingAs($this->marketer)
            ->get('/admin/bulk-site-requests/'.$bulk->id)
            ->assertRedirect('/marketing/bulk-site-requests/'.$bulk->id);

        $this->actingAs($this->marketer)
            ->get('/admin/community?tab=claims&status=pending')
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($this->marketer)
            ->get('/admin/sites/records')
            ->assertRedirect(route('marketing.dashboard'));
    }
}
