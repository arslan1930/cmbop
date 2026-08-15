<?php

namespace Tests\Feature;

use App\Models\AdBanner;
use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPromotionsCrudTest extends TestCase
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

    public function test_create_update_toggle_soft_delete_restore_duplicate_announcement(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.promotions.announcements.store'), [
                'title' => 'Spring notice',
                'message' => 'Hello advertisers',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'cta_url' => '/advertiser/catalog',
                'cta_label' => 'Browse',
                'is_active' => 1,
                'priority' => 10,
            ])
            ->assertRedirect(route('admin.promotions.announcements.index'));

        $announcement = SiteAnnouncement::query()->firstOrFail();
        $this->assertSame('/advertiser/catalog', $announcement->cta_url);
        $this->assertSame(1, (int) $announcement->version);

        $this->actingAs($this->admin)
            ->put(route('admin.promotions.announcements.update', $announcement), [
                'title' => 'Spring notice v2',
                'message' => 'Updated body',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'advertiser',
                'is_active' => 1,
                'priority' => 10,
            ])
            ->assertRedirect(route('admin.promotions.announcements.index'));

        $announcement->refresh();
        $this->assertSame('Spring notice v2', $announcement->title);
        $this->assertSame(2, (int) $announcement->version);

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.announcements.toggle', $announcement))
            ->assertRedirect();
        $this->assertFalse($announcement->fresh()->is_active);

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.announcements.duplicate', $announcement))
            ->assertRedirect();
        $copy = SiteAnnouncement::query()->where('id', '!=', $announcement->id)->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_active);
        $this->assertStringContainsString('(copy)', $copy->title);

        $this->actingAs($this->admin)
            ->delete(route('admin.promotions.announcements.destroy', $announcement))
            ->assertRedirect(route('admin.promotions.announcements.index'));
        $this->assertSoftDeleted($announcement);

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.announcements.restore', $announcement->id))
            ->assertRedirect();
        $this->assertNotSoftDeleted($announcement->fresh());
    }

    public function test_create_banner_with_image_url_and_relative_link(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.promotions.banners.store'), [
                'name' => 'Header offer',
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
        $this->assertSame('/advertiser/catalog', $banner->link_url);
        $this->assertSame(728, (int) $banner->width);
    }

    public function test_userinfo_cta_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.announcements.create'))
            ->post(route('admin.promotions.announcements.store'), [
                'title' => 'Bad',
                'message' => 'Nope',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'cta_url' => 'https://google.com@evil.example/path',
            ])
            ->assertRedirect(route('admin.promotions.announcements.create'))
            ->assertSessionHasErrors('cta_url');
    }

    public function test_encoded_dotdot_cta_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.announcements.create'))
            ->post(route('admin.promotions.announcements.store'), [
                'title' => 'Bad',
                'message' => 'Nope',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'cta_url' => '/%2e%2e/admin',
            ])
            ->assertRedirect(route('admin.promotions.announcements.create'))
            ->assertSessionHasErrors('cta_url');
    }

    public function test_javascript_cta_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.announcements.create'))
            ->post(route('admin.promotions.announcements.store'), [
                'title' => 'Bad',
                'message' => 'Nope',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'cta_url' => 'javascript:alert(1)',
            ])
            ->assertRedirect(route('admin.promotions.announcements.create'))
            ->assertSessionHasErrors('cta_url');
    }

    public function test_unchecked_active_stays_unchecked_after_validation_error(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.promotions.announcements.create'))
            ->post(route('admin.promotions.announcements.store'), [
                'title' => '',
                'message' => 'Body',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
            ])
            ->assertRedirect(route('admin.promotions.announcements.create'));

        $html = $this->get(route('admin.promotions.announcements.create'))
            ->assertOk()
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="is_active"[^>]*checked/', $html);
    }
}
