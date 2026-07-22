<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublisherChatResubmitTest extends TestCase
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
            'site_name' => 'Chat Resubmit Site',
            'site_url' => 'https://chat-resubmit.example',
            'domain' => 'chat-resubmit.example',
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

    private function reviewOrder(User $advertiser, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CHAT-RESUB-'.uniqid(),
            'reference_code' => 'REF-CHAT-RESUB-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
            'live_url' => 'https://chat-resubmit.example/live-post',
            'live_url_submitted_at' => now()->subHours(12),
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        return $order->fresh('items');
    }

    public function test_request_changes_creates_revision_chat_message(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->reviewOrder($advertiser, $site);
        $reason = 'Please fix the broken anchor link in paragraph two.';

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('success', true);

        $message = OrderChatMessage::where('order_id', $order->id)
            ->where('sender_type', 'advertiser')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Revision requested:', $message->message);
        $this->assertStringContainsString($reason, $message->message);
        $this->assertStringContainsString('paste the corrected live URL in this chat to resubmit', $message->message);
    }

    public function test_publisher_chat_details_expose_can_resubmit_after_revision(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->reviewOrder($advertiser, $site);
        $item = $order->items->first();
        $reason = 'Please update the H1 to match the brief.';

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), ['reason' => $reason])
            ->assertOk();

        $this->actingAs($publisher)
            ->getJson('/chat/messages/'.$order->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_details.order_item_id', $item->id)
            ->assertJsonPath('order_details.can_resubmit', true)
            ->assertJsonPath('order_details.modification_requested', 'yes')
            ->assertJsonPath('order_details.completion_notes', $reason);

        $this->actingAs($advertiser)
            ->getJson('/chat/messages/'.$order->id)
            ->assertOk()
            ->assertJsonPath('order_details.can_resubmit', false);
    }

    public function test_resubmit_creates_chat_message_and_clears_can_resubmit(): void
    {
        Http::fake([
            'https://chat-resubmit.example/*' => Http::response('ok', 200),
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->reviewOrder($advertiser, $site);
        $item = $order->items->first();
        $newUrl = 'https://chat-resubmit.example/updated-post';

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'Please fix the CTA link at the end.',
            ])
            ->assertOk();

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.resubmit', $item->id), [
                'live_url' => $newUrl,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $message = OrderChatMessage::where('order_id', $order->id)
            ->where('sender_type', 'publisher')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Live URL resubmitted:', $message->message);
        $this->assertStringContainsString($newUrl, $message->message);

        $this->assertSame('no', $item->fresh()->modification_requested);
        $this->assertSame('review', $order->fresh()->status);

        $this->actingAs($publisher)
            ->getJson('/chat/messages/'.$order->id)
            ->assertOk()
            ->assertJsonPath('order_details.can_resubmit', false)
            ->assertJsonPath('order_details.live_url', $newUrl);
    }

    public function test_publisher_tasks_page_wires_chat_resubmit_cta(): void
    {
        $publisher = $this->publisher();

        $html = $this->actingAs($publisher)
            ->get(route('publisher.tasks'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="resubmitModal"', $html);
        $this->assertStringNotContainsString('resubmit-live-url', $html);
        $this->assertStringContainsString('can_resubmit', $html);
        $this->assertStringContainsString('chat-resubmit-form', $html);
        $this->assertStringContainsString('Make the corrections on the live article', $html);
        $this->assertStringContainsString('add the URL here again', $html);
        $this->assertStringContainsString('orderChat.load', $html);
    }
}
