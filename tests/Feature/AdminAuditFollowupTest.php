<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\AdminPageTitle;
use App\Support\AdvertiserOrderDetails;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditFollowupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_admin_root_redirects_to_dashboard(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect('/admin/dashboard');
    }

    public function test_unknown_admin_path_uses_admin_styled_404(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get('/admin/this-page-does-not-exist')
            ->assertNotFound()
            ->getContent();

        $this->assertStringContainsString('Admin page not found', $html);
        $this->assertStringContainsString(route('admin.dashboard', [], false), $html);
        $this->assertStringNotContainsString('Browse catalog', $html);
    }

    public function test_deposit_view_button_is_not_a_submit_and_confirm_is_on_bulk_button(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => '190754',
            'payment_reference' => 'SLB-49-EUR',
            'amount' => 100,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('type="button" class="btn btn-sm btn-outline-primary view-deposit"', $html);
        $this->assertStringContainsString('data-slb-confirm-title="Bulk approve deposits?"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<form[^>]+id="adminDepositsBulkForm"[^>]+data-slb-confirm-title/',
            $html
        );
        $this->assertStringContainsString('Tiles are global totals', $html);
        $this->assertStringContainsString('SLB-49-EUR', $html);
    }

    public function test_deposit_show_includes_payment_reference_label(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => '190754',
            'payment_reference' => 'SLB-49-EUR',
            'amount' => 100,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deposit.payment_reference_label', 'SLB-49-EUR');
    }

    public function test_order_detail_explains_missing_placements_and_shows_primary_id(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '797026',
            'reference_code' => '831126',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Order #797026', false)
            ->assertSee('Internal ID '.$order->id, false)
            ->assertSee('Payment ref 831126', false)
            ->assertSee('Unknown — no line items', false)
            ->assertSee('No line items', false)
            ->assertSee('Estimated', false)
            ->getContent();

        $this->assertStringContainsString('<title>Order #797026 — Admin — SEOLinkBuildings</title>', $html);
        $this->assertTrue(AdvertiserOrderDetails::placementsMissing($order));
    }

    public function test_user_detail_shows_country_name(): void
    {
        $admin = $this->userWithRole('admin');
        $member = $this->userWithRole('advertiser', [
            'country' => 'gr',
            'name' => 'Hellenic Buyer',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $member))
            ->assertOk()
            ->assertSee('Greece', false)
            ->assertDontSee('>gr<', false);
    }

    public function test_community_defaults_to_problem_reports_and_hides_test_rows(): void
    {
        $admin = $this->userWithRole('admin');
        $tester = $this->userWithRole('advertiser', ['is_test' => true, 'name' => 'Test Reporter']);
        $real = $this->userWithRole('advertiser', ['name' => 'Live Reporter']);
        $reviewer = $this->userWithRole('admin', ['name' => 'Riley Reviewer']);

        ProblemReport::create([
            'user_id' => $tester->id,
            'name' => $tester->name,
            'email' => $tester->email,
            'subject' => 'Test-only report',
            'message' => 'Should be hidden',
            'page_url' => 'https://example.com/very/long/path/that/gets-truncated-in-the-table',
            'status' => 'pending',
        ]);
        ProblemReport::create([
            'user_id' => $real->id,
            'name' => $real->name,
            'email' => $real->email,
            'subject' => 'Live report',
            'message' => 'Keep this',
            'page_url' => 'https://example.com/live-page',
            'status' => 'reviewed',
            'reviewed_by' => $reviewer->id,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index'))
            ->assertOk()
            ->assertSee('Problem reports', false)
            ->assertSee('Live report', false)
            ->assertDontSee('Test-only report', false)
            ->assertSee('Riley Reviewer', false)
            ->assertSee('title="https://example.com/live-page"', false)
            ->getContent();

        $this->assertStringContainsString('<title>Community — Admin — SEOLinkBuildings</title>', $html);
        $this->assertStringContainsString('<th>Reviewer</th>', $html);

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['include_test' => 1]))
            ->assertOk()
            ->assertSee('Test-only report', false);
    }

    public function test_withdrawals_history_path_redirects_and_history_copy_hides_queue_chrome(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/withdrawals/history')
            ->assertRedirect(route('admin.withdrawals', ['queue' => 'history']));

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals', ['queue' => 'history']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-label-history="Any status"', $html);
        $this->assertStringContainsString('Payout history', $html);
        $this->assertStringContainsString('Newest first', $html);
        $this->assertStringContainsString('initialWithdrawals', $html);
    }

    public function test_orders_index_bootstraps_server_data(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-SSR-1',
            'subtotal' => 10,
            'tax' => 0,
            'total_amount' => 10,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ORD-SSR-1', $html);
        $this->assertStringContainsString('initialOrders', $html);
        $this->assertStringContainsString('<title>Orders — Admin — SEOLinkBuildings</title>', $html);
    }

    public function test_records_search_and_category_chips(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Chip Site',
            'site_url' => 'https://chip-site.example',
            'domain' => 'chip-site.example',
            'da' => 20,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'marketing|seo',
            'price' => 20,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Chip site for records search.',
            'verified' => true,
            'active' => true,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.records', ['q' => 'chip-site']))
            ->assertOk()
            ->assertSee('recordsQuerySearch', false)
            ->assertSee('chip-site.example', false)
            ->assertSee('Below quality bar (live catalog)', false)
            ->getContent();

        $this->assertStringContainsString('<title>Records — Admin — SEOLinkBuildings</title>', $html);
        $this->assertStringContainsString('badge text-bg-light border', $html);
    }

    public function test_sites_default_heading_is_publishers(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Publisher directory', $html);
        $this->assertStringContainsString('<title>Sites — Admin — SEOLinkBuildings</title>', $html);
    }

    public function test_on_demand_requires_name_and_one_contact_method(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.sites.on-demand.index'))
            ->post(route('admin.sites.on-demand.store'), [
                'site_url' => ['only-name.example'],
                'email' => [],
                'whatsapp' => [],
                'whatsapp_code' => [],
                'contacted_via_email' => [],
            ])
            ->assertRedirect(route('admin.sites.on-demand.index'))
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)
            ->post(route('admin.sites.on-demand.store'), [
                'site_url' => ['email-only.example'],
                'email' => ['desk@example.com'],
                'whatsapp' => [],
                'whatsapp_code' => [],
                'contacted_via_email' => [],
            ])
            ->assertRedirect(route('admin.sites.on-demand.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_admin_page_title_helper_format(): void
    {
        $this->assertSame(
            'Records — Admin — SEOLinkBuildings',
            AdminPageTitle::document('', 'admin.sites.records')
        );
        $this->assertSame(
            'Deposits — Admin — SEOLinkBuildings',
            AdminPageTitle::document('Deposits', 'admin.deposits')
        );
    }

    public function test_nav_badge_script_formats_thousands(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function formatNavBadgeCount', $html);
        $this->assertStringNotContainsString("count > 99 ? '99+'", $html);
    }
}
