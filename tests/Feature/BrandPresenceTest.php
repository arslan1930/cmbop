<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_hero_uses_seolinkbuildings_as_main_heading(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*class="[^"]*slb-hero-title[^"]*"[^>]*>\s*SEOLinkBuildings\s*<\/h1>/',
            $html
        );
        $this->assertStringContainsString('Earn powerful backlinks from trusted websites.', $html);
        $this->assertStringContainsString('assets/img/logo1.png', $html);
        $this->assertStringContainsString('favicon.svg', $html);
        $this->assertStringContainsString('alt="SEOLinkBuildings"', $html);
    }

    public function test_marketing_subpage_hero_includes_brand_line(): void
    {
        $html = $this->get('/marketplace')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('marketing-brand-link', $html);
        $this->assertStringContainsString('>SEOLinkBuildings</a>', $html);
        $this->assertStringContainsString('favicon.svg', $html);
        $this->assertStringContainsString('assets/img/logo1.png', $html);
    }

    public function test_contact_and_blog_heroes_include_brand(): void
    {
        $this->get('/contact')
            ->assertOk()
            ->assertSee('marketing-brand-link', false)
            ->assertSee('SEOLinkBuildings', false);

        $this->get('/blog')
            ->assertOk()
            ->assertSee('marketing-brand-link', false)
            ->assertSee('SEOLinkBuildings', false);
    }
}
