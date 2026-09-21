<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserBlacklist;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk discount deals sit under the Spendable banner (above the Catalog
 * heading) in fixed batches of six with a centered page pager
 * (← 1 2 3 → + Page X of Y), a smooth R→L translateX slideshow, trackpad/pointer
 * swipe between pages, and a site search beside Hide — not a wrapping grid
 * or a horizontal scrollbar rail.
 */
class CatalogBulkDealRailTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    private function makeBulkSite(int $index, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Bulk Deal Site '.$index,
            'site_url' => 'https://bulk-deal-'.$index.'.example',
            'domain' => 'bulk-deal-'.$index.'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 20000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'A listing that joined the bulk discount programme.',
            'verified' => true,
            'active' => 1,
            'bulk_discount_enabled' => 1,
            'bulk_discount_percent' => 10,
        ], $overrides));
    }

    private function catalogHtml(array $query = []): string
    {
        return (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', $query))
            ->assertOk()
            ->getContent();
    }

    public function test_the_deals_render_as_a_paged_batch_rail(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-bulk-rail', $html);
        $this->assertStringContainsString('data-bulk-viewport', $html);
        $this->assertStringContainsString('catalog-bulk-viewport', $html);
        $this->assertStringContainsString('data-bulk-track', $html);
        $this->assertStringContainsString('data-bulk-page-size="6"', $html);
        $this->assertStringContainsString('data-bulk-pager', $html);
        $this->assertStringContainsString('data-bulk-page-label', $html);
        $this->assertStringContainsString('catalog-bulk-section is-collapsed', $html);
        $this->assertStringContainsString('data-bulk-start="collapsed"', $html);

        // The grid columns are what let the section wrap onto extra rows.
        $this->assertStringNotContainsString('<div class="col-md-4 col-lg-3">', $html);
    }

    public function test_the_header_counts_deals_and_offers_search_beside_hide(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-bulk-count">7<', $html);
        $this->assertStringContainsString('data-bulk-search', $html);
        $this->assertStringContainsString('placeholder="Search deal by site"', $html);
        $this->assertStringContainsString('data-bulk-toggle', $html);
        $this->assertStringContainsString('No bulk deal for this site.', $html);
        $this->assertStringContainsString('aria-label="Previous bulk deals page"', $html);
        $this->assertStringContainsString('aria-label="Next bulk deals page"', $html);
    }

    public function test_a_deal_card_shows_name_https_url_and_tld(): void
    {
        $this->makeBulkSite(1);

        $html = $this->catalogHtml();

        // Unmasked rail: full name + https:// root URL + TLD chip; search covers all.
        $this->assertStringContainsString('bulk-deal-card__name', $html);
        $this->assertStringContainsString('>Bulk Deal Site 1<', $html);
        $this->assertStringContainsString('bulk-deal-card__url', $html);
        $this->assertStringContainsString('>https://bulk-deal-1.example<', $html);
        $this->assertStringContainsString('bulk-deal-card__tld', $html);
        $this->assertStringContainsString('>.example<', $html);
        $this->assertStringContainsString('data-bulk-deal-card', $html);
        $this->assertMatchesRegularExpression(
            '/data-bulk-search-text="[^"]*bulk deal site 1[^"]*https:\/\/bulk-deal-1\.example[^"]*\.example/',
            $html
        );
    }

    public function test_bulk_deals_follow_catalog_country_filter(): void
    {
        $this->makeBulkSite(1, [
            'site_name' => 'US Bulk Deal',
            'site_url' => 'https://us-bulk.example',
            'domain' => 'us-bulk.example',
            'country' => 'us',
            'countries' => ['us'],
        ]);
        $this->makeBulkSite(2, [
            'site_name' => 'DE Bulk Deal',
            'site_url' => 'https://de-bulk.example',
            'domain' => 'de-bulk.example',
            'country' => 'de',
            'countries' => ['de'],
        ]);

        $deHtml = $this->catalogHtml(['country' => 'de']);
        $this->assertStringContainsString('DE Bulk Deal', $deHtml);
        $this->assertStringContainsString('https://de-bulk.example', $deHtml);
        $this->assertStringNotContainsString('US Bulk Deal', $deHtml);
        $this->assertStringNotContainsString('https://us-bulk.example', $deHtml);

        $allHtml = $this->catalogHtml();
        $this->assertStringContainsString('US Bulk Deal', $allHtml);
        $this->assertStringContainsString('DE Bulk Deal', $allHtml);
    }

    public function test_bulk_deals_fragment_endpoint_respects_country(): void
    {
        $this->makeBulkSite(1, [
            'site_name' => 'AT Bulk Deal',
            'site_url' => 'https://at-bulk.example',
            'domain' => 'at-bulk.example',
            'country' => 'at',
            'countries' => ['at'],
        ]);
        $this->makeBulkSite(2, [
            'site_name' => 'FR Bulk Deal',
            'site_url' => 'https://fr-bulk.example',
            'domain' => 'fr-bulk.example',
            'country' => 'fr',
            'countries' => ['fr'],
        ]);

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['country' => 'at']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('AT Bulk Deal', $html);
        $this->assertStringContainsString('https://at-bulk.example', $html);
        $this->assertStringNotContainsString('FR Bulk Deal', $html);
    }

    public function test_the_rail_keeps_a_six_up_grid_without_horizontal_scroll(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makeBulkSite($i);
        }

        $html = $this->catalogHtml();
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        // Master may still render two bulk rails (duplicate markup); each lists all deals.
        $rails = max(1, substr_count($html, 'data-bulk-rail'));
        $this->assertSame(12 * $rails, substr_count($html, 'bulk-deal-card__cta'));

        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-page-panel \{[^}]*grid-template-columns: repeat\(6,/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-rail \{[^}]*transition:\s*transform/s',
            $css
        );
        $this->assertStringContainsString('.catalog-bulk-viewport', $css);
        $this->assertStringContainsString('overflow: hidden', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-bulk-rail \{[^}]*overflow-x:\s*auto;/s',
            $css
        );
        $this->assertStringContainsString('justify-content: center', $css);
        $this->assertStringContainsString('.catalog-bulk-pager', $css);
        // Square (not pill) arrows and page numbers.
        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-nav \{[^}]*border-radius:\s*var\(--radius-sm,\s*6px\);/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-page \{[^}]*border-radius:\s*var\(--radius-sm,\s*6px\);/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-bulk-nav \{[^}]*border-radius:\s*var\(--radius-pill,\s*999px\);/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-bulk-page \{[^}]*border-radius:\s*var\(--radius-pill,\s*999px\);/s',
            $css
        );
        // Mist wash stays lighter than brand-primary-tint (#f4fbfb), with hover
        // colour/shadow feedback — no translateY (that shifted the rail layout).
        $this->assertStringContainsString(
            'background: linear-gradient(180deg, #fbfdfe 0%, #ffffff 62%)',
            $css
        );
        $this->assertStringNotContainsString(
            'background: linear-gradient(180deg, var(--brand-primary-tint, #f4fbfb) 0%, var(--surface-1, #fff) 100%)',
            $css
        );
        $this->assertStringContainsString('.catalog-bulk-section .bulk-deal-card:hover', $css);
        // A — stronger teal border + ring on hover (was 0.28, no ring).
        $this->assertStringContainsString('border-color: rgba(26, 88, 94, 0.45)', $css);
        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-section \.bulk-deal-card:hover[\s\S]*?0 0 0 3px rgba\(26, 88, 94, 0\.14\)/s',
            $css
        );
        // C — dim non-hovered siblings in the section (search matches stay full).
        $this->assertStringContainsString(
            '.catalog-bulk-section:has(.bulk-deal-card:hover) .bulk-deal-card:not(:hover):not(:focus-within):not(.is-bulk-match)',
            $css
        );
        $this->assertStringContainsString('opacity: 0.72', $css);
        // Search match stays one step stronger than hover (4px ring + teal wash).
        $this->assertMatchesRegularExpression(
            '/\.catalog-bulk-section \.bulk-deal-card\.is-bulk-match \{[^}]*0 0 0 4px rgba\(26, 88, 94, 0\.18\)/s',
            $css
        );
        $this->assertStringContainsString(
            'background: linear-gradient(165deg, #eef8f9 0%, #e4f4f5 48%, #ffffff 100%)',
            $css
        );
        // Match:hover must follow plain hover in source order so wash/ring stay on top.
        $hoverPos = strpos($css, '.catalog-bulk-section .bulk-deal-card:hover,');
        $matchHoverPos = strpos($css, '.catalog-bulk-section .bulk-deal-card.is-bulk-match:hover');
        $this->assertNotFalse($hoverPos);
        $this->assertNotFalse($matchHoverPos);
        $this->assertGreaterThan($hoverPos, $matchHoverPos);
        // CTA fill on hover kept.
        $this->assertMatchesRegularExpression(
            '/\.bulk-deal-card:hover \.bulk-deal-card__cta[\s\S]*?background: var\(--brand-primary/s',
            $css
        );
        $this->assertStringNotContainsString('transform: translateY(-3px)', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.bulk-deal-card[^{]*\{[^}]*transform:\s*translateY/s',
            $css
        );
    }

    public function test_the_rail_script_pages_searches_and_autoplays(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function initBulkDealRail(', $js);
        $this->assertStringContainsString("'catalog.bulkDeals.collapsed'", $js);
        $this->assertStringContainsString('data-bulk-page-size', $js);
        $this->assertStringContainsString('Page ', $js);
        $this->assertStringContainsString('of ', $js);
        $this->assertStringContainsString('AUTOPLAY_MS', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('data-bulk-search-text', $js);
        $this->assertStringContainsString('is-bulk-match', $js);
        $this->assertStringContainsString('function canAutoplay(', $js);
        $this->assertStringContainsString('pointerInside', $js);
        $this->assertStringContainsString('swipeToAdjacentPage', $js);
        $this->assertStringContainsString("addEventListener('wheel'", $js);
        $this->assertStringContainsString('SWIPE_COOLDOWN_MS', $js);
        $this->assertStringContainsString('translateX(-', $js);
        $this->assertStringContainsString('rebuildPanels', $js);
        $this->assertStringContainsString('catalog-bulk-page-panel', $js);
        $this->assertStringContainsString('data-bulk-viewport', $js);
        $this->assertStringContainsString('function syncPanelInert(', $js);
        $this->assertStringContainsString("setAttribute('inert'", $js);
        $this->assertStringContainsString("setAttribute('aria-hidden', 'true')", $js);

        // Blocked localStorage must not take the toggle down with it.
        $this->assertStringContainsString('function bulkRailReadCollapsed(', $js);
        $this->assertStringContainsString("getAttribute('data-bulk-start') === 'open'", $js);
        $this->assertStringContainsString('return false;', $js);

        // Bulk CTAs pass a fixed pack (data-bulk-qty) into addToCart.
        $this->assertStringContainsString('cartOptions.bulk = true', $js);
        $this->assertStringContainsString('button.dataset.bulkQty', $js);
        $this->assertStringContainsString('window.initBulkDealRail', $js);
        $this->assertStringContainsString('window.destroyBulkDealRail', $js);
        $this->assertStringContainsString('function refreshBulkDeals(', $js);
        $this->assertStringContainsString('bulkDealsEndpoint', $js);
        $this->assertStringContainsString('lastBulkFilterKey', $js);
        $this->assertStringContainsString('bulkAbortController', $js);
        $this->assertStringContainsString('lastBulkFilterKey === filterKey', $js);
    }

    public function test_https_rooted_url_keeps_www_on_bulk_cards(): void
    {
        $this->makeBulkSite(1, [
            'site_name' => 'WWW Bulk Deal',
            'site_url' => 'http://www.www-bulk.example/path/to/page',
            'domain' => 'www.www-bulk.example',
        ]);

        $html = $this->catalogHtml();

        $this->assertMatchesRegularExpression(
            '/bulk-deal-card__url[^>]*>https:\/\/www\.www-bulk\.example</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/bulk-deal-card__url[^>]*>https?:\/\/www-bulk\.example</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/bulk-deal-card__url[^>]*>http:\/\//',
            $html
        );
    }

    public function test_bulk_deals_blacklist_only_mode_matches_listing(): void
    {
        $kept = $this->makeBulkSite(1, [
            'site_name' => 'Blacklisted Bulk',
            'site_url' => 'https://blocked-bulk.example',
            'domain' => 'blocked-bulk.example',
        ]);
        $this->makeBulkSite(2, [
            'site_name' => 'Open Bulk',
            'site_url' => 'https://open-bulk.example',
            'domain' => 'open-bulk.example',
        ]);

        UserBlacklist::create([
            'user_id' => $this->advertiser->id,
            'site_id' => $kept->id,
        ]);

        $html = $this->catalogHtml(['blacklist_filter' => '1']);

        $this->assertStringContainsString('Blacklisted Bulk', $html);
        $this->assertStringNotContainsString('Open Bulk', $html);
    }

    public function test_bulk_fragment_empty_when_country_has_no_deals(): void
    {
        $this->makeBulkSite(1, [
            'country' => 'us',
            'countries' => ['us'],
        ]);

        $html = (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['country' => 'li']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-bulk-rail', $html);
        $this->assertStringNotContainsString('Bulk Deal Site', $html);
    }

    public function test_bulk_deals_sit_below_spendable_and_above_catalog_heading(): void
    {
        $this->makeBulkSite(1);

        // Spendable banner only renders when bonus > 0 — credit a welcome bonus.
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => $advertiserRole->id,
            'balance' => 30,
            'reserved_balance' => 0,
            'bonus_balance' => 20,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $html = $this->catalogHtml();

        $spendablePos = strpos($html, 'Spendable <strong>');
        $bulkPos = strpos($html, 'data-bulk-rail');
        $filtersPos = strpos($html, 'id="catalogFiltersPanel"');
        $resultsPos = strpos($html, 'id="catalogResults"');

        $this->assertNotFalse($spendablePos);
        $this->assertNotFalse($bulkPos);
        $this->assertNotFalse($filtersPos);
        $this->assertNotFalse($resultsPos);
        // Under Spendable, above filters + results — never duplicated.
        $this->assertLessThan($bulkPos, $spendablePos);
        $this->assertLessThan($filtersPos, $bulkPos);
        $this->assertLessThan($resultsPos, $bulkPos);
        $this->assertSame(1, substr_count($html, 'data-bulk-rail'));
        $this->assertSame(1, substr_count($html, 'id="bulkDealsRail"'));
    }

    public function test_the_section_is_absent_when_no_publisher_joined(): void
    {
        $html = $this->catalogHtml();

        $this->assertStringNotContainsString('catalog-bulk-rail', $html);
    }

    public function test_rail_badge_follows_better_of_when_custom_beats_bulk(): void
    {
        $site = $this->makeBulkSite(1);
        $site->update([
            'bulk_discount_percent' => 15,
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->catalogHtml();

        // Pack “now” floors at publisher payout; badge shows effective ~11.5%, not nominal 20/15.
        $this->assertMatchesRegularExpression('/bulk-deal-card__pct[\s\S]*?Sale −11\.5%/', $html);
        $this->assertStringNotContainsString('Sale −20%', $html);
        $this->assertStringNotContainsString('Bulk −15%', $html);
        $this->assertStringContainsString('Site sale applies on this pack', $html);
    }

    public function test_rail_badge_keeps_bulk_percent_when_bulk_beats_custom(): void
    {
        $site = $this->makeBulkSite(1);
        $site->update([
            'bulk_discount_percent' => 15,
            'custom_discount_percent' => 10,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->catalogHtml();

        // Pack floors to the same €100 “now”; badge is effective, not nominal −15%.
        $this->assertMatchesRegularExpression(
            '/bulk-deal-card__pct[\s\S]*?−11\.5%/',
            $html
        );
        $this->assertStringNotContainsString('Sale −10%', $html);
        $this->assertStringNotContainsString('Sale −15%', $html);
        $this->assertStringNotContainsString('Sale −11.5%', $html);
    }
}
