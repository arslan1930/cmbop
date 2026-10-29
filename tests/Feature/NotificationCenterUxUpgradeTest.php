<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\InAppNotificationPreference;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Support\InAppNotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationCenterUxUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdvertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function makePublisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function makeOrder(User $advertiser, string $orderNumber): Order
    {
        return Order::create([
            'user_id' => $advertiser->id,
            'order_number' => $orderNumber,
            'reference_code' => 'REF-NC-'.random_int(1000, 9999),
            'subtotal' => 92,
            'tax' => 0,
            'total_amount' => 92,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    public function test_js_copies_stay_identical(): void
    {
        $a = public_path('assets/js/notification-center.js');
        $b = public_path('js/notification-center.js');
        $this->assertFileExists($a);
        $this->assertFileExists($b);
        $this->assertSame(md5_file($a), md5_file($b));
    }

    public function test_dropdown_markup_has_settings_gear_and_filter_arrows(): void
    {
        $user = $this->makeAdvertiser();
        $html = $this->actingAs($user)->get('/advertiser/dashboard')->getContent();

        $this->assertStringContainsString('data-nc-settings', $html);
        $this->assertStringContainsString('profile/notifications', $html);
        $this->assertStringContainsString('data-nc-filters-prev', $html);
        $this->assertStringContainsString('data-nc-filters-next', $html);
        $this->assertStringContainsString('Notification settings', $html);
    }

    public function test_index_groups_order_notifications_and_flags_action_needed(): void
    {
        $user = $this->makeAdvertiser();
        $service = app(InAppNotificationService::class);

        $order = $this->makeOrder($user, '695973');

        $service->notify(
            $user,
            InAppNotificationService::TYPE_ORDER_ACCEPTED,
            'Order #695973 accepted',
            'The publisher accepted your order.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View order',
                'action_url' => '/advertiser/orders',
                'meta' => ['order_number' => '695973', 'live_url' => null],
            ]
        );

        $published = $service->notify(
            $user,
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'Order #695973 published',
            'Your backlink has been published and is ready for review.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'priority' => InAppNotification::PRIORITY_HIGH,
                'action_label' => 'Review order',
                'action_url' => '/advertiser/orders',
                'meta' => [
                    'order_number' => '695973',
                    'live_url' => 'https://example.com/article-1',
                    'auto_approve_hours' => 72,
                    'site_domain' => 'example.com',
                ],
            ]
        );

        $this->assertNotNull($published);

        $response = $this->actingAs($user)
            ->getJson(route('notifications.index', [
                'status' => 'active',
                'category' => 'all',
                'per_page' => 20,
                'group' => 1,
            ]));

        $response->assertOk()->assertJsonPath('success', true);
        $cards = $response->json('notifications');
        $this->assertIsArray($cards);
        $this->assertNotEmpty($cards);

        $orderCard = collect($cards)->first(fn ($c) => ($c['order_id'] ?? null) == $order->id);
        $this->assertNotNull($orderCard, 'Expected a grouped order card');
        $this->assertTrue((bool) ($orderCard['is_group'] ?? false) || ($orderCard['event_count'] ?? 0) >= 1);
        $this->assertTrue((bool) $orderCard['action_needed']);
        $this->assertSame('https://example.com/article-1', $orderCard['live_url']);
        $this->assertNotEmpty($orderCard['auto_approve_label']);
        $this->assertStringContainsString('Auto-approves', $orderCard['auto_approve_label']);
        $this->assertNotEmpty($orderCard['favicon_url']);
        $this->assertNotEmpty($orderCard['timeline']);
    }

    public function test_publisher_new_order_is_action_needed(): void
    {
        $publisher = $this->makePublisher();
        $presenter = app(InAppNotificationPresenter::class);

        $this->assertTrue($presenter->isActionNeeded(
            InAppNotificationService::TYPE_ORDER_CREATED,
            'View task',
            InAppNotification::PRIORITY_HIGH
        ));

        $service = app(InAppNotificationService::class);
        $n = $service->notify(
            $publisher,
            InAppNotificationService::TYPE_ORDER_CREATED,
            'New order #100',
            'You received a new order.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'priority' => InAppNotification::PRIORITY_HIGH,
                'action_label' => 'View task',
                'action_url' => '/publisher/tasks',
            ]
        );

        $presented = $presenter->presentOne($n->fresh());
        $this->assertTrue($presented['action_needed']);
    }

    public function test_action_needed_cards_pin_above_info(): void
    {
        $user = $this->makeAdvertiser();
        $service = app(InAppNotificationService::class);
        $presenter = app(InAppNotificationPresenter::class);

        $info = $service->notify(
            $user,
            InAppNotificationService::TYPE_ORDER_ACCEPTED,
            'Order accepted',
            'Info only.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View order',
                'action_url' => '/advertiser/orders',
            ]
        );
        $info->forceFill(['created_at' => now()->subMinute()])->save();

        $action = $service->notify(
            $user,
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'Order published',
            'Ready for review.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'priority' => InAppNotification::PRIORITY_HIGH,
                'action_label' => 'Review order',
                'action_url' => '/advertiser/orders',
                'meta' => ['live_url' => 'https://example.com/x', 'auto_approve_hours' => 72],
            ]
        );
        $action->forceFill(['created_at' => now()->subHours(2)])->save();

        $cards = $presenter->groupItems($presenter->presentMany(
            InAppNotification::forUser($user->id)->latest()->get()
        ));

        $this->assertNotEmpty($cards);
        $this->assertTrue((bool) $cards[0]['action_needed']);
        $this->assertSame($action->id, $cards[0]['id']);
    }

    public function test_show_all_renders_grouped_cards_and_open_link(): void
    {
        $user = $this->makeAdvertiser();
        $service = app(InAppNotificationService::class);
        $order = $this->makeOrder($user, '555');

        $service->notify(
            $user,
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            'Order #555 published',
            'Ready for review.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Review order',
                'action_url' => '/advertiser/orders',
                'meta' => [
                    'live_url' => 'https://news.example/post',
                    'auto_approve_hours' => 72,
                    'site_domain' => 'news.example',
                ],
            ]
        );

        $this->actingAs($user)
            ->get(route('notifications.all'))
            ->assertOk()
            ->assertSee('Action needed', false)
            ->assertSee('Open link', false)
            ->assertSee('https://news.example/post', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('nc-kebab', false)
            ->assertSee('Settings', false);
    }

    public function test_in_app_preference_mute_skips_bell_when_table_exists(): void
    {
        if (! Schema::hasTable('in_app_notification_preferences')) {
            $this->markTestSkipped('preferences table missing');
        }

        $user = $this->makeAdvertiser();
        InAppNotificationPreference::create([
            'user_id' => $user->id,
            'category' => 'orders',
            'enabled' => false,
        ]);

        $n = app(InAppNotificationService::class)->notify(
            $user,
            InAppNotificationService::TYPE_ORDER_ACCEPTED,
            'Should not create',
            'Muted.',
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
            ]
        );

        $this->assertNull($n);
        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $user->id,
            'title' => 'Should not create',
        ]);
    }

    public function test_preferences_page_loads_and_saves_in_app_toggles(): void
    {
        $user = $this->makeAdvertiser();

        $this->actingAs($user)
            ->get(route('profile.notifications'))
            ->assertOk()
            ->assertSee('Notification Preferences', false)
            ->assertSee('In-app notifications', false);

        if (! InAppNotificationPreference::tableAvailable()) {
            return;
        }

        $this->actingAs($user)
            ->post(route('profile.notifications.update'), [
                'in_app' => [
                    'orders' => '1',
                    'messages' => '1',
                    // payments intentionally off
                    'account' => '1',
                ],
                'preferences' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('in_app_notification_preferences', [
            'user_id' => $user->id,
            'category' => 'payments',
            'enabled' => 0,
        ]);
        $this->assertDatabaseHas('in_app_notification_preferences', [
            'user_id' => $user->id,
            'category' => 'orders',
            'enabled' => 1,
        ]);
    }

    public function test_js_includes_kebab_and_nine_plus_cap(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/notification-center.js'));
        $this->assertStringContainsString('nc-kebab', $js);
        $this->assertStringContainsString('capAt: 9', $js);
        $this->assertStringContainsString('Open link', $js);
        $this->assertStringContainsString('Action needed', $js);
        $this->assertStringContainsString('syncFilterOverflow', $js);
        $this->assertStringContainsString('noopener', $js);
    }
}
