<?php

namespace Tests\Unit;

use App\Models\AdBanner;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionServiceRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_banner_per_placement_and_stable_for_the_day(): void
    {
        config(['promotions.banners_per_placement' => 1]);

        AdBanner::create([
            'name' => 'A',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'image_url' => 'https://example.com/a.png',
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
            'priority' => 10,
        ]);
        AdBanner::create([
            'name' => 'B',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'image_url' => 'https://example.com/b.png',
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
            'priority' => 20,
        ]);

        $service = app(PromotionService::class);
        $first = $service->activeBanners('header', 'public');
        $second = $service->activeBanners('header', 'public');

        $this->assertCount(1, $first);
        $this->assertTrue($first->pluck('id')->all() === $second->pluck('id')->all());
    }

    public function test_banner_without_safe_image_does_not_take_the_slot(): void
    {
        config(['promotions.banners_per_placement' => 1]);

        AdBanner::create([
            'name' => 'Broken creative',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'image_path' => '../etc/passwd',
            'image_url' => 'javascript:alert(1)',
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
            'priority' => 1,
        ]);
        $good = AdBanner::create([
            'name' => 'Good creative',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'image_url' => 'https://example.com/good.png',
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
            'priority' => 50,
        ]);

        $chosen = app(PromotionService::class)->activeBanners('header', 'public');

        $this->assertCount(1, $chosen);
        $this->assertSame($good->id, $chosen->first()->id);
        $this->assertSame('https://example.com/good.png', $chosen->first()->imageSrc());
    }

    public function test_staff_now_showing_reuses_active_winners_and_wired_slots(): void
    {
        config(['promotions.banners_per_placement' => 1]);

        $winner = AdBanner::create([
            'name' => 'Header winner',
            'size_key' => 'leaderboard',
            'width' => 728,
            'height' => 90,
            'image_url' => 'https://example.com/header.png',
            'placement' => 'header',
            'audience' => 'all',
            'is_active' => true,
            'priority' => 10,
        ]);

        $now = app(PromotionService::class)->staffNowShowing();
        $this->assertSame($winner->id, $now['banners']['public']['header']->id);
        $this->assertArrayNotHasKey('header', $now['banners']['advertiser']);
        $this->assertContains($winner->id, PromotionService::showingBannerIds($now));
    }
}
