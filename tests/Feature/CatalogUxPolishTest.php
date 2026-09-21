<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogUxPolishTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $this->advertiser->roles()->attach($advRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Polish Catalog Blog',
            'site_url' => 'https://polish-catalog.example',
            'domain' => 'polish-catalog.example',
            'example_url' => 'https://polish-catalog.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'categories' => ['Marketing'],
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Catalog UX polish fixture.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_closed_row_shows_in_cart_and_keeps_claim_in_details(): void
    {
        $site = $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                ]],
            ])
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('is-in-cart', $html);
        $this->assertStringContainsString('>In cart</span>', $html);
        $this->assertStringContainsString('btn-claim-site', $html);
        $this->assertStringContainsString('Is this your site?', $html);

        $actionStart = strpos($html, 'catalog-td-action');
        $detailsStart = strpos($html, 'catalog-site-details');
        $this->assertNotFalse($actionStart);
        $this->assertNotFalse($detailsStart);
        $closedBuy = substr($html, $actionStart, $detailsStart - $actionStart);
        $this->assertStringContainsString('buy-now', $closedBuy);
        $this->assertStringNotContainsString('btn-claim-site', $closedBuy);

        $cardBuy = strpos($html, 'catalog-card-buy');
        $this->assertNotFalse($cardBuy);
        $cardChunk = substr($html, $cardBuy, 1200);
        $this->assertStringNotContainsString('btn-claim-site', $cardChunk);
    }

    public function test_live_results_fragment_keeps_in_cart_state(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Live Cart Polish',
            'site_url' => 'https://live-cart-polish.example',
            'domain' => 'live-cart-polish.example',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                ]],
            ])
            ->get(route('advertiser.catalog.results', ['search' => 'Live Cart Polish']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="catalogResults"', $html);
        $this->assertStringContainsString('is-in-cart', $html);
        $this->assertStringContainsString('>In cart</span>', $html);
    }

    public function test_favorites_quick_chip_stays_beside_tag_select_in_more(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-catalog-favorites="1"', $html);
        $this->assertStringContainsString('id="catalogTagFilter"', $html);
        $this->assertStringContainsString('catalog-quick-filters', $html);
        $this->assertStringContainsString('catalog-tag-quick', $html);
        $this->assertStringContainsString('Browse publisher listings', $html);
        $this->assertStringContainsString('catalog-article-banner', $html);
        $this->assertStringContainsString('btn-suggest-website', $html);

        $barPos = strpos($html, 'catalog-results-bar');
        $suggestPos = strpos($html, 'btn-suggest-website');
        $tablePos = strpos($html, 'id="catalogResults"');
        $this->assertNotFalse($barPos);
        $this->assertNotFalse($suggestPos);
        $this->assertNotFalse($tablePos);
        $this->assertLessThan($suggestPos, $barPos);
        $this->assertLessThan($tablePos, $suggestPos);
    }

    public function test_rated_only_chip_avoids_the_forbidden_row_class(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Rated Polish Site',
            'site_url' => 'https://rated-polish.example',
            'domain' => 'rated-polish.example',
            'rating_avg' => 4.5,
            'rating_count' => 8,
            'completed_orders_count' => 6,
        ]);

        $completedAt = now()->subDay();
        $completed = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-'.uniqid(),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'completed_at' => $completedAt,
        ]);
        OrderItem::create([
            'order_id' => $completed->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'content_link' => 'https://example.com/article.docx',
            'publisher_status' => 'completed',
            'completed_at' => $completedAt,
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Rated Polish']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-trust-chip', $html);
        $this->assertStringContainsString('4.5', $html);
        $this->assertStringNotContainsString('catalog-site-trust--row', $html);
        $this->assertStringContainsString('8 ratings', $html);
    }

    public function test_unrated_closed_row_has_no_trust_chip(): void
    {
        $this->makeSite(['site_name' => 'Unrated Polish Site']);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Unrated Polish']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('site-trust-chip', $html);
        $this->assertStringContainsString('Awaiting first ratings', $html);
        $this->assertStringNotContainsString('catalog-site-trust--row', $html);
    }

    public function test_country_cell_carries_the_full_name_as_title(): void
    {
        $this->makeSite(['country' => 'us', 'countries' => ['us']]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/catalog-country__name[^>]*title="[^"]+"/',
            $html
        );
    }

    public function test_scripts_and_styles_cover_in_cart_clamp_and_favorites(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $results = (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-results.blade.php')
        );

        $this->assertStringContainsString('function markCatalogSiteInCart', $js);
        $this->assertStringContainsString('function initCatalogFavoritesQuick', $js);
        $this->assertStringContainsString('function syncFavoritesQuick', $js);
        $this->assertStringContainsString('window.openCart', $js);
        $this->assertStringContainsString("closest('article')", $js);
        $this->assertStringContainsString('-webkit-line-clamp: 2', $css);
        $this->assertStringContainsString('.buy-now.is-in-cart', $css);
        $this->assertStringContainsString('.site-trust-chip', $css);
        $this->assertStringNotContainsString('catalog-site-trust--row', $results);
        $this->assertStringContainsString("variant' => 'chip'", $results);
        $this->assertStringContainsString('col-lg-4 col-md-5 catalog-expand-preview', $results);
    }
}
