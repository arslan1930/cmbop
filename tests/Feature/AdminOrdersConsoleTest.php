<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminOrdersConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
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
            'site_name' => 'Admin Orders Site',
            'site_url' => 'https://admin-orders.example',
            'domain' => 'admin-orders.example',
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
            'order_number' => 'ORD-ADMIN-'.uniqid(),
            'reference_code' => 'REF-ADMIN-'.uniqid(),
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

    public function test_marketing_cannot_access_orders_console(): void
    {
        $marketing = $this->userWithRole('marketing');

        $this->actingAs($marketing)
            ->get(route('admin.orders.index'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($marketing)
            ->getJson(route('admin.orders.data'))
            ->assertStatus(403);
    }

    public function test_advertiser_cannot_access_orders_console(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->get(route('admin.orders.index'))
            ->assertStatus(403);
    }

    public function test_admin_can_list_and_view_order_with_chat(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        OrderChatMessage::create([
            'order_id' => $order->id,
            'user_id' => $advertiser->id,
            'sender_type' => 'advertiser',
            'message' => 'Please publish this week',
            'is_read' => false,
        ]);

        OrderActivity::create([
            'order_id' => $order->id,
            'actor_id' => $advertiser->id,
            'actor_name' => $advertiser->name,
            'actor_role' => 'advertiser',
            'event' => 'chat.message',
            'title' => 'Message sent',
            'description' => 'Please publish this week',
            'icon' => 'message-circle',
            'badge_color' => 'secondary',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Orders', false);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => $order->order_number]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Please publish this week')
            ->assertSee('Read-only')
            ->assertSee('Message sent')
            ->assertDontSee('chatForm', false);
    }

    public function test_admin_orders_data_array_search_does_not_500(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', [
                'search' => [$order->order_number],
                'status' => ['pending'],
                'payment_status' => ['paid'],
                'date_from' => ['2020-01-01'],
                'date_to' => ['2030-12-31'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => $order->order_number]);
    }

    public function test_stub_reports_and_settings_routes_are_gone(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get('/admin/reports')->assertNotFound();
        $this->actingAs($admin)->get('/admin/settings')->assertNotFound();
    }

    public function test_orders_index_reads_open_dispute_filter(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['dispute' => 'open']))
            ->assertOk()
            ->assertSee('id="disputeFilter"', false)
            ->assertSee("boot.get('dispute')", false);
    }

    public function test_orders_index_syncs_filters_to_the_url_and_reset_clears_them(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.orders.index', [
                'dispute' => 'open',
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertSee('function syncOrdersUrl', false)
            ->assertSee('history.replaceState', false)
            ->assertSee("boot.get('date_from')", false)
            ->assertSee("boot.get('date_to')", false)
            ->assertSee("boot.get('page')", false)
            ->assertSee('type="button" id="resetFiltersBtn"', false)
            ->assertSee('requested > lastPage', false)
            ->assertDontSee('setTimeout(() => loadOrders(1), 0)', false);
    }

    public function test_orders_data_reports_overshot_page_so_the_list_can_clamp(): void
    {
        $admin = $this->userWithRole('admin');
        $this->orderFor($this->userWithRole('advertiser'), $this->siteFor($this->userWithRole('publisher')));

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['page' => 9, 'per_page' => 20]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination.current_page', 9)
            ->assertJsonPath('pagination.last_page', 1);
    }

    public function test_orders_index_reads_unpaid_ops_filter(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['payment_status' => 'unpaid']))
            ->assertOk()
            ->assertSee('value="unpaid"', false)
            ->assertSee('Unpaid (ops queue)', false)
            ->assertSee("boot.get('payment_status')", false);
    }

    public function test_orders_data_filters_unpaid_ops_queue(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));

        $unpaid = $this->orderFor($advertiser, $site);
        $unpaid->update([
            'payment_status' => 'pending',
            'status' => 'processing',
            'paid_at' => null,
        ]);

        $failedOpen = $this->orderFor($advertiser, $site);
        $failedOpen->update([
            'payment_status' => 'failed',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $paid = $this->orderFor($advertiser, $site);

        $pendingButCompleted = $this->orderFor($advertiser, $site);
        $pendingButCompleted->update([
            'payment_status' => 'pending',
            'status' => 'completed',
            'paid_at' => null,
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['payment_status' => 'unpaid']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => $unpaid->order_number])
            ->assertJsonFragment(['order_number' => $failedOpen->order_number])
            ->assertJsonMissing(['order_number' => $paid->order_number])
            ->assertJsonMissing(['order_number' => $pendingButCompleted->order_number]);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['payment_status' => 'paid']))
            ->assertOk()
            ->assertJsonFragment(['order_number' => $paid->order_number])
            ->assertJsonMissing(['order_number' => $unpaid->order_number]);
    }

    public function test_orders_data_filters_by_created_date_range(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        $old = $this->orderFor($advertiser, $site);
        $old->created_at = now()->subDays(10);
        $old->save();

        $recent = $this->orderFor($advertiser, $site);
        $recent->created_at = now()->subDay();
        $recent->save();

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', [
                'date_from' => now()->subDays(2)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['order_number' => $recent->order_number])
            ->assertJsonMissing(['order_number' => $old->order_number]);
    }

    public function test_order_show_deep_links_payments_to_the_order_number(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee(route('admin.payments', ['search' => $order->order_number]), false)
            ->assertDontSee('payment_status=unpaid', false);
    }

    public function test_unpaid_order_show_deep_links_payments_to_the_ops_queue(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));
        $order->update([
            'payment_status' => 'pending',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee(e(route('admin.payments', [
                'search' => $order->order_number,
                'payment_status' => 'unpaid',
            ])), false);
    }

    public function test_orders_data_search_matches_site_and_publisher(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $advertiser->update(['name' => 'Buyer Alice', 'email' => 'buyer-alice@example.test']);

        $publisher = $this->userWithRole('publisher');
        $publisher->update(['name' => 'Nordic Publisher Co', 'email' => 'ops@nordic-pub.example']);

        $site = $this->siteFor($publisher);
        $site->update([
            'site_name' => 'Fjell Magazine',
            'site_url' => 'https://fjell-magazine.example',
            'domain' => 'fjell-magazine.example',
        ]);

        $order = $this->orderFor($advertiser, $site->fresh());
        $other = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Order #, reference, user, site, publisher…', false);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['search' => 'Nordic Publisher']))
            ->assertOk()
            ->assertJsonFragment(['order_number' => $order->order_number])
            ->assertJsonMissing(['order_number' => $other->order_number]);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['search' => 'fjell-magazine.example']))
            ->assertOk()
            ->assertJsonFragment(['order_number' => $order->order_number])
            ->assertJsonMissing(['order_number' => $other->order_number]);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['search' => 'ops@nordic-pub.example']))
            ->assertOk()
            ->assertJsonFragment(['order_number' => $order->order_number])
            ->assertJsonMissing(['order_number' => $other->order_number]);
    }

    public function test_order_show_displays_brief_and_content_download(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));
        $item = $order->items->first();
        $path = 'content-uploads/'.$advertiser->id.'/brief-article.docx';
        Storage::disk('local')->put($path, 'article-bytes');

        $submission = ContentSubmission::create([
            'user_id' => $advertiser->id,
            'title' => 'Brief article',
            'original_filename' => 'brief-article.docx',
            'disk' => 'local',
            'path' => $path,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 13,
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'anchor_text' => 'best guest post tools',
            'target_url' => 'https://advertiser.example/tools',
        ]);
        $item->update([
            'content_submission_id' => $submission->id,
            'anchor_text' => 'best guest post tools',
            'target_url' => 'https://advertiser.example/tools',
            'accepted_at' => now()->subHours(3),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('best guest post tools')
            ->assertSee('https://advertiser.example/tools', false)
            ->assertSee('Not submitted')
            ->assertSee(route('admin.orders.content.download', $item), false)
            ->assertSee('brief-article.docx');
    }

    public function test_admin_can_download_order_content_and_others_cannot(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));
        $item = $order->items->first();
        $path = 'content-uploads/'.$advertiser->id.'/download-me.docx';
        Storage::disk('local')->put($path, 'download-bytes');

        $submission = ContentSubmission::create([
            'user_id' => $advertiser->id,
            'title' => 'Download me',
            'original_filename' => 'download-me.docx',
            'disk' => 'local',
            'path' => $path,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 14,
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
        ]);
        $item->update(['content_submission_id' => $submission->id]);

        $this->actingAs($admin)
            ->get(route('admin.orders.content.download', $item))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($advertiser)
            ->get(route('admin.orders.content.download', $item))
            ->assertStatus(403);

        Storage::disk('local')->delete($path);

        $this->actingAs($admin)
            ->get(route('admin.orders.content.download', $item))
            ->assertNotFound();
    }

    public function test_order_show_hides_advertiser_only_content_links(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));
        $item = $order->items->first();
        $path = 'content-uploads/'.$advertiser->id.'/library-article.docx';
        Storage::disk('local')->put($path, 'library-bytes');

        $submission = ContentSubmission::create([
            'user_id' => $advertiser->id,
            'title' => 'Library article',
            'original_filename' => 'library-article.docx',
            'disk' => 'local',
            'path' => $path,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 13,
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
        ]);
        $item->update([
            'content_submission_id' => $submission->id,
            'content_link' => route('advertiser.content-submissions.download', $submission),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertDontSee('Open content link', false)
            ->assertSee(route('admin.orders.content.download', $item), false)
            ->assertSee('library-article.docx');
    }

    public function test_order_show_keeps_external_content_links(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->orderFor(
            $this->userWithRole('advertiser'),
            $this->siteFor($this->userWithRole('publisher'))
        );
        $order->items->first()->update([
            'content_link' => 'https://docs.google.com/document/d/abc123',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Open content link', false)
            ->assertSee('https://docs.google.com/document/d/abc123', false);
    }

    public function test_order_show_falls_back_to_submission_brief_fields(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));
        $item = $order->items->first();
        $path = 'content-uploads/'.$advertiser->id.'/brief-only.docx';
        Storage::disk('local')->put($path, 'brief-bytes');

        $submission = ContentSubmission::create([
            'user_id' => $advertiser->id,
            'title' => 'Brief only on submission',
            'original_filename' => 'brief-only.docx',
            'disk' => 'local',
            'path' => $path,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 11,
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'anchor_text' => 'guest post outreach kit',
            'target_url' => 'https://advertiser.example/outreach',
        ]);
        $item->update([
            'content_submission_id' => $submission->id,
            'anchor_text' => null,
            'target_url' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('guest post outreach kit')
            ->assertSee('https://advertiser.example/outreach', false);
    }

    public function test_admin_content_download_returns_404_for_unknown_disk(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->orderFor(
            $this->userWithRole('advertiser'),
            $this->siteFor($this->userWithRole('publisher'))
        );
        $item = $order->items->first();
        $item->update([
            'content_disk' => 'not-a-configured-disk',
            'content_path' => 'orphaned/article.docx',
            'content_original_name' => 'article.docx',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.content.download', $item))
            ->assertNotFound();
    }

    public function test_orders_index_renders_signal_and_party_link_helpers(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('function signalBadges', false)
            ->assertSee('has_open_dispute', false)
            ->assertSee('#order-disputes', false)
            ->assertSee('#order-schedule', false)
            ->assertSee('site_admin_url', false)
            ->assertSee('order.advertiser.url', false)
            ->assertSee('order.publisher.url', false);
    }

    public function test_orders_data_includes_list_signals_and_party_links(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        $plain = $this->orderFor($advertiser, $site);

        $live = $this->orderFor($advertiser, $site);
        $live->items->first()->update([
            'live_url' => 'https://admin-orders.example/published-guest-post',
        ]);

        $scheduledAt = Carbon::parse('2026-09-15 14:00:00', 'UTC');
        $scheduled = $this->orderFor($advertiser, $site);
        $scheduled->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $scheduledAt,
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $released = $this->orderFor($advertiser, $site);
        $released->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $scheduledAt,
            'schedule_timezone' => 'Europe/Berlin',
            'schedule_released_at' => Carbon::parse('2026-09-15 14:05:00', 'UTC'),
        ]);

        $cancelledScheduled = $this->orderFor($advertiser, $site);
        $cancelledScheduled->update([
            'status' => 'cancelled',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $scheduledAt,
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $disputed = $this->orderFor($advertiser, $site);
        $disputed->update(['status' => 'completed', 'completed_at' => now()->subDay()]);
        OrderItemDispute::ensureTable();
        OrderItemDispute::create([
            'order_id' => $disputed->id,
            'order_item_id' => $disputed->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live link was removed after approval.',
        ]);

        $advertiserUrl = route('admin.users.index', ['user' => $advertiser->id]).'#user-'.$advertiser->id;
        $publisherUrl = route('admin.users.index', ['user' => $publisher->id]).'#user-'.$publisher->id;
        $siteUrl = route('admin.sites.edit', $site->id);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'order_number' => $plain->order_number,
                'has_open_dispute' => false,
                'has_live_url' => false,
                'is_scheduled' => false,
                'site_admin_url' => $siteUrl,
            ])
            ->assertJsonFragment([
                'order_number' => $live->order_number,
                'has_live_url' => true,
                'live_url' => 'https://admin-orders.example/published-guest-post',
            ])
            ->assertJsonFragment([
                'order_number' => $scheduled->order_number,
                'is_scheduled' => true,
                'scheduled_publish_at_human' => 'Sep 15, 2026 4:00 PM Europe/Berlin',
            ])
            ->assertJsonFragment([
                'order_number' => $released->order_number,
                'is_scheduled' => false,
            ])
            ->assertJsonFragment([
                'order_number' => $cancelledScheduled->order_number,
                'is_scheduled' => false,
            ])
            ->assertJsonFragment([
                'order_number' => $disputed->order_number,
                'has_open_dispute' => true,
            ]);

        $rows = $this->actingAs($admin)
            ->getJson(route('admin.orders.data'))
            ->json('data');
        $plainRow = collect($rows)->firstWhere('order_number', $plain->order_number);

        $this->assertSame($advertiserUrl, $plainRow['advertiser']['url'] ?? null);
        $this->assertSame($publisherUrl, $plainRow['publisher']['url'] ?? null);
        $this->assertSame($siteUrl, $plainRow['site_admin_url'] ?? null);
    }

    public function test_order_show_exposes_dispute_anchor_for_list_signals(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));
        $order->update(['status' => 'completed', 'completed_at' => now()->subDay()]);
        OrderItemDispute::ensureTable();
        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live link was removed after approval.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('id="order-disputes"', false);
    }

    public function test_order_show_uses_named_dispute_routes_and_layout_sweetalert(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = $this->orderFor($advertiser, $this->siteFor($this->userWithRole('publisher')));
        $order->update(['status' => 'completed', 'completed_at' => now()->subDay()]);
        OrderItemDispute::ensureTable();
        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live link was removed after approval.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee(route('admin.orders.disputes.uphold', $dispute), false)
            ->assertSee(route('admin.orders.disputes.dismiss', $dispute), false)
            ->assertSee('data-resolve-url', false)
            ->assertDontSee('/admin/order-disputes/${', false)
            ->assertDontSee('sweetalert2.all.min.js', false)
            ->assertSee('cdn.jsdelivr.net/npm/sweetalert2@11', false);
    }

    public function test_order_show_offers_accept_reminder_while_the_publisher_has_not_accepted(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $order = $this->orderFor($this->userWithRole('advertiser'), $this->siteFor($publisher));
        $item = $order->items->first();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('id="remind-publisher"', false)
            ->assertSee('Remind to accept', false)
            ->assertSee(route('admin.orders.remind-publisher', $item), false)
            ->assertSee('Does not use up the automated reminder ladder', false)
            ->assertDontSee('Remind to publish', false);
    }

    public function test_order_show_offers_publish_reminder_after_accept_without_a_live_url(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->orderFor($this->userWithRole('advertiser'), $this->siteFor($this->userWithRole('publisher')));
        $item = $order->items->first();
        $item->update(['accepted_at' => now()->subDay()]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Remind to publish', false)
            ->assertSee(route('admin.orders.remind-publisher', $item), false)
            ->assertDontSee('Remind to accept', false);
    }

    public function test_order_show_hides_remind_when_the_publisher_cannot_be_chased(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));

        $completed = $this->orderFor($advertiser, $site);
        $completed->update(['status' => 'completed', 'completed_at' => now()]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $completed->id))
            ->assertOk()
            ->assertDontSee('id="remind-publisher"', false)
            ->assertDontSee('Remind to accept', false);

        $unpaid = $this->orderFor($advertiser, $site);
        $unpaid->update([
            'payment_status' => 'pending',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $unpaid->id))
            ->assertOk()
            ->assertDontSee('id="remind-publisher"', false);

        $live = $this->orderFor($advertiser, $site);
        $live->items->first()->update([
            'accepted_at' => now()->subDay(),
            'live_url' => 'https://admin-orders.example/published',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $live->id))
            ->assertOk()
            ->assertDontSee('id="remind-publisher"', false)
            ->assertDontSee('Remind to publish', false);

        $upcoming = $this->orderFor($advertiser, $site);
        $upcoming->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $upcoming->id))
            ->assertOk()
            ->assertDontSee('id="remind-publisher"', false);

        $released = $this->orderFor($advertiser, $site);
        $released->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->subHour(),
            'schedule_timezone' => 'Europe/Berlin',
            'schedule_released_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $released->id))
            ->assertOk()
            ->assertSee('id="remind-publisher"', false)
            ->assertSee('Remind to accept', false);
    }

    public function test_order_show_hides_schedule_fields_for_immediate_orders(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->orderFor($this->userWithRole('advertiser'), $this->siteFor($this->userWithRole('publisher')));
        $order->update([
            'publication_mode' => 'immediate',
            'schedule_timezone' => 'UTC',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertDontSee('id="order-schedule"', false)
            ->assertDontSee('Scheduled for', false)
            ->assertDontSee('Not released', false)
            ->assertDontSee('Not sent', false);
    }

    public function test_order_show_displays_scheduled_publish_fields(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->orderFor($this->userWithRole('advertiser'), $this->siteFor($this->userWithRole('publisher')));
        $at = Carbon::parse('2026-09-15 14:00:00', 'UTC');
        $order->update([
            'status' => 'scheduled',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $at,
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $local = $at->copy()->timezone('Europe/Berlin');

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('id="order-schedule"', false)
            ->assertSee("hash === '#order-schedule'", false)
            ->assertSee('Publication mode', false)
            ->assertSee('Scheduled for', false)
            ->assertSee($local->format('M j, Y g:i A'), false)
            ->assertSee('Europe/Berlin', false)
            ->assertSee('Not released', false)
            ->assertSee('Not sent', false);
    }

    public function test_order_show_displays_released_and_reminder_timestamps(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->orderFor($this->userWithRole('advertiser'), $this->siteFor($this->userWithRole('publisher')));
        $released = Carbon::parse('2026-09-15 14:05:00', 'UTC');
        $reminded = Carbon::parse('2026-09-14 08:00:00', 'UTC');
        $order->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => Carbon::parse('2026-09-15 14:00:00', 'UTC'),
            'schedule_timezone' => 'Europe/Berlin',
            'schedule_released_at' => $released,
            'schedule_reminder_sent_at' => $reminded,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee($released->format('M j, Y g:i A'), false)
            ->assertSee($reminded->format('M j, Y g:i A'), false)
            ->assertDontSee('Not released', false)
            ->assertDontSee('Not sent', false);
    }

    public function test_order_show_falls_back_to_utc_for_invalid_schedule_timezone(): void
    {
        $admin = $this->userWithRole('admin');
        $order = $this->orderFor($this->userWithRole('advertiser'), $this->siteFor($this->userWithRole('publisher')));
        $at = Carbon::parse('2026-09-15 14:00:00', 'UTC');
        $order->update([
            'status' => 'scheduled',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $at,
            'schedule_timezone' => 'Not/AZone',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee($at->copy()->timezone('UTC')->format('M j, Y g:i A'), false)
            ->assertSee('UTC', false)
            ->assertDontSee('Not/AZone', false);
    }

    public function test_orders_data_filters_awaiting_scheduled_release(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $at = Carbon::parse('2026-09-15 14:00:00', 'UTC');

        $upcoming = $this->orderFor($advertiser, $site);
        $upcoming->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $at,
            'schedule_timezone' => 'Europe/Berlin',
        ]);

        $legacyStatus = $this->orderFor($advertiser, $site);
        $legacyStatus->update([
            'status' => 'scheduled',
            'publication_mode' => 'immediate',
            'scheduled_publish_at' => $at,
        ]);

        $released = $this->orderFor($advertiser, $site);
        $released->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $at,
            'schedule_released_at' => now(),
        ]);

        $processing = $this->orderFor($advertiser, $site);
        $processing->update([
            'status' => 'processing',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => $at,
        ]);

        $plain = $this->orderFor($advertiser, $site);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['status' => 'scheduled']))
            ->assertOk()
            ->assertJsonFragment(['order_number' => $upcoming->order_number])
            ->assertJsonFragment(['order_number' => $legacyStatus->order_number])
            ->assertJsonMissing(['order_number' => $released->order_number])
            ->assertJsonMissing(['order_number' => $processing->order_number])
            ->assertJsonMissing(['order_number' => $plain->order_number]);
    }

    public function test_order_show_links_parties_and_site(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->orderFor($advertiser, $site);

        $advertiserUrl = route('admin.users.index', ['user' => $advertiser->id]).'#user-'.$advertiser->id;
        $publisherUrl = route('admin.users.index', ['user' => $publisher->id]).'#user-'.$publisher->id;
        $siteUrl = route('admin.sites.edit', $site->id);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee($advertiserUrl, false)
            ->assertSee($publisherUrl, false)
            ->assertSee($siteUrl, false);
    }
}
