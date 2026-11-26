<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Support\InAppNotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationDropdownPanelTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => ucfirst($roleName).' Demo',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function siteFor(User $publisher, string $name = 'Inka Magazin'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://inka-magazin.example',
            'domain' => 'inka-magazin.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => str_repeat('Notification panel test site. ', 4),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function paidOrder(User $advertiser, Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => '645240',
            'reference_code' => 'REF-NC-'.random_int(1000, 9999),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ], $orderAttrs));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 80,
            'additional_price' => 0,
            'modification_requested' => 'no',
        ], $itemAttrs));

        return $order->fresh(['items.site']);
    }

    private function service(): InAppNotificationService
    {
        return app(InAppNotificationService::class);
    }

    private function presenter(): InAppNotificationPresenter
    {
        return app(InAppNotificationPresenter::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardsFor(User $user): array
    {
        return $this->actingAs($user)
            ->getJson(route('notifications.index', [
                'status' => 'active',
                'category' => 'all',
                'per_page' => 50,
                'group' => 1,
            ]))
            ->assertOk()
            ->json('notifications');
    }

    public function test_older_order_status_rows_are_superseded_and_title_matches_live_status(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'processing'], [
            'accepted_at' => now(),
        ]);

        $this->service()->notifyOrderAccepted($order, $order->items->first(), $site);

        $order->update(['status' => 'completed', 'completed_at' => now()]);
        $order->items->first()->update([
            'live_url' => 'https://inka-magazin.example/live',
            'live_url_submitted_at' => now(),
            'completed_at' => now(),
        ]);

        $this->service()->notify(
            $advertiser,
            InAppNotificationService::TYPE_ORDER_COMPLETED,
            'Order #645240 completed',
            'Done.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order->fresh(['items.site']),
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View order',
                'action_url' => '/advertiser/orders',
                'meta' => ['order_number' => '645240', 'site_name' => 'Inka Magazin'],
            ]
        );

        $accepted = InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_ORDER_ACCEPTED)
            ->first();
        $this->assertNotNull($accepted);
        $this->assertNotEmpty(data_get($accepted->meta, 'superseded_at'));
        $this->assertNotSame(InAppNotification::STATUS_UNREAD, $accepted->status);
        $this->assertNull($accepted->deleted_at);

        $cards = $this->cardsFor($advertiser);
        $lifecycle = collect($cards)->where('is_lifecycle', true)->where('order_id', $order->id)->values();
        $this->assertCount(1, $lifecycle);
        $card = $lifecycle[0];
        $this->assertStringContainsString('Inka Magazin', (string) $card['title']);
        $this->assertStringContainsString('Approved', (string) $card['title']);
        $this->assertSame('#645240', $card['order_ref'] ?? null);
        $this->assertSame(4, (int) ($card['lifecycle_stepper']['current'] ?? 0));
        $this->assertSame('https://inka-magazin.example/live', $card['live_url'] ?? null);
        $this->assertStringNotContainsString('accepted', strtolower((string) $card['title']));
    }

    public function test_placed_order_does_not_show_live_url(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'processing'], [
            'accepted_at' => now(),
        ]);
        $this->service()->notifyOrderAccepted($order, $order->items->first(), $site);

        $card = collect($this->cardsFor($advertiser))->firstWhere('order_id', $order->id);
        $this->assertNotNull($card);
        $this->assertNull($card['live_url'] ?? null);
        $this->assertStringContainsString('Accepted', (string) $card['title']);
        $this->assertSame(2, (int) ($card['lifecycle_stepper']['current'] ?? 0));
    }

    public function test_message_rows_stay_separate_with_role_prefix_and_no_live_url(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, [
            'status' => 'review',
            'order_number' => '695973',
        ], [
            'accepted_at' => now(),
            'live_url' => 'https://inka-magazin.example/live',
            'live_url_submitted_at' => now(),
        ]);

        $this->service()->notify(
            $advertiser,
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'Order #695973 published',
            'Ready.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View order',
                'action_url' => '/advertiser/orders',
                'meta' => [
                    'order_number' => '695973',
                    'live_url' => 'https://inka-magazin.example/live',
                    'site_name' => 'Inka Magazin',
                ],
            ]
        );

        $this->service()->notifyNewChatMessage($order->fresh(['items.site']), $publisher, $advertiser, '🙏');

        $cards = $this->cardsFor($advertiser);
        $messages = collect($cards)->where('is_message', true)->values();
        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('Inka Magazin', $msg['title_primary'] ?? $msg['title'] ?? null);
        $this->assertSame('#695973', $msg['order_ref'] ?? null);
        $this->assertNull($msg['live_url'] ?? null);
        $this->assertStringStartsWith('Publisher:', (string) $msg['message']);
        $this->assertStringContainsString('🙏', (string) $msg['message']);
        $this->assertStringNotContainsString($publisher->email, (string) $msg['message']);
        $this->assertEmpty($msg['lifecycle_stepper'] ?? null);

        $page = $this->actingAs($advertiser)->get(route('notifications.all'))->assertOk();
        $page->assertSee('Publisher: 🙏', false)
            ->assertSee('Live URL', false)
            ->assertDontSee('Open link', false)
            ->assertSee('Inka Magazin — Published', false)
            ->assertSee('#695973', false);
    }

    public function test_unread_header_on_empty_inbox_is_all_caught_up(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $html = $this->actingAs($advertiser)
            ->get(route('notifications.all'))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/nc-page-subtitle[^>]*>\s*All caught up/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/nc-page-subtitle[^>]*>\s*No unread notifications/s',
            $html
        );
    }

    public function test_all_read_inbox_uses_no_unread_copy(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'processing'], [
            'accepted_at' => now(),
        ]);
        $this->service()->notifyOrderAccepted($order, $order->items->first(), $site);
        $note = InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_ORDER_ACCEPTED)
            ->first();
        $this->assertNotNull($note);
        $note->markRead();

        $html = $this->actingAs($advertiser)
            ->get(route('notifications.all'))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/nc-page-subtitle[^>]*>\s*No unread notifications/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/nc-page-subtitle[^>]*>\s*All caught up/s',
            $html
        );
    }

    public function test_publisher_lifecycle_card_uses_site_title(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'pending']);
        $this->service()->notifyOrderCreated($order, $site);

        $card = collect($this->cardsFor($publisher))->firstWhere('is_lifecycle', true);
        $this->assertNotNull($card);
        $this->assertStringContainsString('Inka Magazin', (string) $card['title']);
        $this->assertSame('#645240', $card['order_ref'] ?? null);
        $this->assertNull($card['live_url'] ?? null);
    }

    public function test_thumb_uses_crisp_favicon_size_and_initial_fallback(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'processing'], [
            'accepted_at' => now(),
        ]);
        $this->service()->notifyOrderAccepted($order, $order->items->first(), $site);

        $card = collect($this->cardsFor($advertiser))->firstWhere('order_id', $order->id);
        $this->assertNotNull($card);
        $this->assertSame('I', $card['site_initial'] ?? null);
        $this->assertStringContainsString('sz=128', (string) $card['thumb_url']);
        $this->assertStringContainsString('width="40"', file_get_contents(public_path('assets/js/notification-center.js')));
    }

    public function test_dropdown_partial_exposes_header_copy_keys(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $html = $this->actingAs($advertiser)->get('/advertiser/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('data-i18n-no-unread', $html);
        $this->assertStringContainsString('data-i18n-live-url', $html);
        $this->assertStringContainsString('All caught up', $html);
    }
}
