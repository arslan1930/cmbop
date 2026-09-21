<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Support\CountryLander;
use App\Support\PublicI18n;
use App\Support\RobotsTxt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoGapAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_poland_lander_is_indexable_english_only(): void
    {
        $this->assertSame('guest-posts-poland', CountryLander::find('poland')['slug'] ?? null);

        $html = $this->get('/guest-posts-poland')
            ->assertOk()
            ->assertSee('Guest posts in Poland', false)
            ->assertSee('Guest Posts in Poland — PL Publishers, EUR', false)
            ->assertSee('rel="canonical" href="'.url('/guest-posts-poland').'"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertDontSee('hreflang="de"', false)
            ->assertSee('BreadcrumbList', false)
            ->assertSee('FAQPage', false)
            ->getContent();

        $this->assertStringNotContainsString('seolinkbuildings.pl', $html);
        $this->assertStringNotContainsString('Comprare Guest Post', $html);

        $this->get('/de/guest-posts-poland')->assertRedirect('/guest-posts-poland');
        $this->assertSame(301, $this->get('/de/guest-posts-poland')->status());
    }

    public function test_italy_and_spain_meta_titles_are_not_keyword_stuffed(): void
    {
        $this->get('/guest-posts-italy')
            ->assertOk()
            ->assertSee('Guest Posts in Italy — Verified Publishers, EUR', false)
            ->assertDontSee('Comprare Guest Post in Italy', false);

        $this->get('/guest-posts-spain')
            ->assertOk()
            ->assertSee('Guest Posts in Spain — Verified Publishers, EUR', false)
            ->assertDontSee('Guest Post Comprar in Spain', false);
    }

    public function test_public_marketing_pages_include_breadcrumbs(): void
    {
        foreach ([
            '/contact',
            '/marketplace',
            '/become-a-publisher',
            '/why-choose-us',
            '/privacy-policy',
            '/terms-of-services',
            '/cookie-policy',
            '/blog',
        ] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('BreadcrumbList', false)
                ->assertSee('aria-label="Breadcrumb"', false);
        }
    }

    public function test_why_choose_us_is_linked_from_the_footer(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(localized_url('why-choose-us'), false);
    }

    public function test_robots_allows_every_lander_and_disallows_cron(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->getContent();

        foreach (CountryLander::slugs() as $slug) {
            $this->assertStringContainsString('Allow: /'.$slug, $txt);
        }

        $this->assertStringContainsString('Disallow: /cron/', $txt);
        $this->assertSame(
            RobotsTxt::render('https://seolinkbuildings.com'),
            (string) file_get_contents(public_path('robots.txt'))
        );
    }

    public function test_paginated_blog_is_not_in_the_hreflang_cluster(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $blog = Blog::factory()->published()->create([
                'title' => 'Gap Post '.$i,
                'slug' => 'gap-post-'.$i,
            ]);
            BlogTranslation::create([
                'blog_id' => $blog->id,
                'locale' => 'en',
                'title' => 'Gap Post '.$i,
                'slug' => 'gap-post-'.$i,
                'excerpt' => 'Excerpt',
                'content' => '<p>Body</p>',
                'is_published' => true,
            ]);
        }

        $request = request()->create('/blog', 'GET', ['page' => 2]);
        $this->assertTrue(PublicI18n::isPaginatedBlogIndex($request));
        $this->assertSame('noindex, follow', PublicI18n::robotsContent($request));
        $this->assertSame([], PublicI18n::hreflangTags($request));
    }

    public function test_llms_txt_mentions_poland_lander_not_cctlds(): void
    {
        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('/guest-posts-poland', false)
            ->assertSee('seolinkbuildings.com', false)
            ->assertSee('Polish (/pl)', false)
            ->assertSee('301 onto seolinkbuildings.com', false)
            ->assertDontSee('seolinkbuildings.pl', false)
            ->assertDontSee('seolinkbuildings.de', false);
    }

    public function test_polish_locale_is_on_dot_com_and_in_the_sitemap_index(): void
    {
        $html = $this->get('/pl')->assertOk()->getContent();
        $this->assertStringContainsString('lang="pl-PL"', $html);
        $this->assertStringContainsString('hreflang="pl-PL"', $html);
        $this->assertStringNotContainsString('seolinkbuildings.pl', $html);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('sitemap-pl.xml', false);
        $this->get('/sitemap-pl.xml')
            ->assertOk()
            ->assertSee('/pl/rynek', false)
            ->assertSee('hreflang="pl-PL"', false);
    }
}
