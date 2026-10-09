<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Support\CountryLander;
use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use App\Support\RobotsTxt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GscIndexingFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_apex_https_does_not_redirect_again(): void
    {
        $this->get('https://seolinkbuildings.com/about')->assertOk();
        $this->get('https://seolinkbuildings.com/contact')->assertOk();
    }

    public function test_www_redirect_preserves_path_and_query_in_one_hop(): void
    {
        $this->get('https://www.seolinkbuildings.com/about?ref=gsc')
            ->assertRedirect('https://seolinkbuildings.com/about?ref=gsc');
        $this->assertSame(301, $this->get('https://www.seolinkbuildings.com/about?ref=gsc')->status());
    }

    public function test_canonical_hreflang_and_sitemap_never_emit_www(): void
    {
        $html = $this->get('https://seolinkbuildings.com/about')->assertOk()->getContent();
        $this->assertStringNotContainsString('www.seolinkbuildings.com', $html);
        $this->assertStringContainsString('rel="canonical" href="https://seolinkbuildings.com/about"', $html);

        foreach (['en', 'us', 'at', 'de'] as $locale) {
            $xml = $this->get('/sitemap-'.$locale.'.xml')->assertOk()->getContent();
            $this->assertStringNotContainsString('www.seolinkbuildings.com', $xml);
            $this->assertStringNotContainsString('http://seolinkbuildings.com', $xml);
            $this->assertStringNotContainsString('?page=', $xml);
            $this->assertStringNotContainsString('/login', $xml);
            $this->assertStringNotContainsString('/register', $xml);
        }
    }

    public function test_us_country_header_does_not_bounce_english_blog_posts(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Price your site',
            'slug' => 'how-to-price-your-site-and-sensitive-niches',
            'primary_locale' => 'en',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Price your site',
            'slug' => 'how-to-price-your-site-and-sensitive-niches',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $canonical = url('/blog/how-to-price-your-site-and-sensitive-niches');

        $this->withHeader('CF-IPCountry', 'US')
            ->get('/blog/how-to-price-your-site-and-sensitive-niches')
            ->assertOk()
            ->assertSee('rel="canonical" href="'.$canonical.'"', false);

        $this->withHeader('CF-IPCountry', 'US')
            ->get('/us/blog/how-to-price-your-site-and-sensitive-niches')
            ->assertRedirect($canonical);
        $this->assertSame(
            301,
            $this->withHeader('CF-IPCountry', 'US')
                ->get('/us/blog/how-to-price-your-site-and-sensitive-niches')
                ->status()
        );

        foreach ([
            'how-to-choose-a-publisher-site-dr-da-traffic-niche',
            'wallet-escrow-and-refunds-explained',
        ] as $slug) {
            $post = Blog::factory()->published()->create([
                'title' => $slug,
                'slug' => $slug,
                'primary_locale' => 'en',
            ]);
            BlogTranslation::create([
                'blog_id' => $post->id,
                'locale' => 'en',
                'title' => $slug,
                'slug' => $slug,
                'excerpt' => 'Excerpt',
                'content' => '<p>Body</p>',
                'is_published' => true,
            ]);

            $this->withHeader('CF-IPCountry', 'US')
                ->get('/blog/'.$slug)
                ->assertOk();
        }
    }

    public function test_at_kontakt_is_self_canonical_with_reciprocal_hreflang_and_x_default(): void
    {
        $expected = [];
        foreach (PublicI18n::supported() as $locale) {
            $expected[PublicI18n::hreflang($locale)] = url(LocalizedPublicPath::publicPath('contact', $locale));
        }
        $expected['x-default'] = url('/contact');

        foreach (['/contact', '/at/kontakt', '/ch/kontakt', '/de/kontakt', '/us/contact'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('rel="canonical" href="'.url($path).'"', $html, $path);
            $this->assertStringContainsString('hreflang="x-default"', $html, $path);
            $this->assertStringContainsString('href="'.url('/contact').'"', $html, $path);

            preg_match_all(
                '/<link[^>]+rel="alternate"[^>]+hreflang="([^"]+)"[^>]+href="([^"]+)"/i',
                $html,
                $matches,
                PREG_SET_ORDER
            );
            $cluster = [];
            foreach ($matches as $match) {
                $cluster[$match[1]] = $match[2];
            }
            $this->assertSame($expected, $cluster, $path);
        }
    }

    public function test_robots_allows_auth_pages_and_disk_copy_matches(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringNotContainsString("Disallow: /login\n", $txt);
        $this->assertStringNotContainsString("Disallow: /register\n", $txt);
        $this->assertStringNotContainsString("Disallow: /forgot-password\n", $txt);
        $this->assertStringNotContainsString("Disallow: /reset-password\n", $txt);
        $this->assertStringContainsString('Disallow: /admin/', $txt);
        $this->assertSame(
            RobotsTxt::render('https://seolinkbuildings.com'),
            (string) file_get_contents(public_path('robots.txt'))
        );
    }

    public function test_auth_and_password_pages_are_noindex(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('name="robots" content="noindex, nofollow', false)
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_english_sitemap_lists_blog_posts_and_country_landers(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Indexed post',
            'slug' => 'indexed-post-for-sitemap',
            'primary_locale' => 'en',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'Indexed post',
            'slug' => 'indexed-post-for-sitemap',
            'excerpt' => 'Excerpt',
            'content' => '<p>Body</p>',
            'is_published' => true,
        ]);

        $xml = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/blog/indexed-post-for-sitemap', $xml);
        foreach (CountryLander::slugs() as $slug) {
            $this->assertStringContainsString('/'.$slug, $xml, $slug);
        }

        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('sitemap-en.xml', $index);
        $this->assertStringContainsString('sitemap-pl.xml', $index);
        $this->assertSame(
            count(PublicI18n::supported()),
            substr_count($index, '<lastmod>')
        );
    }
}
