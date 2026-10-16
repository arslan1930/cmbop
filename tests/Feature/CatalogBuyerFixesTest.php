<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogAdvertiserPriceFilter;
use App\Services\Catalog\CatalogFilterStatus;
use App\Services\Catalog\CatalogUrlQuery;
use App\Support\CatalogGoogleNews;
use App\Support\CatalogSaleBadge;
use App\Support\CatalogSensitiveTopics;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogBuyerFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Cache::flush();

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
            'display_currency' => 'EUR',
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->actingAs($this->advertiser);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function site(string $name, array $extra = []): Site
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name) ?: 'site');

        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 8000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'listing_currency' => 'EUR',
            'publication_time' => '7 days',
            'turnaround_time' => '3days',
            'link_type' => 'dofollow',
            'description' => 'Buyer catalog fixture.',
            'verified' => true,
            'active' => 1,
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function onSale(float $price, string $name, float $percent = 20, array $extra = []): Site
    {
        return $this->site($name, array_merge([
            'price' => $price,
            'custom_discount_percent' => $percent,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(7),
        ], $extra));
    }

    public function test_price_sort_and_from_header_use_discounted_buyer_pay(): void
    {
        $plain = $this->site('Plain List Price', ['price' => 100, 'dr' => 50]);
        $sale = $this->onSale(110, 'Discounted Floor Site', 20, ['dr' => 50]);

        $filter = app(CatalogAdvertiserPriceFilter::class);
        $plainPay = $filter->phpBuyerPay($plain->fresh(), 'EUR');
        $salePay = $filter->phpBuyerPay($sale->fresh(), 'EUR');
        $this->assertLessThan($plainPay, $salePay);

        $fromAttr = number_format($salePay, 2, '.', '');
        $html = $this->get(route('advertiser.catalog', ['sort' => 'price_asc']))
            ->assertOk()
            ->getContent();

        $salePos = strpos($html, 'Discounted Floor Site');
        $plainPos = strpos($html, 'Plain List Price');
        $this->assertNotFalse($salePos);
        $this->assertNotFalse($plainPos);
        $this->assertTrue($salePos < $plainPos, 'Buyer-pay asc must list the discounted site first');
        $this->assertStringContainsString('data-inventory-from="'.$fromAttr.'"', $html);

        $desc = $this->get(route('advertiser.catalog', ['sort' => 'price_desc']))
            ->assertOk()
            ->getContent();
        $this->assertTrue(
            strpos($desc, 'Plain List Price') < strpos($desc, 'Discounted Floor Site'),
            'Buyer-pay desc must list the undiscounted site first'
        );
    }

    public function test_out_of_range_page_redirects_to_last_page(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->site('Paged Buyer '.$i, ['dr' => 20 + $i, 'price' => 80]);
        }

        $this->get(route('advertiser.catalog', ['per_page' => 10, 'page' => 99]))
            ->assertRedirect(route('advertiser.catalog', ['per_page' => '10', 'page' => '3']));

        $last = $this->get(route('advertiser.catalog', ['per_page' => 10, 'page' => 3]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('21–25', $last);
        $this->assertStringNotContainsString('Showing –', $last);
        $this->assertStringNotContainsString('No publishers available yet', $last);

        $fragment = $this->get(route('advertiser.catalog.results', ['per_page' => 10, 'page' => 99]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('21–25', $fragment);
        $this->assertStringContainsString('data-current-page="3"', $fragment);
        $this->assertMatchesRegularExpression('/data-effective-query="[^"]*page[^"]*3/', $fragment);
        $this->assertStringNotContainsString('Showing –', $fragment);
        $this->assertStringNotContainsString('No publishers available yet', $fragment);
    }

    public function test_sensitive_chip_and_filter_exclude_casino(): void
    {
        $this->site('Crypto Add-on Site', [
            'sensitive_prices' => ['crypto' => 25, 'casino' => 90],
        ]);
        $this->site('Casino Only Site', [
            'sensitive_prices' => ['casino' => 80],
        ]);
        $this->site('Plain Niche Site');

        $all = $this->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('site-chip--sensitive', $all);
        $this->assertStringContainsString('Crypto', $all);
        $this->assertStringNotContainsString('site-chip--sensitive">Casino', $all);

        $filtered = $this->get(route('advertiser.catalog', ['sensitive_topics' => '1']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Crypto Add-on Site', $filtered);
        $this->assertStringNotContainsString('Casino Only Site', $filtered);
        $this->assertStringNotContainsString('Plain Niche Site', $filtered);
        $this->assertStringContainsString('Accepts sensitive topics', $filtered);
        $this->assertStringContainsString('id="catalogSensitiveTopics"', $filtered);
        $this->assertContains('sensitive_topics', CatalogUrlQuery::KEYS);
        $this->assertContains('sensitive_topics', CatalogFilterStatus::QUERY_KEYS);
        $this->assertNotContains('casino', CatalogSensitiveTopics::FILTER_TYPES);
    }

    public function test_discount_badge_matches_actual_percent_not_stored_nominal(): void
    {
        $this->onSale(304, 'Fee Floor Sale Site', 15);

        $html = $this->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $site = Site::query()->where('site_name', 'Fee Floor Sale Site')->firstOrFail();
        $prices = $site->catalogPricesForViewer($this->advertiser);
        $this->assertNotNull($prices['sale']);
        $badge = CatalogSaleBadge::label((float) $prices['list'], (float) $prices['sale']);
        $this->assertSame('10.7%', $badge);
        $this->assertSame(15.0, (float) $site->custom_discount_percent);
        $this->assertStringContainsString('10.7% off', $html);
        $this->assertStringNotContainsString('site-chip--sale', $html);
        $this->assertStringNotContainsString('−10.7%', $html);
        $this->assertStringNotContainsString('15% off', $html);
        $this->assertStringNotContainsString('−15%', $html);
    }

    public function test_new_filters_chips_and_language_multi_select(): void
    {
        Language::query()->updateOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'native_name' => 'English']
        );
        Language::query()->updateOrCreate(
            ['code' => 'de'],
            ['name' => 'German', 'native_name' => 'Deutsch']
        );

        $this->site('DoFollow Fast', [
            'link_type' => 'dofollow',
            'turnaround_time' => '24h',
            'language' => 'en',
            'languages' => ['en'],
        ]);
        $this->site('NoFollow Slow', [
            'link_type' => 'nofollow',
            'turnaround_time' => '7days',
            'language' => 'de',
            'languages' => ['de'],
            'price' => 200,
        ]);

        $shell = $this->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="catalogLinkType"', $shell);
        $this->assertStringContainsString('id="catalogTurnaroundMax"', $shell);
        $this->assertStringContainsString('Accepts sensitive topics', $shell);
        $this->assertStringContainsString('id="languageMultiTrigger"', $shell);
        $this->assertStringContainsString('aria-multiselectable="true"', $shell);
        if (! CatalogGoogleNews::isAvailable()) {
            $this->assertStringNotContainsString('id="catalogGoogleNews"', $shell);
        }

        $link = $this->get(route('advertiser.catalog', ['link_type' => 'nofollow']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('NoFollow Slow', $link);
        $this->assertStringNotContainsString('DoFollow Fast', $link);
        $this->assertStringContainsString('Remove filter: NoFollow', $link);

        $tat = $this->get(route('advertiser.catalog', ['turnaround_max' => '1']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('DoFollow Fast', $tat);
        $this->assertStringNotContainsString('NoFollow Slow', $tat);
        $this->assertStringContainsString('Turnaround: 1 day or faster', $tat);

        $priceChip = $this->get(route('advertiser.catalog', ['price_max' => 50]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Price ≤ €50', $priceChip);

        $langs = $this->get(route('advertiser.catalog', ['language' => 'en,de']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('English, German', $langs);
        $this->assertStringContainsString('DoFollow Fast', $langs);
        $this->assertStringContainsString('NoFollow Slow', $langs);
    }

    public function test_busy_pill_visible_details_and_no_claim_in_buyer_catalog(): void
    {
        $this->site('Buyer View Site');

        $html = $this->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertStringContainsString('class="catalog-results-busy"', $html);
        $this->assertStringContainsString('Updating results…', $html);
        $this->assertStringContainsString('function markCatalogResultsBusy(', $js);
        $this->assertStringContainsString('small non-blocking pill', $js);
        $this->assertStringContainsString('pointer-events: none', $css);
        $this->assertStringNotContainsString('.catalog-results-card.is-busy > .card-body', $css);

        $this->assertStringContainsString('expand-arrow catalog-details-toggle', $html);
        $this->assertStringContainsString('catalog-details-toggle__label">Details', $html);
        $this->assertStringNotContainsString('class="expand-arrow visually-hidden"', $html);
        $this->assertStringNotContainsString('btn-claim-site', $html);
        $this->assertStringNotContainsString('Claim website', $html);
    }
}
