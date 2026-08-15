<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPromotionsFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_expired_lists_only_expired_rows(): void
    {
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        SiteAnnouncement::create([
            'title' => 'Expired row',
            'message' => 'x',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);
        SiteAnnouncement::create([
            'title' => 'Live row',
            'message' => 'y',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.promotions.announcements.index', ['status' => 'expired']))
            ->assertOk()
            ->assertSee('Expired row', false)
            ->assertDontSee('Live row', false);
    }

    public function test_status_live_excludes_unparseable_schedule_rows(): void
    {
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        SiteAnnouncement::create([
            'title' => 'Really live',
            'message' => 'y',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);
        $broken = SiteAnnouncement::create([
            'title' => 'Broken schedule',
            'message' => 'x',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->addDay(),
        ]);
        DB::table('site_announcements')->where('id', $broken->id)->update([
            'ends_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.promotions.announcements.index', ['status' => 'live']))
            ->assertOk()
            ->assertSee('Really live', false)
            ->assertDontSee('Broken schedule', false);
    }
}
