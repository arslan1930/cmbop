<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalog listed publisher sites as a wall of monospace domains and bare
 * numbers: "55" with nothing to say whether 55 was good, two lines of grey prose
 * per row, and the price wedged inside the Buy button. This pins the visual
 * language that replaced it.
 */
class CatalogVisualLanguageTest extends TestCase
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

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Visual Language Site',
            'site_url' => 'https://visual-language.example',
            'domain' => 'visual-language.example',
            'da' => 44,
            'dr' => 72,
            'traffic' => 42000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 100,
            'publication_time' => '7 days',
            'turnaround_time' => '48h',
            'link_type' => 'dofollow',
            'description' => 'A listing used to pin the catalog visual language.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    private function catalogHtml(array $query = []): string
    {
        return (string) $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', $query))
            ->assertOk()
            ->getContent();
    }

    public function test_metrics_carry_a_bar_so_a_number_has_a_scale(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        // DR 72 of 100 is a 72% fill; DA 44 is 44%.
        $this->assertStringContainsString('catalog-metric__fill" style="width: 72%"', $html);
        $this->assertStringContainsString('catalog-metric__fill" style="width: 44%"', $html);

        // Past 70 the fill deepens, so standouts are visible without colour-coding
        // the whole scale — which would pass judgement on publisher inventory.
        $this->assertStringContainsString('catalog-metric--dr is-standout', $html);
        $this->assertStringContainsString('catalog-metric--da', $html);
        $this->assertStringContainsString('catalog-metric--dr', $html);

        // Screen readers get the number and its scale, not a bare digit.
        $this->assertStringContainsString('Ahrefs Domain Rating 72 out of 100', $html);
        $this->assertStringContainsString('Moz Domain Authority 44 out of 100', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        // DA bar tracks Moz brand blue (battery charged blue).
        $this->assertStringContainsString('.catalog-metric--da .catalog-metric__fill', $css);
        $this->assertStringContainsString('background: #24abe2', $css);
        // Bars stay readable under the number (track + fill, not a 1px hairline).
        $this->assertMatchesRegularExpression(
            '/\.catalog-metric__bar \{[\s\S]*?height:\s*6px;/',
            $css
        );
        $this->assertStringContainsString('catalog-metric__bar', $html);

        // Country is flag over name (previous catalog layout), larger emoji flag.
        $this->assertMatchesRegularExpression(
            '/\.catalog-country \{[\s\S]*?flex-direction:\s*column;/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-country__flag \{[\s\S]*?font-size:\s*22px;/',
            $css
        );
        $this->assertStringContainsString('catalog-country__flag', $html);

        // Row data is vertically centered in each cell block.
        $this->assertMatchesRegularExpression(
            '/\.catalog-page \.table tbody td \{[\s\S]*?vertical-align:\s*middle;/',
            $css
        );
    }

    public function test_traffic_is_compact_on_screen_and_exact_in_the_title(): void
    {
        $this->makeSite(['traffic' => 1450000]);

        $html = $this->catalogHtml();

        // A column of "1,450,000" crowds out everything beside it.
        $this->assertStringContainsString('>1.5M<', $html);
        $this->assertStringContainsString('1,450,000 monthly visits', $html);
    }

    public function test_traffic_uses_a_log_scale_so_small_sites_are_not_all_zero(): void
    {
        $this->makeSite(['traffic' => 800, 'domain' => 'small.example', 'site_url' => 'https://small.example']);

        $html = $this->catalogHtml();

        // On a linear scale against millions, 800 visits would render as an empty
        // bar and be indistinguishable from a site with none.
        $this->assertMatchesRegularExpression(
            '/catalog-metric__fill" style="width: (4[0-9]|5[0-9])(\.\d)?%"/',
            $html
        );
    }

    public function test_each_listing_gets_a_monogram_tile_from_the_label_on_screen(): void
    {
        $site = $this->makeSite();

        $html = $this->catalogHtml();

        // Outside hide mode the label is the real host "visual-language.example",
        // so the tile initials are VL (first letters of the hyphenated segment).
        $this->assertStringContainsString('catalog-tile catalog-tile--md', $html);
        $this->assertStringContainsString('catalog-tile catalog-tile--lg', $html);
        $this->assertMatchesRegularExpression('/catalog-tile--tone[1-6]/', $html);
        $this->assertStringContainsString('>VL</span>', $html);
        unset($site);
    }

    public function test_the_tile_never_reveals_a_masked_host(): void
    {
        // Hide mode masks the host as "secr***.example"; the tile must use that
        // label (SE), never the real hyphenated initials (SI).
        $this->advertiser->forceFill([
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        $this->makeSite([
            'site_name' => 'Secret Inventory',
            'site_url' => 'https://secret-inventory.example',
            'domain' => 'secret-inventory.example',
        ]);

        $html = $this->catalogHtml();

        $this->assertStringNotContainsString('secret-inventory.example', $html);
        $this->assertStringContainsString('>SE</span>', $html);
    }

    public function test_the_price_has_its_own_block_beside_the_button(): void
    {
        $this->makeSite([
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->catalogHtml();

        // The CTA used to read "Buy €113.00 €90.40" — three pieces of text in one
        // control. €100 + 13% fee = €113 list; 20% off floors at the publisher
        // payout (€100) because discounts can only consume the portal fee.
        // Label shows effective savings (€13 / €113 ≈ 11.5%), not the nominal 20%.
        $this->assertStringContainsString('catalog-price__pay base-price-display">€100.00', $html);
        $this->assertStringContainsString('catalog-price__list list-price-display', $html);
        $this->assertStringContainsString('>€113.00<', $html);
        $this->assertStringContainsString('catalog-price__offer', $html);
        $this->assertStringContainsString('11.5% off', $html);
        $this->assertStringNotContainsString('20% off', $html);

        // The button now says what it does rather than carrying the number.
        $this->assertStringContainsString('>Add to cart</span>', $html);
    }

    public function test_a_listing_without_an_offer_hides_the_struck_price(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        $this->assertStringContainsString('list-price-display" hidden>', $html);
        $this->assertStringNotContainsString('catalog-price__offer', $html);
    }

    public function test_the_repeated_row_facts_are_chips_not_two_lines_of_prose(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        $this->assertStringContainsString('catalog-meta-chip', $html);
        $this->assertStringContainsString('DoFollow', $html);
        $this->assertStringContainsString('>48 hours<', $html);

        // The old prose lines added height to every row and read slowly.
        $this->assertStringNotContainsString('Max 03 DoFollow links</div>', $html);
        $this->assertStringNotContainsString('Turnaround: 48h', $html);
        $this->assertStringNotContainsString('3 dofollow', $html);
    }

    public function test_each_metric_column_is_marked_with_the_tool_it_comes_from(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        $this->assertStringContainsString('ahref.jpeg', $html);
        $this->assertStringContainsString('moz_da.png', $html);
        $this->assertStringContainsString('traffic.svg', $html);

        // The wording still explains the score; the mark only labels the source.
        $this->assertStringContainsString('Ahrefs Domain Rating', $html);
        $this->assertStringContainsString('Moz Domain Authority', $html);
        $this->assertStringContainsString('Source: Ahrefs', $html);
        $this->assertStringContainsString('Source: Moz', $html);
    }

    public function test_the_source_mark_is_not_repeated_on_every_row(): void
    {
        // Three listings: as per-row crops this was nine logos on screen, which is
        // what made the table noisy the first time round.
        $this->makeSite();
        $this->makeSite([
            'site_name' => 'Second Listing',
            'site_url' => 'https://second-listing.example',
            'domain' => 'second-listing.example',
        ]);
        $this->makeSite([
            'site_name' => 'Third Listing',
            'site_url' => 'https://third-listing.example',
            'domain' => 'third-listing.example',
        ]);

        $html = $this->catalogHtml();

        // Once in the table head, once per card in the layout that has no head.
        $this->assertSame(4, substr_count($html, 'ahref.jpeg'));
        $this->assertSame(4, substr_count($html, 'moz_da.png'));

        // Decorative: the heading and its tip already name the tool, so the logo
        // would only repeat it to a screen reader.
        $this->assertStringContainsString('class="metric-source metric-source--md metric-source--fit-cover"', $html);
        $this->assertStringContainsString('metric-source--blend-multiply', $html);
        $this->assertStringNotContainsString('alt="Ahrefs"', $html);

        // Intrinsic 400×400 Ahrefs / 225×225 Moz must not size the thead in Chrome.
        $this->assertMatchesRegularExpression(
            '/ahref\.jpeg"[^>]*\bwidth="20"[^>]*\bheight="20"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/moz_da\.png"[^>]*\bwidth="20"[^>]*\bheight="20"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/traffic\.svg"[^>]*\bwidth="20"[^>]*\bheight="20"/',
            $html
        );
    }

    public function test_metric_source_marks_match_the_sticky_header_surface(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        // White chips on the mint-grey sticky head looked pasted on.
        $this->assertStringContainsString(
            '.catalog-page .table thead th .metric-source',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/thead th \.metric-source \{[\s\S]*?background:\s*transparent/',
            $css
        );
        $this->assertStringContainsString('metric-source--blend-multiply img', $css);
        $this->assertStringContainsString('mix-blend-mode: multiply', $css);
        // Hard caps so Chrome cannot fall back to the raster's intrinsic size.
        $this->assertStringContainsString('--metric-source-size: 20px', $css);
        $this->assertStringContainsString('max-width: var(--metric-source-size)', $css);
        $this->assertStringContainsString('max-height: var(--metric-source-size)', $css);

        // Scroll shake: no sticky-header box-shadow, stable scrollbar gutter.
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-page \.table thead th \{[^}]*box-shadow:/',
            $css
        );
        $this->assertStringContainsString('scrollbar-gutter: stable', $css);
        $this->assertStringContainsString('border-collapse: separate', $css);
    }

    public function test_sorting_and_paging_announce_that_results_are_updating(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        // Both are full reloads, so the click had no answer until the next
        // document painted.
        $this->assertStringContainsString('id="catalogResults"', $html);
        $this->assertStringContainsString('catalog-results-busy', $html);
        $this->assertStringContainsString('Updating results', $html);
        $this->assertStringContainsString('id="catalogSearchStatus"', $html);
        // Must stay hidden until a sort/filter navigation — otherwise Chrome
        // paints the overlay over every listing when CSS is late/cached.
        $this->assertMatchesRegularExpression(
            '/class="catalog-results-busy"[^>]*\bhidden\b/',
            $html
        );
        $this->assertStringContainsString('function markCatalogResultsBusy(', $js);
        $this->assertStringContainsString('function clearCatalogResultsBusy(', $js);
        $this->assertStringContainsString('clearCatalogResultsBusy()', $js);
        $this->assertStringContainsString('catalogFilterSubmitInFlight', $js);
        $this->assertStringContainsString('Searching…', $js);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-results-busy[hidden]', $css);
        // Category niche pills wrap horizontally so rows stay compact.
        $this->assertMatchesRegularExpression(
            '/\.categories-column \{[\s\S]*?flex-direction:\s*row;[\s\S]*?flex-wrap:\s*wrap;/',
            $css
        );

        // form.submit() does not fire a submit event, so the sort path has to
        // raise the busy state itself (via CatalogLive.apply → markCatalogResultsBusy).
        $this->assertStringContainsString('function submitCatalogFilters', $js);
        $this->assertStringContainsString('CatalogLive.apply', $js);
        $this->assertMatchesRegularExpression(
            '/function apply\(options\) \{[\s\S]*?markCatalogResultsBusy\(/s',
            $js
        );
    }

    public function test_the_empty_state_draws_a_list_rather_than_an_error_glyph(): void
    {
        $html = $this->catalogHtml(['da_min' => 99]);

        $this->assertStringContainsString('No sites match your filters', $html);
        $this->assertStringContainsString('catalog-empty-art', $html);
        $this->assertStringContainsString('An empty list of publisher listings', $html);

        // A crossed-out filter in a circle read as something having gone wrong.
        $this->assertStringNotContainsString('catalog-empty-icon', $html);
        $this->assertStringNotContainsString('fa-filter-circle-xmark', $html);
    }

    public function test_the_new_visuals_are_shared_by_the_table_and_the_cards(): void
    {
        // Results markup lives in the shared partial (Phase 1); bulk rail is its own include.
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'))
            ."\n"
            .(string) file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'))
            ."\n"
            .(string) file_get_contents(resource_path('views/advertiser/partials/catalog-bulk-deals.blade.php'));

        // The table row and the card are near-duplicate markup, so anything that
        // renders in both belongs in a partial or it drifts.
        foreach ([
            // Three metrics per layout, everything else once per layout.
            'advertiser.partials.catalog-metric' => 6,
            'advertiser.partials.catalog-price' => 2,
            'advertiser.partials.catalog-meta-chips' => 2,
            'advertiser.partials.catalog-empty-art' => 2,
            // Table head names each source once; the cards have no head, so they
            // carry the mark on the metric label instead.
            'advertiser.partials.metric-source' => 6,
        ] as $partial => $expected) {
            $this->assertSame(
                $expected,
                substr_count($blade, $partial),
                $partial.' should be included by both the table and the card'
            );
        }

        // Plus the bulk rail, which shows the same identity as a results row.
        $this->assertSame(3, substr_count($blade, 'advertiser.partials.catalog-site-tile'));
    }

    public function test_the_sticky_column_is_labelled_buy_not_action(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();

        // The column holds the price and Add to cart — "Action" was too vague
        // for a marketplace listing.
        $this->assertStringContainsString('catalog-th-action', $html);
        $this->assertMatchesRegularExpression(
            '/catalog-th-action[\s\S]{0,400}?>\s*Buy\s*</',
            $html
        );
        $this->assertStringContainsString('About Buy column', $html);
        $this->assertStringNotContainsString('About Action column', $html);
    }

    public function test_table_and_card_both_expose_a_labelled_details_control(): void
    {
        $this->makeSite();

        $html = $this->catalogHtml();
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        // The table used a bare chevron; the card already said "Details". Both
        // now share the same labelled control and open/close voice.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'catalog-details-toggle__label">Details'));
        $this->assertStringContainsString('expand-arrow catalog-details-toggle', $html);
        $this->assertStringContainsString('function setCatalogDetailsToggleState(', $js);
        $this->assertStringContainsString("label.textContent = open ? 'Hide details' : 'Details'", $js);
    }
}
