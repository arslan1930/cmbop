<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPromotionsStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->admin->roles()->attach($role->id);
    }

    public function test_hub_and_index_show_expired_not_scheduled(): void
    {
        SiteAnnouncement::create([
            'title' => 'Ended sale copy',
            'message' => 'Was live last week',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'all',
            'is_active' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        SiteAnnouncement::create([
            'title' => 'Future notice',
            'message' => 'Later',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'starts_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('Ended sale copy', false)
            ->assertSee('Expired', false)
            ->assertSee('Scheduled', false);

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.announcements.index'))
            ->assertOk()
            ->assertSee('Expired', false);

        $this->assertSame('expired', SiteAnnouncement::query()->where('title', 'Ended sale copy')->first()->scheduleState());
        $this->assertSame('scheduled', SiteAnnouncement::query()->where('title', 'Future notice')->first()->scheduleState());
    }
}
