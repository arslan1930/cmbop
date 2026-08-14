<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationBellUnreadUxTest extends TestCase
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

    private function notify(User $user, string $title = 'Welcome'): InAppNotification
    {
        $notification = app(InAppNotificationService::class)->notify(
            $user,
            InAppNotificationService::TYPE_SYSTEM,
            $title,
            'Body copy.',
            ['category' => InAppNotificationService::CATEGORY_SYSTEM]
        );

        $this->assertNotNull($notification);

        return $notification;
    }

    public function test_guest_cannot_mark_unread(): void
    {
        $this->postJson('/notifications/1/unread')->assertStatus(401);
    }

    public function test_user_cannot_mark_another_users_notification_unread(): void
    {
        $owner = $this->makeAdvertiser();
        $other = $this->makeAdvertiser();
        $notification = $this->notify($owner);
        $notification->markRead();

        $this->actingAs($other)
            ->postJson(route('notifications.unread', $notification->id))
            ->assertNotFound();

        $this->assertDatabaseHas('in_app_notifications', [
            'id' => $notification->id,
            'status' => InAppNotification::STATUS_READ,
        ]);
    }

    public function test_mark_unread_on_archived_does_not_resurrect_into_unread(): void
    {
        $user = $this->makeAdvertiser();
        $notification = $this->notify($user);
        $notification->archive();

        $this->actingAs($user)
            ->postJson(route('notifications.unread', $notification->id))
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('notification.is_archived', true)
            ->assertJsonPath('notification.is_unread', false);

        $this->assertTrue($notification->fresh()->isArchived());

        $this->actingAs($user)
            ->getJson(route('notifications.index', ['status' => 'unread']))
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'notifications');
    }

    public function test_archive_leaves_dropdown_but_stays_on_show_all(): void
    {
        $user = $this->makeAdvertiser();
        $live = $this->notify($user, 'Live tip');
        $archived = $this->notify($user, 'Old tip');
        $archived->archive();

        $this->actingAs($user)
            ->getJson(route('notifications.index', ['status' => 'active']))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Live tip'])
            ->assertJsonMissing(['title' => 'Old tip']);

        $this->actingAs($user)
            ->getJson(route('notifications.index', ['status' => 'unread']))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Live tip'])
            ->assertJsonMissing(['title' => 'Old tip']);

        $this->actingAs($user)
            ->get(route('notifications.all'))
            ->assertOk()
            ->assertSee('Live tip', false)
            ->assertSee('Old tip', false)
            ->assertSee('nc-item-state is-archived', false);

        $this->actingAs($user)
            ->get(route('notifications.all', ['category' => 'archived']))
            ->assertOk()
            ->assertSee('Old tip', false)
            ->assertDontSee('Live tip', false);

        $this->actingAs($user)
            ->get(route('notifications.all', ['q' => ['Live'], 'category' => ['all']]))
            ->assertOk()
            ->assertSee('Live tip', false)
            ->assertDontSee('Array to string conversion', false);

        $this->actingAs($user)
            ->getJson(route('notifications.index', ['q' => ['Live'], 'status' => ['unread']]))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Live tip']);
    }

    public function test_api_payload_includes_archived_flag(): void
    {
        $user = $this->makeAdvertiser();
        $notification = $this->notify($user);
        $notification->markRead();

        $this->actingAs($user)
            ->getJson(route('notifications.index', ['status' => 'active']))
            ->assertOk()
            ->assertJsonPath('notifications.0.is_unread', false)
            ->assertJsonPath('notifications.0.is_archived', false);
    }

    public function test_dropdown_and_js_copies_default_to_unread(): void
    {
        $partial = (string) file_get_contents(resource_path('views/partials/notification-center.blade.php'));
        $this->assertStringContainsString('class="nc-filter is-active" data-nc-filter="unread"', $partial);
        $this->assertStringContainsString('data-nc-unread-label', $partial);
        $this->assertStringContainsString('data-unread-item-url="/notifications/__ID__/unread"', $partial);

        $card = (string) file_get_contents(resource_path('views/partials/notification-card.blade.php'));
        $this->assertStringContainsString('Mark as read', $card);
        $this->assertStringContainsString('Mark as unread', $card);
        $this->assertStringContainsString('nc-item-state', $card);

        $css = (string) file_get_contents(public_path('assets/css/notification-center.css'));
        $this->assertStringContainsString('.nc-item.is-unread', $css);
        $this->assertStringContainsString('border-left-color', $css);
        $this->assertStringContainsString('.nc-item-state', $css);
        $this->assertStringContainsString('min-height: 0', $css);
        $this->assertStringContainsString('flex-shrink: 0', $css);

        $paths = [
            public_path('js/notification-center.js'),
            public_path('assets/js/notification-center.js'),
        ];
        $hashes = [];
        foreach ($paths as $path) {
            $this->assertFileExists($path);
            $js = (string) file_get_contents($path);
            $hashes[] = md5($js);
            $this->assertStringContainsString("this.status = 'unread'", $js);
            $this->assertStringContainsString("self.showAllLink.style.display = 'inline-flex'", $js);
            $this->assertStringNotContainsString('data.pagination.total > 0', $js);
            $this->assertStringNotContainsString("allParams.set('category', 'unread')", $js);
            $this->assertStringContainsString('window.location.assign', $js);
            $this->assertStringContainsString('syncUnreadLabel', $js);
            $this->assertStringContainsString('Mark as unread', $js);
            $this->assertStringContainsString('unreadItemUrl', $js);
            $this->assertStringContainsString('window.confirm', $js);
            $this->assertStringContainsString('Switch to All to see earlier notifications.', $js);
        }
        $this->assertSame($hashes[0], $hashes[1], 'Both notification-center.js copies must stay identical');
    }
}
