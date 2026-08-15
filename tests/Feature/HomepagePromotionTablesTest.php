<?php

namespace Tests\Feature;

use App\Models\SiteAnnouncement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_homepage_does_not_echo_tainted_style_or_type(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Safe title',
            'message' => 'Safe body',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'is_active' => true,
        ]);
        $announcement->forceFill([
            'type' => 'general"><img src=x onerror=alert(1)>',
            'style' => 'info onmouseover=alert(1)',
        ])->save();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('onerror=alert(1)', $html);
        $this->assertStringNotContainsString('onmouseover=alert(1)', $html);
        $this->assertStringContainsString('site-announcement--info', $html);
        $this->assertStringContainsString('site-announcement-type--general', $html);
    }

    public function test_homepage_and_click_ok_when_deleted_at_column_missing(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Notice before soft-deletes',
            'message' => 'Still visible',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
            'cta_label' => 'Go',
            'cta_url' => '/advertiser/catalog',
            'is_active' => true,
        ]);

        Schema::table('site_announcements', function ($table) {
            $table->dropSoftDeletes();
        });
        $this->assertFalse(Schema::hasColumn('site_announcements', 'deleted_at'));

        $this->get('/')
            ->assertOk()
            ->assertSee('Notice before soft-deletes', false);

        $this->get(route('announcements.click', $announcement))
            ->assertRedirect();
        $this->assertStringContainsString(
            '/advertiser/catalog',
            (string) $this->get(route('announcements.click', $announcement))->headers->get('Location')
        );
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

    public function test_announcement_click_is_404_when_table_is_missing(): void
    {
        Schema::dropIfExists('site_announcements');

        $this->get('/announcements/1/click')->assertNotFound();
    }

    public function test_only_trashed_is_empty_when_deleted_at_is_missing(): void
    {
        SiteAnnouncement::create([
            'title' => 'Still listed',
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

        $this->assertSame(0, SiteAnnouncement::onlyTrashed()->count());
        $this->assertSame(1, SiteAnnouncement::query()->count());
        $this->assertFalse(SiteAnnouncement::query()->first()->restore());
    }

    public function test_homepage_and_click_ok_when_ends_at_is_unparseable(): void
    {
        $announcement = SiteAnnouncement::create([
            'title' => 'Garbage date notice',
            'message' => 'Body',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'all',
            'cta_label' => 'Go',
            'cta_url' => '/advertiser/catalog',
            'is_active' => true,
            'ends_at' => now()->addDay(),
        ]);

        DB::table('site_announcements')->where('id', $announcement->id)->update([
            'ends_at' => 'not-a-date',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Garbage date notice', false)
            ->assertDontSee('Something went wrong');

        $location = (string) $this->get(route('announcements.click', $announcement->id))
            ->assertRedirect()
            ->headers->get('Location');
        $this->assertStringContainsString('/advertiser/catalog', $location);
    }

    public function test_limited_offer_shows_ends_label(): void
    {
        SiteAnnouncement::create([
            'title' => 'Sale notice',
            'message' => 'Body',
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'all',
            'is_active' => true,
            'ends_at' => now()->addDays(3),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Sale notice', false)
            ->assertSee('Ends', false);
    }
}
