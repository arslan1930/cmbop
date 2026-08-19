<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherTasksDetailsTest extends TestCase
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
            'site_name' => 'Tasks Details Site',
            'site_url' => 'https://tasks-details.example',
            'domain' => 'tasks-details.example',
            'example_url' => 'https://tasks-details.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Tech',
            'categories' => ['Tech'],
            'price' => 30,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'turnaround_time' => '3days',
            'description' => str_repeat('Tasks details site description text. ', 4),
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $orderExtra
     * @param  array<string, mixed>  $itemExtra
     */
    private function makePaidItem(string $orderStatus = 'pending', array $orderExtra = [], array $itemExtra = []): OrderItem
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'DET-'.uniqid(),
            'subtotal' => 30,
            'total_amount' => 30,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => $orderStatus,
            'paid_at' => now()->setDate(2026, 8, 12)->setTime(12, 24),
        ], $orderExtra));

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article',
            'price' => 30,
            'publisher_price' => 30,
        ], $itemExtra));
    }

    public function test_details_includes_paid_at_and_wallet_copy(): void
    {
        $item = $this->makePaidItem('completed');

        $details = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.details', $item->id))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($details['order']['paid_at']);
        $this->assertEquals(30.0, $details['you_earn']);

        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('function formatPublisherTaskDate', $blade);
        $this->assertStringContainsString('order.paid_at', $blade);
        $this->assertStringContainsString('released to your wallet', $blade);
        $this->assertStringContainsString('View balance', $blade);
        $this->assertStringContainsString('publisherBalanceUrl', $blade);
        $this->assertStringNotContainsString('toLocaleDateString()', $blade);
    }

    public function test_details_reports_placement_index_for_sibling_cart_rows(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'PACK-DET-'.uniqid(),
            'subtotal' => 60,
            'total_amount' => 60,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        $first = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/a',
            'price' => 30,
            'publisher_price' => 30,
        ]);
        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/b',
            'price' => 30,
            'publisher_price' => 30,
        ]);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.details', $second->id))
            ->assertOk()
            ->assertJsonPath('data.order_items_count', 2)
            ->assertJsonPath('data.order_item_index', 2);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.details', $first->id))
            ->assertOk()
            ->assertJsonPath('data.order_items_count', 2)
            ->assertJsonPath('data.order_item_index', 1);

        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('This placement', $blade);
        $this->assertStringContainsString(' of ', $blade);
        $this->assertStringContainsString('detailsPlacementIndex', $blade);
        $this->assertStringNotContainsString('>Order Items<', $blade);
    }

    public function test_details_puts_live_url_in_status_and_completed_actions(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('id="detailsLiveUrl"', $blade);
        $this->assertStringContainsString('id="detailsCopyLiveUrl"', $blade);
        $this->assertStringContainsString('id="detailsModalExtraActions"', $blade);
        $this->assertStringContainsString('function renderDetailsModalExtraActions', $blade);
        $this->assertStringContainsString('open-task-chat', $blade);
        $this->assertStringNotContainsString('onclick="openChat(', $blade);
        $this->assertMatchesRegularExpression(
            "/id=\"detailsNextStep\">' \\+ nextStepHtml \\+ '<\\/p>' \\+[\\s\\S]*?liveUrlTop/",
            $blade
        );
    }

    public function test_details_masks_timeline_actors_for_publishers_only(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('function publisherTimelineActorLabel', $blade);
        $this->assertStringContainsString('function renderPublisherOrderActivityTimeline', $blade);
        $this->assertStringContainsString("role === 'advertiser') return 'Advertiser'", $blade);
        $this->assertStringContainsString("role === 'publisher') return 'You'", $blade);
        $this->assertStringContainsString("role === 'admin' || role === 'marketing') return 'Support'", $blade);
        $this->assertStringContainsString('copy.actor_name = publisherTimelineActorLabel(a)', $blade);

        $shared = file_get_contents(public_path('js/notification-center.js'));
        $this->assertStringContainsString('window.renderOrderActivityTimeline', $shared);
        $this->assertStringContainsString('a.actor_name', $shared);
        $this->assertStringNotContainsString('publisherTimelineActorLabel', $shared);
    }
}
