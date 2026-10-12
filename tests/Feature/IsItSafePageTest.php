<?php

namespace Tests\Feature;

use App\Support\EnglishOnlyMarketingSlugs;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsItSafePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_faq_schema_and_topurlz_only(): void
    {
        $html = $this->get('/is-seolinkbuildings-safe')
            ->assertOk()
            ->assertSee('Is SEOLinkBuildings safe?', false)
            ->assertSee('FAQPage', false)
            ->assertSee('Topurlz Ltd', false)
            ->assertSee('16607074', false)
            ->assertSee('Verified publishers', false)
            ->assertSee('support@seolinkbuildings.com', false)
            ->assertSee('/how-it-works', false)
            ->assertSee('/refund-policy', false)
            ->assertSee('/about', false)
            ->assertDontSee('Teqno', false)
            ->assertDontSee('messages.', false)
            ->getContent();

        $this->assertStringContainsString('rel="canonical" href="'.url('/is-seolinkbuildings-safe').'"', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('Is SEOLinkBuildings a legitimate company?', $html);
    }

    public function test_footer_links_the_safe_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/is-seolinkbuildings-safe', false)
            ->assertSee('Is SEOLinkBuildings safe?', false);
    }

    public function test_prefixed_locales_redirect_to_the_english_url(): void
    {
        $this->get('/de/is-seolinkbuildings-safe')
            ->assertRedirect('/is-seolinkbuildings-safe');
        $this->assertSame(301, $this->get('/de/is-seolinkbuildings-safe')->status());
        $this->assertSame(301, $this->get('/us/is-seolinkbuildings-safe')->status());
    }

    public function test_english_sitemap_lists_the_page_without_locale_copies(): void
    {
        $en = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/is-seolinkbuildings-safe', $en);
        $this->assertStringNotContainsString('/de/is-seolinkbuildings-safe', $en);
        $this->assertStringNotContainsString('/is-seolinkbuildings-safe', $de);
    }

    public function test_robots_allows_the_safe_page(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /is-seolinkbuildings-safe', false);
    }

    public function test_slug_is_english_only(): void
    {
        $this->assertContains('is-seolinkbuildings-safe', PublicI18n::englishOnlyMarketingSlugs());
        $this->assertContains('is-seolinkbuildings-safe', EnglishOnlyMarketingSlugs::all());
    }
}
