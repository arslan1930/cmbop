<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dropdown Orders chip + live search must not leak account/system rows
 * (e.g. "New advertiser registered", "New bulk sites request").
 */
class NotificationCenterOrdersFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function admin(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function seedMixedNotifications(User $admin): array
    {
        $service = app(InAppNotificationService::class);

        $account = $service->notify(
            $admin,
            InAppNotificationService::TYPE_ACCOUNT,
            'New advertiser registered',
            'Alice just created an advertiser account.',
            [
                'audience' => InAppNotification::AUDIENCE_ADMIN,
                'category' => InAppNotificationService::CATEGORY_ACCOUNT,
                'action_label' => 'View advertisers (no orders)',
                'action_url' => '/admin/audiences?tab=no_orders',
            ]
        );

        $system = $service->notify(
            $admin,
            InAppNotificationService::TYPE_SYSTEM,
            'New bulk sites request',
            'Bob submitted 5 site URL(s) + price(s).',
            [
                'audience' => InAppNotification::AUDIENCE_ADMIN,
                'category' => InAppNotificationService::CATEGORY_SYSTEM,
                'action_label' => 'Open bulk request',
                'action_url' => '/admin/bulk-site-requests/1',
            ]
        );

        $orders = $service->notify(
            $admin,
            InAppNotificationService::TYPE_ORDER_CREATED,
            'New order #ORD-100',
            'Advertiser placed an order worth €50.',
            [
                'audience' => InAppNotification::AUDIENCE_ADMIN,
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'action_label' => 'View order',
                'action_url' => '/admin/orders/1',
            ]
        );

        $messages = $service->notify(
            $admin,
            InAppNotificationService::TYPE_MESSAGE,
            'New chat reply',
            'Publisher replied on order chat.',
            [
                'audience' => InAppNotification::AUDIENCE_ADMIN,
                'category' => InAppNotificationService::CATEGORY_MESSAGES,
                'action_label' => 'Open messages',
                'action_url' => '/admin/orders/1',
            ]
        );

        return compact('account', 'system', 'orders', 'messages');
    }

    public function test_category_orders_returns_only_orders_notifications(): void
    {
        $admin = $this->admin();
        $seeded = $this->seedMixedNotifications($admin);

        $response = $this->actingAs($admin)
            ->getJson(route('notifications.index', [
                'category' => 'orders',
                'status' => 'active',
                'per_page' => 20,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $titles = collect($response->json('notifications'))->pluck('title')->all();
        $categories = collect($response->json('notifications'))->pluck('category')->unique()->values()->all();

        $this->assertSame(['orders'], $categories);
        $this->assertContains('New order #ORD-100', $titles);
        $this->assertNotContains('New advertiser registered', $titles);
        $this->assertNotContains('New bulk sites request', $titles);
        $this->assertNotContains('New chat reply', $titles);
        $this->assertSame(InAppNotificationService::CATEGORY_ORDERS, $seeded['orders']->category);
        $this->assertSame(InAppNotificationService::CATEGORY_ACCOUNT, $seeded['account']->category);
        $this->assertSame(InAppNotificationService::CATEGORY_SYSTEM, $seeded['system']->category);
    }

    public function test_leftover_archived_at_does_not_hide_bell(): void
    {
        $admin = $this->admin();
        $seeded = $this->seedMixedNotifications($admin);
        DB::table('in_app_notifications')->where('id', $seeded['orders']->id)->update([
            'archived_at' => 'not-a-date',
        ]);

        $this->assertFalse($seeded['orders']->fresh()->isArchived());
        $this->assertSame(4, app(InAppNotificationService::class)->unreadCount($admin->id));

        $titles = collect($this->actingAs($admin)
            ->getJson(route('notifications.index', [
                'status' => 'active',
                'per_page' => 20,
            ]))
            ->assertOk()
            ->json('notifications'))->pluck('title')->all();

        $this->assertContains('New order #ORD-100', $titles);
    }

    public function test_search_q_filters_title_and_message_not_action_label(): void
    {
        $admin = $this->admin();
        $this->seedMixedNotifications($admin);

        // "order" appears in the account CTA label ("no orders") and in the order title.
        // listForUser must search title/message only — not action_label — so the
        // advertiser-registration row must not false-match.
        $byOrder = $this->actingAs($admin)
            ->getJson(route('notifications.index', [
                'category' => 'all',
                'q' => 'order',
                'per_page' => 20,
            ]))
            ->assertOk()
            ->json('notifications');

        $orderTitles = collect($byOrder)->pluck('title')->all();
        $this->assertContains('New order #ORD-100', $orderTitles);
        $this->assertContains('New chat reply', $orderTitles); // message: "order chat"
        $this->assertNotContains('New advertiser registered', $orderTitles);
        $this->assertNotContains('New bulk sites request', $orderTitles);

        $byBulk = $this->actingAs($admin)
            ->getJson(route('notifications.index', [
                'q' => 'bulk sites',
                'per_page' => 20,
            ]))
            ->assertOk()
            ->json('notifications');

        $this->assertCount(1, $byBulk);
        $this->assertSame('New bulk sites request', $byBulk[0]['title']);
    }

    public function test_notify_admins_new_user_and_bulk_use_non_orders_categories(): void
    {
        $admin = $this->admin();
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
            'name' => 'Fresh Adv',
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $service = app(InAppNotificationService::class);
        $service->notifyAdminsNewUser($advertiser);

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('title', 'New advertiser registered')
            ->latest('id')
            ->first();

        $this->assertNotNull($note);
        $this->assertSame(InAppNotificationService::CATEGORY_ACCOUNT, $note->category);
        $this->assertSame('View advertisers (no orders)', $note->action_label);
        $this->assertNotSame(InAppNotificationService::CATEGORY_ORDERS, $note->category);
    }

    public function test_notification_center_js_queries_filters_from_panel_after_portal(): void
    {
        foreach ([
            public_path('js/notification-center.js'),
            public_path('assets/js/notification-center.js'),
        ] as $path) {
            $this->assertFileExists($path);
            $js = (string) file_get_contents($path);

            // Panel is portaled to document.body on open; chip active-state must
            // be updated via panel (or a filter scope), not only root.
            $this->assertStringContainsString('document.body.appendChild(this.panel)', $js);
            $this->assertTrue(
                str_contains($js, 'self.panel.querySelectorAll(\'[data-nc-filter]\')')
                || str_contains($js, 'self.panel.querySelectorAll("[data-nc-filter]")')
                || str_contains($js, 'filterScope.querySelectorAll')
                || str_contains($js, '(self.panel || self.root).querySelectorAll')
                || str_contains($js, 'this.panel || this.root')
                || str_contains($js, 'syncFilterChips'),
                basename($path).' must update filter chips via panel after body portal'
            );
            // Wider fetch window for order grouping; cardLimit caps dropdown cards.
            $this->assertTrue(
                str_contains($js, 'per_page: String(this.fetchLimit)')
                || str_contains($js, 'per_page: String(this.limit)'),
                basename($path).' must pass per_page for the dropdown fetch'
            );
            $this->assertTrue(
                str_contains($js, 'this.fetchLimit = 40')
                || str_contains($js, 'this.limit = 3'),
                basename($path).' must define a dropdown fetch/card limit'
            );
            $this->assertStringContainsString('280', $js); // search debounce
            $this->assertStringContainsString('No matching notifications.', $js);
            $this->assertStringContainsString('syncFilterChips', $js);
            $this->assertStringContainsString('activeChipValue', $js);
            // Client-side category guard so Orders never paints account/system rows.
            $this->assertStringContainsString('n.category', $js);
            $this->assertTrue(
                str_contains($js, "String(n.category || '') === self.filter")
                || str_contains($js, 'n.category === self.filter'),
                basename($path).' must client-filter by category when a chip is active'
            );
        }
    }

    public function test_new_advertiser_message_uses_an_article(): void
    {
        $admin = $this->admin();
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
            'name' => 'Michael Jordan',
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        app(InAppNotificationService::class)->notifyAdminsNewUser($advertiser);

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('title', 'New advertiser registered')
            ->latest('id')
            ->first();

        $this->assertNotNull($note);
        $this->assertStringContainsString('just created an advertiser account.', (string) $note->message);
        $this->assertStringNotContainsString('just created a advertiser account.', (string) $note->message);
    }
}
