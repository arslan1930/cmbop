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
use Tests\TestCase;

class AdvertiserOrdersChatDeliveryTicksTest extends TestCase
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
            'site_name' => 'Chat Ticks Site',
            'site_url' => 'https://chat-ticks.example',
            'domain' => 'chat-ticks.example',
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

    private function orderFor(User $advertiser, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-TICKS-'.uniqid(),
            'reference_code' => 'REF-TICKS-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
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

    public function test_own_unread_message_has_no_receipt_until_counterpart_opens_chat(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $sent = $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Please publish soon'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('delivery', 'delivered')
            ->assertJsonPath('message.is_read', false)
            ->json('message');

        $before = $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame([], $before->json('receipts'));
        $this->assertFalse((bool) $before->json('messages.0.is_read'));

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) OrderChatMessage::find($sent['id'])->is_read);

        $after = $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('messages.0.is_read', true)
            ->assertJsonPath('receipts.0.id', $sent['id'])
            ->assertJsonPath('receipts.0.is_read', true);

        $this->assertCount(1, $after->json('receipts'));
    }

    public function test_poll_since_id_still_returns_own_read_receipts(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $own = OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Older own',
            'is_read' => false,
        ]);
        $reply = OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Newer reply',
            'is_read' => false,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk();

        $poll = $this->actingAs($advertiser)
            ->getJson(route('chat.messages', ['orderId' => $order->id, 'since_id' => $own->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('has_more_older', false)
            ->assertJsonPath('receipts.0.id', $own->id)
            ->assertJsonPath('receipts.0.is_read', true);

        $ids = collect($poll->json('messages'))->pluck('id')->all();
        $this->assertSame([$reply->id], $ids);
        $this->assertCount(1, $poll->json('receipts'));
    }

    public function test_blocked_and_counterpart_messages_are_not_receipts(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $blocked = $this->actingAs($advertiser)
            ->postJson(route('chat.send', $order->id), ['message' => 'Email me at leak@example.com'])
            ->assertOk()
            ->assertJsonPath('delivery', 'blocked')
            ->json('message');

        OrderChatMessage::where('id', $blocked['id'])->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        $reply = OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $publisher->id,
            'sender_type' => 'publisher',
            'message' => 'Got it',
            'is_read' => true,
            'read_at' => now(),
        ]);

        $payload = $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $receiptIds = collect($payload->json('receipts'))->pluck('id')->all();
        $this->assertNotContains($blocked['id'], $receiptIds);
        $this->assertNotContains($reply->id, $receiptIds);

        $ownMessages = collect($payload->json('messages'))->where('id', $blocked['id']);
        $this->assertTrue((bool) $ownMessages->first()['is_blocked']);
    }

    public function test_receipts_survive_missing_read_at_column(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $own = OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Already seen',
            'is_read' => true,
            'read_at' => now(),
        ]);

        Schema::table('order_chat_messages', function ($table) {
            $table->dropColumn('read_at');
        });

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('messages.0.id', $own->id)
            ->assertJsonPath('receipts.0.id', $own->id)
            ->assertJsonPath('receipts.0.is_read', true)
            ->assertJsonPath('receipts.0.read_at', null)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column');
    }

    public function test_receipts_empty_when_is_read_column_missing(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Tick leftover',
            'is_read' => true,
            'read_at' => now(),
        ]);

        Schema::table('order_chat_messages', function ($table) {
            try {
                $table->dropIndex('order_chat_messages_user_id_is_read_index');
            } catch (\Throwable $e) {
                // SQLite leftover drop needs the composite index gone first.
            }
            $table->dropColumn('is_read');
        });

        $this->actingAs($advertiser)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('messages.0.message', 'Tick leftover')
            ->assertJsonPath('receipts', [])
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Unknown column');
    }

    public function test_order_chat_js_renders_ticks_and_applies_receipts(): void
    {
        $live = (string) file_get_contents(public_path('js/order-chat.js'));
        $assets = (string) file_get_contents(public_path('assets/js/order-chat.js'));

        foreach ([$live, $assets] as $js) {
            $this->assertStringContainsString('function chatTickHtml', $js);
            $this->assertStringContainsString('data-chat-ticks', $js);
            $this->assertStringContainsString('OrderChat.prototype.applyReceipts', $js);
            $this->assertStringContainsString('self.applyReceipts(data.receipts || [])', $js);
            $this->assertStringContainsString('aria-label', $js);
            $this->assertStringContainsString('title', $js);
            $this->assertStringContainsString("'Read'", $js);
            $this->assertStringContainsString("'Delivered'", $js);
            $this->assertStringContainsString('fa-check', $js);
        }

        $this->assertSame($live, $assets);

        $css = (string) file_get_contents(public_path('assets/css/chat.css'));
        $this->assertStringContainsString('.chat-bubble__ticks', $css);
        $this->assertStringContainsString('[data-read="1"]', $css);
    }
}
