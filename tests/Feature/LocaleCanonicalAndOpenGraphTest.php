<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleCanonicalAndOpenGraphTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{canonical: ?string, og_title: ?string, og_url: ?string, og_image: ?string, og_logo: ?string, title: ?string}
     */
    private function headTags(string $html): array
    {
        $pick = function (string $pattern) use ($html): ?string {
            return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
        };

        return [
            'canonical' => $pick('/rel="canonical" href="([^"]+)"/'),
            'og_title' => $pick('/property="og:title" content="([^"]+)"/'),
            'og_url' => $pick('/property="og:url" content="([^"]+)"/'),
            'og_image' => $pick('/property="og:image" content="([^"]+)"/'),
            'og_logo' => $pick('/property="og:logo" content="([^"]+)"/'),
            'title' => $pick('/<title>(.*?)<\/title>/s'),
        ];
    }

    public function test_locale_homes_and_blog_indexes_are_self_canonical(): void
    {
        $uk = $this->headTags($this->get('/')->assertOk()->getContent());
        $us = $this->headTags($this->get('/us')->assertOk()->getContent());

        $this->assertSame(url('/'), $uk['canonical']);
        $this->assertSame(url('/us'), $us['canonical']);
        $this->assertNotSame($uk['canonical'], $us['canonical']);

        foreach (PublicI18n::supported() as $locale) {
            foreach (['', 'marketplace', 'blog'] as $english) {
                $path = LocalizedPublicPath::publicPath($english, $locale);
                $tags = $this->headTags($this->get($path)->assertOk()->getContent());
                $this->assertSame(url($path), $tags['canonical'], $path);
                $this->assertSame($tags['canonical'], $tags['og_url'], $path.' og:url');
            }
        }
    }

    public function test_blog_post_canonical_follows_the_translation_not_the_url_locale(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'English title',
            'slug' => 'english-title',
            'content' => '<p>English body</p>',
            'primary_locale' => 'en',
        ]);
        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => 'English title',
            'slug' => 'english-title',
            'excerpt' => 'English excerpt',
            'content' => '<p>English body</p>',
            'is_published' => true,
        ]);

        $enCanonical = url('/blog/english-title');

        foreach (['/blog/english-title', '/us/blog/english-title', '/de/blog/english-title'] as $path) {
            $tags = $this->headTags($this->get($path)->assertOk()->getContent());
            $this->assertSame($enCanonical, $tags['canonical'], $path);
            $this->assertSame($enCanonical, $tags['og_url'], $path);
        }
    }

    public function test_money_pages_publish_og_title_url_and_logo(): void
    {
        $this->assertFileExists(public_path('assets/brand/web/og-share-1200x630.png'));
        $this->assertFileExists(public_path('assets/img/logo1.png'));

        $pages = [
            '/' => 'Guest Post Marketplace for SEO Backlinks',
            '/marketplace' => 'Browse Publisher Sites and Buy Guest Posts',
            '/pricing' => 'Digital PR Marketplace | Guest Posts and Packages',
            '/become-a-publisher' => 'Become a Publisher and Sell Guest Posts',
            '/guest-posts-germany' => 'Guest Post Cost in Germany',
            '/guest-post-prices-europe' => 'Guest Post Prices in Europe',
        ];

        foreach ($pages as $path => $titleNeedle) {
            $html = $this->get($path)->assertOk()->getContent();
            $tags = $this->headTags($html);

            $this->assertNotNull($tags['title'], $path);
            $this->assertStringContainsString($titleNeedle, (string) $tags['title'], $path);
            $this->assertSame($tags['title'], $tags['og_title'], $path.' og:title');
            $this->assertSame(url($path === '/' ? '/' : $path), $tags['canonical'], $path);
            $this->assertSame($tags['canonical'], $tags['og_url'], $path.' og:url');
            $this->assertStringContainsString('assets/brand/web/og-share-1200x630.png', (string) $tags['og_image'], $path);
            $this->assertStringContainsString('assets/img/logo1.png', (string) $tags['og_logo'], $path);
            $this->assertStringContainsString('og:image:type" content="image/png"', $html, $path);
        }
    }
}
