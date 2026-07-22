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
use Illuminate\Support\Facades\Mail;
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
}
