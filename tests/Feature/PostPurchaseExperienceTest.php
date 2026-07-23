<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostPurchaseExperienceTest extends TestCase
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
            'site_name' => 'Post Purchase Site',
            'site_url' => 'https://post-purchase.example',
            'domain' => 'post-purchase.example',
            'da' => 35,
            'dr' => 35,
            'traffic' => 2000,
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

    private function reviewOrder(User $advertiser, Site $site, array $itemExtra = []): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-PP-'.uniqid(),
            'reference_code' => 'REF-PP-'.uniqid(),
            'subtotal' => 57.5,
            'tax' => 0,
            'total_amount' => 57.5,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now()->subDays(3),
        ]);

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 57.5,
            'content_link' => 'https://example.com/article.docx',
            'live_url' => 'https://post-purchase.example/live-post',
            'live_url_submitted_at' => now()->subHours(12),
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ], $itemExtra));

        return $order->fresh('items');
    }

    public function test_review_order_meta_shows_url_delivered_and_auto_approve_hint(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->reviewOrder($advertiser, $site);

        $meta = AdvertiserOrderStatus::meta($order, $order->items->first());
        $this->assertSame('URL delivered · your review', $meta['label']);
        $this->assertStringContainsString('approve or request changes', $meta['next']);
        $this->assertNotNull($meta['auto_approve_hint']);
        $this->assertStringContainsString('Auto-approves', $meta['auto_approve_hint']);

        $steps = AdvertiserOrderStatus::timelineSteps($order, $order->items->first());
        $labels = array_column($steps, 'label');
        $this->assertSame(['Paid', 'Accepted', 'Processing', 'URL delivered', 'Completed'], $labels);
        $this->assertTrue($steps[3]['current']);
        $this->assertFalse($steps[3]['done']);

        $response = $this->actingAs($advertiser)->getJson(route('advertiser.orders.list'));
        $response->assertOk()->assertJsonPath('success', true);
        $row = collect($response->json('orders'))->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame('URL delivered · your review', $row['status_label']);
        $this->assertNotEmpty($row['auto_approve_hint']);
    }

    public function test_request_changes_persists_reason_on_order_item(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->reviewOrder($advertiser, $site);
        $reason = 'Please fix the broken anchor link in paragraph two.';

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Change request sent to the publisher.');

        $item = $order->items()->first()->fresh();
        $this->assertSame('yes', $item->modification_requested);
        $this->assertSame($reason, $item->completion_notes);
        $this->assertSame('processing', $order->fresh()->status);

        $meta = AdvertiserOrderStatus::meta($order->fresh('items'));
        $this->assertSame('Revision requested', $meta['label']);
    }

    public function test_publisher_live_url_submit_stores_health_check_fields(): void
    {
        Http::fake([
            'https://reachable.example/*' => Http::response('ok', 200),
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-PP-URL-'.uniqid(),
            'reference_code' => 'REF-PP-URL-'.uniqid(),
            'subtotal' => 57.5,
            'tax' => 0,
            'total_amount' => 57.5,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 57.5,
            'content_link' => 'https://example.com/article.docx',
        ]);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://reachable.example/guest-post',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live_url_check.ok', true);

        $item->refresh();
        $this->assertTrue($item->live_url_check_ok);
        $this->assertSame(200, $item->live_url_http_status);
        $this->assertNotNull($item->live_url_checked_at);
        $this->assertSame('review', $order->fresh()->status);
    }

    public function test_auto_approve_notifies_advertiser_in_app(): void
    {
        config([
            'orders.auto_approve_hours' => 72,
            'orders.auto_approve_require_live_url_ok' => true,
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $publisherRoleId = Wallet::publisherRoleId();
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $publisherRoleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $advertiserRoleId = Wallet::advertiserRoleId();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advertiserRoleId,
            'balance' => 0,
            'reserved_balance' => 57.5,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $order = $this->reviewOrder($advertiser, $site, [
            'live_url_submitted_at' => now()->subHours(73),
            'live_url_check_ok' => true,
        ]);

        $this->artisan('orders:auto-approve')->assertSuccessful();

        $this->assertSame('completed', $order->fresh()->status);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => 'order_completed',
            'related_id' => $order->id,
        ]);

        $note = InAppNotification::where('user_id', $advertiser->id)
            ->where('related_id', $order->id)
            ->where('type', 'order_completed')
            ->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('auto-approved', strtolower($note->message));
    }

    public function test_auto_approve_reminder_sends_bell_at_about_24h_left(): void
    {
        config([
            'orders.auto_approve_hours' => 72,
            'orders.auto_approve_reminder_hours_before' => 24,
            'orders.auto_approve_require_live_url_ok' => true,
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $order = $this->reviewOrder($advertiser, $site, [
            'live_url_submitted_at' => now()->subHours(49),
            'live_url_check_ok' => true,
            'auto_approve_reminder_sent_at' => null,
        ]);

        $this->artisan('orders:auto-approve')->assertSuccessful();

        $this->assertSame('review', $order->fresh()->status);
        $this->assertNotNull($order->items()->first()->fresh()->auto_approve_reminder_sent_at);

        $note = InAppNotification::where('user_id', $advertiser->id)
            ->where('related_id', $order->id)
            ->where('type', 'order_updated')
            ->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('1 day left', strtolower($note->title));
    }

    public function test_auto_approve_skips_when_live_url_health_check_failed(): void
    {
        config([
            'orders.auto_approve_hours' => 72,
            'orders.auto_approve_require_live_url_ok' => true,
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $order = $this->reviewOrder($advertiser, $site, [
            'live_url_submitted_at' => now()->subHours(73),
            'live_url_check_ok' => false,
        ]);

        $this->artisan('orders:auto-approve')->assertSuccessful();

        $this->assertSame('review', $order->fresh()->status);
        $this->assertFalse((bool) $order->items()->first()->fresh()->auto_approve_triggered);
    }

    public function test_modification_request_resets_auto_approve_reminder(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->reviewOrder($advertiser, $site, [
            'auto_approve_reminder_sent_at' => now()->subHour(),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.order.modification', $order->id), [
                'reason' => 'Please update the H1 to match the brief.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item = $order->items()->first()->fresh();
        $this->assertSame('yes', $item->modification_requested);
        $this->assertNull($item->auto_approve_reminder_sent_at);
    }

    public function test_chat_order_details_include_next_action_and_order_id(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->reviewOrder($advertiser, $site);

        $this->actingAs($advertiser)
            ->getJson('/chat/messages/'.$order->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_details.order_id', $order->id)
            ->assertJsonPath('order_details.status_label', 'URL delivered · your review')
            ->assertJsonPath('order_details.can_approve', true)
            ->assertJsonPath('order_details.can_request_changes', true);

        $details = $this->actingAs($advertiser)
            ->getJson('/chat/messages/'.$order->id)
            ->json('order_details');
        $this->assertNotEmpty($details['next_action']);
        $this->assertArrayNotHasKey('da', $details);
        $this->assertArrayNotHasKey('dr', $details);
    }

    public function test_orders_page_includes_post_purchase_ux_copy(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->assertSee('Request changes', false)
            ->assertSee('Raise an issue', false)
            ->assertSee('URL delivered', false)
            ->assertSee('Refunded', false);
    }

    public function test_orders_chat_strip_has_no_review_action_buttons(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function renderChatOrderDetails', $html);
        $this->assertStringContainsString('hideOrderDetailsModal', $html);
        $this->assertStringContainsString('hideChatModal', $html);

        // Chat strip is status-only; review actions remain in View order details / table.
        $start = strpos($html, 'function renderChatOrderDetails');
        $this->assertNotFalse($start);
        $end = strpos($html, 'const orderChat = new OrderChat', $start);
        $this->assertNotFalse($end);
        $chatRenderer = substr($html, $start, $end - $start);

        $this->assertStringNotContainsString('Request changes', $chatRenderer);
        $this->assertStringNotContainsString('Open live URL', $chatRenderer);
        $this->assertStringNotContainsString('approveOrder(', $chatRenderer);
        $this->assertStringNotContainsString('requestModification(', $chatRenderer);
        $this->assertStringContainsString('Request changes', $html);
    }
}
