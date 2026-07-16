<?php

namespace Tests\Feature;

use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoAndSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_include_seo_meta_and_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('og:title', false);
        $response->assertSee('application/ld+json', false);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
    }

    public function test_contact_page_has_dedicated_title(): void
    {
        $this->get('/contact')
            ->assertOk()
            ->assertSee('Contact us — SEOLinkBuildings', false);
    }

    public function test_sitemap_and_robots_are_available(): void
    {
        Blog::factory()->published()->create([
            'title' => 'Sitemap Post',
            'slug' => 'sitemap-post',
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('/blog/sitemap-post', false)
            ->assertSee('/contact', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap:', false)
            ->assertSee('Disallow: /admin/', false);
    }

    public function test_blog_show_includes_article_structured_data(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Structured Data Post',
            'slug' => 'structured-data-post',
            'excerpt' => 'A short excerpt for SEO.',
        ]);

        $this->get(route('blog.show', ['slug' => $blog->slug]))
            ->assertOk()
            ->assertSee('BlogPosting', false)
            ->assertSee('Structured Data Post', false)
            ->assertSee('twitter:card', false);
    }

    public function test_help_widget_has_accessible_labels(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Open help and feedback"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('role="tablist"', false);
    }
}
