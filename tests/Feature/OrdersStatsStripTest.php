<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersStatsStripTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Funnel KPI Site',
            'site_url' => 'https://funnel-kpi.example',
            'domain' => 'funnel-kpi.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, Site $site, array $attrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-KPI-'.uniqid(),
            'reference_code' => 'REF-KPI-'.uniqid(),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ], $attrs));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
        ], $itemAttrs));

        return $order->fresh('items');
    }

    public function test_orders_page_shows_funnel_kpi_strip(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Needs review', $html);
        $this->assertStringContainsString('In progress', $html);
        $this->assertStringContainsString('Completed', $html);
        $this->assertStringContainsString('Awaiting payment', $html);
        $this->assertStringContainsString('id="ordNeedsReview"', $html);
        $this->assertStringContainsString('id="ordInProgress"', $html);
        $this->assertStringContainsString('id="ordCompleted"', $html);
        $this->assertStringContainsString('Marked complete', $html);
        $this->assertStringNotContainsString('Approved &amp; live', $html);
        $this->assertStringContainsString('id="ordAwaitingPayment"', $html);
        $this->assertStringContainsString('wallet-kpi', $html);
        $this->assertStringContainsString('AdvertiserOrdersConfig', $html);
        $this->assertStringContainsString('assets/js/advertiser-orders.js', $html);
        $this->assertStringContainsString('statistics:', $html);
        // Relative paths avoid APP_URL host mismatches breaking live fetch.
        $this->assertMatchesRegularExpression('/statistics:\s*"(\\\\?\/|https?:\\\\?\/\\\\?\/)[^"]*orders\\\\?\/statistics"/', $html);

        $this->assertStringNotContainsString('Total Deposits', $html);
        $this->assertStringNotContainsString('id="ordTotalDeposits"', $html);
        $this->assertStringNotContainsString('reports/statistics', str_replace('\\/', '/', $html));

        $js = file_get_contents(public_path('assets/js/advertiser-orders.js'));
        $this->assertIsString($js);
        $this->assertStringContainsString('function loadOrdStatistics', $js);
        $this->assertStringContainsString('document.hidden', $js);
    }

    public function test_order_statistics_endpoint_returns_funnel_counts(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $this->makeOrder($advertiser, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'live_url' => 'https://funnel-kpi.example/live',
        ]);
        $this->makeOrder($advertiser, $site, [
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);
        $this->makeOrder($advertiser, $site, [
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);
        $this->makeOrder($advertiser, $site, [
            'status' => 'pending',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);
        $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.statistics'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'needs_review' => 1,
                    'needs_action' => 1,
                    'in_progress' => 2,
                    'completed' => 1,
                    'awaiting_payment' => 1,
                ],
            ]);

        $inProgressList = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['status' => 'in_progress']))
            ->assertOk()
            ->json('orders');
        $this->assertCount(2, $inProgressList);
    }

    public function test_needs_review_kpi_counts_only_review_orders_with_a_live_url(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $this->makeOrder($advertiser, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'live_url' => 'https://funnel-kpi.example/ready',
        ]);
        $this->makeOrder($advertiser, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.needs_review', 1)
            ->assertJsonPath('data.needs_action', 1);

        $reviewRows = collect($this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['status' => 'review']))
            ->assertOk()
            ->json('orders'));
        $this->assertCount(1, $reviewRows);
        $this->assertNotEmpty($reviewRows->first()['items'][0]['live_url'] ?? null);
    }

    public function test_reports_orders_payload_is_honest_for_review_and_orphans(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-REP-READY',
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'live_url' => 'https://funnel-kpi.example/ready',
        ]);
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-REP-WAIT',
            'status' => 'review',
            'payment_status' => 'paid',
        ]);
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-REP-REFUND',
            'status' => 'review',
            'payment_status' => 'refunded',
        ], [
            'live_url' => 'https://funnel-kpi.example/refunded',
        ]);
        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => '797026',
            'reference_code' => '83126',
            'subtotal' => 103.50,
            'tax' => 0,
            'total_amount' => 103.50,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
        ]);

        $rows = collect($this->actingAs($advertiser)
            ->getJson(route('advertiser.reports.orders'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('orders'))
            ->keyBy('order_number');

        $this->assertSame('URL delivered · your review', $rows['ORD-REP-READY']['status_label']);
        $this->assertSame('In review', $rows['ORD-REP-WAIT']['status_label']);
        $this->assertSame('Refunded', $rows['ORD-REP-REFUND']['status_label']);
        $this->assertSame('status-cancelled', $rows['ORD-REP-REFUND']['status_cls']);
        $this->assertTrue($rows['797026']['placements_missing']);
        $this->assertSame('Completed', $rows['797026']['status_label']);
        $this->assertSame([], $rows['797026']['items']);

        $js = file_get_contents(resource_path('views/advertiser/reports.blade.php'));
        $this->assertIsString($js);
        $this->assertStringContainsString('function repOrderStatusBadge', $js);
        $this->assertStringContainsString('order.status_label', $js);
        $this->assertStringContainsString('order.placements_missing', $js);
        $this->assertStringContainsString("payment === 'failed' || (payment === 'refunded' && order.status !== 'completed')", $js);
        $this->assertStringContainsString("order.payment_status === 'refunded'", $js);
        $this->assertStringContainsString('<span class="small">Refunded</span>', $js);
        $this->assertStringContainsString('<span class="small">Failed</span>', $js);
        $this->assertStringNotContainsString("else if (order.status === 'completed') statusBadge = '<span class=\"badge bg-success\">Completed</span>';", $js);
    }

    public function test_reports_page_no_longer_shows_kpi_strip(): void
    {
        // ReportsController::index uses MySQL DATE_FORMAT; assert the Blade markup directly.
        $html = file_get_contents(resource_path('views/advertiser/reports.blade.php'));

        $this->assertIsString($html);
        $this->assertStringNotContainsString('id="repTotalDeposits"', $html);
        $this->assertStringNotContainsString('id="repTotalSpent"', $html);
        $this->assertStringNotContainsString('id="repTotalOrders"', $html);
        $this->assertStringNotContainsString('loadRepStatistics', $html);
        $this->assertStringContainsString('Funds Activity', $html);
        $this->assertStringContainsString('id="repFundsTab"', $html);
        $this->assertStringContainsString('id="repOrdersTab"', $html);
    }

    public function test_reports_statistics_endpoint_still_works(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.reports.statistics'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_deposits' => 0,
                    'total_spent' => 0,
                    'total_orders' => 0,
                ],
            ]);
    }
}
