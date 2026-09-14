<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdvertiserOrdersChatCompletedViewTest extends TestCase
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

    private function siteFor(User $publisher, string $name = 'Chat Completed Site'): Site
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?: 'chat-completed');

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
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

    private function makeOrder(User $advertiser, ?Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CHAT-CV-'.uniqid(),
            'reference_code' => 'REF-CHAT-CV-'.uniqid(),
            'subtotal' => 103.50,
            'tax' => 0,
            'total_amount' => 103.50,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
            'completed_at' => now(),
        ], $orderAttrs));

        if ($site !== null) {
            OrderItem::create(array_merge([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 103.50,
                'content_link' => 'https://example.com/article.docx',
            ], $itemAttrs));
        }

        return $order->fresh('items');
    }

    /**
     * @param  TestResponse  $response
     * @return array<string, mixed>
     */
    private function chatDetails($response): array
    {
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column')
            ->assertDontSee('paid for this placement');

        return $response->json();
    }

    public function test_completed_chat_with_live_url_is_follow_up_not_paid_for(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Live Post Site');
        $liveUrl = 'https://live.example/completed-guest-post';
        $order = $this->makeOrder($advertiser, $site, [], [
            'live_url' => $liveUrl,
        ]);

        $payload = $this->chatDetails(
            $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id))
        );

        $this->assertStringContainsString('Your post is live', $payload['order_details']['next_action']);
        $this->assertSame('This order is completed. You can still message about the live post.', $payload['composer_note']);
        $this->assertStringNotContainsString('paid for', (string) $payload['composer_note']);
        $this->assertSame('Live Post Site', $payload['order_details']['website_name']);
        $this->assertSame($liveUrl, $payload['order_details']['live_url']);
        $this->assertTrue($payload['order_details']['can_view_order']);
        $this->assertFalse($payload['order_details']['details_missing']);
        $this->assertTrue($payload['order_details']['has_placement']);
        $this->assertFalse($payload['order_details']['can_approve']);
    }

    public function test_completed_chat_with_item_and_no_url_asks_to_message_publisher(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site);

        $payload = $this->chatDetails(
            $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id))
        );

        $this->assertSame('Placement finished. Open the order for details.', $payload['order_details']['next_action']);
        $this->assertSame('This order is completed. You can still message the publisher.', $payload['composer_note']);
        $this->assertFalse($payload['order_details']['details_missing']);
        $this->assertTrue($payload['order_details']['can_view_order']);
        $this->assertFalse($payload['order_details']['can_approve']);
    }

    public function test_completed_chat_without_items_is_honest_about_missing_details(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->makeOrder($advertiser, null, [
            'order_number' => '7970266',
            'total_amount' => 103.50,
        ]);

        $payload = $this->chatDetails(
            $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id))
        );

        $this->assertNotSame('—', $payload['order_details']['website_name']);
        $this->assertSame('Placement details are missing for this order.', $payload['order_details']['website_name']);
        $this->assertTrue($payload['order_details']['details_missing']);
        $this->assertFalse($payload['order_details']['has_placement']);
        $this->assertSame('Placement details are missing for this order.', $payload['order_details']['next_action']);
        $this->assertSame(
            'Placement details are missing for this order. You can still send a message.',
            $payload['composer_note']
        );
        $this->assertTrue($payload['order_details']['can_view_order']);
        $this->assertFalse($payload['order_details']['can_approve']);
    }

    public function test_chat_survives_dropped_order_items_table(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site, [], [
            'live_url' => 'https://live.example/leftover-chat',
        ]);

        Schema::dropIfExists('order_items');

        $payload = $this->chatDetails(
            $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id))
        );

        $this->assertSame([], $payload['messages']);
        $this->assertTrue($payload['can_send']);
        $this->assertTrue($payload['order_details']['details_missing']);
        $this->assertNotSame('—', $payload['order_details']['website_name']);
        $this->assertSame('Placement details are missing for this order.', $payload['order_details']['website_name']);
        $this->assertSame('Placement details are missing for this order.', $payload['order_details']['next_action']);
        $this->assertSame(
            'Placement details are missing for this order. You can still send a message.',
            $payload['composer_note']
        );
        $this->assertTrue($payload['order_details']['can_view_order']);
        $this->assertFalse($payload['order_details']['can_approve']);
        $this->assertStringNotContainsString('order_items', json_encode($payload));
        $this->assertStringNotContainsString('Base table', json_encode($payload));

        $this->actingAs($advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column');

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Still here after leftover items.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('can_send', true)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column')
            ->assertDontSee('order_items');
    }

    public function test_chat_keeps_item_site_name_when_sites_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Leftover Sites Host');
        $order = $this->makeOrder($advertiser, $site, [], [
            'live_url' => 'https://live.example/leftover-sites',
        ]);

        Schema::rename('sites', 'sites_leftover_gone');
        $this->assertFalse(Schema::hasTable('sites'));

        $payload = $this->chatDetails(
            $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id))
        );

        $this->assertFalse($payload['order_details']['details_missing']);
        $this->assertTrue($payload['order_details']['has_placement']);
        $this->assertSame('Leftover Sites Host', $payload['order_details']['website_name']);
        $this->assertStringContainsString('Your post is live', $payload['order_details']['next_action']);
        $this->assertSame('This order is completed. You can still message about the live post.', $payload['composer_note']);
        $this->assertStringNotContainsString('no such table', json_encode($payload));

        $this->actingAs($advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('no such table');

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Follow-up after leftover sites.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('can_send', true)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('no such table');
    }

    public function test_chat_load_survives_missing_read_at_column(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Live URL is up.',
            'is_read' => false,
        ]);

        Schema::table('order_chat_messages', function ($table) {
            $table->dropColumn('read_at');
        });

        $payload = $this->chatDetails(
            $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id))
        );

        $this->assertNotEmpty($payload['messages']);
        $this->assertSame('Live URL is up.', $payload['messages'][0]['message']);
        $this->assertTrue($payload['can_send']);
        $this->assertIsArray($payload['receipts'] ?? null);
    }

    public function test_chat_send_survives_dropped_notifications_table(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site);

        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('order_activities');

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Hello after leftover notifications.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('can_send', true)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('in_app_notifications')
            ->assertDontSee('order_activities');
    }

    public function test_publisher_chat_is_forbidden_when_order_items_are_gone(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site);

        Schema::dropIfExists('order_items');

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthorized')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('order_items');

        $this->actingAs($publisher)
            ->postJson(route('chat.send', $order->id), ['message' => 'Can I still reply?'])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthorized')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('order_items');
    }

    public function test_chat_fail_closed_when_orders_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site);

        Schema::dropIfExists('orders');

        $messages = $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id));
        $messages->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column')
            ->assertDontSee('<html', false);
        $this->assertSame('Failed to fetch messages.', $messages->json('message'));

        $send = $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Hello leftover orders']);
        $send->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column');
        $this->assertSame('Failed to send message. Please try again.', $send->json('message'));
    }

    public function test_chat_send_and_unread_fail_closed_when_messages_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site);

        Schema::dropIfExists('order_chat_messages');

        $send = $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Hello leftover chat table']);

        $send->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column')
            ->assertDontSee('order_chat_messages');
        $this->assertSame('Failed to send message. Please try again.', $send->json('message'));

        $unread = $this->actingAs($advertiser)->getJson(route('chat.unread-summary'));
        $unread->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column')
            ->assertDontSee('order_chat_messages');
        $this->assertSame('Failed to load chat summary.', $unread->json('message'));
    }

    public function test_chat_details_js_has_view_order_and_missing_details_copy(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/advertiser-orders.js'));

        $this->assertStringContainsString('function renderChatOrderDetails', $js);
        $this->assertStringContainsString('View order', $js);
        $this->assertStringContainsString('data-chat-view-order', $js);
        $this->assertStringContainsString('details_missing', $js);
        $this->assertStringContainsString('Placement details are missing for this order.', $js);
        $this->assertStringNotContainsString('paid for this placement', $js);

        $start = strpos($js, 'function renderChatOrderDetails');
        $this->assertNotFalse($start);
        $end = strpos($js, 'orderChat = new window.OrderChat', $start);
        $this->assertNotFalse($end);
        $chatRenderer = substr($js, $start, $end - $start);

        $this->assertStringContainsString('View order', $chatRenderer);
        $this->assertStringContainsString('details_missing', $chatRenderer);
        $this->assertStringNotContainsString('paid for this placement', $chatRenderer);
        $this->assertStringNotContainsString('can_approve', $chatRenderer);

        $chatJs = (string) file_get_contents(public_path('js/order-chat.js'));
        $this->assertStringContainsString('function safeChatError', $chatJs);
        $this->assertStringContainsString('SQLSTATE', $chatJs);
        $this->assertStringContainsString('safeChatError(data.message', $chatJs);
    }
}
