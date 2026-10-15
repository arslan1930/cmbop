<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\FasterPublisherPayoutsBlogPost;
use App\Support\HowToPriceYourSiteBlogPost;
use App\Support\WhySitesGetRejectedBlogPost;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\CountryLanguageSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublisherDashboardAuditRemainderTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CountryLanguageSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    public function test_dashboard_zero_sites_uses_all_listed_sites_verified(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('All listed sites verified', $html);
        $this->assertStringNotContainsString('Add a site to get listed', $html);
    }

    public function test_publisher_pages_use_specific_titles_and_matching_headings(): void
    {
        $this->actingAs($this->publisher)
            ->get(route('publisher.tasks'))
            ->assertOk()
            ->assertSee('<title>Tasks — SEOLinkBuildings</title>', false)
            ->assertSee('<h2 class="mb-1 fw-semibold">Tasks</h2>', false)
            ->assertSee('What Base means', false)
            ->assertSee('What Sensitive means', false)
            ->assertSee('What You earn means', false)
            ->assertSee('What Content Link means', false)
            ->assertSee('publisherOrderThread', false)
            ->assertSee('order-inline-thread.js', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('<title>My Sites — SEOLinkBuildings</title>', false)
            ->assertSee('>My Sites</h4>', false)
            ->assertSee('id="sitesNicheFilter"', false)
            ->assertSee('id="sitesDaMin"', false)
            ->assertSee('id="sitesPriceMax"', false)
            ->assertSee('mysites-toolbar', false)
            ->assertSee('Claim a website', false)
            ->assertSee('rounded-pill', false)
            ->assertSee('Minimum 20 characters', false)
            ->assertSee('1–2 business days', false)
            ->assertSee('claimLookup', false)
            ->assertSee('Valid category names, country codes, and language codes', false)
            ->assertSee('EUR or GBP', false)
            ->assertSee('at least 2 rows', false)
            ->assertDontSee('Own 150+ websites?', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee('<title>Withdraw — SEOLinkBuildings</title>', false)
            ->assertSee('<h2 class="mb-1 fw-semibold">Withdraw</h2>', false)
            ->assertSee('publisher-withdraw', false)
            ->assertDontSee('You need at least €', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.billing.index'))
            ->assertOk()
            ->assertSee('<title>Payout docs — SEOLinkBuildings</title>', false)
            ->assertSee('<h2 class="mb-1 fw-semibold">Payout docs</h2>', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.reports'))
            ->assertOk()
            ->assertSee('<title>Reports — SEOLinkBuildings</title>', false)
            ->assertSee('<h2 class="mb-1 fw-semibold">Reports</h2>', false);
    }

    public function test_my_sites_ajax_filters_by_niche_da_and_price(): void
    {
        $this->makeSite([
            'site_name' => 'News High',
            'site_url' => 'https://news-high.example',
            'domain' => 'news-high.example',
            'category' => 'News',
            'categories' => ['News'],
            'da' => 70,
            'price' => 200,
            'verified' => true,
            'active' => true,
        ]);
        $this->makeSite([
            'site_name' => 'Tech Low',
            'site_url' => 'https://tech-low.example',
            'domain' => 'tech-low.example',
            'category' => 'Tech',
            'categories' => ['Tech'],
            'da' => 20,
            'price' => 40,
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', [
                'status' => 'active',
                'niche' => 'News',
            ]))
            ->assertOk()
            ->assertSee('News High', false)
            ->assertDontSee('Tech Low', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', [
                'status' => 'active',
                'da_min' => 50,
            ]))
            ->assertOk()
            ->assertSee('News High', false)
            ->assertDontSee('Tech Low', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', [
                'status' => 'active',
                'price_max' => 50,
            ]))
            ->assertOk()
            ->assertSee('Tech Low', false)
            ->assertDontSee('News High', false);
    }

    public function test_site_submit_flash_includes_help_links(): void
    {
        Queue::fake();
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $response = $this->actingAs($this->publisher)->from(route('publisher.websites'))->post(route('publisher.sites.store'), [
            'siteName' => 'Help Link News',
            'siteUrl' => 'https://help-link-news.example',
            'exampleUrl' => 'https://help-link-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $category->name,
            'price' => 90,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $actions = session('success_actions');
        $this->assertIsArray($actions);
        $labels = collect($actions)->pluck('label')->all();
        $this->assertContains('Pricing tips', $labels);
        $this->assertContains('Content guidelines', $labels);
        $this->assertContains('Payout FAQ', $labels);
        $urls = collect($actions)->pluck('url')->implode(' ');
        $this->assertStringContainsString(HowToPriceYourSiteBlogPost::SLUG, $urls);
        $this->assertStringContainsString(WhySitesGetRejectedBlogPost::SLUG, $urls);
        $this->assertStringContainsString(FasterPublisherPayoutsBlogPost::SLUG, $urls);
    }

    public function test_claim_lookup_and_source_guards(): void
    {
        $this->makeSite([
            'publisher_id' => User::factory()->create(['email_verified_at' => now()])->id,
            'site_name' => 'Foreign List',
            'site_url' => 'https://foreign-list.example',
            'domain' => 'foreign-list.example',
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.claim-lookup', ['website_url' => 'https://foreign-list.example']))
            ->assertOk()
            ->assertJsonPath('listed', true)
            ->assertJsonPath('owned', false)
            ->assertJsonFragment(['message' => 'This URL matches a listed site. Submit proof of at least 20 characters — review usually takes 1–2 business days.']);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.claim-lookup', ['website_url' => 'https://missing-claim.example']))
            ->assertOk()
            ->assertJsonPath('listed', false);
    }

    public function test_remaining_source_guards_cover_wizard_bulk_bell_and_thread(): void
    {
        $websites = (string) file_get_contents(resource_path('views/publisher/websites.blade.php'));
        $this->assertStringContainsString('listingSymbolFromCurrencySelect', $websites);
        $this->assertStringContainsString("return code === 'GBP' ? '£' : '€'", $websites);
        $this->assertStringContainsString('prefixHttps', $websites);
        $this->assertStringContainsString("title: 'Almost there'", $websites);
        $this->assertStringContainsString('wizardFieldLabel', $websites);
        $this->assertStringContainsString('focusWizardField', $websites);
        $this->assertStringContainsString('hasContent', $websites);
        $this->assertStringContainsString('is-readonly', $websites);
        $this->assertStringContainsString('publisher-wizard-open', $websites);
        $this->assertStringContainsString('SiteTag::helpText()', $websites);
        $this->assertStringContainsString('Moz Domain Authority', $websites);
        $this->assertStringContainsString('Ahrefs Domain Rating', $websites);
        $this->assertStringContainsString('PublicationDuration::helpText()', $websites);
        $this->assertStringContainsString('bulkPasteUrlsWarning', $websites);

        $css = (string) file_get_contents(public_path('assets/css/publisher-websites.css'));
        $this->assertStringContainsString('z-index: 1100', $css);
        $this->assertStringContainsString('#languageWrapper.is-readonly', $css);
        $this->assertStringContainsString('bottom: calc(100% + 4px)', $css);

        $shell = (string) file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('.slb-currency-label', $shell);
        $this->assertStringContainsString('pointer-events: none', $shell);
        $this->assertStringContainsString('body.publisher-wizard-open .slb-live-chat', $shell);

        $pulse = (string) file_get_contents(public_path('js/pulse-badge.js'));
        $assetsPulse = (string) file_get_contents(public_path('assets/js/pulse-badge.js'));
        $this->assertSame(md5($pulse), md5($assetsPulse));
        $this->assertStringContainsString("el.textContent = ''", $pulse);

        $thread = (string) file_get_contents(public_path('js/order-inline-thread.js'));
        $assetsThread = (string) file_get_contents(public_path('assets/js/order-inline-thread.js'));
        $this->assertSame(md5($thread), md5($assetsThread));
        $this->assertStringContainsString('/chat/messages/', $thread);
        $this->assertStringContainsString('/chat/send/', $thread);

        $orders = (string) file_get_contents(public_path('assets/js/advertiser-orders.js'));
        $this->assertStringContainsString('advertiserOrderThread', $orders);
        $this->assertStringContainsString('OrderInlineThread.mount', $orders);

        $bulk = (string) file_get_contents(public_path('assets/js/publisher-websites-bulk.js'));
        $this->assertStringContainsString('skipped', $bulk);
        $this->assertStringContainsString('listingPriceSymbol', $bulk);
        $this->assertStringContainsString('showImportWarning', $bulk);

        $switcher = (string) file_get_contents(resource_path('views/partials/currency-switcher.blade.php'));
        $this->assertStringContainsString('slb-currency-label', $switcher);
        $this->assertStringContainsString('Display currency (saved on your account)', $switcher);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Audit Site',
            'site_url' => 'https://audit-site.example',
            'domain' => 'audit-site.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'description' => 'Publisher site used by the remaining audit tests.',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }
}
