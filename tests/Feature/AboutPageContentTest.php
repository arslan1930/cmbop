<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Country;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\LocalizedPublicPath;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_renders_europe_hub_sections_and_schema(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('AboutPage', false)
            ->assertSee('FAQPage', false)
            ->assertSee('areaServed', false)
            ->assertSee('knowsAbout', false)
            ->assertSee('16607074', false)
            ->assertSee('find-and-update.company-information.service.gov.uk/company/16607074', false)
            ->assertSee('https://www.facebook.com/seolinkbuildings/', false)
            ->assertSee('https://www.youtube.com/@seolinkbuildingss', false)
            ->assertSee('https://www.linkedin.com/company/seolinkbuildings', false)
            ->assertSee(config('services.trustpilot.review_url'), false)
            ->assertSee(__('messages.about_page_europe_title'), false)
            ->assertSee(__('messages.about_page_proof_title'))
            ->assertSee(__('messages.about_page_proof_intro'))
            ->assertSee(__('messages.about_page_trust_ratings'))
            ->assertSee(__('messages.about_page_trust_completions'))
            ->assertSee(__('messages.about_page_trust_verified'))
            ->assertSee(__('messages.about_page_trust_reach'))
            ->assertSee(__('messages.about_page_workflow_trust'))
            ->assertSee(__('messages.about_page_faq_q_6'))
            ->assertSee(__('messages.about_page_faq_a_6'))
            ->assertSee('Publisher ratings', false)
            ->assertSee('Order completion tracking', false)
            ->assertSee('Verified publishers', false)
            ->assertSee(__('messages.about_page_faq_title'), false)
            ->assertSee(__('messages.about_page_team_title'), false)
            ->assertSee(__('messages.about_page_operated_by'), false)
            ->assertSee(__('messages.about_page_cta_marketplace'), false)
            ->assertSee(__('messages.about_page_cta_publisher'), false)
            ->assertSee(__('messages.get_started'), false)
            ->assertSee(url('/register'), false)
            ->assertSee('/marketplace', false)
            ->assertSee('/become-a-publisher', false)
            ->assertSee('/how-it-works', false)
            ->assertSee('support@seolinkbuildings.com', false)
            ->assertDontSee('VAT:', false)
            ->assertDontSee('Not VAT registered', false)
            ->assertDontSee('mailto:"', false)
            ->assertDontSee('"email":""', false)
            ->assertDontSee('AggregateRating', false)
            ->assertDontSee('4.9', false);
    }

    public function test_about_page_shows_payment_and_data_protection_strip(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee(__('messages.about_page_security_title'), false)
            ->assertSee(__('messages.about_page_security_body'), false)
            ->assertSee('Payments secured by', false)
            ->assertSee('assets/img/payments/visa.svg', false)
            ->assertSee(localized_url('privacy-policy'), false)
            ->assertSee(localized_url('refund-policy'), false)
            ->assertDontSee('100% secure', false);
    }

    public function test_about_page_falls_back_when_support_email_env_is_blank(): void
    {
        config([
            'billing.company.support_email' => '',
        ]);

        $this->get('/about')
            ->assertOk()
            ->assertSee('mailto:support@seolinkbuildings.com', false)
            ->assertSee('"email":"support@seolinkbuildings.com"', false)
            ->assertDontSee('mailto:"', false)
            ->assertDontSee('"email":""', false);
    }

    public function test_about_page_shows_live_proof_counts_when_available(): void
    {
        $this->seed(RolesTableSeeder::class);

        $this->assertGreaterThan(
            0,
            Country::query()->marketplace()->count(),
            'Migrations should seed marketplace countries.'
        );

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'About Proof Blog',
            'site_url' => 'https://about-proof.example',
            'domain' => 'about-proof.example',
            'example_url' => 'https://about-proof.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'About page proof listing.',
            'verified' => true,
            'rating_avg' => 4.5,
            'rating_count' => 3,
            'active' => 1,
        ]);

        $html = $this->get('/about')->assertOk()->getContent();

        $this->assertStringContainsString(__('messages.about_page_proof_sites'), $html);
        $this->assertStringContainsString(__('messages.about_page_proof_countries'), $html);
        $this->assertStringContainsString(__('messages.about_page_proof_verified'), $html);
        $this->assertStringContainsString(__('messages.about_page_proof_rated'), $html);
        $this->assertMatchesRegularExpression('/class="about-proof-value">\s*1\s*</', $html);
    }

    public function test_about_page_lists_published_locale_blog_pillars(): void
    {
        // Curated Europe pillar is upserted by migrations; only assert it surfaces on About.
        $this->assertTrue(
            Blog::published()
                ->where('slug', 'buy-guest-posts-in-europe-how-to-choose-publisher-sites')
                ->exists()
                || BlogTranslation::query()
                    ->where('slug', 'buy-guest-posts-in-europe-how-to-choose-publisher-sites')
                    ->where('is_published', true)
                    ->exists()
        );

        $this->get('/about')
            ->assertOk()
            ->assertSee(__('messages.about_page_blog_title'), false)
            ->assertSee('/blog/buy-guest-posts-in-europe-how-to-choose-publisher-sites', false);
    }

    public function test_about_page_links_to_uniquified_pillar_not_custom_catalog_slug(): void
    {
        $slug = 'what-to-check-after-the-live-link-indexation-attributes-rankings';
        Blog::query()->where('slug', $slug)->orWhere('curated_key', $slug)->get()->each->delete();

        Blog::factory()->published()->create([
            'title' => 'Custom Occupying Catalog Slug',
            'slug' => $slug,
            'content' => '<p>Custom body.</p>',
            'manually_edited_at' => now(),
            'curated_key' => null,
        ]);

        $this->artisan('blog:upsert-live-link-checklist')->assertSuccessful();

        $pillarSlug = Blog::query()->where('curated_key', $slug)->value('slug');
        $this->assertNotNull($pillarSlug);
        $this->assertNotSame($slug, $pillarSlug);

        $this->get('/about')
            ->assertOk()
            ->assertSee('/blog/'.$pillarSlug, false)
            ->assertDontSee('Custom Occupying Catalog Slug', false);
    }

    public function test_german_about_page_uses_local_copy_and_companies_house_link(): void
    {
        $this->get(LocalizedPublicPath::publicPath('about', 'de'))
            ->assertOk()
            ->assertSee('Der Guest-Post-Marktplatz für Europa', false)
            ->assertSee('DACH', false)
            ->assertSee('Betrieben von Topurlz Ltd', false)
            ->assertSee('Companies House', false)
            ->assertSee('FAQPage', false);
    }

    public function test_french_and_dutch_about_pages_are_unique(): void
    {
        $fr = $this->get(LocalizedPublicPath::publicPath('about', 'fr'))->assertOk()->getContent();
        $nl = $this->get(LocalizedPublicPath::publicPath('about', 'nl'))->assertOk()->getContent();

        $this->assertStringContainsString('conçue pour l’Europe', $fr);
        $this->assertStringContainsString('France &amp; Belgique', $fr);
        $this->assertStringContainsString('gebouwd voor Europa', $nl);
        $this->assertStringContainsString('Nederland &amp; België', $nl);
        $this->assertNotSame(
            strip_tags($fr),
            strip_tags($nl),
            'FR and NL About copy should not be identical.'
        );
    }
}
