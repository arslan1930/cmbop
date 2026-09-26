<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\ContentModerationLog;
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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
            ->assertSee('All-time euro order totals')
            ->assertSee('last 7 days')
            ->assertSee('Collected this month')
            ->assertSee('Rolling paid-order euros by paid date')
            ->assertSee('Missing tax invoices')
            ->assertSee('Articles in review')
            ->assertSee('refreshAdminDashboardQueues')
            ->assertSee('data-queue-meta="deposits"', false)
            ->assertSee(route('admin.finance', ['period' => 'month']), false)
            ->assertSee(route('admin.finance', ['period' => 'all']), false)
            ->assertSee(route('admin.invoices.index', ['queue' => 'missing']), false)
            ->assertSee(route('admin.invoices.index', ['pdf' => 'missing']), false)
            ->assertSee(route('admin.content-library.index', ['availability' => 'evaluating']), false)
            ->assertSee(route('admin.campaigns.index', ['status' => 'attention']), false)
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
            ->assertSee('kpiBulk')
            ->assertSee('kpiMail')
            ->assertSee('kpiModeration')
            ->assertSee('kpiEnrichment')
            ->assertSee('kpiCatalogHide')
            ->assertSee("row.classList.add('d-none')", false)
            ->assertSee('setQueuePanel')
            ->assertSee('setText')
            ->assertSee('queuesAllClear')
            ->assertSee('All queues are clear.')
            ->assertSee('Users with more than one role appear in more than one slice.')
            ->assertSee('js-queue-panel')
            ->assertSee('Failed mail')
            ->assertSee('Moderation errors')
            ->assertSee('Catalog hide-mode')
            ->assertSee('Bulk requests')
            ->assertSee(route('admin.bulk-site-requests.index', ['status' => 'needs_marketer']), false)
            ->assertSee(route('admin.moderation.index', ['status' => 'error']), false)
            ->assertSee(route('admin.catalog-activity'), false)
            ->assertSee(route('admin.emails.index'), false)
            ->assertSee('Live announcements')
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
                'open_bulk_requests' => 0,
                'failed_mail' => 0,
                'moderation_errors' => 0,
                'enrichment_failed' => 0,
                'catalog_hide' => 0,
                'missing_tax_invoices' => 0,
                'missing_pdf_invoices' => 0,
                'library_evaluating' => 0,
                'campaigns_attention' => 0,
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
                'bulk' => [],
                'mail' => [],
                'moderation' => [],
                'catalog_hide' => [],
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

        $deposit = DepositRequest::create([
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

        $queue = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('deposits.0.url', route('admin.deposits', ['status' => 'pending', 'search' => 'DEP-'.$deposit->id]))
            ->assertJsonPath('deposits.0.method_label', 'wise')
            ->assertJsonPath('deposits.0.action_label', 'Review')
            ->assertJsonPath('withdrawals.0.url', route('admin.withdrawals', ['queue' => 'open', 'search' => 'WD-'.$withdrawal->id]))
            ->assertJsonPath('withdrawals.0.method_label', 'paypal')
            ->assertJsonPath('withdrawals.0.action_label', 'Mark paid')
            ->assertJsonPath('withdrawals.0.id', $withdrawal->id)
            ->assertJsonPath('sites.0.url', route('admin.sites.edit', $site->id))
            ->json();

        $depositAction = (string) ($queue['deposits'][0]['action_url'] ?? '');
        $this->assertStringContainsString('/admin/deposits/'.$deposit->id.'/approve-confirm', $depositAction);
        $this->assertStringContainsString('signature=', $depositAction);
        $this->actingAs($admin)
            ->get($depositAction)
            ->assertOk()
            ->assertSee('Confirm deposit approval', false);

        $withdrawalAction = (string) ($queue['withdrawals'][0]['action_url'] ?? '');
        $this->assertStringContainsString('/admin/withdrawals/'.$withdrawal->id.'/mark-paid-confirm', $withdrawalAction);
        $this->assertStringContainsString('signature=', $withdrawalAction);
        $this->actingAs($admin)
            ->get($withdrawalAction)
            ->assertOk()
            ->assertSee('Confirm marked paid', false);

        $this->assertNotEmpty($queue['deposits'][0]['age'] ?? null);
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
            ->assertJsonPath('data.url', route('admin.finance', ['period' => 'month']))
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
            ->assertJsonPath('unpaid.0.method_label', 'bank')
            ->assertJsonPath('unpaid.0.url', route('admin.payments', [
                'payment_status' => 'unpaid',
                'search' => 'ORD-UNPAID-1',
            ]))
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

    public function test_action_queue_ok_when_dispute_created_at_is_unparseable(): void
    {
        $admin = $this->makeAdmin();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'active_role_id' => $publisherRole->id,
            'email_verified_at' => now(),
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leftover Dispute Site',
            'site_url' => 'https://leftover-dispute.example',
            'domain' => 'leftover-dispute.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Leftover dispute fixture site',
            'verified' => 1,
            'active' => 1,
        ]);
        $paid = Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-DSP-LEFTOVER',
            'reference_code' => 'REF-DSP-LEFTOVER',
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
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 80,
            'publisher_price' => 68,
            'content_link' => 'https://example.com/article',
        ]);
        OrderItemDispute::ensureTable();
        $dispute = OrderItemDispute::create([
            'order_id' => $paid->id,
            'order_item_id' => $item->id,
            'opened_by' => $admin->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live link was removed after approval.',
        ]);
        DB::table('order_item_disputes')->where('id', $dispute->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('disputes.0.order_number', 'ORD-DSP-LEFTOVER')
            ->assertJsonPath('disputes.0.url', route('admin.orders.show', $paid->id));
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

    public function test_statistics_and_trends_survive_missing_paid_at_column(): void
    {
        if (! Schema::hasColumn('orders', 'paid_at')) {
            $this->markTestSkipped('orders.paid_at is already absent');
        }

        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('paid_at');
            });
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop orders.paid_at on this driver');
        }

        if (Schema::hasColumn('orders', 'paid_at')) {
            $this->markTestSkipped('orders.paid_at is still present after drop');
        }

        try {
            $admin = $this->makeAdmin();

            $this->actingAs($admin)
                ->getJson(route('admin.dashboard.statistics'))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->actingAs($admin)
                ->getJson(route('admin.dashboard.trends'))
                ->assertOk()
                ->assertJsonPath('success', true);
        } finally {
            if (! Schema::hasColumn('orders', 'paid_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->timestamp('paid_at')->nullable();
                });
            }
        }
    }

    public function test_needs_attention_includes_bulk_mail_moderation_enrichment_and_hide_mode(): void
    {
        $admin = $this->makeAdmin();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create([
            'active_role_id' => $publisherRole->id,
            'email_verified_at' => now(),
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://bulk-queue.example',
            'domain' => 'bulk-queue.example',
            'price' => 40,
            'site_id' => null,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => 'App\\Mail\\WelcomeEmail',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            ]),
            'exception' => 'SMTP timeout while sending welcome mail',
            'failed_at' => now()->subHours(3),
        ]);

        $modLog = ContentModerationLog::create([
            'user_id' => $admin->id,
            'document_url' => 'upload:dashboard-mod',
            'status' => ContentModerationLog::STATUS_ERROR,
            'passed' => false,
            'error_code' => 'provider_timeout',
            'error_message' => 'Scanner timed out',
            'scan_token' => 'scan-dashboard',
            'word_count' => 12,
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dashboard enrich fail',
            'site_url' => 'https://dashboard-enrich.example',
            'domain' => 'dashboard-enrich.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Enrichment failure for dashboard queue',
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

        $hidden = User::factory()->create([
            'email_verified_at' => now(),
            'catalog_hide_until' => now()->addHours(6),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('open_bulk_requests', 1)
            ->assertJsonPath('failed_mail', 1)
            ->assertJsonPath('moderation_errors', 1)
            ->assertJsonPath('enrichment_failed', 1)
            ->assertJsonPath('catalog_hide', 1)
            ->assertJsonPath('needs_attention', 5);

        $queue = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('bulk.0.id', $bulk->id)
            ->assertJsonPath('bulk.0.url', route('admin.bulk-site-requests.show', $bulk->id))
            ->assertJsonPath('mail.0.url', route('admin.emails.index'))
            ->assertJsonPath('moderation.0.id', $modLog->id)
            ->assertJsonPath('moderation.0.url', route('admin.moderation.show', $modLog->id))
            ->assertJsonPath('enrichment.0.site_name', 'Dashboard enrich fail')
            ->assertJsonPath('catalog_hide.0.id', $hidden->id)
            ->json();

        $this->assertNotEmpty($queue['bulk'][0]['age'] ?? null);
        $this->assertStringContainsString('SMTP', (string) ($queue['mail'][0]['label'] ?? ''));
    }
}
