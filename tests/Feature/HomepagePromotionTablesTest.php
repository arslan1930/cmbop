<?php

namespace Tests\Feature;

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
}
