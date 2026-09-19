<?php

namespace Tests\Feature;

use App\Support\BrandOrganization;
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

    public function test_homepage_brand_misspelling_uses_schema_not_the_title(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Guest Post Marketplace for SEO Backlinks', $html);
        $this->assertStringContainsString('"SEO Link Buildings"', $html);
        $this->assertStringContainsString('"Seolink Buildings"', $html);
        $this->assertStringContainsString('"Topurlz Ltd"', $html);
        $this->assertStringContainsString('20 Wenlock Road', $html);
        $this->assertStringContainsString('N1 7GU', $html);
        $this->assertStringContainsString('16607074', $html);
        $this->assertStringContainsString('support@seolinkbuildings.com', $html);
        $this->assertStringContainsString('find-and-update.company-information.service.gov.uk/company/16607074', $html);
        $this->assertStringNotContainsString('<title>SEO Link Buildings', $html);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.</title>', $html);
    }

    public function test_homepage_brand_serp_links_site_linkedin_trustpilot_and_about(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Guest Post Marketplace for SEO Backlinks', $html);
        $this->assertStringContainsString('https://www.linkedin.com/company/seolinkbuildings', $html);
        $this->assertStringContainsString(config('services.trustpilot.review_url'), $html);
        $this->assertStringContainsString('/about', $html);
        $this->assertStringContainsString('"@type":"AboutPage"', $html);
        $this->assertStringContainsString('trustpilot.com/review/seolinkbuildings.com', $html);
        $this->assertStringNotContainsString('<title>seolinkbuildings', $html);
        $this->assertStringNotContainsString('<title>SEOLinkBuildings</title>', $html);

        $sameAs = BrandOrganization::sameAs();
        $this->assertContains('https://www.linkedin.com/company/seolinkbuildings', $sameAs);
        $this->assertContains(config('services.trustpilot.review_url'), $sameAs);
        $this->assertContains('https://find-and-update.company-information.service.gov.uk/company/16607074', $sameAs);
    }

    public function test_homepage_and_marketplace_target_different_money_queries(): void
    {
        $home = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Guest Post Marketplace for SEO Backlinks', $home);
        $this->assertStringContainsString('Earn powerful backlinks from trusted websites.', $home);
        $this->assertStringNotContainsString('Buy guest posts from verified publishers', $home);

        $market = $this->get('/marketplace')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Browse Publisher Sites and Buy Guest Posts', $market);
        $this->assertStringContainsString('Buy guest posts from verified publishers', $market);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $market);
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
        $this->assertStringContainsString(config('social.profiles.linkedin.url'), $html);
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
