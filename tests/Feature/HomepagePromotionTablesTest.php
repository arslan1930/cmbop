<?php

namespace Tests\Feature;

use App\Models\SiteAnnouncement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HomepagePromotionTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_ok_when_promotion_tables_missing(): void
    {
        Schema::dropIfExists('site_announcements');
        Schema::dropIfExists('ad_banners');

        $this->assertFalse(Schema::hasTable('site_announcements'));
        $this->assertFalse(Schema::hasTable('ad_banners'));

        $this->get('/')
            ->assertOk()
            ->assertSee('SEOLinkBuildings', false);
    }

    public function test_homepage_ok_with_promotion_tables_present(): void
    {
        $this->get('/')
            ->assertOk();
    }

    public function test_homepage_shows_live_announcement_and_hides_expired(): void
    {
        SiteAnnouncement::create([
            'title' => 'Live homepage notice',
            'message' => 'Visible now',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);
        SiteAnnouncement::create([
            'title' => 'Expired homepage notice',
            'message' => 'Gone',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Live homepage notice', false)
            ->assertDontSee('Expired homepage notice', false);
    }

    public function test_homepage_caps_live_announcements_at_two(): void
    {
        foreach (['First cap notice', 'Second cap notice', 'Third cap notice'] as $i => $title) {
            SiteAnnouncement::create([
                'title' => $title,
                'message' => 'Body',
                'type' => 'general',
                'style' => 'info',
                'audience' => 'all',
                'is_active' => true,
                'priority' => ($i + 1) * 10,
            ]);
        }

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('First cap notice', $html);
        $this->assertStringContainsString('Second cap notice', $html);
        $this->assertStringNotContainsString('Third cap notice', $html);
    }
}
