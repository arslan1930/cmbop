<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function publisherWithWallet(float $balance = 25): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return $user;
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function site(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dash Site',
            'site_url' => 'https://dash.example',
            'domain' => 'dash.example',
            'example_url' => 'https://dash.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Publisher dashboard site for testing. ', 3),
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    /**
     * Advertiser price includes 15% markup: listing €100 → €115 stored.
     */
    private function createOrderItem(
        User $advertiser,
        Site $site,
        array $orderAttrs = [],
        array $itemAttrs = []
    ): OrderItem {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ], $orderAttrs));

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'additional_price' => 0,
        ], $itemAttrs));
    }

    public function test_dashboard_empty_sites_renders_onboarding_and_zero_kpis(): void
    {
        $publisher = $this->publisherWithWallet(12.5);

        $response = $this->actingAs($publisher)->get(route('publisher.dashboard'));

        $response->assertOk()
            ->assertSee('No performance data yet')
            ->assertSee('€12.50')
            ->assertSee('€0.00')
            ->assertSee('dash-page-end', false);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.statistics'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_orders' => 0,
                    'total_earnings' => 0,
                    'pending_earnings' => 0,
                    'success_rate' => 0,
                    'total_sites' => 0,
                ],
            ]);
    }

    public function test_unpaid_card_orders_are_excluded_from_stats_and_recent(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $this->createOrderItem($advertiser, $site, [
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
            'paid_at' => null,
            'total_amount' => 115,
        ]);

        $this->createOrderItem($advertiser, $site, [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'total_amount' => 115,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.pending_orders', 1);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.recent'))
            ->assertOk()
            ->assertJsonCount(1, 'orders');

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.order-status'))
            ->assertOk()
            ->assertJsonPath('data.values.0', 1) // pending
            ->assertJsonPath('data.labels.2', 'In Review');
    }

    public function test_unpaid_manual_payment_orders_are_excluded_from_stats_and_recent(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $this->createOrderItem($advertiser, $site, [
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
            'paid_at' => null,
            'total_amount' => 115,
        ]);

        $this->createOrderItem($advertiser, $site, [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'total_amount' => 115,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.pending_orders', 1);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.recent'))
            ->assertOk()
            ->assertJsonCount(1, 'orders');
    }

    public function test_success_rate_uses_completed_over_resolved_not_total(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        // 2 completed, 1 cancelled, 1 still open → success = 2/3 = 66.7%, completion = 2/4 = 50%
        $this->createOrderItem($advertiser, $site, ['status' => 'completed']);
        $this->createOrderItem($advertiser, $site, ['status' => 'completed']);
        $this->createOrderItem($advertiser, $site, ['status' => 'cancelled']);
        $this->createOrderItem($advertiser, $site, ['status' => 'processing']);

        $data = $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.statistics'))
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $data['total_orders']);
        $this->assertSame(2, $data['completed_orders']);
        $this->assertSame(1, $data['cancelled_orders']);
        $this->assertEqualsWithDelta(66.7, (float) $data['success_rate'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $data['completion_rate'], 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $data['open_rate'], 0.01);
    }

    public function test_earnings_math_uses_publisher_payout_not_advertiser_price(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $completed = $this->createOrderItem($advertiser, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_amount' => 115,
        ], [
            'price' => 115,
            'additional_price' => 0,
        ]);

        $inReview = $this->createOrderItem($advertiser, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
            'total_amount' => 230,
        ], [
            'price' => 230,
            'additional_price' => 0,
        ]);

        // €115 / 1.15 = €100 publisher payout; €230 / 1.15 = €200 pending
        $this->assertSame(100.0, $completed->publisherPayoutAmount());
        $this->assertSame(200.0, $inReview->publisherPayoutAmount());

        $stats = $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.statistics'))
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(100.0, (float) $stats['total_earnings'], 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $stats['pending_earnings'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $stats['avg_order_value'], 0.01);

        $recent = $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.recent'))
            ->assertOk()
            ->json('orders');

        $this->assertNotEmpty($recent);
        foreach ($recent as $row) {
            $this->assertArrayHasKey('payout', $row);
            $this->assertArrayNotHasKey('total_amount', $row);
            $this->assertContains((float) $row['payout'], [100.0, 200.0]);
        }
    }

    public function test_weekly_earnings_prefer_item_completed_at_over_later_order_updates(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $completedDay = now()->subDays(2)->startOfDay()->addHours(12);

        $item = $this->createOrderItem($advertiser, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
        ], [
            'price' => 115,
            'additional_price' => 0,
            'completed_at' => $completedDay,
        ]);

        // Later non-completion touch on the order must not move the earnings bucket.
        $item->order->forceFill([
            'status' => 'completed',
            'payment_status' => 'paid',
            'updated_at' => now(),
        ])->save();

        $weekly = $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.weekly-earnings'))
            ->assertOk()
            ->json('data.values');

        // Index for "2 days ago" in the last-7-days window (i=6..0 → index 4)
        $this->assertEqualsWithDelta(100.0, (float) $weekly[4], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) end($weekly), 0.01);
    }

    public function test_weekly_earnings_keep_completion_day_after_later_clawback(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);

        $completedDay = now()->subDays(2)->startOfDay()->addHours(12);

        $item = $this->createOrderItem($advertiser, $site, [
            'status' => 'completed',
            'payment_status' => 'refunded',
            'completed_at' => $completedDay,
        ], [
            'price' => 115,
            'additional_price' => 0,
            'publisher_price' => 100,
            'platform_fee_percent' => 15,
            'platform_fee_amount' => 15,
            'completed_at' => $completedDay,
        ]);

        OrderItemDispute::create([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'opened_by' => $admin->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Live URL was removed after the report window started.',
            'resolved_at' => now(),
            'advertiser_credited' => 115,
            'publisher_debited' => 100,
        ]);

        $weekly = $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.weekly-earnings'))
            ->assertOk()
            ->json('data.values');

        $this->assertEqualsWithDelta(100.0, (float) $weekly[4], 0.01);
        $this->assertEqualsWithDelta(-100.0, (float) end($weekly), 0.01);

        $stats = $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.statistics'))
            ->assertOk()
            ->json('data');
        $this->assertEqualsWithDelta(0.0, (float) $stats['total_earnings'], 0.01);
    }

    public function test_dashboard_ssr_surfaces_balance_pending_earnings_and_both_task_kpis(): void
    {
        $publisher = $this->publisherWithWallet(42);
        $advertiser = $this->advertiser();
        $site = $this->site($publisher, [
            'verified' => false,
            'site_name' => 'Unverified Blog',
            'site_url' => 'https://unverified.example',
            'domain' => 'unverified.example',
        ]);

        $this->createOrderItem($advertiser, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ], [
            'price' => 115,
            'additional_price' => 0,
        ]);

        $response = $this->actingAs($publisher)->get(route('publisher.dashboard'));

        $response->assertOk()
            ->assertSee('€42.00')
            ->assertSee('Pending earnings')
            ->assertSee('€100.00')
            ->assertSee('Needs you')
            ->assertSee('id="openTasks"', false)
            ->assertSee('Unverified')
            ->assertSee('id="unverifiedSites"', false)
            ->assertSee('Unverified Blog')
            ->assertSee('Your payout')
            ->assertSee('id="orderStatusChart"', false)
            ->assertSee('All time')
            ->assertSee('Recent tasks', false)
            ->assertSee('dash-recent-col', false)
            ->assertSee('dash-page-end', false);
    }

    public function test_order_status_distribution_includes_review_and_scheduled(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $this->createOrderItem($advertiser, $site, ['status' => 'pending']);
        $this->createOrderItem($advertiser, $site, ['status' => 'processing']);
        $this->createOrderItem($advertiser, $site, ['status' => 'review']);
        $this->createOrderItem($advertiser, $site, ['status' => 'scheduled']);
        $this->createOrderItem($advertiser, $site, ['status' => 'completed']);
        $this->createOrderItem($advertiser, $site, ['status' => 'cancelled']);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.order-status'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'labels' => ['Pending', 'Processing', 'In Review', 'Scheduled', 'Completed', 'Cancelled'],
                    'values' => [1, 1, 1, 1, 1, 1],
                ],
            ]);
    }

    public function test_checkout_scheduled_orders_count_as_scheduled_not_pending(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $this->createOrderItem($advertiser, $site, ['status' => 'pending']);
        $this->createOrderItem($advertiser, $site, [
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_orders', 1)
            ->assertJsonPath('data.scheduled_orders', 1);

        $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.order-status'))
            ->assertOk()
            ->assertJsonPath('data.values', [1, 0, 0, 1, 0, 0]);

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('id="openTasks">1', false)
            ->assertSee('status-scheduled', false)
            ->assertSee('Scheduled', false);

        $recent = $this->actingAs($publisher)
            ->getJson(route('publisher.dashboard.recent'))
            ->assertOk()
            ->json('orders');
        $statuses = collect($recent)->pluck('status')->all();
        $this->assertContains('pending', $statuses);
        $this->assertContains('scheduled', $statuses);
    }

    public function test_review_only_item_is_not_needs_you_cta(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->createOrderItem($advertiser, $site, [
            'status' => 'review',
            'payment_status' => 'paid',
        ]);

        $html = $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('data-primary-action="add_site"', false)
            ->assertSee('id="openTasks">0', false)
            ->assertSee('1 waiting on advertiser', false)
            ->assertSee('Waiting on advertiser', false)
            ->assertDontSee('You have 1 task waiting', false)
            ->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('publisher.tasks', ['focus' => 'order', 'order' => $item->order_id], false)).'"',
            $html
        );

        $badge = $this->actingAs($publisher)
            ->getJson('/chat/unread-summary')
            ->assertOk()
            ->json();
        $this->assertSame(0, (int) ($badge['needs_action'] ?? -1));
    }

    public function test_pending_item_uses_needs_you_cta_and_accept_copy(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $this->createOrderItem($advertiser, $site, [
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('data-primary-action="needs_you"', false)
            ->assertSee('You have 1 task waiting', false)
            ->assertSee('Accept this order', false)
            ->assertSee('id="openTasks">1', false)
            ->assertSee(route('publisher.tasks', ['needs_action' => 1], false), false);

        $badge = $this->actingAs($publisher)
            ->getJson('/chat/unread-summary')
            ->assertOk()
            ->json();
        $this->assertSame(1, (int) ($badge['needs_action'] ?? 0));
    }

    public function test_processing_without_live_url_shows_publish_next_action(): void
    {
        $publisher = $this->publisherWithWallet();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $this->createOrderItem($advertiser, $site, [
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('data-primary-action="needs_you"', false)
            ->assertSee('Submit live URL', false)
            ->assertSee('data-next-action="publish"', false);
    }

    public function test_awaiting_details_site_uses_complete_details_cta(): void
    {
        $publisher = $this->publisherWithWallet();
        $this->site($publisher, [
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'site_name' => 'Draft Details Site',
            'site_url' => 'https://draft-details.example',
            'domain' => 'draft-details.example',
        ]);

        $this->actingAs($publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('data-primary-action="complete_details"', false)
            ->assertSee('Finish details on 1 site', false)
            ->assertSee(route('publisher.bulk-sites.complete', [], false), false)
            ->assertSee('1 need details from you', false)
            ->assertDontSee('You have 1 task waiting', false);
    }
}
