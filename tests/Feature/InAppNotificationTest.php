<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InAppNotificationTest extends TestCase
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

    public function test_guest_cannot_list_notifications(): void
    {
        $this->getJson(route('notifications.index'))->assertStatus(401);
    }

    public function test_user_can_list_and_mark_notifications(): void
    {
        $user = $this->makeAdvertiser();

        $notification = app(InAppNotificationService::class)->notify(
            $user,
            InAppNotificationService::TYPE_SYSTEM,
            'Welcome',
            'Your in-app notification center is ready.',
            ['category' => InAppNotificationService::CATEGORY_SYSTEM]
        );

        $this->assertNotNull($notification);

        $this->actingAs($user)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.title', 'Welcome');

        $this->actingAs($user)
            ->postJson(route('notifications.read', $notification->id))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertDatabaseHas('in_app_notifications', [
            'id' => $notification->id,
            'status' => InAppNotification::STATUS_READ,
        ]);
    }

    public function test_deleted_notifications_are_soft_deleted(): void
    {
        $user = $this->makeAdvertiser();
        $notification = app(InAppNotificationService::class)->notify(
            $user,
            InAppNotificationService::TYPE_ACCOUNT,
            'Account tip',
            'Complete your profile.'
        );

        $this->actingAs($user)
            ->deleteJson(route('notifications.destroy', $notification->id))
            ->assertOk();

        $this->assertSoftDeleted('in_app_notifications', ['id' => $notification->id]);
    }
}
