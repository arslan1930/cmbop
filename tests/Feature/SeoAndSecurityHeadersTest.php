<?php

namespace Tests\Feature;

use App\Http\Middleware\CanonicalHost;
use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Support\BrandOrganization;
use App\Support\RobotsTxt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoAndSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_include_seo_meta_and_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('name="robots" content="index, follow', false);
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('og:title', false);
        $response->assertSee('og:image:width', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('Guest Post Marketplace for SEO Backlinks', false);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
    }

    public function test_contact_page_has_dedicated_title(): void
    {
        $this->get('/contact')
            ->assertOk()
            ->assertSee('Contact SEOLinkBuildings — Sales and Support', false)
            ->assertSee('support@seolinkbuildings.com', false);
    }

    public function test_sitemap_and_robots_are_available(): void
    {
        Blog::factory()->published()->create([
            'title' => 'Sitemap Post',
            'slug' => 'sitemap-post',
        ]);
        $blog = Blog::where('slug', 'sitemap-post')->firstOrFail();
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Sitemap Post',
            'slug' => 'sitemap-post',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('sitemap-en.xml', false)
            ->assertSee('sitemap-de.xml', false);

        $this->get('/sitemap-en.xml')
            ->assertOk()
            ->assertSee('/blog/sitemap-post', false)
            ->assertSee('/contact', false)
            ->assertDontSee('/login', false)
            ->assertDontSee('/register', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap:', false)
            ->assertSee('Allow: /marketplace', false)
            ->assertSee('Allow: /blog', false)
            ->assertSee('Allow: /become-a-publisher', false)
            ->assertSee('Disallow: /admin/', false)
            ->assertSee('Disallow: /marketing/', false)
            ->assertSee('Disallow: /login', false)
            ->assertSee('Disallow: /register', false)
            ->assertSee('Googlebot', false)
            ->assertSee('bingbot', false)
            ->assertSee('Slurp', false)
            ->assertSee('GPTBot', false)
            ->assertSee('ChatGPT-User', false)
            ->assertSee('OAI-SearchBot', false)
            ->assertSee('Google-Extended', false)
            ->assertSee('PerplexityBot', false)
            ->assertSee('Bytespider', false)
            ->assertSee('Applebot-Extended', false)
            ->assertSee('LinkedInBot', false)
            ->assertSee('llms.txt', false);

        $this->assertFileDoesNotExist(public_path('llms.txt'));
        $this->assertFileExists(resource_path('llms.txt'));

        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('SEOLinkBuildings', false)
            ->assertSee('seolinkbuildings.com', false)
            ->assertSee('Topurlz', false)
            ->assertSee('/pricing', false)
            ->assertSee('16607074', false)
            ->assertSee('/es/', false)
            ->assertSee('/it/', false)
            ->assertSee('/us/', false);
    }

    public function test_auth_pages_use_branded_meta(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign In to Your Guest Post Account | SEOLinkBuildings', false)
            ->assertSee('name="robots" content="noindex, nofollow', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('"@type":"WebPage"', false)
            ->assertSee('"@graph"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertDontSee('hreflang="de"', false);

        $this->get('/register')
            ->assertOk()
            ->assertSee('€20 Welcome Credit', false)
            ->assertSee('name="robots" content="noindex, nofollow', false)
            ->assertDontSee('meta_register_title');
    }

    public function test_login_schema_graph_is_valid_json_ld(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">\s*(.*?)\s*</script>#s', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        $payload = json_decode($matches[1][0], true);
        $this->assertIsArray($payload);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('https://schema.org', $payload['@context'] ?? null);
        $types = array_column($payload['@graph'] ?? [], '@type');
        $this->assertContains('Organization', $types);
        $this->assertContains('WebPage', $types);
    }

    public function test_login_title_length_meets_on_page_band_in_every_locale(): void
    {
        foreach (['en', 'us', 'de', 'fr', 'nl', 'es', 'it'] as $locale) {
            $title = trans('messages.meta_login_title', [], $locale);
            $len = mb_strlen($title);
            $this->assertGreaterThanOrEqual(30, $len, $locale.': '.$title);
            $this->assertLessThanOrEqual(60, $len, $locale.': '.$title);
        }
    }

    public function test_contact_and_marketplace_include_organization_schema(): void
    {
        foreach (['/contact', '/marketplace', '/privacy-policy'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('application/ld+json', false)
                ->assertSee('"@type":"Organization"', false)
                ->assertSee('"@type":"WebPage"', false);
        }
    }

    public function test_home_includes_website_and_organization_schema(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"WebSite"', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('"@type":"SoftwareApplication"', $html);
        $this->assertStringContainsString('16607074', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);
        $this->assertStringContainsString('https://www.facebook.com/seolinkbuildings/', $html);
        $this->assertStringContainsString('https://www.instagram.com/seolinkbuildings', $html);
        $this->assertStringContainsString('https://x.com/seolinbuildings', $html);
        $this->assertStringContainsString('https://www.youtube.com/@seolinkbuildingss', $html);
        $this->assertStringContainsString('"inLanguage":"en-GB"', $html);
        $this->assertStringNotContainsString('"inLanguage":[', $html);

        $de = $this->get('/de')->assertOk()->getContent();
        $this->assertStringContainsString('"inLanguage":"de"', $de);
        $this->assertStringNotContainsString('"inLanguage":[', $de);
    }

    public function test_layout_json_ld_escapes_script_breakout_in_page_title(): void
    {
        $json = BrandOrganization::pageGraphJson(
            'Break</script><script>alert(1)</script>',
            'Description',
            'https://seolinkbuildings.com/login'
        );

        $this->assertStringContainsString('\\u003C/script\\u003E', $json);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('https://schema.org', $decoded['@context'] ?? null);
    }

    public function test_json_ld_helper_escapes_script_breakout(): void
    {
        $json = BrandOrganization::jsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => 'Break</script><script>alert(1)</script>',
        ]);

        $this->assertStringContainsString('\\u003C/script\\u003E', $json);
        $this->assertStringNotContainsString('</script>', $json);
    }

    public function test_sitemap_index_uses_request_origin_when_app_url_is_loopback(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        $this->get('https://seolinkbuildings.com/sitemap.xml')
            ->assertOk()
            ->assertDontSee('localhost:8000', false)
            ->assertSee('https://seolinkbuildings.com/sitemap-en.xml', false)
            ->assertSee('https://seolinkbuildings.com/sitemap-de.xml', false);
    }

    public function test_www_host_redirects_to_apex(): void
    {
        $this->assertFileExists(app_path('Http/Middleware/CanonicalHost.php'));
        $this->assertTrue(class_exists(CanonicalHost::class));

        $this->get('https://www.seolinkbuildings.com/about')
            ->assertRedirect('https://seolinkbuildings.com/about');

        $this->assertSame(301, $this->get('https://www.seolinkbuildings.com/about')->status());
    }

    public function test_short_legal_urls_redirect_to_full_paths(): void
    {
        $this->get('/privacy')->assertRedirect('/privacy-policy');
        $this->get('/terms')->assertRedirect('/terms-of-services');
        $this->assertSame(301, $this->get('/privacy')->status());
    }

    public function test_blog_page_two_has_rel_prev_and_self_canonical(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $blog = Blog::factory()->published()->create([
                'title' => 'Pagination Post '.$i,
                'slug' => 'pagination-post-'.$i,
            ]);
            BlogTranslation::create([
                'blog_id' => $blog->id,
                'locale' => 'en',
                'title' => 'Pagination Post '.$i,
                'slug' => 'pagination-post-'.$i,
                'excerpt' => 'Excerpt',
                'content' => '<p>Body</p>',
                'is_published' => true,
            ]);
        }

        $this->get('/blog?page=2')
            ->assertOk()
            ->assertSee('rel="prev"', false)
            ->assertSee('rel="canonical" href="'.url('/blog').'?page=2"', false)
            ->assertSee('name="robots" content="noindex, follow"', false)
            ->assertSee(' — Page 2', false)
            ->assertDontSee('hreflang="de"', false);
    }

    public function test_static_robots_txt_matches_renderer_for_production_origin(): void
    {
        $disk = (string) file_get_contents(public_path('robots.txt'));
        $this->assertSame(
            RobotsTxt::render('https://seolinkbuildings.com'),
            $disk
        );
    }

    public function test_faq_page_includes_faqpage_schema(): void
    {
        $this->get('/faq')
            ->assertOk()
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"Question"', false)
            ->assertSee('BreadcrumbList', false);
    }

    public function test_pricing_page_includes_offer_schema(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('"@type":"Service"', false)
            ->assertSee('"@type":"Offer"', false)
            ->assertSee('"price":"499"', false)
            ->assertSee('EUR', false);
    }

    public function test_about_page_includes_company_entity(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('AboutPage', false)
            ->assertSee('16607074', false)
            ->assertSee('Wenlock', false)
            ->assertSee('BreadcrumbList', false);
    }

    public function test_blog_show_includes_article_structured_data(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Structured Data Post',
            'slug' => 'structured-data-post',
            'excerpt' => 'A short excerpt for SEO.',
            'featured_image' => 'blogs/featured/structured-data.jpg',
        ]);
        Storage::disk('public')->put('blogs/featured/structured-data.jpg', 'fake-image');
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Structured Data Post',
            'slug' => 'structured-data-post',
            'excerpt' => 'A short excerpt for SEO.',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $this->get(route('blog.show', ['slug' => $blog->slug]))
            ->assertOk()
            ->assertSee('BlogPosting', false)
            ->assertSee('Structured Data Post', false)
            ->assertSee('twitter:card', false)
            ->assertSee('media/blogs/featured/structured-data.jpg', false)
            ->assertSee('BreadcrumbList', false);
    }

    public function test_blog_json_ld_escapes_script_breakout_in_title(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Break</script><script>alert(1)</script>',
            'slug' => 'json-ld-breakout',
            'excerpt' => 'Excerpt',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Break</script><script>alert(1)</script>',
            'slug' => 'json-ld-breakout',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $html = $this->get(route('blog.show', ['slug' => $blog->slug]))
            ->assertOk()
            ->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);
        $jsonLd = implode("\n", $blocks[1] ?? []);

        $this->assertStringContainsString('BlogPosting', $jsonLd);
        $this->assertStringContainsString('\\u003C/script\\u003E', $jsonLd);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $jsonLd);
    }

    public function test_help_widget_has_accessible_labels(): void
    {
        config([
            'services.support_chat.enabled' => false,
            'services.tawk.property_id' => '',
            'services.tawk.widget_id' => '',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Open help and feedback"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('role="tablist"', false);
    }

    public function test_csp_allows_quill_and_chart_cdns(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('cdn.quilljs.com', $csp);
        $this->assertStringContainsString('cdn.jsdelivr.net', $csp);
        $this->assertStringContainsString('js.stripe.com', $csp);
        $this->assertStringContainsString('embed.tawk.to', $csp);
        $this->assertStringContainsString('https://*.tawk.to', $csp);
        $this->assertStringContainsString('wss://*.tawk.to', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);

        $htaccess = (string) file_get_contents(public_path('.htaccess'));
        $this->assertStringNotContainsString(
            'Content-Security-Policy',
            $htaccess,
            'A second CSP in .htaccess is intersected by the browser and blocks Quill/jsDelivr.'
        );
    }
}
