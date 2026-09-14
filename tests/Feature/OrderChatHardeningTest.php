<?php

namespace Tests\Feature;

use App\Mail\NewChatMessageNotification;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderChatHardeningTest extends TestCase
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
            'site_name' => 'Chat Hardening Site',
            'site_url' => 'https://chat-hardening.example',
            'domain' => 'chat-hardening.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function orderFor(User $advertiser, Site $site, string $status = 'processing'): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CHAT-'.uniqid(),
            'reference_code' => 'REF-CHAT-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $status,
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
            'modification_requested' => 'no',
        ]);

        return $order->fresh('items');
    }

    public function test_unauthorized_user_cannot_read_or_send_chat(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $outsider = $this->advertiser();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $this->actingAs($outsider)
            ->getJson(route('chat.messages', $order->id))
            ->assertStatus(403);

        $this->actingAs($outsider)
            ->postJson(route('chat.send', $order->id), ['message' => 'Hello'])
            ->assertStatus(403);
    }

    public function test_send_creates_message_notification_and_mail_cta_deep_link(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Please publish soon'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('order_chat_messages', [
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Please publish soon',
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => 'message',
        ]);

        Mail::assertQueued(NewChatMessageNotification::class, function (NewChatMessageNotification $mail) use ($order, $publisher) {
            $built = $mail->build();
            $url = $built->viewData['url'] ?? null;
            $expectedPath = parse_url(route('publisher.tasks', [
                'focus' => 'messages',
                'order' => $order->id,
            ]), PHP_URL_PATH);
            $this->assertNotNull($url);
            $this->assertStringContainsString((string) $expectedPath, (string) $url);
            $this->assertStringContainsString('focus=messages', (string) $url);
            $this->assertStringContainsString('order='.$order->id, (string) $url);
            $this->assertSame('chat_message:'.$mail->chatMessageId, $mail->dedupeKey);
            $this->assertSame($publisher->name, $mail->receiverName);

            return true;
        });
    }

    public function test_chat_mail_dedupe_key_is_per_message_id(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'First'])
            ->assertOk();
        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Second'])
            ->assertOk();

        $keys = [];
        Mail::assertQueued(NewChatMessageNotification::class, function (NewChatMessageNotification $mail) use (&$keys) {
            $keys[] = $mail->dedupeKey;

            return true;
        });

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
        $this->assertMatchesRegularExpression('/^chat_message:\d+$/', $keys[0]);
        $this->assertMatchesRegularExpression('/^chat_message:\d+$/', $keys[1]);
    }

    public function test_unpaid_order_rejects_send_and_is_read_only(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);
        $order->update([
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Can we start before payment?'])
            ->assertStatus(422)
            ->assertJsonPath('can_send', false)
            ->assertJsonPath('success', false);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('can_send', false)
            ->assertJsonPath('composer_note', 'Chat is available after the order is paid.');

        $this->assertDatabaseMissing('order_chat_messages', [
            'order_id' => $order->id,
            'message' => 'Can we start before payment?',
        ]);

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertForbidden();

        $this->actingAs($publisher)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertForbidden();
    }

    public function test_leftover_refunded_review_is_read_only_not_pay_to_chat(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site, 'review');
        $order->update(['payment_status' => 'refunded']);
        $order->items->first()?->update([
            'live_url' => 'https://publisher.example/refunded-review',
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('can_send', false)
            ->assertJsonPath('composer_note', 'This order was refunded. Chat is read-only.')
            ->assertJsonPath('order_details.can_approve', false);

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Still reviewing this?'])
            ->assertStatus(422)
            ->assertJsonPath('can_send', false)
            ->assertJsonPath('message', 'This order was refunded. Chat is closed.');

        $this->assertDatabaseMissing('order_chat_messages', [
            'order_id' => $order->id,
            'message' => 'Still reviewing this?',
        ]);

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertForbidden();
        $this->actingAs($publisher)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertForbidden();

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Unread on a leftover refund should not badge',
            'is_read' => false,
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('unread_chat', 0)
            ->assertJsonPath('needs_action', 0);
    }

    public function test_failed_payment_unread_chat_does_not_inflate_header_badge(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $failed = $this->orderFor($advertiser, $site, 'review');
        $failed->update(['payment_status' => 'failed']);
        $failed->items->first()?->update([
            'live_url' => 'https://publisher.example/failed-review',
        ]);

        OrderChatMessage::create([
            'order_id' => $failed->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Unread on a failed charge should not badge',
            'is_read' => false,
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('unread_chat', 0)
            ->assertJsonPath('needs_action', 0);
    }

    public function test_completed_clawback_still_allows_chat(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site, 'completed');
        $order->update(['payment_status' => 'refunded']);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('can_send', true)
            ->assertJsonPath('composer_note', 'This order is completed. You can still message about this placement.');

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Thanks — refund received'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('can_send', true)
            ->assertJsonPath('composer_note', 'This order is completed. You can still message about this placement.');
        $this->actingAs($publisher)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Unread on a completed clawback should still badge',
            'is_read' => false,
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('unread_chat', 1);
    }

    public function test_publisher_unread_ignores_unpaid_and_cancelled_orders(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $unpaid = $this->orderFor($advertiser, $site);
        $unpaid->update([
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);
        OrderChatMessage::create([
            'order_id' => $unpaid->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Unpaid chat should not badge',
            'is_read' => false,
        ]);

        $cancelled = $this->orderFor($advertiser, $site, 'cancelled');
        OrderChatMessage::create([
            'order_id' => $cancelled->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Cancelled chat should not badge',
            'is_read' => false,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('unread_chat', 0);

        $paid = $this->orderFor($advertiser, $site);
        OrderChatMessage::create([
            'order_id' => $paid->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Paid chat should badge',
            'is_read' => false,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('unread_chat', 1);
    }

    public function test_cancelled_order_rejects_send_completed_allows_send(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $cancelled = $this->orderFor($advertiser, $site, 'cancelled');
        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $cancelled->id), ['message' => 'Still here?'])
            ->assertStatus(422)
            ->assertJsonPath('can_send', false);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $cancelled->id))
            ->assertOk()
            ->assertJsonPath('can_send', false)
            ->assertJsonPath('composer_note', 'This order is cancelled. Chat is read-only.');

        $completed = $this->orderFor($advertiser, $site, 'completed');
        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $completed->id), ['message' => 'Thanks for the placement'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $completed->id))
            ->assertOk()
            ->assertJsonPath('can_send', true)
            ->assertJsonPath('composer_note', 'This order is completed. You can still message about this placement.');

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $completed->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_details.content_link', 'https://example.com/article.docx');
    }

    public function test_completed_chat_without_line_items_does_not_claim_a_placement(): void
    {
        $advertiser = $this->advertiser();
        $orphan = Order::create([
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

        $payload = $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $orphan->id))
            ->assertOk()
            ->assertJsonPath('can_send', true)
            ->assertJsonPath('composer_note', 'This order is completed. You can still message support about it.')
            ->json();

        $this->assertStringNotContainsString(
            'paid for this placement',
            (string) data_get($payload, 'order_details.next_action')
        );
    }

    public function test_order_chat_details_strip_javascript_live_url_and_block_approve(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site, 'review');

        OrderItem::where('order_id', $order->id)->update([
            'live_url' => 'javascript:alert(1)',
            'live_url_submitted_at' => now(),
            'content_link' => 'javascript:alert(2)',
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_details.live_url', null)
            ->assertJsonPath('order_details.content_link', null)
            ->assertJsonPath('order_details.can_approve', false)
            ->assertJsonPath('order_details.can_request_changes', false);
    }

    public function test_since_id_returns_only_newer_messages(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $older = OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Older',
            'is_read' => false,
        ]);
        $newer = OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Newer',
            'is_read' => false,
        ]);

        $response = $this->actingAs($advertiser)
            ->getJson(route('chat.messages', ['orderId' => $order->id, 'since_id' => $older->id]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('messages'))->pluck('id')->all();
        $this->assertSame([$newer->id], $ids);
        $this->assertFalse($response->json('has_more_older'));
    }

    public function test_messages_ok_when_created_at_is_unparseable(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $message = OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Leftover stamp',
            'is_read' => false,
        ]);
        DB::table('order_chat_messages')->where('id', $message->id)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'not-a-date',
            'read_at' => 'not-a-date',
        ]);

        $this->assertNull($message->fresh()->created_at);

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', ['orderId' => $order->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonPath('messages.0.created_at', null)
            ->assertJsonPath('messages.0.message', 'Leftover stamp');
    }

    public function test_upload_image_route_is_gone(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->post('/chat/upload-image', [])
            ->assertNotFound();
    }

    public function test_unread_summary_returns_unread_chat_and_needs_action(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site, 'review');

        OrderItem::where('order_id', $order->id)->update([
            'live_url' => 'https://chat-hardening.example/live',
        ]);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Live URL is ready',
            'is_read' => false,
        ]);

        $this->actingAs($advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'unread_chat' => 1,
                'needs_action' => 1,
            ])
            ->assertJsonStructure([
                'unread_chat',
                'needs_action',
                'latest_unread_order',
                'role',
            ]);
    }

    public function test_contact_share_is_saved_blocked_hidden_from_counterpart_and_not_notified(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Email me at leak@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('delivery', 'blocked')
            ->assertJsonPath('message.is_blocked', true);

        $this->assertDatabaseHas('order_chat_messages', [
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'message' => 'Email me at leak@example.com',
            'is_blocked' => 1,
        ]);

        Mail::assertNotQueued(NewChatMessageNotification::class);
        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $publisher->id,
            'type' => 'message',
        ]);

        $senderMessages = $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->json('messages');
        $this->assertCount(1, $senderMessages);
        $this->assertTrue((bool) $senderMessages[0]['is_blocked']);
        $this->assertArrayHasKey('user', $senderMessages[0]);
        $this->assertArrayHasKey('name', $senderMessages[0]['user']);
        $this->assertArrayNotHasKey('email', $senderMessages[0]['user']);

        $receiverMessages = $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->json('messages');
        $this->assertCount(0, $receiverMessages);
    }

    public function test_ask_for_phone_is_blocked_and_ignored_by_unread_summary(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site, 'review');

        OrderItem::where('order_id', $order->id)->update([
            'live_url' => 'https://chat-hardening.example/live',
        ]);

        $this->actingAs($publisher)
            ->postJson(route('chat.send', $order->id), ['message' => "What's your phone number?"])
            ->assertOk()
            ->assertJsonPath('delivery', 'blocked');

        $this->actingAs($advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('unread_chat', 0);

        $this->assertSame(0, $order->unreadChatMessages($advertiser->id, 'advertiser')->count());
        $this->assertSame(
            0,
            OrderChatMessage::unreadForUser($advertiser->id, 'advertiser')
                ->where('order_id', $order->id)
                ->count()
        );

        Mail::assertNotQueued(NewChatMessageNotification::class);
    }

    public function test_clean_message_still_delivers_and_user_payload_has_no_email(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $response = $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Please publish soon'])
            ->assertOk()
            ->assertJsonPath('delivery', 'delivered')
            ->assertJsonPath('message.is_blocked', false);

        $this->assertArrayNotHasKey('email', $response->json('message.user') ?? []);

        Mail::assertQueued(NewChatMessageNotification::class);
    }

    public function test_publisher_tasks_orders_data_loads_with_unread_chat(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Need this live soon',
            'is_read' => false,
            'is_blocked' => false,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.unread_chat', 1);
    }

    public function test_order_chat_messages_fetch_works_without_is_blocked_column(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Hello from advertiser',
            'is_read' => false,
        ]);

        Schema::table('order_chat_messages', function ($table) {
            try {
                $table->dropIndex('order_chat_messages_order_blocked_idx');
            } catch (\Throwable $e) {
                // ignore
            }
            $cols = [];
            if (Schema::hasColumn('order_chat_messages', 'blocked_reason')) {
                $cols[] = 'blocked_reason';
            }
            if (Schema::hasColumn('order_chat_messages', 'is_blocked')) {
                $cols[] = 'is_blocked';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
        OrderChatMessage::forgetBlockedColumnCache();

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('messages.0.message', 'Hello from advertiser');

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        OrderChatMessage::forgetBlockedColumnCache();
    }

    public function test_publisher_tasks_orders_data_works_without_is_blocked_column(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Legacy schema message',
            'is_read' => false,
        ]);

        Schema::table('order_chat_messages', function ($table) {
            try {
                $table->dropIndex('order_chat_messages_order_blocked_idx');
            } catch (\Throwable $e) {
                // ignore
            }
            $cols = [];
            if (Schema::hasColumn('order_chat_messages', 'blocked_reason')) {
                $cols[] = 'blocked_reason';
            }
            if (Schema::hasColumn('order_chat_messages', 'is_blocked')) {
                $cols[] = 'is_blocked';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
        OrderChatMessage::forgetBlockedColumnCache();

        $this->actingAs($publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.unread_chat', 1);

        OrderChatMessage::forgetBlockedColumnCache();
    }
}
