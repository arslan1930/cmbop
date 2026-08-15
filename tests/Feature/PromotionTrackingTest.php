<?php

namespace Tests\Feature;

use App\Models\AdBanner;
use App\Models\PromotionEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function liveBanner(): AdBanner
    {
        return AdBanner::create([
            'name' => 'Track me',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'image_url' => 'https://example.com/b.png',
            'link_url' => 'https://example.com/offer',
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
        ]);
    }

    public function test_homepage_does_not_write_impressions_inline(): void
    {
        $this->liveBanner();

        $this->get('/')->assertOk();
        $this->assertSame(0, PromotionEvent::query()->count());
        $this->assertSame(0, (int) AdBanner::query()->value('impressions'));
    }

    public function test_track_endpoint_records_one_impression_per_visitor_per_day(): void
    {
        $banner = $this->liveBanner();

        $this->postJson(route('promotions.track'), [
            'subject_type' => 'banner',
            'subject_id' => $banner->id,
            'event' => 'impression',
        ])->assertOk();

        $this->postJson(route('promotions.track'), [
            'subject_type' => 'banner',
            'subject_id' => $banner->id,
            'event' => 'impression',
        ])->assertOk();

        $this->assertSame(1, PromotionEvent::query()->count());
        $this->assertSame(1, (int) $banner->fresh()->impressions);
    }

    public function test_bot_user_agent_is_ignored(): void
    {
        $banner = $this->liveBanner();

        $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])
            ->postJson(route('promotions.track'), [
                'subject_type' => 'banner',
                'subject_id' => $banner->id,
                'event' => 'impression',
            ])
            ->assertOk();

        $this->assertSame(0, PromotionEvent::query()->count());
    }

    public function test_paused_banner_click_does_not_redirect_away(): void
    {
        $banner = $this->liveBanner();
        $banner->update(['is_active' => false]);

        $this->get(route('banners.click', $banner))
            ->assertRedirect('/');
        $this->assertSame(0, (int) $banner->fresh()->clicks);
    }

    public function test_live_banner_click_increments_and_redirects(): void
    {
        $banner = $this->liveBanner();

        $this->get(route('banners.click', $banner))
            ->assertRedirect('https://example.com/offer');
        $this->assertSame(1, (int) $banner->fresh()->clicks);
    }

    public function test_preview_page_is_staff_only(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        $this->get(route('admin.promotions.preview'))->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.promotions.preview', ['audience' => 'public']))
            ->assertOk()
            ->assertSee('Preview only', false);
    }
}
