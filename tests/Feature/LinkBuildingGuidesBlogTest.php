<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Support\GuestPostingGuideBlogPost;
use App\Support\HowToGetBacklinksBlogPost;
use App\Support\LinkBuildingGuideBlogPost;
use App\Support\PublicI18n;
use App\Support\SponsoredPostGuideBlogPost;
use App\Support\ThinBlogRedirects;
use Database\Seeders\LinkBuildingGuidesBlogsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkBuildingGuidesBlogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    private function postClasses(): array
    {
        return [
            HowToGetBacklinksBlogPost::class,
            GuestPostingGuideBlogPost::class,
            SponsoredPostGuideBlogPost::class,
            LinkBuildingGuideBlogPost::class,
        ];
    }

    public function test_upsert_command_publishes_the_four_pillars_with_meta(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);

        foreach ($this->postClasses() as $class) {
            $payload = $class::payload();
            $blog = Blog::query()->where('slug', $class::SLUG)->first();

            $this->assertNotNull($blog, $class::SLUG.' missing');
            $this->assertSame('published', $blog->status);
            $this->assertSame('en', $blog->primary_locale);
            $this->assertSame($class::SLUG, $blog->curated_key);
            $this->assertSame($payload['title'], $blog->title);
            $this->assertStringContainsString('<h2>', (string) $blog->content);

            $translation = BlogTranslation::query()
                ->where('blog_id', $blog->id)
                ->where('locale', 'en')
                ->first();

            $this->assertNotNull($translation);
            $this->assertTrue((bool) $translation->is_published);
            $this->assertSame($payload['meta_title'], $translation->meta_title);
            $this->assertSame($payload['meta_description'], $translation->meta_description);
            $this->assertGreaterThanOrEqual(50, mb_strlen((string) $translation->meta_title));
            $this->assertLessThanOrEqual(70, mb_strlen((string) $translation->meta_title));
            $this->assertLessThanOrEqual(180, mb_strlen((string) $translation->meta_description));
            $this->assertSame([], $class::faqItems());
            $this->assertSame($class::FEATURED_STORAGE, $blog->featured_image);
            $this->assertFileExists(public_path($class::FEATURED_ASSET));
            $this->assertFileExists(storage_path('app/public/'.$class::FEATURED_STORAGE));

            foreach ($class::translations() as $locale => $localePayload) {
                $row = BlogTranslation::query()
                    ->where('blog_id', $blog->id)
                    ->where('locale', $locale)
                    ->first();
                $this->assertNotNull($row, $class::SLUG.' missing '.$locale);
                $this->assertTrue((bool) $row->is_published);
                $this->assertSame($localePayload['slug'], $row->slug);
                $this->assertSame($localePayload['title'], $row->title);
                $this->assertGreaterThanOrEqual(50, mb_strlen((string) $row->meta_title));
                $this->assertLessThanOrEqual(70, mb_strlen((string) $row->meta_title));
                $this->assertLessThanOrEqual(180, mb_strlen((string) $row->meta_description));
            }
        }
    }

    public function test_public_pages_render_without_faq_schema_and_link_the_cluster(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);

        $expectedH1 = [
            HowToGetBacklinksBlogPost::SLUG => 'How to Get Backlinks: A Practical Guide to Earning High-Quality Links',
            GuestPostingGuideBlogPost::SLUG => 'Guest Posting: A Complete Guide to Guest Blogging for SEO',
            SponsoredPostGuideBlogPost::SLUG => 'Sponsored Posts: What They Are, How They Work and What Advertisers Should Know',
            LinkBuildingGuideBlogPost::SLUG => 'Link Building Guide: A Practical SEO Strategy for Building Authority',
        ];

        foreach ($this->postClasses() as $class) {
            $slug = $class::SLUG;
            $canonical = PublicI18n::urlForLocale('blog/'.$slug, 'en');
            $payload = $class::payload();

            $response = $this->get('/blog/'.$slug)
                ->assertOk()
                ->assertSee($expectedH1[$slug], false)
                ->assertSee('rel="canonical" href="'.$canonical.'"', false)
                ->assertSee($payload['meta_title'].' — SEOLinkBuildings', false)
                ->assertSee($payload['meta_description'], false)
                ->assertSee('BlogPosting', false)
                ->assertDontSee('"@type":"FAQPage"', false)
                ->assertSee('/marketplace', false)
                ->assertSee('/how-it-works', false)
                ->assertSee(basename($class::FEATURED_STORAGE), false);

            $html = $response->getContent();
            $this->assertIsString($html);
            $this->assertTrue(
                str_contains($html, '/storage/blogs/content/') || str_contains($html, '/media/blogs/content/'),
                $slug.' should include the inline diagram'
            );
            $this->assertStringContainsString(basename($class::FEATURED_STORAGE), $html);
            $this->assertStringNotContainsString('og-share-1200x630.png', $html);
            $this->assertStringContainsString('og:image:type" content="image/jpeg"', $html);
            $featured = preg_quote(basename($class::FEATURED_STORAGE), '#');
            $this->assertMatchesRegularExpression(
                '#property="og:image" content="[^"]*'.$featured.'"#',
                $html
            );
            $this->assertMatchesRegularExpression(
                '#name="twitter:image" content="[^"]*'.$featured.'"#',
                $html
            );

            foreach ($this->postClasses() as $other) {
                if ($other::SLUG === $slug) {
                    continue;
                }
                $this->assertStringContainsString('/blog/'.$other::SLUG, $html, $slug.' should link to '.$other::SLUG);
            }
        }

        $sponsored = $this->get('/blog/'.SponsoredPostGuideBlogPost::SLUG)->assertOk();
        $sponsored->assertSee('rel="sponsored"', false);
        $sponsored->assertSee('https://developers.google.com/search/docs/essentials/spam-policies', false);
        $sponsored->assertSee('https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking', false);
    }

    public function test_locale_translations_render_and_english_slug_301s_to_localized_slug(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);

        $de = HowToGetBacklinksBlogPost::translations()['de'];
        $fr = HowToGetBacklinksBlogPost::translations()['fr'];

        $this->get('/de/blog/'.$de['slug'])
            ->assertOk()
            ->assertSee($de['title'], false)
            ->assertSee('rel="canonical" href="'.PublicI18n::urlForLocale('blog/'.$de['slug'], 'de').'"', false)
            ->assertDontSee('FAQPage', false);

        $this->get('/fr/blog/'.HowToGetBacklinksBlogPost::SLUG)
            ->assertStatus(301)
            ->assertRedirect(PublicI18n::urlForLocale('blog/'.$fr['slug'], 'fr'));
    }

    public function test_thin_blog_seeder_slugs_301_onto_the_pillars(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);

        $this->get('/blog/how-to-build-high-quality-backlinks-in-2026')
            ->assertStatus(301)
            ->assertRedirect(PublicI18n::urlForLocale('blog/'.HowToGetBacklinksBlogPost::SLUG, 'en'));

        $this->get('/blog/guest-posting-checklist-for-advertisers')
            ->assertStatus(301)
            ->assertRedirect(PublicI18n::urlForLocale('blog/'.GuestPostingGuideBlogPost::SLUG, 'en'));

        $this->get('/blog/digital-pr-ideas-that-earn-coverage-and-links')
            ->assertStatus(301)
            ->assertRedirect(PublicI18n::urlForLocale('blog/'.LinkBuildingGuideBlogPost::SLUG, 'en'));

        $deSlug = HowToGetBacklinksBlogPost::translations()['de']['slug'];
        $this->get('/de/blog/how-to-build-high-quality-backlinks-in-2026')
            ->assertStatus(301)
            ->assertRedirect(PublicI18n::urlForLocale('blog/'.$deSlug, 'de'));
    }

    public function test_legacy_stubs_are_unpublished_and_omitted_from_sitemap_and_index(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);

        $stubs = [
            'how-to-build-high-quality-backlinks-in-2026' => 'Thin backlinks stub',
            'guest-posting-checklist-for-advertisers' => 'Thin guest-post stub',
        ];

        foreach ($stubs as $slug => $title) {
            $blog = Blog::factory()->published()->create([
                'title' => $title,
                'slug' => $slug,
                'content' => '<p>Short leftover seeder body.</p>',
                'primary_locale' => 'en',
            ]);
            BlogTranslation::create([
                'blog_id' => $blog->id,
                'locale' => 'en',
                'title' => $title,
                'slug' => $slug,
                'excerpt' => 'Short leftover excerpt.',
                'content' => '<p>Short leftover seeder body.</p>',
                'is_published' => true,
            ]);
        }

        ThinBlogRedirects::unpublishLegacy();

        foreach ($stubs as $slug => $title) {
            $row = Blog::query()->where('slug', $slug)->first();
            $this->assertNotNull($row);
            $this->assertSame('draft', $row->status);
            $this->assertFalse((bool) BlogTranslation::query()->where('blog_id', $row->id)->value('is_published'));
        }

        $sitemap = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        foreach (array_keys($stubs) as $slug) {
            $this->assertStringNotContainsString('/blog/'.$slug, $sitemap);
        }
        $this->assertStringContainsString('/blog/'.HowToGetBacklinksBlogPost::SLUG, $sitemap);
        $this->assertStringContainsString('/blog/'.GuestPostingGuideBlogPost::SLUG, $sitemap);

        $index = $this->get('/blog')->assertOk();
        $index->assertDontSee('Thin backlinks stub', false);
        $index->assertDontSee('Thin guest-post stub', false);

        $listingTitles = Blog::published()
            ->withoutLegacyRedirects()
            ->withPublishedLocale('en')
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (Blog $post) => $post->applyPublishedLocale('en')->title)
            ->implode("\n");
        $this->assertStringContainsString('How to Get Backlinks', $listingTitles);
        $this->assertStringNotContainsString('Thin backlinks stub', $listingTitles);

        $this->get('/blog/'.HowToGetBacklinksBlogPost::SLUG)
            ->assertOk()
            ->assertSee('How to Get Backlinks', false);
    }

    public function test_blog_upsert_curated_includes_link_building_guides(): void
    {
        $this->artisan('blog:upsert-curated')->assertSuccessful();

        foreach ($this->postClasses() as $class) {
            $this->assertTrue(
                Blog::query()->where('slug', $class::SLUG)->exists(),
                'upsert-curated missed '.$class::SLUG
            );
        }
    }
}
