<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\User;
use App\Support\BrandOrganization;
use App\Support\PublicFaq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlAccessDay1Test extends TestCase
{
    use RefreshDatabase;

    public function test_cookieless_us_visitor_gets_uk_urls_without_a_redirect(): void
    {
        config(['fx.fake_country' => 'US', 'fx.force_display' => '']);

        $home = $this->get('/');
        $home->assertOk();
        $home->assertCookie(config('i18n.cookie', 'public_locale'), 'en');

        $this->get('/about')->assertOk();
        $this->get('/us/about')->assertOk();
    }

    public function test_localized_home_schema_uses_one_organization_id(): void
    {
        $html = $this->get('/de')->assertOk()->getContent();
        $orgId = BrandOrganization::organizationId();

        $this->assertGreaterThanOrEqual(2, substr_count($html, $orgId));
        $this->assertStringNotContainsString(rtrim(url('/de'), '/').'/#organization', $html);
        $this->assertStringNotContainsString('Partners with', $html);
    }

    public function test_us_blog_lists_english_posts_at_their_canonical_urls(): void
    {
        config(['fx.fake_country' => 'US', 'fx.force_display' => '']);
        $author = User::factory()->create();
        $post = Blog::create([
            'title' => 'English Crawl Post',
            'slug' => 'english-crawl-post',
            'excerpt' => 'English excerpt for the US blog index.',
            'content' => '<p>English crawl body.</p>',
            'author' => $author->name,
            'status' => 'published',
            'published_at' => now(),
            'created_by' => $author->id,
        ]);
        BlogTranslation::create([
            'blog_id' => $post->id,
            'locale' => 'en',
            'title' => 'English Crawl Post',
            'slug' => 'english-crawl-post',
            'excerpt' => 'English excerpt for the US blog index.',
            'content' => '<p>English crawl body.</p>',
            'is_published' => true,
        ]);

        $this->get('/blog/english-crawl-post')->assertOk();

        $index = $this->get('/us/blog')->assertOk();
        $index->assertSee('English Crawl Post', false);
        $index->assertDontSee('No Blog Posts Yet', false);
        $index->assertSee('/blog/english-crawl-post', false);
        $index->assertDontSee('/us/blog/english-crawl-post', false);
    }

    public function test_homepage_drops_fake_testimonials_and_keeps_euro_prices_for_us(): void
    {
        config(['fx.fake_country' => 'US', 'fx.force_display' => '']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Sarah Johnson', $html);
        $this->assertStringNotContainsString('TechStart', $html);
        $this->assertStringNotContainsString('slb-testimonials', $html);
        $this->assertStringContainsString('€499', $html);
        $this->assertStringNotContainsString('$560', $html);
        $this->assertStringContainsString('European guest post and link building marketplace', $html);
        $this->assertStringNotContainsString('global link building marketplace', $html);
    }

    public function test_english_faq_matches_its_schema_and_skips_unconfirmed_answers(): void
    {
        $html = $this->get('/faq')->assertOk()->getContent();

        $this->assertStringNotContainsString('[CONFIRM]', $html);
        $this->assertStringNotContainsString('Do new advertisers get bonus credit?', $html);
        $this->assertStringNotContainsString('Chinese markets', $html);
        $this->assertStringContainsString('What is SEOLinkBuildings?', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        foreach (PublicFaq::items() as $item) {
            $this->assertGreaterThanOrEqual(2, substr_count($decoded, $item['q']), $item['q']);
            $this->assertGreaterThanOrEqual(2, substr_count($decoded, $item['a']), $item['q']);
            $words = preg_split('/\s+/u', trim($item['a'])) ?: [];
            $this->assertGreaterThanOrEqual(40, count($words), $item['q']);
            $this->assertLessThanOrEqual(80, count($words), $item['q']);
        }
    }
}
