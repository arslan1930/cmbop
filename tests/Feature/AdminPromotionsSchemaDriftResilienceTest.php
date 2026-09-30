<?php

namespace Tests\Feature;

use App\Models\AdBanner;
use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use App\Models\WelcomeBonusClaim;
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
            ->assertOk()
            ->assertSee('Enabled', false)
            ->assertDontSee('>Unknown<', false)
            ->assertDontSee('Promotions storage is incomplete', false);
    }

    public function test_admin_promotions_hub_ok_when_welcome_bonus_settings_table_missing(): void
    {
        Schema::dropIfExists('welcome_bonus_settings');
        $this->assertFalse(Schema::hasTable('welcome_bonus_settings'));

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('€20 welcome credit', false)
            ->assertSee('Disabled', false)
            ->assertDontSee('>Enabled</span>', false)
            ->assertDontSee('>Unknown<', false)
            ->assertSee('Enable', false)
            ->assertSee('Set amount', false)
            ->assertDontSee('Something went wrong');

        $this->assertTrue(Schema::hasTable('welcome_bonus_settings'));
    }

    public function test_admin_promotions_hub_creates_claims_table_when_missing(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');
        $this->assertFalse(Schema::hasTable('welcome_bonus_claims'));

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('Enabled', false)
            ->assertDontSee('>Unknown<', false)
            ->assertSee('Disable', false)
            ->assertSee('0 claims this week', false)
            ->assertDontSee('Something went wrong');

        $this->assertTrue(Schema::hasTable('welcome_bonus_claims'));
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

    public function test_destroy_is_refused_when_deleted_at_is_missing(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Keep me',
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
            ->delete(route('admin.promotions.announcements.destroy', $announcement))
            ->assertRedirect(route('admin.promotions.announcements.index'))
            ->assertSessionHas('error')
            ->assertSessionMissing('promotions_undo');

        $this->assertDatabaseHas('site_announcements', [
            'id' => $announcement->id,
            'title' => 'Keep me',
        ]);
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

        $this->assertFalse($announcement->fresh()->isCurrentlyLive());
        $this->assertSame('paused', $announcement->fresh()->scheduleState());
    }

    public function test_admin_can_reschedule_and_duplicate_when_ends_at_is_unparseable(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Leftover schedule',
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
            ->put(route('admin.promotions.announcements.update', $announcement), [
                'title' => 'Leftover schedule fixed',
                'message' => 'Body',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'is_active' => 1,
                'priority' => 10,
                'starts_at' => now()->subHour()->format('Y-m-d\TH:i'),
                'ends_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('admin.promotions.announcements.index'))
            ->assertSessionHas('success');

        $fresh = $announcement->fresh();
        $this->assertSame('Leftover schedule fixed', $fresh->title);
        $this->assertNotNull($fresh->safeEndsAt());
        $this->assertTrue($fresh->isCurrentlyLive());

        DB::table('site_announcements')->where('id', $announcement->id)->update([
            'ends_at' => 'not-a-date',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.announcements.duplicate', $announcement))
            ->assertRedirect();

        $copy = SiteAnnouncement::query()->where('id', '!=', $announcement->id)->first();
        $this->assertNotNull($copy);
        $this->assertStringContainsString('(copy)', $copy->title);
        $this->assertFalse($copy->is_active);
        $this->assertNull($copy->safeEndsAt());
    }

    public function test_restore_heals_unparseable_deleted_at(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Leftover trash',
            'message' => 'Body',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);
        DB::table('site_announcements')->where('id', $announcement->id)->update([
            'deleted_at' => 'not-a-date',
        ]);

        $this->assertFalse($announcement->fresh()->trashed());

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.announcements.restore', $announcement->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $raw = DB::table('site_announcements')->where('id', $announcement->id)->value('deleted_at');
        $this->assertNull($raw);
        $this->assertTrue(SiteAnnouncement::query()->whereKey($announcement->id)->exists());
    }

    public function test_hub_ok_when_latest_welcome_bonus_claim_date_is_unparseable(): void
    {
        $claim = WelcomeBonusClaim::query()->create([
            'user_id' => $this->admin->id,
            'ip_address' => '203.0.113.10',
            'source' => 'registration',
            'amount' => 20,
        ]);

        DB::table('welcome_bonus_claims')->where('id', $claim->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('welcome credit', false)
            ->assertSee(scalar_text($this->admin->email), false)
            ->assertSee('0 claims this week', false)
            ->assertDontSee('Something went wrong');
    }

    public function test_banner_duplicate_ok_when_counter_columns_are_missing(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'Counter drift',
                'size_key' => 'leaderboard',
                'placement' => 'header',
                'audience' => 'all',
                'image_url' => 'https://example.com/banner.png',
                'link_url' => '/advertiser/catalog',
                'is_active' => 1,
                'priority' => 10,
            ])
            ->assertRedirect(route('admin.promotions.banners.index'));

        $banner = AdBanner::query()->firstOrFail();

        Schema::table('ad_banners', function ($table) {
            if (Schema::hasColumn('ad_banners', 'impressions')) {
                $table->dropColumn('impressions');
            }
            if (Schema::hasColumn('ad_banners', 'clicks')) {
                $table->dropColumn('clicks');
            }
        });

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.banners.duplicate', $banner))
            ->assertRedirect()
            ->assertSessionHas('success');

        $copy = AdBanner::query()->where('id', '!=', $banner->id)->first();
        $this->assertNotNull($copy);
        $this->assertStringContainsString('(copy)', $copy->name);
    }
}
