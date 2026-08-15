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
}
