<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_restore_is_not_500_when_announcement_table_is_missing(): void
    {
        Schema::dropIfExists('site_announcements');
        $this->assertFalse(Schema::hasTable('site_announcements'));

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.index'))
            ->post(route('admin.promotions.announcements.restore', 1))
            ->assertRedirect(route('admin.promotions.index'))
            ->assertSessionHas('error');
    }

    public function test_restore_reports_error_when_deleted_at_is_missing(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Undo me',
            'message' => 'Body',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);

        Schema::table('site_announcements', function ($table) {
            $table->dropSoftDeletes();
        });
        $this->assertFalse(Schema::hasColumn('site_announcements', 'deleted_at'));

        $this->actingAs($this->admin)
            ->from(route('admin.promotions.announcements.index'))
            ->post(route('admin.promotions.announcements.restore', $announcement->id))
            ->assertRedirect(route('admin.promotions.announcements.index'))
            ->assertSessionHas('error');
    }

    public function test_admin_list_and_edit_ok_when_ends_at_is_unparseable(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Bad schedule row',
            'message' => 'Body',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->addDay(),
        ]);

        DB::table('site_announcements')->where('id', $announcement->id)->update([
            'ends_at' => 'not-a-date',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.announcements.index'))
            ->assertOk()
            ->assertSee('Bad schedule row', false)
            ->assertDontSee('Something went wrong');

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.announcements.edit', $announcement->id))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }
}
