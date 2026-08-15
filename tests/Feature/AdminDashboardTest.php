<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSuggestion;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $role = Role::create(['name' => 'admin']);
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_admin_dashboard_loads(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin Dashboard')
            ->assertSee('Needs Attention')
            ->assertSee('GMV (paid orders)')
            ->assertSee('in review')
            ->assertSee('live in catalog')
            ->assertSee('Margin & wallets')
            ->assertSee('pending_community')
            ->assertSee('Remind the publisher, or open the order to refund.')
            ->assertDontSee('Chase again or refund the advertiser.')
            ->assertSee(route('admin.deposits', ['status' => 'pending']), false)
            ->assertSee(route('admin.withdrawals', ['queue' => 'open']), false)
            ->assertSee(route('admin.sites.index', ['needs_review' => 1]), false)
            ->assertSee(route('admin.sites.records'), false)
            ->assertSee('dashboardFetch')
            ->assertSee('js-dashboard-retry')
            ->assertSee('kpiRetry')
            ->assertSee('showRetry')
            ->assertSee('Due to pay now')
            ->assertSee('In publisher wallets')
            ->assertSee('Total publisher liability')
            ->assertSee('Fee margin (this month)')
            ->assertSee('Open finance')
            ->assertSee('id="financePeriod"', false)
            ->assertDontSee('id="financePeriod" class="fw-normal text-capitalize"', false)
            ->assertDontSee('text-uppercase small">Finance', false)
            ->assertSee('Unpaid orders')
            ->assertSee('Open disputes')
            ->assertSee('Community inbox')
            ->assertSee('Enrichment failed')
            ->assertSee(route('admin.payments', ['payment_status' => 'unpaid']), false)
            ->assertSee(route('admin.orders.index', ['dispute' => 'open']), false)
            ->assertSee('unpaid ·')
            ->assertSee('community ·')
            ->assertSee('disputes')
            ->assertSee(route('admin.community.index', ['status' => 'pending']), false)
            ->assertSee(route('admin.site-enrichment.index'), false)
            ->assertSee('loadFinanceStrip')
            ->assertSee('js-kpi-link')
            ->assertSee('js-kpi-users-caption')
            ->assertSee('All accounts. Role counts can overlap.')
            ->assertSee('kpiAdmins')
            ->assertSee('kpiMarketers')
            ->assertSee('kpiStalled')
            ->assertSee("row.classList.add('d-none')", false)
            ->assertSee('js-chart-range')
            ->assertSee('js-chart-range-label')
            ->assertSee('id="dashboardActionQueues"', false)
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.sites.records'), false)
            ->assertSee(route('admin.finance'), false)
            ->assertSee('Fee margin (this month)', false)
            ->assertSee('Fees − fee reversals − bonuses', false)
            ->assertSee('js/chart.umd.min.js')
            ->assertDontSee('cdn.jsdelivr.net/npm/chart.js', false)
            ->assertSee('scrollIntoView')
            ->assertSee('backgroundColor: palette')
            ->assertDontSee("backgroundColor: ['#1a585e', '#0ea5e9', '#75787B']", false);
    }

    public function test_admin_queue_counts_endpoint(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'pending_deposits' => 0,
                'pending_withdrawals' => 0,
                'unverified_sites' => 0,
                'pending_payments' => 0,
                'pending_claims' => 0,
                'pending_community' => 0,
                'open_disputes' => 0,
                'stalled_orders' => 0,
                'needs_attention' => 0,
            ]);
    }

    public function test_admin_statistics_endpoint(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_users', 1)
            ->assertJsonPath('data.admins', 1)
            ->assertJsonPath('data.marketers', 0)
            ->assertJsonPath('data.stalled_orders', 0)
            ->assertJsonPath('data.advertisers', 0)
            ->assertJsonPath('data.pending_deposits', 0)
            ->assertJsonPath('data.needs_attention', 0)
            ->assertJsonPath('data.live_sites', 0);
    }

    public function test_admin_trends_distributions_and_action_queue_endpoints(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.trends'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(30, 'labels')
            ->assertJsonCount(30, 'revenue');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.trends', ['days' => 7]))
            ->assertOk()
            ->assertJsonCount(7, 'labels')
            ->assertJsonCount(7, 'revenue');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.trends', ['days' => 90]))
            ->assertOk()
            ->assertJsonCount(90, 'labels')
            ->assertJsonCount(90, 'revenue');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.trends', ['days' => 3]))
            ->assertOk()
            ->assertJsonCount(7, 'labels');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.distributions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['orders' => ['labels', 'values'], 'roles' => ['labels', 'values']]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'deposits' => [],
                'withdrawals' => [],
                'sites' => [],
                'unpaid' => [],
                'disputes' => [],
                'community' => [],
                'enrichment' => [],
            ]);
    }

    public function test_non_admin_cannot_access_ops_dashboard(): void
    {
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertStatus(403);
    }

    public function test_processing_withdrawals_appear_in_the_action_queue(): void
    {
        $admin = $this->makeAdmin();
        Withdrawal::create([
            'user_id' => $admin->id,
            'amount' => 40,
            'fee' => 5,
            'net_amount' => 35,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'processing',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('withdrawals.0.status', 'processing')
            ->assertJsonPath('withdrawals.0.amount', 35);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_withdrawals', 1)
            ->assertJsonPath('needs_attention', 1);
    }

    public function test_action_queue_withdrawals_break_created_at_ties_by_id(): void
    {
        $admin = $this->makeAdmin();
        $at = now()->subHour();
        $first = Withdrawal::create([
            'user_id' => $admin->id,
            'amount' => 10,
            'fee' => 0,
            'net_amount' => 10,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'pending',
        ]);
        $second = Withdrawal::create([
            'user_id' => $admin->id,
            'amount' => 20,
            'fee' => 0,
            'net_amount' => 20,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'b@c.com'],
            'status' => 'pending',
        ]);
        $first->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        $second->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('withdrawals.0.id', $second->id)
            ->assertJsonPath('withdrawals.1.id', $first->id);
    }

    public function test_needs_attention_includes_unpaid_orders_and_community(): void
    {
        $admin = $this->makeAdmin();

        Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-ATTN-1',
            'reference_code' => 'REF-ATTN-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'bank',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        ProblemReport::create([
            'name' => 'Reporter',
            'email' => 'ops@example.com',
            'subject' => 'Broken checkout',
            'message' => 'Cannot pay',
            'status' => 'pending',
        ]);
        Suggestion::create([
            'name' => 'Suggester',
            'email' => 'idea@example.com',
            'message' => 'Add more filters',
            'status' => 'pending',
        ]);
        WebsiteSuggestion::create([
            'website_name' => 'Example Mag',
            'website_url' => 'https://example-mag.test',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_payments', 1)
            ->assertJsonPath('pending_claims', 0)
            ->assertJsonPath('pending_problems', 1)
            ->assertJsonPath('pending_suggestions', 1)
            ->assertJsonPath('pending_websites', 1)
            ->assertJsonPath('pending_community', 3)
            ->assertJsonPath('needs_attention', 4);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.needs_attention', 4)
            ->assertJsonPath('data.pending_payments', 1)
            ->assertJsonPath('data.pending_community', 3);
    }

    public function test_seven_day_gmv_uses_paid_at_not_created_at(): void
    {
        $admin = $this->makeAdmin();

        $order = Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-GMV-1',
            'reference_code' => 'REF-GMV-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now()->subDay(),
        ]);
        $order->created_at = now()->subDays(10);
        $order->save();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.revenue_7d', 80)
            ->assertJsonPath('data.orders_7d', 1);

        $trends = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.trends'))
            ->assertOk()
            ->json();

        // 30-day window: index 0 is 29 days ago, 19 is created_at (10d ago), 28 is paid_at (yesterday).
        $this->assertSame(0.0, (float) $trends['revenue'][19]);
        $this->assertSame(80.0, (float) $trends['revenue'][28]);
        $this->assertSame(0, (int) $trends['orders'][19]);
        $this->assertSame(1, (int) $trends['orders'][28]);
    }

    public function test_sites_card_separates_live_catalog_from_verified_only(): void
    {
        $admin = $this->makeAdmin();
        $publisherRole = Role::create(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'active_role_id' => $publisherRole->id,
            'email_verified_at' => now(),
        ]);
        $publisher->roles()->attach($publisherRole->id);

        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Verified but dark',
            'site_url' => 'https://verified-dark.example',
            'domain' => 'verified-dark.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Verified but not active in the catalog',
            'verified' => 1,
            'active' => 0,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.verified_sites', 1)
            ->assertJsonPath('data.live_sites', 0)
            ->assertJsonPath('data.total_sites', 1);
    }

    public function test_action_queue_rows_include_admin_urls(): void
    {
        $admin = $this->makeAdmin();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'active_role_id' => $publisherRole->id,
            'email_verified_at' => now(),
        ]);
        $publisher->roles()->attach($publisherRole->id);

        DepositRequest::create([
            'user_id' => $admin->id,
            'reference_code' => '555444',
            'amount' => 25,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);

        $withdrawal = Withdrawal::create([
            'user_id' => $admin->id,
            'amount' => 15,
            'fee' => 0,
            'net_amount' => 15,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'pending',
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Review me',
            'site_url' => 'https://review-me.example',
            'domain' => 'review-me.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Needs admin review',
            'verified' => 0,
            'active' => 0,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('deposits.0.url', route('admin.deposits', ['status' => 'pending']))
            ->assertJsonPath('withdrawals.0.url', route('admin.withdrawals.show', $withdrawal->id, false))
            ->assertJsonPath('withdrawals.0.id', $withdrawal->id)
            ->assertJsonPath('sites.0.url', route('admin.sites.edit', $site->id));
    }

    public function test_finance_strip_matches_overview_service(): void
    {
        $admin = $this->makeAdmin();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'active_role_id' => $publisherRole->id,
            'email_verified_at' => now(),
        ]);
        $publisher->roles()->attach($publisherRole->id);

        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisherRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'pending',
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('month')
        );

        $json = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.finance'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period_label', $overview['period']['label'])
            ->assertJsonPath('data.url', route('admin.finance'))
            ->json('data');

        $this->assertEquals($overview['due_to_pay_now'], $json['due_to_pay_now']);
        $this->assertEquals($overview['in_publisher_wallets'], $json['in_publisher_wallets']);
        $this->assertEquals($overview['total_publisher_liability'], $json['total_publisher_liability']);
        $this->assertEquals($overview['platform']['margin'], $json['margin']);
    }

    public function test_action_queue_includes_unpaid_disputes_community_and_enrichment(): void
    {
        $admin = $this->makeAdmin();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'active_role_id' => $publisherRole->id,
            'email_verified_at' => now(),
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $order = Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-UNPAID-1',
            'reference_code' => 'REF-UNPAID-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'bank',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $disputeSite = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dispute Site',
            'site_url' => 'https://dispute.example',
            'domain' => 'dispute.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Dispute fixture site',
            'verified' => 1,
            'active' => 1,
        ]);
        $paid = Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-DSP-1',
            'reference_code' => 'REF-DSP-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDay(),
        ]);
        $item = OrderItem::create([
            'order_id' => $paid->id,
            'site_id' => $disputeSite->id,
            'site_name' => $disputeSite->site_name,
            'site_url' => $disputeSite->site_url,
            'price' => 80,
            'publisher_price' => 68,
            'content_link' => 'https://example.com/article',
        ]);
        OrderItemDispute::ensureTable();
        OrderItemDispute::create([
            'order_id' => $paid->id,
            'order_item_id' => $item->id,
            'opened_by' => $admin->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live link was removed after approval.',
        ]);

        ProblemReport::create([
            'name' => 'Reporter',
            'email' => 'ops@example.com',
            'subject' => 'Broken checkout',
            'message' => 'Cannot pay',
            'status' => 'pending',
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Failed enrich',
            'site_url' => 'https://failed-enrich.example',
            'domain' => 'failed-enrich.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Enrichment failure fixture',
            'verified' => 1,
            'active' => 1,
        ]);
        SiteEnrichmentRun::create([
            'site_id' => $site->id,
            'type' => 'metrics',
            'provider' => 'manual',
            'status' => 'failed',
            'error' => 'Provider timed out',
            'triggered_by' => 'admin',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('unpaid.0.order_number', 'ORD-UNPAID-1')
            ->assertJsonPath('unpaid.0.url', route('admin.orders.show', $order->id))
            ->assertJsonPath('disputes.0.order_number', 'ORD-DSP-1')
            ->assertJsonPath('disputes.0.url', route('admin.orders.show', $paid->id))
            ->assertJsonPath('community.0.type', 'problem')
            ->assertJsonPath('community.0.label', 'Broken checkout')
            ->assertJsonPath('community.0.url', route('admin.community.index', ['tab' => 'problems', 'status' => 'pending']))
            ->assertJsonPath('enrichment.0.site_name', 'Failed enrich')
            ->assertJsonPath('enrichment.0.url', route('admin.sites.edit', $site->id));

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['payment_status' => 'unpaid']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => 'ORD-UNPAID-1'])
            ->assertJsonMissing(['order_number' => 'ORD-DSP-1']);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['dispute' => 'open']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => 'ORD-DSP-1'])
            ->assertJsonMissing(['order_number' => 'ORD-UNPAID-1']);
    }

    public function test_metrics_cache_is_off_by_default_and_can_be_enabled(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_users', 1);

        User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_users', 2);

        config(['dashboard.metrics_cache_seconds' => 60]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_users', 2);

        User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_users', 2);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_deposits', 0);

        DepositRequest::create([
            'user_id' => $admin->id,
            'reference_code' => '555333',
            'amount' => 10,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_deposits', 1);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_users', 2)
            ->assertJsonPath('data.pending_deposits', 1)
            ->assertJsonPath('data.needs_attention', 1);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('deposits.0.amount', 10);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.finance'))
            ->assertOk()
            ->assertJsonPath('data.due_to_pay_now', 0);

        Withdrawal::create([
            'user_id' => $admin->id,
            'amount' => 20,
            'fee' => 5,
            'net_amount' => 15,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.finance'))
            ->assertOk()
            ->assertJsonPath('data.due_to_pay_now', 15);
    }
}
