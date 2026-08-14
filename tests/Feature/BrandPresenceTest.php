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

        $this->assertStringContainsString('slb-hero-title', $html);
        $this->assertStringContainsString('Earn powerful backlinks from trusted websites.', $html);
        $this->assertStringContainsString('assets/img/logo1.png', $html);
        $this->assertStringContainsString('slb-hero-mark', $html);
        $this->assertStringContainsString('favicon.svg', $html);
        $this->assertStringContainsString('alt="SEOLinkBuildings"', $html);
        $this->assertStringContainsString('navbar-logo', $html);
        $this->assertStringContainsString('height: 64px', $html);
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

    public function test_contact_info_links_wrap_on_narrow_viewports(): void
    {
        $html = $this->get('/contact')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('contact-info-card', $html);
        $this->assertStringContainsString('contact-info-link', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
        $this->assertStringContainsString('linkedin.com/company/seolinkbuildings', $html);
    }

    public function test_footer_includes_official_social_icons(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('slb-social-icons', $html);
        $this->assertStringContainsString('slb-social-icons__link text-dark text-decoration-none', $html);
        $css = (string) file_get_contents(public_path('assets/css/marketing-saas.css'));
        $this->assertStringContainsString('.slb-social-icons__link:hover', $css);
        $this->assertStringContainsString('text-decoration: none', $css);
        $hoverCss = (string) file_get_contents(public_path('assets/css/hover-system.css'));
        $this->assertStringContainsString(':not(.slb-social-icons__link):hover', $hoverCss);
        $this->assertStringContainsString('https://www.linkedin.com/company/seolinkbuildings', $html);
        $this->assertStringContainsString('https://www.facebook.com/seolinkbuildings/', $html);
        $this->assertStringContainsString('https://www.instagram.com/seolinkbuildings', $html);
        $this->assertStringContainsString('https://x.com/seolinbuildings', $html);
        $this->assertStringContainsString('https://www.youtube.com/@seolinkbuildingss', $html);
        $this->assertStringContainsString('fab fa-facebook', $html);
        $this->assertStringContainsString('fab fa-instagram', $html);
        $this->assertStringContainsString('fab fa-x-twitter', $html);
        $this->assertStringContainsString('fab fa-youtube', $html);
        $this->assertStringNotContainsString('igsh=', $html);
        $this->assertStringContainsString('aria-label="SEOLinkBuildings on Facebook"', $html);
        $this->assertStringContainsString('aria-label="SEOLinkBuildings on Instagram"', $html);
        $this->assertStringContainsString('aria-label="SEOLinkBuildings on X"', $html);
        $this->assertStringContainsString('aria-label="SEOLinkBuildings on YouTube"', $html);
    }
}
