<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\PublisherNeedsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherTasksNeedsActionTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'publisher']);
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Tasks Needs Action Site',
            'site_url' => 'https://tasks-needs.example',
            'domain' => 'tasks-needs.example',
            'example_url' => 'https://tasks-needs.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Tech',
            'categories' => ['Tech'],
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'turnaround_time' => '3days',
            'description' => str_repeat('Needs action site description text. ', 4),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeItem(string $paymentStatus, string $orderStatus, array $orderExtra = []): OrderItem
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'NA-'.uniqid(),
            'subtotal' => 50,
            'total_amount' => 50,
            'payment_method' => 'card',
            'payment_status' => $paymentStatus,
            'status' => $orderStatus,
            'paid_at' => $paymentStatus === 'paid' ? now() : null,
        ], $orderExtra));

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article',
            'price' => 50,
        ]);
    }

    public function test_needs_action_badge_ignores_unpaid_pending_orders(): void
    {
        $this->makeItem('pending', 'pending');
        $this->makeItem('paid', 'pending');

        $res = $this->actingAs($this->publisher)
            ->getJson('/chat/unread-summary')
            ->assertOk()
            ->json();

        $this->assertSame(1, (int) ($res['needs_action'] ?? 0));
    }

    public function test_needs_action_list_filter_returns_only_actionable_paid_rows(): void
    {
        $paidPending = $this->makeItem('paid', 'pending');
        $this->makeItem('pending', 'pending');
        $paidProcessingDone = $this->makeItem('paid', 'processing');
        $paidProcessingDone->update(['live_url' => 'https://live.example/done']);

        $htmlish = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data', ['needs_action' => 1]))
            ->assertOk()
            ->json();

        $this->assertTrue($htmlish['success']);
        $this->assertArrayHasKey('preview_html', $htmlish['data'][0] ?? []);
        $this->assertNull($htmlish['data'][0]['preview_html']);
        $ids = collect($htmlish['data'])->pluck('id')->all();
        $this->assertContains($paidPending->id, $ids);
        $this->assertNotContains($paidProcessingDone->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_locate_order_item_resolves_paid_publisher_row(): void
    {
        $item = $this->makeItem('paid', 'pending');
        $this->makeItem('pending', 'pending');

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.locate', ['order_id' => $item->order_id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_item_id', $item->id);
    }

    public function test_tasks_page_uses_safe_chat_buttons_and_colspan_eight(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('class="btn btn-primary btn-action-sm open-task-chat"', $blade);
        $this->assertStringNotContainsString('onclick="openChat(', $blade);
        $this->assertStringContainsString('/publisher/orders/locate', $blade);
        $this->assertStringNotContainsString('colspan="9"', $blade);
        $this->assertStringContainsString("Showing ' + from", $blade);
        $this->assertStringContainsString('do not enable macros', $blade);
    }

    public function test_tasks_empty_states_distinguish_filters_and_needs_you(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('function tasksFiltersAreActive', $blade);
        $this->assertStringContainsString('No tasks yet', $blade);
        $this->assertStringContainsString('No tasks match these filters', $blade);
        $this->assertStringContainsString('caught up', $blade);
        $this->assertStringContainsString('emptyResetFilters', $blade);
        $this->assertStringContainsString('emptyShowAllTasks', $blade);
        $this->assertStringContainsString('needsYouEmpty', $blade);
        $this->assertStringContainsString('filteredEmpty', $blade);
        $caughtAt = strpos($blade, 'caught up');
        $yetAt = strpos($blade, 'No tasks yet');
        $this->assertNotFalse($caughtAt);
        $this->assertNotFalse($yetAt);
        $this->assertGreaterThan($yetAt, $caughtAt, 'Caught-up copy must live in the needs-you empty branch.');
    }

    public function test_tasks_page_exposes_extended_stats_and_extracted_css(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('publisher-tasks.css', $blade);
        $this->assertStringContainsString('id="statProcessingOrders"', $blade);
        $this->assertStringContainsString('id="statReviewOrders"', $blade);
        $this->assertStringContainsString('id="statScheduledOrders"', $blade);
        $this->assertStringContainsString('id="statNeedsYou"', $blade);
        $this->assertStringContainsString('>Orders<', $blade);
        $this->assertStringContainsString('>Needs you<', $blade);
        $this->assertStringContainsString('value="scheduled"', $blade);
        $this->assertStringContainsString('awaitingSchedule', $blade);
        $this->assertStringContainsString('is_awaiting_scheduled_release', $blade);
        $this->assertMatchesRegularExpression(
            "/orderStatus === 'pending' && awaitingSchedule\\) \\{[\\s\\S]*?viewBtn \\+ chatBtn/",
            $blade
        );
        if (preg_match(
            "/if \\(orderStatus === 'pending' && awaitingSchedule\\) \\{([\\s\\S]*?)\\} else if \\(orderStatus === 'pending'\\)/",
            $blade,
            $scheduledBranch
        )) {
            $this->assertStringNotContainsString('reject-task', $scheduledBranch[1]);
        } else {
            $this->fail('Scheduled pending task actions branch is missing.');
        }
        $this->assertFileExists(public_path('assets/css/publisher-tasks.css'));
        $css = file_get_contents(public_path('assets/css/publisher-tasks.css'));
        $this->assertStringContainsString('@media (max-width: 768px)', $css);
    }

    public function test_upcoming_scheduled_orders_are_not_needs_action(): void
    {
        $acceptNow = $this->makeItem('paid', 'pending');
        $scheduled = $this->makeItem('paid', 'pending', [
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $this->actingAs($this->publisher)
            ->getJson('/chat/unread-summary')
            ->assertOk()
            ->assertJsonPath('needs_action', 1);

        $needsAction = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data', ['needs_action' => 1]))
            ->assertOk()
            ->json('data');
        $needsActionIds = collect($needsAction)->pluck('id')->all();
        $this->assertContains($acceptNow->id, $needsActionIds);
        $this->assertNotContains($scheduled->id, $needsActionIds);

        $pending = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data', ['status' => 'pending']))
            ->assertOk()
            ->json('data');
        $pendingIds = collect($pending)->pluck('id')->all();
        $this->assertContains($acceptNow->id, $pendingIds);
        $this->assertNotContains($scheduled->id, $pendingIds);

        $scheduledRows = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data', ['status' => 'scheduled']))
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $scheduledRows);
        $this->assertSame($scheduled->id, $scheduledRows[0]['id']);
        $this->assertTrue($scheduledRows[0]['order']['is_awaiting_scheduled_release']);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_orders', 1)
            ->assertJsonPath('data.scheduled_orders', 1)
            ->assertJsonPath('data.needs_you', 1);
    }

    public function test_statistics_count_orders_not_line_items(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'PACK-'.uniqid(),
            'subtotal' => 100,
            'total_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/a',
            'price' => 50,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/b',
            'price' => 50,
        ]);

        $rows = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $rows);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.pending_orders', 1)
            ->assertJsonPath('data.needs_you', 2);
    }

    public function test_helper_badge_and_tasks_filter_share_the_same_needs_you_count(): void
    {
        $acceptNow = $this->makeItem('paid', 'pending');
        $this->makeItem('paid', 'pending', [
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);
        $this->makeItem('pending', 'pending');
        $this->makeItem('paid', 'review');
        $processingNoUrl = $this->makeItem('paid', 'processing');
        $processingDone = $this->makeItem('paid', 'processing');
        $processingDone->update(['live_url' => 'https://live.example/done']);
        $modification = $this->makeItem('paid', 'processing');
        $modification->update([
            'live_url' => 'https://live.example/mod',
            'modification_requested' => 'yes',
        ]);

        $helperCount = PublisherNeedsAction::needsYouCount((int) $this->publisher->id);
        $this->assertSame(3, $helperCount);
        $this->assertSame(1, PublisherNeedsAction::waitingOnAdvertiserCount((int) $this->publisher->id));

        $badge = $this->actingAs($this->publisher)
            ->getJson('/chat/unread-summary')
            ->assertOk()
            ->json();
        $this->assertSame($helperCount, (int) ($badge['needs_action'] ?? 0));

        $list = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data', ['needs_action' => 1]))
            ->assertOk()
            ->json('data');
        $ids = collect($list)->pluck('id')->all();
        $this->assertCount($helperCount, $ids);
        $this->assertContains($acceptNow->id, $ids);
        $this->assertContains($processingNoUrl->id, $ids);
        $this->assertContains($modification->id, $ids);
        $this->assertNotContains($processingDone->id, $ids);
    }
}
