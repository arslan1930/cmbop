<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionsDismissVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_prints_version_and_update_increments_it(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Notice',
            'message' => 'Hello',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'version' => 1,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-announcement-version="1"', false);

        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        $this->actingAs($admin)
            ->put(route('admin.promotions.announcements.update', $announcement), [
                'title' => 'Notice changed',
                'message' => 'Hello',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'is_active' => 1,
            ]);

        $this->assertSame(2, (int) $announcement->fresh()->version);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-announcement-version="2"', false);
    }

    public function test_style_only_edit_bumps_dismiss_version(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Notice',
            'message' => 'Hello',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'is_dismissible' => true,
            'version' => 1,
        ]);

        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        $this->actingAs($admin)
            ->put(route('admin.promotions.announcements.update', $announcement), [
                'title' => 'Notice',
                'message' => 'Hello',
                'type' => 'general',
                'style' => 'warning',
                'audience' => 'all',
                'is_active' => 1,
                'is_dismissible' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(2, (int) $announcement->fresh()->version);
    }
}
