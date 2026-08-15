<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminPromotionsSchemaDriftResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    public function test_admin_promotions_hub_ok_when_promotion_tables_missing(): void
    {
        Schema::dropIfExists('site_announcements');
        Schema::dropIfExists('ad_banners');

        $this->assertFalse(Schema::hasTable('site_announcements'));
        $this->assertFalse(Schema::hasTable('ad_banners'));

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('Promotions storage is incomplete', false)
            ->assertDontSee('Something went wrong');
    }

    public function test_admin_announcements_and_banners_indexes_ok_when_tables_missing(): void
    {
        Schema::dropIfExists('site_announcements');
        Schema::dropIfExists('ad_banners');

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.announcements.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.banners.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    public function test_admin_promotions_hub_ok_with_tables_present(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk();
    }

    public function test_admin_promotions_hub_ok_when_welcome_bonus_settings_table_missing(): void
    {
        Schema::dropIfExists('welcome_bonus_settings');
        $this->assertFalse(Schema::hasTable('welcome_bonus_settings'));

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('€20 welcome credit', false)
            ->assertSee('Unknown', false)
            ->assertDontSee('Something went wrong');
    }
}
