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
            'traffic' => 1000,
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

    public function test_homepage_shows_live_multi_country_preview_and_masks_domains(): void
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
            'traffic' => 220000,
            'category' => 'News & Politics',
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
            'traffic' => 180000,
            'category' => 'Lifestyle & Fashion',
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Spanish Travel',
            'domain' => 'spanish-travel.es',
            'site_url' => 'https://spanish-travel.es',
            'country' => 'es',
            'language' => 'es',
            'countries' => ['es'],
            'languages' => ['es'],
            'dr' => 62,
            'da' => 50,
            'traffic' => 140000,
            'category' => 'Travel & Tourism',
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->assertSee('Marketplace catalog', false)
            ->assertSee('All markets', false)
            ->assertSee('German News Hub', false)
            ->assertSee('French Lifestyle', false)
            ->assertSee('Spanish Travel', false)
            ->assertSee('Germany', false)
            ->assertSee('France', false)
            ->assertSee('Spain', false)
            ->assertSee('g********.de', false)
            ->assertSee('f********.fr', false)
            ->assertSee('s********.es', false)
            ->assertDontSee('advertiser/catalog', false)
            ->getContent();

        $this->assertStringContainsString('slb-hero-catalog__chip', $html);
        $this->assertStringContainsString('slb-hero-catalog__buy', $html);
        $this->assertStringNotContainsString('dashboard.png', $html);
        $this->assertStringNotContainsString('Live publisher catalog', $html);
    }

    public function test_homepage_shows_multi_country_showcase_when_no_live_sites(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertSee('Marketplace catalog', false)
            ->assertSee('All markets', false)
            ->assertSee('Germany', false)
            ->assertSee('France', false)
            ->assertSee('United States', false)
            ->assertSee('United Kingdom', false)
            ->assertDontSee('advertiser/catalog', false)
            ->getContent();

        $this->assertStringNotContainsString('dashboard.png', $html);
        $this->assertGreaterThanOrEqual(4, substr_count(strtolower($html), 'slb-hero-catalog__chip'));
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
        $this->assertStringContainsString('*', (string) $teasers->first()['domain_masked']);
    }

    public function test_showcase_tops_up_sparse_inventory_with_other_countries(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'Only Germany A',
            'domain' => 'only-de-a.de',
            'country' => 'de',
            'countries' => ['de'],
            'dr' => 90,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Only Germany B',
            'domain' => 'only-de-b.de',
            'country' => 'de',
            'countries' => ['de'],
            'dr' => 80,
        ]);

        $showcase = app(CatalogTeaserService::class)->showcase(7);
        $countries = $showcase->pluck('country')->map(fn ($c) => strtolower((string) $c))->unique()->values()->all();

        $this->assertCount(7, $showcase);
        $this->assertContains('de', $countries);
        $this->assertGreaterThanOrEqual(5, count($countries));
        $this->assertTrue($showcase->contains(fn ($row) => ($row['name'] ?? '') === 'Only Germany A'));
    }

    public function test_format_traffic_uses_compact_labels(): void
    {
        $service = app(CatalogTeaserService::class);

        $this->assertSame('427K', $service->formatTraffic(427000));
        $this->assertSame('1.3K', $service->formatTraffic(1300));
        $this->assertSame('1.1M', $service->formatTraffic(1100000));
        $this->assertSame('—', $service->formatTraffic(0));
    }
}
