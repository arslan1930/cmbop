<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketing\CatalogTeaserService;
use App\Support\CountryLander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CountryLanderPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

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
            'description' => 'Country lander inventory',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_every_lander_is_public_english_only(): void
    {
        foreach (CountryLander::all() as $key => $lander) {
            $slug = $lander['slug'];
            $this->get('/'.$slug)
                ->assertOk()
                ->assertSee($lander['title'], false)
                ->assertSee($lander['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/'.$slug).'"', false)
                ->assertSee('hreflang="en-GB"', false)
                ->assertSee('FAQPage', false)
                ->assertSee(url('/register'), false)
                ->assertSee(localized_url('become-a-publisher'), false)
                ->assertDontSee('advertiser/catalog', false);

            $this->get('/de/'.$slug)
                ->assertOk()
                ->assertSee('rel="canonical" href="'.url('/'.$slug).'"', false);
        }
    }

    public function test_germany_lander_shows_only_primary_de_teasers(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'Berlin Editorial',
            'domain' => 'berlin-editorial.de',
            'site_url' => 'https://berlin-editorial.de',
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'dr' => 70,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'US Magazine',
            'domain' => 'us-magazine.com',
            'site_url' => 'https://us-magazine.com',
            'country' => 'us',
            'countries' => ['us'],
            'dr' => 80,
        ]);

        $html = $this->get('/guest-posts-germany')->assertOk()->getContent();
        $this->assertStringContainsString('Berlin Editorial', $html);
        $this->assertStringContainsString('*', $html);
        $this->assertStringNotContainsString('US Magazine', $html);
        $this->assertStringNotContainsString('us-magazine.com', $html);
        $this->assertStringNotContainsString('berlin-editorial.de', $html);
    }

    public function test_uk_lander_uses_uk_not_gb(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'London Trade',
            'domain' => 'london-trade.co.uk',
            'site_url' => 'https://london-trade.co.uk',
            'country' => 'uk',
            'countries' => ['uk'],
            'language' => 'en',
            'languages' => ['en'],
        ]);

        $this->get('/guest-posts-uk')
            ->assertOk()
            ->assertSee('London Trade', false)
            ->assertSee('Guest posts in the UK', false);
    }

    public function test_teaser_service_filters_primary_country(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'DE Only',
            'domain' => 'de-only.de',
            'country' => 'de',
            'countries' => ['de', 'us'],
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'US Only',
            'domain' => 'us-only.com',
            'country' => 'us',
            'countries' => ['us', 'de'],
        ]);

        $teasers = app(CatalogTeaserService::class)->teasersForCountries(['de'], 8);
        $names = $teasers->pluck('name')->all();
        $this->assertContains('DE Only', $names);
        $this->assertNotContains('US Only', $names);
    }

    public function test_empty_inventory_still_renders_without_invented_stats(): void
    {
        $html = $this->get('/guest-posts-italy')->assertOk()->getContent();
        $this->assertStringContainsString('Guest posts in Italy', $html);
        $this->assertStringNotContainsString('Verified sites', $html);
        $this->assertStringContainsString('Other markets', $html);
    }

    public function test_english_sitemap_lists_landers_without_locale_copies(): void
    {
        $en = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();

        foreach (CountryLander::slugs() as $slug) {
            $this->assertStringContainsString('/'.$slug, $en);
            $this->assertStringNotContainsString('/de/'.$slug, $en);
            $this->assertStringNotContainsString('/'.$slug, $de);
        }
    }

    public function test_marketplace_links_to_landers(): void
    {
        $this->get('/marketplace')
            ->assertOk()
            ->assertSee('/guest-posts-germany', false)
            ->assertSee('/guest-posts-uk', false)
            ->assertSee('Germany', false)
            ->assertSee('country-lander-nav__card', false)
            ->assertSee('Guest posts by market', false)
            ->assertSee('/guest-post-prices-europe', false)
            ->assertSee('EU guest-post price index', false)
            ->assertSee(url('/register'), false)
            ->assertSee(localized_url('become-a-publisher'), false);
    }

    public function test_robots_allows_lander_paths(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /guest-posts-germany', false)
            ->assertSee('Allow: /guest-posts-netherlands', false);
    }
}
