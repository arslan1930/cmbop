<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketing\CatalogTeaserService;
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
            ->assertSee('Buy Now', false)
            ->assertSee('Niche', false)
            ->assertSee('Backlinks', false)
            ->assertSee('berlin**.de', false)
            ->assertSee('munich**.net', false)
            ->assertSee('hamburg**.de', false)
            ->assertSee('Germany', false)
            ->assertDontSee('Demo Site', false)
            ->assertDontSee('German News Hub', false)
            ->assertDontSee('Add to cart', false)
            ->assertDontSee('dashboard.png', false)
            ->assertDontSee('French Lifestyle', false)
            ->assertDontSee('french-lifestyle.fr', false)
            ->assertDontSee('german-news-hub.de', false)
            ->assertDontSee('advertiser/catalog', false)
            ->getContent();

        $this->assertStringContainsString('**', $html);
    }

    public function test_homepage_always_shows_catalog_table_even_without_sites(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Publisher catalog preview', false)
            ->assertSee('Buy Now', false)
            ->assertSee('Germany', false)
            ->assertSee('berlin**.de', false)
            ->assertSee('munich**.net', false)
            ->assertSee('hamburg**.de', false)
            ->assertDontSee('Demo Site', false)
            ->assertDontSee('Add to cart', false)
            ->assertDontSee('dashboard.png', false)
            ->assertDontSee('advertiser/catalog', false);
    }

    public function test_hero_catalog_preview_keeps_full_table_readable_on_narrow_viewports(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('slb-hero-shot-table', $html);
        $this->assertStringContainsString('slb-hero-catalog-clone', $html);
        $this->assertStringContainsString('min-width: 720px', $html);
        $this->assertStringContainsString('overscroll-behavior-x: contain', $html);

        $hero = (string) file_get_contents(resource_path('views/components/hero.blade.php'));
        $this->assertStringContainsString('overflow-x: visible', $hero);
        $this->assertDoesNotMatchRegularExpression(
            '/#main-content[^{;]*\{[^}]*overflow-x:\s*clip/',
            $hero
        );
        $this->assertStringNotContainsString('overflow-x: clip;', file_get_contents(resource_path('views/layouts/app.blade.php')));
        $this->assertStringNotContainsString('overflow-x: clip;', file_get_contents(resource_path('views/components/navbar.blade.php')));
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
}
