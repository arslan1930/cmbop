<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\DashboardMetricsService;
use App\Support\CountryCode;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardAuditFixesTest extends TestCase
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

    public function test_users_list_shows_wallet_orders_and_exports_csv(): void
    {
        $admin = $this->userWithRole('admin', ['name' => 'Ops Admin', 'email' => 'ops.admin@example.com']);
        $advertiser = $this->userWithRole('advertiser', [
            'name' => 'Wallet Buyer',
            'email' => 'wallet.buyer@example.com',
            'country' => 'Spain',
        ]);
        $this->assertSame('es', $advertiser->fresh()->country);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiser->active_role_id,
            'balance' => 42.5,
            'currency' => 'EUR',
        ]);
        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-WALLET-1',
            'subtotal' => 10,
            'tax' => 0,
            'total_amount' => 10,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'wallet.buyer']))
            ->assertOk()
            ->assertSee('€42.50', false)
            ->assertSee('data-paid-orders="1"', false)
            ->getContent();
        $this->assertStringContainsString(route('admin.users.export'), $html);

        $csv = $this->actingAs($admin)
            ->get(route('admin.users.export', ['q' => 'wallet.buyer']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('wallet_balance', $csv);
        $this->assertStringContainsString('42.50', $csv);
        $this->assertStringContainsString('wallet.buyer@example.com', $csv);
    }

    public function test_dashboard_reports_audience_unique_separately_from_total_users(): void
    {
        $admin = $this->userWithRole('admin', ['email' => 'staff.only@example.com']);
        $this->userWithRole('advertiser', ['email' => 'market.buyer@example.com']);

        $stats = app(DashboardMetricsService::class)->statistics();
        $this->assertGreaterThanOrEqual(2, $stats['total_users']);
        $this->assertLessThan($stats['total_users'], $stats['audience_unique']);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_users', $stats['total_users'])
            ->assertJsonPath('data.audience_unique', $stats['audience_unique']);
    }

    public function test_bulk_requests_can_be_claimed_in_bulk(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $first = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $second = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.bulk-site-requests.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Claim selected', false);

        $this->actingAs($admin)
            ->post(route('admin.bulk-site-requests.bulk-claim'), [
                'ids' => [$first->id, $second->id],
            ])
            ->assertRedirect();

        $this->assertSame($admin->id, (int) $first->fresh()->handled_by);
        $this->assertSame($admin->id, (int) $second->fresh()->handled_by);
    }

    public function test_ratings_and_promotions_use_ajax_search_instead_of_loading_every_row(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Searchable Publisher',
            'email' => 'searchable.pub@example.com',
        ]);
        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ajax Rated Site',
            'site_url' => 'https://ajax-rated.example',
            'domain' => 'ajax-rated.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.site-ratings.index'))
            ->assertOk()
            ->assertSee(route('admin.site-ratings.sites-search', [], false), false)
            ->assertDontSee('ajax-rated.example', false);

        $this->actingAs($admin)
            ->getJson(route('admin.site-ratings.sites-search', ['q' => 'ajax-rated']))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Ajax Rated Site (ajax-rated.example)']);

        $this->actingAs($admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee(route('admin.promotions.feature-credits.users', [], false), false)
            ->assertDontSee('searchable.pub@example.com', false);

        $this->actingAs($admin)
            ->getJson(route('admin.promotions.feature-credits.users', ['q' => 'searchable.pub']))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Searchable Publisher — searchable.pub@example.com']);
    }

    public function test_deposits_page_offers_bulk_approve_and_completed_total_label(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->assertSee('Completed total', false)
            ->assertSee('Approve selected', false)
            ->getContent();
        $this->assertStringContainsString(route('admin.deposits.bulk-approve'), $html);
    }

    public function test_email_center_can_bulk_dismiss_failed_logs(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'to_email' => 'bounce@example.com',
            'subject' => 'New User Registered',
            'status' => EmailLog::STATUS_FAILED,
            'error' => '554 spam',
            'attempts' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.emails.dismiss'), [
                'ids' => [$log->id],
            ])
            ->assertRedirect();

        $this->assertSame(EmailLog::STATUS_DISMISSED, $log->fresh()->status);
        $this->assertSame(0, EmailLog::openFailedCount());
    }

    public function test_activity_history_labels_bulk_claim_actions(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('From', false)
            ->assertSee('To', false);

        $this->assertSame('Claimed bulk request', activity_action_label('bulk_request.claimed'));
        $this->assertSame('es', CountryCode::normalize('España'));
    }
}
