<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Support\AdvertiserOrderStatus;
use App\Support\InAppNotificationPresenter;
use App\Support\OrderNotificationReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OrderNotificationSyncTest extends TestCase
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
            'site_name' => 'Sync Site',
            'site_url' => 'https://sync-site.example',
            'domain' => 'sync-site.example',
            'example_url' => 'https://sync-site.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => str_repeat('Notification sync test site. ', 4),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function paidOrder(User $advertiser, Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(200000, 299999),
            'reference_code' => 'REF-SYNC-'.random_int(1000, 9999),
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

    private function presenter(): InAppNotificationPresenter
    {
        return app(InAppNotificationPresenter::class);
    }

    private function service(): InAppNotificationService
    {
        return app(InAppNotificationService::class);
    }

    public function test_advertiser_published_bell_is_action_needed_only_while_review_ready(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'review'], [
            'live_url' => 'https://sync-site.example/live',
            'live_url_submitted_at' => now(),
        ]);

        $this->service()->notifyLiveUrlSubmitted($order, $order->items->first(), $site, 'https://sync-site.example/live');

        $card = $this->orderCardFor($advertiser, $order->id);
        $this->assertTrue((bool) $card['action_needed']);
        $this->assertSame(3, (int) ($card['lifecycle_stepper']['current'] ?? 0));
        $this->assertSame(
            1,
            AdvertiserOrderStatus::needsActionCountForUser((int) $advertiser->id)
        );

        $order->update(['status' => 'completed', 'completed_at' => now()]);
        $order->items->first()->update(['completed_at' => now()]);

        $this->service()->syncOrderActionNotifications($order->fresh(['items']));

        $card = $this->orderCardFor($advertiser, $order->id);
        $this->assertTrue($card === null || empty($card['action_needed']));
        $this->assertSame(
            0,
            AdvertiserOrderStatus::needsActionCountForUser((int) $advertiser->id)
        );

        $published = InAppNotification::query()
            ->where('user_id', $advertiser->id)
            ->where('type', InAppNotificationService::TYPE_GUEST_POST_PUBLISHED)
            ->where('related_id', $order->id)
            ->first();
        $this->assertNotNull($published);
        $this->assertTrue($published->isArchived());
    }

    public function test_advertiser_unread_count_drops_after_complete_sync(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'review'], [
            'live_url' => 'https://sync-site.example/live-2',
            'live_url_submitted_at' => now(),
        ]);

        $this->service()->notifyLiveUrlSubmitted($order, $order->items->first(), $site, 'https://sync-site.example/live-2');
        $this->assertSame(1, $this->service()->unreadCount((int) $advertiser->id, 'advertiser'));

        $order->update(['status' => 'completed']);
        $this->service()->notifyOrderCompleted($order->fresh(['items']), $publisher, 80.0, true);

        $this->assertSame(1, $this->service()->unreadCount((int) $advertiser->id, 'advertiser'));
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'type' => InAppNotificationService::TYPE_ORDER_COMPLETED,
            'status' => InAppNotification::STATUS_UNREAD,
        ]);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $advertiser->id)
                ->where('type', InAppNotificationService::TYPE_GUEST_POST_PUBLISHED)
                ->first()
                ->isArchived()
        );
    }

    public function test_advertiser_cancelled_order_is_not_action_needed(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'review'], [
            'live_url' => 'https://sync-site.example/cancel',
            'live_url_submitted_at' => now(),
        ]);

        $this->service()->notifyLiveUrlSubmitted($order, $order->items->first(), $site, 'https://sync-site.example/cancel');
        $this->assertTrue((bool) $this->orderCardFor($advertiser, $order->id)['action_needed']);

        $order->update(['status' => 'cancelled', 'payment_status' => 'refunded']);
        $this->service()->notifyRefundCredited($order->fresh(['items']), 80.0, 'Publisher rejected');

        $card = $this->orderCardFor($advertiser, $order->id);
        $this->assertTrue($card === null || empty($card['action_needed']));
        $this->assertSame(0, AdvertiserOrderStatus::needsActionCountForUser((int) $advertiser->id));
    }

    public function test_advertiser_content_revision_then_fulfill_clears_action_needed(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'processing'], [
            'content_revision_requested' => 'yes',
        ]);
        $item = $order->items->first();

        $this->service()->notifyContentRevisionRequested($order, $item, $site, 'Please send an updated draft.');
        $this->assertTrue((bool) $this->orderCardFor($advertiser, $order->id)['action_needed']);
        $this->assertSame(1, AdvertiserOrderStatus::needsActionCountForUser((int) $advertiser->id));

        $item->update(['content_revision_requested' => 'no']);
        $this->service()->notifyContentRevisionFulfilled($order->fresh(['items']), $item->fresh(), $site);

        $card = $this->orderCardFor($advertiser, $order->id);
        $this->assertTrue($card === null || empty($card['action_needed']));
        $this->assertSame(0, AdvertiserOrderStatus::needsActionCountForUser((int) $advertiser->id));
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $advertiser->id)
                ->where('type', InAppNotificationService::TYPE_CONTENT_REVISION_REQUESTED)
                ->first()
                ->isArchived()
        );
    }

    public function test_publisher_new_order_action_needed_clears_after_accept(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'pending']);

        $this->service()->notifyOrderCreated($order->fresh(['items.site']), $site);
        $card = $this->orderCardFor($publisher, $order->id);
        $this->assertTrue((bool) $card['action_needed']);

        $item = $order->items->first();
        $item->update(['accepted_at' => now()]);
        $order->update(['status' => 'processing']);
        $this->service()->notifyOrderAccepted($order->fresh(['items.site']), $item->fresh(), $site);

        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $publisher->id)
                ->where('type', InAppNotificationService::TYPE_ORDER_CREATED)
                ->first()
                ->isArchived()
        );

        $this->actingAs($publisher)
            ->getJson(route('notifications.index', ['status' => 'active', 'group' => 1]))
            ->assertOk();
        $createdStillVisible = collect(
            $this->actingAs($publisher)->getJson(route('notifications.index', [
                'status' => 'active',
                'group' => 1,
            ]))->json('notifications')
        )->contains(fn ($c) => ($c['type'] ?? '') === InAppNotificationService::TYPE_ORDER_CREATED);
        $this->assertFalse($createdStillVisible);
    }

    public function test_publisher_modification_request_is_action_needed_until_resubmitted(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'processing'], [
            'modification_requested' => 'yes',
            'accepted_at' => now(),
        ]);

        $this->service()->notifyModificationRequested($order->fresh(['items.site']), 'Please fix the anchor.');
        $this->assertTrue((bool) $this->orderCardFor($publisher, $order->id)['action_needed']);

        $order->items->first()->update([
            'modification_requested' => 'no',
            'live_url' => 'https://sync-site.example/fixed',
            'live_url_submitted_at' => now(),
        ]);
        $order->update(['status' => 'review']);
        $this->service()->notifyLiveUrlSubmitted(
            $order->fresh(['items.site']),
            $order->items->first()->fresh(),
            $site,
            'https://sync-site.example/fixed'
        );

        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $publisher->id)
                ->where('type', InAppNotificationService::TYPE_MODIFICATION_REQUESTED)
                ->first()
                ->isArchived()
        );
        $this->actingAs($publisher)
            ->getJson(route('notifications.index', ['status' => 'active', 'group' => 1]))
            ->assertOk();
        $modCard = collect(
            $this->actingAs($publisher)->getJson(route('notifications.index', [
                'status' => 'active',
                'group' => 1,
            ]))->json('notifications')
        )->first(fn ($c) => ($c['type'] ?? '') === InAppNotificationService::TYPE_MODIFICATION_REQUESTED);
        $this->assertNull($modCard);
    }

    public function test_managed_live_url_is_not_advertiser_action_needed(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'review'], [
            'live_url' => 'https://sync-site.example/managed',
            'live_url_submitted_at' => now(),
            'fulfilment_source' => 'managed_by_slb',
        ]);

        $note = $this->service()->notify(
            $advertiser,
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'Your article is live · #'.$order->order_number,
            'Managed copy.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Open My Orders',
                'action_url' => '/advertiser/orders',
            ]
        );
        $this->assertNotNull($note);
        $this->assertFalse($note->fresh()->isArchived(), 'Informational live-URL bell stays until the order is terminal');

        $presented = $this->presenter()->presentOne($note->fresh());
        $this->assertFalse((bool) $presented['action_needed']);
        $this->assertSame(0, AdvertiserOrderStatus::needsActionCountForUser((int) $advertiser->id));
    }

    public function test_admin_needs_action_ignores_completed_managed_orders(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        $open = $this->paidOrder($advertiser, $site, ['status' => 'processing'], [
            'fulfilment_source' => 'managed_by_slb',
        ]);
        $done = $this->paidOrder($advertiser, $site, ['status' => 'completed', 'completed_at' => now()], [
            'fulfilment_source' => 'managed_by_slb',
            'live_url' => 'https://sync-site.example/done',
        ]);
        $this->assertNotNull($open);
        $this->assertNotNull($done);

        $this->assertSame(1, OrderNotificationReconciliation::adminOpenWorkCount());

        $this->actingAs($admin)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('needs_action', 1);
    }

    public function test_admin_stalled_bell_clears_when_order_completes(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'processing']);

        $this->service()->notifyAdminsStalledOrder(
            $order,
            $order->items->first(),
            $publisher,
            'publish',
            96
        );

        $bell = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('type', InAppNotificationService::TYPE_ORDER_UPDATED)
            ->where('related_id', $order->id)
            ->first();
        $this->assertNotNull($bell);
        $this->assertTrue((bool) $this->presenter()->presentOne($bell, collect([$order->id => $order]))['action_needed']);

        $order->update(['status' => 'completed']);
        $this->service()->syncOrderActionNotifications($order->fresh(['items']));

        $this->assertTrue($bell->fresh()->isArchived());
    }

    public function test_lifecycle_stepper_follows_live_order_not_event_history(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'completed'], [
            'live_url' => 'https://sync-site.example/done',
            'accepted_at' => now(),
        ]);

        $note = InAppNotification::create([
            'user_id' => $advertiser->id,
            'audience' => InAppNotification::AUDIENCE_ADVERTISER,
            'type' => InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'category' => InAppNotificationService::CATEGORY_ORDERS,
            'title' => 'Order #'.$order->order_number.' published',
            'message' => 'Ready for review.',
            'priority' => InAppNotification::PRIORITY_HIGH,
            'status' => InAppNotification::STATUS_UNREAD,
            'related_type' => Order::class,
            'related_id' => $order->id,
            'action_label' => 'Review order',
            'action_url' => '/advertiser/orders',
        ]);

        $presented = $this->presenter()->presentOne(
            $note->fresh(),
            collect([(int) $order->id => $order->fresh(['items'])])
        );
        $this->assertSame(4, (int) ($presented['lifecycle_stepper']['current'] ?? 0));
        $this->assertFalse((bool) $presented['action_needed']);
        $this->assertStringContainsString('Approved', (string) $presented['title']);
        $this->assertSame('#'.$order->order_number, $presented['order_ref'] ?? null);
        $this->assertSame('https://sync-site.example/done', $presented['live_url'] ?? null);
    }

    public function test_sync_command_is_idempotent(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site, ['status' => 'completed'], [
            'live_url' => 'https://sync-site.example/cmd',
        ]);

        $this->service()->notify(
            $advertiser,
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'Stale published bell',
            'Should be archived.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Review order',
                'action_url' => '/advertiser/orders',
            ]
        );

        // Creating the bell on a completed order archives it immediately via notify() sync.
        // Seed a leftover unread row the way production leftovers look.
        $leftover = InAppNotification::create([
            'user_id' => $advertiser->id,
            'audience' => InAppNotification::AUDIENCE_ADVERTISER,
            'type' => InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'category' => InAppNotificationService::CATEGORY_ORDERS,
            'title' => 'Leftover published bell',
            'message' => 'Production leftover.',
            'priority' => InAppNotification::PRIORITY_HIGH,
            'status' => InAppNotification::STATUS_UNREAD,
            'related_type' => Order::class,
            'related_id' => $order->id,
            'action_label' => 'Review order',
            'action_url' => '/advertiser/orders',
        ]);

        Artisan::call('notifications:sync-order-actions', ['--dry-run' => true]);
        $this->assertFalse($leftover->fresh()->isArchived());

        Artisan::call('notifications:sync-order-actions');
        $this->assertTrue($leftover->fresh()->isArchived());

        Artisan::call('notifications:sync-order-actions');
        $this->assertTrue($leftover->fresh()->isArchived());
        $this->assertSame(
            1,
            InAppNotification::withTrashed()
                ->where('id', $leftover->id)
                ->count()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderCardFor(User $user, int $orderId): ?array
    {
        $cards = $this->actingAs($user)
            ->getJson(route('notifications.index', [
                'status' => 'active',
                'category' => 'all',
                'per_page' => 50,
                'group' => 1,
            ]))
            ->assertOk()
            ->json('notifications');

        return collect($cards)->first(fn ($c) => (int) ($c['order_id'] ?? 0) === $orderId);
    }
}
