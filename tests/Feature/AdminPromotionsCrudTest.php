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

    public function test_banner_update_and_duplicate_tolerate_tainted_image_path(): void
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
        $banner->forceFill(['image_path' => '../victim.txt'])->save();

        $this->actingAs($this->admin)
            ->put(route('admin.promotions.banners.update', $banner), [
                'name' => 'Header offer v2',
                'size_key' => 'leaderboard',
                'placement' => 'header',
                'audience' => 'all',
                'image_url' => 'https://example.com/banner.png',
                'link_url' => '/advertiser/catalog',
                'is_active' => 1,
                'priority' => 10,
            ])
            ->assertRedirect(route('admin.promotions.banners.index'))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->post(route('admin.promotions.banners.duplicate', $banner))
            ->assertRedirect();

        $copy = AdBanner::query()->where('id', '!=', $banner->id)->first();
        $this->assertNotNull($copy);
        $this->assertNull($copy->image_path);
        $this->assertSame('https://example.com/banner.png', $copy->image_url);
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

    public function test_everyone_announcement_email_handoff_targets_both_roles(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Everyone sale',
            'message' => 'Save 20% this week.',
            'type' => 'general',
            'style' => 'promo',
            'audience' => 'all',
            'cta_label' => 'Shop',
            'cta_url' => '/advertiser/catalog',
            'is_active' => true,
            'priority' => 10,
            'created_by' => $this->admin->id,
        ]);

        $indexHtml = $this->actingAs($this->admin)
            ->get(route('admin.promotions.announcements.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('audience=both', html_entity_decode($indexHtml));
        $this->assertStringContainsString('body_html=', $indexHtml);
        $this->assertStringNotContainsString('audience=advertisers', html_entity_decode($indexHtml));

        $editHtml = $this->actingAs($this->admin)
            ->get(route('admin.promotions.announcements.edit', $announcement))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('audience=both', html_entity_decode($editHtml));

        $campaignHtml = $this->actingAs($this->admin)
            ->get(route('admin.campaigns.index', [
                'audience' => 'both',
                'subject' => 'Everyone sale',
                'body_html' => '<p>Save 20% this week.</p>',
            ]))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/id="campaignAudience"[^>]*value="both"/', $campaignHtml);
        $this->assertMatchesRegularExpression('/single-select-option selected[^>]*data-value="both"/', $campaignHtml);
        $this->assertStringContainsString('Save 20% this week.', $campaignHtml);
    }

    public function test_public_announcement_has_no_email_handoff(): void
    {
        SiteAnnouncement::create([
            'title' => 'Homepage only',
            'message' => 'Public visitors only.',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'public',
            'is_active' => true,
            'priority' => 10,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.promotions.announcements.index'))
            ->assertOk()
            ->assertSee('Homepage only', false)
            ->assertDontSee('>Email</a>', false);
    }
}
