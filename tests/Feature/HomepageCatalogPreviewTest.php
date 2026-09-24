<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketing\CatalogTeaserService;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageCatalogPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        $country = $overrides['country'] ?? 'de';
        $language = $overrides['language'] ?? 'en';

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Example Site',
            'site_url' => 'https://example-'.$country.'.com',
            'domain' => 'example-'.$country.'.com',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => $country,
            'language' => $language,
            'countries' => [$country],
            'languages' => [$language],
            'category' => 'Marketing, PR & Advertising',
            'price' => 100,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Catalog preview inventory',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_homepage_hero_uses_german_catalog_table(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'German News Hub',
            'domain' => 'german-news-hub.de',
            'site_url' => 'https://german-news-hub.de',
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'dr' => 70,
            'da' => 60,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'French Lifestyle',
            'domain' => 'french-lifestyle.fr',
            'site_url' => 'https://french-lifestyle.fr',
            'country' => 'fr',
            'language' => 'fr',
            'countries' => ['fr'],
            'languages' => ['fr'],
            'dr' => 65,
            'da' => 55,
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->assertSee('Publisher catalog preview', false)
            ->assertSee('Add to cart', false)
            ->assertSee('berlin**.de', false)
            ->assertSee('munich**.de', false)
            ->assertSee('hamburg**.de', false)
            ->assertSee('Germany', false)
            ->assertDontSee('Demo Site', false)
            ->assertDontSee('German News Hub', false)
            ->assertDontSee('dashboard.png', false)
            ->assertDontSee('French Lifestyle', false)
            ->assertDontSee('french-lifestyle.fr', false)
            ->assertDontSee('german-news-hub.de', false)
            ->assertDontSee('advertiser/catalog', false)
            ->getContent();

        $this->assertStringContainsString('**', $html);
        $this->assertHeroDemoScreenshotScores($html);
    }

    public function test_homepage_always_shows_catalog_table_even_without_sites(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertSee('Publisher catalog preview', false)
            ->assertSee('Add to cart', false)
            ->assertSee('Germany', false)
            ->assertSee('berlin**.de', false)
            ->assertSee('munich**.de', false)
            ->assertSee('hamburg**.de', false)
            ->assertDontSee('Demo Site', false)
            ->assertDontSee('dashboard.png', false)
            ->assertDontSee('advertiser/catalog', false)
            ->getContent();

        $this->assertHeroDemoScreenshotScores($html);
    }

    public function test_hero_keeps_screenshot_demo_scores_when_live_teasers_exist(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'Low DA News',
            'domain' => 'low-da-news.de',
            'site_url' => 'https://low-da-news.de',
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'dr' => 12,
            'da' => 32,
            'traffic' => 500000,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/catalog-metric--dr[^>]*>\s*<span class="catalog-metric__value">12</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/catalog-metric--da">\s*<span class="catalog-metric__value">32</',
            $html
        );
        $this->assertHeroDemoScreenshotScores($html);
    }

    public function test_hero_ctas_stay_on_one_line(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('slb-hero-cta-group', $html);
        $this->assertStringContainsString('Get Started', $html);
        $this->assertStringContainsString('Become a publisher', $html);

        $hero = (string) file_get_contents(resource_path('views/components/hero.blade.php'));
        $this->assertMatchesRegularExpression(
            '/\.slb-hero-cta-group\s*\{[^}]*flex-wrap:\s*nowrap/s',
            $hero
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.slb-hero-cta-group\s*\{[^}]*flex-direction:\s*column/s',
            $hero
        );
        $this->assertStringContainsString('white-space: nowrap', $hero);
    }

    public function test_hero_catalog_preview_keeps_full_table_readable_on_narrow_viewports(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-table', $html);
        $this->assertStringContainsString('slb-hero-catalog-clone', $html);
        $catalogCssAt = strpos($html, 'assets/css/catalog.css');
        $heroAt = strpos($html, 'class="slb-hero"');
        $this->assertNotFalse($catalogCssAt);
        $this->assertNotFalse($heroAt);
        $this->assertLessThan($heroAt, $catalogCssAt, 'catalog.css must load in head before the hero paints');
        $this->assertStringContainsString('min-width: 720px', $html);
        $this->assertStringContainsString('overscroll-behavior-x: contain', $html);

        $hero = (string) file_get_contents(resource_path('views/components/hero.blade.php'));
        $this->assertStringContainsString('#mainNavbar .navbar-logo', $hero);
        $this->assertStringContainsString('max-height: 52px', $hero);
        $this->assertStringContainsString('transition: none !important', $hero);
        $this->assertStringContainsString('animation: none !important', $hero);
        $this->assertStringNotContainsString('animation: slbHeroFade', $hero);
        $this->assertStringNotContainsString('animation: slbHeroRise', $hero);
        $this->assertStringContainsString('overflow-x: clip', $hero);
        $this->assertStringContainsString('overflow-y: visible', $hero);
        $this->assertStringNotContainsString('overflow-x: hidden', $hero);
        $this->assertStringContainsString('overflow-x: visible', $hero);
        $this->assertStringContainsString("view()->exists('components.hero-catalog-preview')", $hero);
        $this->assertDoesNotMatchRegularExpression(
            '/#main-content[^{;]*\{[^}]*overflow-x:\s*clip/',
            $hero
        );
    }

    public function test_every_locale_homepage_uses_the_catalog_clone_hero(): void
    {
        $paths = ['/'];
        foreach (PublicI18n::prefixed() as $locale) {
            $paths[] = '/'.$locale;
        }

        foreach ($paths as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('slb-hero-catalog-clone', $html, $path);
            $this->assertStringContainsString('catalog-filters-card', $html, $path);
            $this->assertStringContainsString('catalog-table', $html, $path);
            $this->assertStringContainsString('berlin**.de', $html, $path);
            $this->assertStringNotContainsString('dashboard.png', $html, $path);
        }
    }

    public function test_teaser_service_diversifies_countries_before_filling(): void
    {
        $publisher = $this->publisher();

        $this->makeSite($publisher, [
            'site_name' => 'DE High',
            'domain' => 'de-high.de',
            'country' => 'de',
            'countries' => ['de'],
            'dr' => 90,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'DE Mid',
            'domain' => 'de-mid.de',
            'country' => 'de',
            'countries' => ['de'],
            'dr' => 80,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'US Site',
            'domain' => 'us-site.com',
            'country' => 'us',
            'countries' => ['us'],
            'dr' => 40,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'IT Site',
            'domain' => 'it-site.it',
            'country' => 'it',
            'countries' => ['it'],
            'dr' => 35,
        ]);

        $teasers = app(CatalogTeaserService::class)->teasers(3);

        $this->assertCount(3, $teasers);
        $countries = $teasers->pluck('country')->map(fn ($c) => strtolower((string) $c))->all();
        $this->assertContains('de', $countries);
        $this->assertContains('us', $countries);
        $this->assertContains('it', $countries);
        $this->assertSame(3, count(array_unique($countries)));
        $this->assertStringContainsString('**', (string) $teasers->first()['domain_masked']);
    }

    public function test_mask_domain_hides_the_middle_with_double_stars(): void
    {
        $service = app(CatalogTeaserService::class);

        $this->assertSame('berlin**.de', $service->maskDomain('berlin-editorial.de'));
        $this->assertSame('london**.co.uk', $service->maskDomain('www.london-trade.co.uk'));
        $this->assertSame('site**.com', $service->maskDomain(''));
    }

    private function assertHeroDemoScreenshotScores(string $html): void
    {
        preg_match_all(
            '/catalog-metric--dr[^>]*>\s*<span class="catalog-metric__value">(\d+)/',
            $html,
            $drMatches
        );
        preg_match_all(
            '/catalog-metric--da">\s*<span class="catalog-metric__value">(\d+)/',
            $html,
            $daMatches
        );

        $this->assertSame(['89', '89', '88'], $drMatches[1], $html);
        $this->assertSame(['83', '82', '83'], $daMatches[1], $html);
        $this->assertStringContainsString('€253.87', $html);
        $this->assertStringContainsString('€188.98', $html);
        $this->assertStringContainsString('€149.63', $html);
        $this->assertStringContainsString('627k', $html);
        $this->assertStringContainsString('580.4k', $html);
        $this->assertStringContainsString('501.2k', $html);
    }
}
