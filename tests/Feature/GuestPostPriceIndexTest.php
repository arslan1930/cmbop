<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketing\GuestPostPriceIndex;
use App\Services\PlatformFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GuestPostPriceIndexTest extends TestCase
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
        $suffix = $overrides['domain'] ?? ('example-'.$country.'-'.uniqid().'.com');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Example Site',
            'site_url' => 'https://'.$suffix,
            'domain' => $suffix,
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
            'description' => 'Price index inventory',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_empty_catalog_renders_without_invented_prices(): void
    {
        $html = $this->get('/guest-post-prices-europe')->assertOk()->getContent();

        $this->assertStringContainsString('Guest Post Prices in Europe — Median by Country', $html);
        $this->assertStringContainsString('EU guest-post price index', $html);
        $this->assertStringContainsString('Index updates as the catalog grows', $html);
        $this->assertStringContainsString('How this index is calculated', $html);
        $this->assertStringContainsString('FAQPage', $html);
        $this->assertStringContainsString('Dataset', $html);
        $this->assertStringContainsString('rel="canonical" href="'.url('/guest-post-prices-europe').'"', $html);
        $this->assertStringContainsString('hreflang="en-GB"', $html);
        $this->assertStringContainsString(url('/register'), $html);
        $this->assertStringContainsString(localized_url('become-a-publisher'), $html);
        $this->assertStringContainsString('/guest-posts-germany', $html);
        $this->assertStringNotContainsString('data-price-index-kpi="europe-median"', $html);
        $this->assertStringNotContainsString('Median price by country', $html);
        $this->assertStringNotContainsString('AggregateRating', $html);
        $this->assertStringNotContainsString('advertiser/catalog', $html);
    }

    public function test_locale_prefix_keeps_english_canonical(): void
    {
        $this->get('/de/guest-post-prices-europe')
            ->assertOk()
            ->assertSee('rel="canonical" href="'.url('/guest-post-prices-europe').'"', false)
            ->assertSee('EU guest-post price index', false)
            ->assertDontSee('advertiser/catalog', false);
    }

    public function test_germany_median_uses_advertiser_checkout_and_excludes_us(): void
    {
        $fees = app(PlatformFeeService::class);
        $this->assertSame(113.0, $fees->advertiserBase(100));
        $this->assertSame(226.0, $fees->advertiserBase(200));
        $this->assertSame(336.0, $fees->advertiserBase(300));

        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'site_name' => 'DE Low',
            'domain' => 'de-low.example',
            'site_url' => 'https://de-low.example',
            'country' => 'de',
            'countries' => ['de'],
            'price' => 100,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'DE Mid',
            'domain' => 'de-mid.example',
            'site_url' => 'https://de-mid.example',
            'country' => 'de',
            'countries' => ['de'],
            'price' => 200,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'DE High',
            'domain' => 'de-high.example',
            'site_url' => 'https://de-high.example',
            'country' => 'de',
            'countries' => ['de'],
            'price' => 300,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'US One',
            'domain' => 'us-one.example',
            'site_url' => 'https://us-one.example',
            'country' => 'us',
            'countries' => ['us'],
            'price' => 50,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'US Two',
            'domain' => 'us-two.example',
            'site_url' => 'https://us-two.example',
            'country' => 'us',
            'countries' => ['us'],
            'price' => 60,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'US Three',
            'domain' => 'us-three.example',
            'site_url' => 'https://us-three.example',
            'country' => 'us',
            'countries' => ['us'],
            'price' => 70,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'DE Hidden',
            'domain' => 'de-hidden.example',
            'site_url' => 'https://de-hidden.example',
            'country' => 'de',
            'countries' => ['de'],
            'price' => 999,
            'verified' => false,
        ]);

        $snapshot = app(GuestPostPriceIndex::class)->snapshot();
        $this->assertTrue($snapshot['has_index']);
        $this->assertSame(226.0, $snapshot['europe']['median']);
        $this->assertSame(3, $snapshot['europe']['listings']);
        $this->assertCount(1, $snapshot['countries']);
        $this->assertSame('de', $snapshot['countries'][0]['code']);
        $this->assertSame(226.0, $snapshot['countries'][0]['median']);
        $this->assertSame(3, $snapshot['countries'][0]['listings']);
        $this->assertSame(url('/guest-posts-germany'), $snapshot['countries'][0]['lander_url']);

        $html = $this->get('/guest-post-prices-europe')->assertOk()->getContent();
        $this->assertStringContainsString('Europe median', $html);
        $this->assertStringContainsString('€226', $html);
        $this->assertStringContainsString('Germany', $html);
        $this->assertStringContainsString('/guest-posts-germany', $html);
        $this->assertStringNotContainsString('United States', $html);
        $this->assertStringNotContainsString('€999', $html);
        $this->assertStringNotContainsString('DE Hidden', $html);
        $this->assertStringNotContainsString('AggregateRating', $html);
    }

    public function test_country_row_requires_three_listings(): void
    {
        $publisher = $this->publisher();
        $this->makeSite($publisher, [
            'domain' => 'de-a.example',
            'site_url' => 'https://de-a.example',
            'country' => 'de',
            'countries' => ['de'],
            'price' => 100,
        ]);
        $this->makeSite($publisher, [
            'domain' => 'de-b.example',
            'site_url' => 'https://de-b.example',
            'country' => 'de',
            'countries' => ['de'],
            'price' => 200,
        ]);

        $snapshot = app(GuestPostPriceIndex::class)->snapshot();
        $this->assertFalse($snapshot['has_index']);
        $this->assertNull($snapshot['europe']['median']);
        $this->assertSame(2, $snapshot['europe']['listings']);
        $this->assertSame([], $snapshot['countries']);

        $this->get('/guest-post-prices-europe')
            ->assertOk()
            ->assertSee('Index updates as the catalog grows', false)
            ->assertDontSee('data-price-index-kpi="europe-median"', false)
            ->assertDontSee('Median price by country', false);
    }

    public function test_gb_primary_country_counts_as_united_kingdom(): void
    {
        $publisher = $this->publisher();
        foreach (['gb-a.example', 'gb-b.example', 'gb-c.example'] as $domain) {
            $this->makeSite($publisher, [
                'domain' => $domain,
                'site_url' => 'https://'.$domain,
                'country' => 'gb',
                'countries' => ['gb'],
                'price' => 100,
            ]);
        }

        $snapshot = app(GuestPostPriceIndex::class)->snapshot();
        $this->assertTrue($snapshot['has_index']);
        $this->assertSame('uk', $snapshot['countries'][0]['code']);
        $this->assertSame(3, $snapshot['countries'][0]['listings']);
        $this->assertSame(url('/guest-posts-uk'), $snapshot['countries'][0]['lander_url']);
        $this->assertStringContainsString('United Kingdom', $snapshot['countries'][0]['name']);
    }

    public function test_english_sitemap_lists_index_without_locale_copies(): void
    {
        $en = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/guest-post-prices-europe', $en);
        $this->assertStringNotContainsString('/de/guest-post-prices-europe', $en);
        $this->assertStringNotContainsString('/guest-post-prices-europe', $de);
    }

    public function test_robots_allows_price_index_path(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /guest-post-prices-europe', false);
    }
}
