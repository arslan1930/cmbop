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

    public function test_forwarded_for_cannot_mint_a_second_daily_impression(): void
    {
        $banner = $this->liveBanner();

        $this->postJson(route('promotions.track'), [
            'subject_type' => 'banner',
            'subject_id' => $banner->id,
            'event' => 'impression',
        ])->assertOk();

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.88'])
            ->postJson(route('promotions.track'), [
                'subject_type' => 'banner',
                'subject_id' => $banner->id,
                'event' => 'impression',
            ])
            ->assertOk();

        $this->assertSame(1, PromotionEvent::query()->count());
        $this->assertSame(1, (int) $banner->fresh()->impressions);
    }

    public function test_track_endpoint_rejects_click_events(): void
    {
        $banner = $this->liveBanner();

        $this->postJson(route('promotions.track'), [
            'subject_type' => 'banner',
            'subject_id' => $banner->id,
            'event' => 'click',
        ])->assertUnprocessable();

        $this->assertSame(0, PromotionEvent::query()->count());
        $this->assertSame(0, (int) $banner->fresh()->clicks);
    }

    public function test_fetch_mode_does_not_count_or_follow_click(): void
    {
        $banner = $this->liveBanner();

        $this->withHeaders([
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
        ])->get(route('banners.click', $banner))
            ->assertNoContent();

        $this->assertSame(0, (int) $banner->fresh()->clicks);
        $this->assertSame(0, PromotionEvent::query()->count());
    }

    public function test_scripted_get_without_html_accept_does_not_count(): void
    {
        $banner = $this->liveBanner();

        $this->withHeaders(['Accept' => '*/*'])
            ->get(route('banners.click', $banner))
            ->assertNoContent();

        $this->assertSame(0, (int) $banner->fresh()->clicks);
    }

    public function test_forwarded_host_cannot_rewrite_home_fallback(): void
    {
        $banner = $this->liveBanner();
        $banner->update(['is_active' => false]);

        $location = (string) $this->withHeaders(['X-Forwarded-Host' => 'evil.example'])
            ->get(route('banners.click', $banner))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertSame('/', $location);
        $this->assertStringNotContainsString('evil.example', $location);
    }

    public function test_forwarded_host_cannot_rewrite_relative_click_target(): void
    {
        $banner = $this->liveBanner();
        $banner->update(['link_url' => '/advertiser/catalog']);

        $location = (string) $this->withHeaders(['X-Forwarded-Host' => 'evil.example'])
            ->get(route('banners.click', $banner))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringContainsString('/advertiser/catalog', $location);
        $this->assertStringNotContainsString('evil.example', $location);
    }

    public function test_image_beacon_does_not_count_or_follow_click(): void
    {
        $banner = $this->liveBanner();

        $this->withHeaders([
            'Sec-Fetch-Dest' => 'image',
            'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ])->get(route('banners.click', $banner))
            ->assertNoContent();

        $this->assertSame(0, (int) $banner->fresh()->clicks);
        $this->assertSame(0, PromotionEvent::query()->count());
    }

    public function test_stored_userinfo_url_does_not_redirect_away(): void
    {
        $banner = $this->liveBanner();
        $banner->update(['link_url' => 'https://google.com@evil.example/phish']);

        $this->get(route('banners.click', $banner))->assertRedirect('/');
        $this->assertSame(0, (int) $banner->fresh()->clicks);
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
