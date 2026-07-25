<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageCatalogPreviewTest extends TestCase
{
    public function test_homepage_hero_uses_static_catalog_image_only(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertSee('dashboard.png', false)
            ->assertDontSee('advertiser/catalog', false)
            ->getContent();

        $this->assertStringContainsString('dashboard.webp', $html);
        $this->assertStringContainsString('slb-hero-product', $html);
        $this->assertStringNotContainsString('Marketplace catalog', $html);
        $this->assertStringNotContainsString('Live publisher catalog', $html);
        $this->assertStringNotContainsString('catalogPreview', $html);
        $this->assertStringNotContainsString('slb-hero-catalog__table', $html);
        $this->assertStringNotContainsString('All markets', $html);
    }

    public function test_dashboard_catalog_art_assets_exist(): void
    {
        $this->assertFileExists(public_path('assets/img/dashboard.png'));
        $this->assertFileExists(public_path('assets/img/dashboard.webp'));
        $this->assertGreaterThan(10_000, filesize(public_path('assets/img/dashboard.png')));
        $this->assertGreaterThan(5_000, filesize(public_path('assets/img/dashboard.webp')));
    }
}
