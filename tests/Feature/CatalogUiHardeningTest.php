<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogUiHardeningTest extends TestCase
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
            'site_name' => 'Catalog Site',
            'site_url' => 'https://catalog-site.example',
            'domain' => 'catalog-site.example',
            'example_url' => 'https://catalog-site.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'A catalog listing used for UI hardening tests.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    private function catalogJs(): string
    {
        return (string) file_get_contents(public_path('assets/js/catalog.js'));
    }

    private function catalogBlade(): string
    {
        // Results markup lives in the shared partial (Phase 1).
        return (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'))
            ."\n"
            .(string) file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'));
    }

    public function test_the_url_guard_rejects_dangerous_schemes(): void
    {
        $this->assertSame('https://example.com/a', safe_external_url('https://example.com/a'));
        $this->assertSame('/storage/a.png', safe_external_url('/storage/a.png'));

        foreach ([
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            "java\0script:alert(1)",
            'data:text/html,<script>alert(1)</script>',
            '//evil.example.com',
            'vbscript:msgbox(1)',
            '',
            null,
        ] as $unsafe) {
            $this->assertSame('#', safe_external_url($unsafe), 'Should reject: '.var_export($unsafe, true));
        }
    }

    public function test_a_hostile_sample_url_is_never_put_in_an_href(): void
    {
        $site = $this->makeSite(['example_url' => 'javascript:alert(document.cookie)']);

        // The advertiser must be able to see the URL for the block to render.
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString("href='javascript:", $html);
        unset($site);
    }

    public function test_catalog_js_escapes_publisher_supplied_values(): void
    {
        $js = $this->catalogJs();

        $this->assertStringContainsString('function catalogEscapeHtml(', $js);
        // The sensitive-topic key is publisher-defined and goes into innerHTML.
        $this->assertStringContainsString('catalogEscapeHtml(selected.type)', $js);
        $this->assertStringNotContainsString("'<strong>' + selected.type", $js);
    }

    public function test_add_to_cart_flies_to_the_header_cart_quietly(): void
    {
        $js = $this->catalogJs();

        $this->assertStringContainsString('function catalogFlyToCart(', $js);
        $this->assertStringContainsString('quiet: true', $js);
        $this->assertStringContainsString('flyOrigin: button', $js);
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('data-cart-fly-target', $layout);
        $this->assertStringContainsString('opts.quiet', $layout);
        $this->assertStringContainsString('catalog-addon-price', $this->catalogBlade());
        $this->assertStringContainsString('lastPublicationLabel()', (string) file_get_contents(
            resource_path('views/advertiser/partials/catalog-site-trust.blade.php')
        ));
        $this->assertStringContainsString('catalog-expand-trust', $this->catalogBlade());
        $this->assertStringNotContainsString('catalog-site-trust--row', $this->catalogBlade());

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.form-check-input.sensitive-price-checkbox', $css);
        $this->assertStringContainsString('appearance: none', $css);
        $this->assertStringContainsString("m6 10 3 3 6-6", $css);
        $this->assertStringContainsString('cart-fly-arrow', $js);
        $this->assertStringContainsString('cart-fly-window', $js);
        $this->assertStringContainsString('.cart-fly-window', $css);
        $this->assertStringContainsString('.cart-fly-arrow', $css);
    }

    public function test_buy_button_price_updates_apply_the_active_discount(): void
    {
        $js = $this->catalogJs();

        // Selecting a sensitive topic used to add the add-on onto the list
        // price and drop the sale when returning to "no sensitive topic".
        $this->assertStringContainsString('function catalogApplyDiscount(', $js);
        $this->assertStringContainsString('catalogApplyDiscount(listTotal, pct, floor)', $js);
        $this->assertStringContainsString('function catalogPublisherPayoutFloor(', $js);
        $this->assertStringContainsString('data-discount-percent', $this->catalogBlade());
        $this->assertStringContainsString('data-publisher-price', $this->catalogBlade());

        // The price readouts moved out of the Buy button into their own block, so
        // the JS has to find them there rather than inside the button.
        $this->assertStringContainsString('function catalogPriceDisplaysFor(', $js);
        $this->assertStringContainsString(".closest('.catalog-card-buy, .catalog-row-actions')", $js);

        $this->makeSite([
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('list-price-display', $html);
        $this->assertStringContainsString('base-price-display', $html);
    }

    public function test_filter_tags_are_built_without_an_inline_handler(): void
    {
        $js = $this->catalogJs();

        // A label containing an apostrophe used to break out of the onclick.
        $this->assertStringNotContainsString('onclick="event.stopPropagation(); removeMultiFilter(', $js);
        $this->assertStringContainsString("createElement('button')", $js);
        $this->assertStringContainsString('data-filter-type', $js);
        $this->assertStringContainsString('.remove-tag[data-filter-type]', $js);

        // Capture phase, or .multi-select-input's own onclick opens the dropdown
        // first and the × reads as unclickable.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]click[\'"]\s*,\s*function\s*\([^)]*\)\s*\{[\s\S]*?remove-tag\[data-filter-type\][\s\S]*?\}\s*,\s*true\s*\)/',
            $js
        );
    }

    public function test_selected_filter_tags_target_the_ids_that_exist(): void
    {
        $js = $this->catalogJs();
        $blade = $this->catalogBlade();

        // Deriving the id by appending "s" gave selectedCategorysDisplay and
        // selectedCountrysDisplay, so those two filters never rendered a tag.
        $this->assertStringNotContainsString("'selected' + type.charAt(0).toUpperCase()", $js);
        $this->assertStringContainsString('MULTI_FILTER_UI', $js);

        foreach (['selectedCategoriesDisplay', 'selectedCountriesDisplay', 'selectedLanguagesDisplay'] as $id) {
            $this->assertStringContainsString($id, $js, 'catalog.js should target '.$id);
            $this->assertStringContainsString('id="'.$id.'"', $blade, 'markup should define '.$id);
        }
    }

    public function test_optimistic_favourite_and_blacklist_changes_revert_on_failure(): void
    {
        $js = $this->catalogJs();

        // Snapshot taken before the optimistic update, restored if the save fails.
        $this->assertStringContainsString('previousFavorites', $js);
        $this->assertStringContainsString('previousBlacklist', $js);
        $this->assertStringContainsString('favorites = previousFavorites;', $js);
        $this->assertStringContainsString('blacklist = previousBlacklist;', $js);

        // Both savers must resolve to a boolean so the caller can roll back.
        $this->assertStringContainsString('saveFavorites().then(function (ok) {', $js);
        $this->assertStringContainsString('saveBlacklist().then(function (ok) {', $js);
        foreach (['saveFavorites', 'saveBlacklist'] as $fn) {
            $body = substr($js, (int) strpos($js, 'function '.$fn.'('));
            $body = substr($body, 0, (int) strpos($body, "\n}\n"));
            $this->assertStringContainsString('return true;', $body, $fn.' should report success');
            $this->assertStringContainsString('return false;', $body, $fn.' should report failure');
        }
    }

    public function test_each_active_filter_can_be_removed_on_its_own(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'catalog', 'da_min' => 30, 'da_max' => 60]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('filter-chip__remove', $html);
        $this->assertStringContainsString('aria-label="Remove filter: Search: catalog"', $html);
        $this->assertStringContainsString('aria-label="Remove filter: DA (Domain Authority)"', $html);
        // Clearing one chip keeps the others, unlike the old clear-all-only link.
        $this->assertStringContainsString('da_min=30', $html);
        $this->assertStringContainsString('Clear all', $html);
    }

    public function test_removing_a_range_chip_clears_both_ends(): void
    {
        $blade = $this->catalogBlade();

        $this->assertStringContainsString("'params' => ['da_min', 'da_max']", $blade);
        $this->assertStringContainsString("'params' => ['price_min', 'price_max']", $blade);
        $this->assertStringContainsString("'params' => ['traffic_min', 'traffic_max']", $blade);
        // Page must reset, or a narrower result set can land on an empty page.
        $this->assertStringContainsString('CatalogUrlQuery::except', $blade);
        $this->assertStringContainsString("\$chip['params']", $blade);
    }

    public function test_table_and_range_inputs_are_described_for_screen_readers(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // Seven columns, each labelling its own column.
        $this->assertSame(7, substr_count($html, 'scope="col"'));

        // Eight range inputs all used to announce as "Min" or "Max".
        foreach ([
            'Minimum price in euros',
            'Maximum price in euros',
            'Minimum Domain Authority',
            'Maximum Domain Authority',
            'Minimum Domain Rating',
            'Maximum Domain Rating',
            'Minimum monthly traffic',
            'Maximum monthly traffic',
        ] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $html);
        }
    }

    public function test_the_expand_control_points_at_the_panel_it_opens(): void
    {
        $site = $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-controls="site-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('id="site-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('aria-label="Show details for '.$site->site_name.'"', $html);
    }

    public function test_row_chrome_moved_out_of_inline_styles(): void
    {
        $blade = $this->catalogBlade();
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertStringNotContainsString('opacity: 0.7; background-color: #fff3f3;', $blade);
        $this->assertStringContainsString('.blacklisted-row', $css);
        $this->assertStringContainsString('.catalog-expand-cell', $css);
        $this->assertStringContainsString('catalog-expand-cell', $blade);
    }

    public function test_duplicated_and_dead_catalog_css_is_gone(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        foreach (['.category-tile', '.btn-toggle-categories', '.categories-grid', '.favorite-btn.btn-danger'] as $dead) {
            $this->assertStringNotContainsString($dead, $css, $dead.' is unused in the catalog markup');
        }

        // .pulse-dot was display:none and its keyframes ran on nothing; browsers
        // ignore nearly all <option> styling, so 70 lines of it did nothing.
        $this->assertStringNotContainsString('.pulse-dot', $css);
        $this->assertStringNotContainsString('select.form-select option', $css);

        // .catalog-table-scroll used to be declared twice as separate blocks;
        // one grouped rule (with .table-responsive) overrides Bootstrap overflow.
        // overflow:visible is required — clip/auto on one axis creates a scroll
        // containment that makes sticky Buy/header cells shake while scrolling.
        $this->assertSame(1, substr_count($css, '.catalog-table-scroll,'));
        $this->assertMatchesRegularExpression(
            '/\.catalog-table-scroll(?:,|\.table-responsive)[\s\S]*?overflow:\s*visible;/',
            $css
        );
        $this->assertStringContainsString('position: sticky', $css);
        $this->assertStringContainsString('top: var(--shell-topbar-height', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.catalog-table-scroll[^{]*\{[^}]*overflow-x:\s*(?:auto|clip)/',
            $css
        );
    }

    public function test_the_shared_multi_select_owns_the_filter_dropdown(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $shared = (string) file_get_contents(public_path('assets/css/multi-select.css'));

        // catalog.css redeclared the whole widget, so catalog filters looked
        // different from every other multi-select — and its z-index of 1000 put
        // the open dropdown underneath the topbar.
        // Scoped tweaks such as ".catalog-filters-card .multi-select-input" are
        // fine; redeclaring the widget itself is what caused the drift.
        foreach (['.multi-select-dropdown', '.selected-tag', '.option-item', '.multi-select-input'] as $owned) {
            $this->assertDoesNotMatchRegularExpression(
                '/^'.preg_quote($owned, '/').'[\s,{]/m',
                $css,
                'multi-select.css owns '.$owned
            );
            $this->assertStringContainsString($owned, $shared);
        }

        $this->assertStringContainsString('z-index: var(--shell-z-dropdown', $shared);

        // The remove affordance is a real button here so it is keyboard
        // reachable; the shared sheet has to strip the native chrome.
        $this->assertStringContainsString('button.remove-tag', $shared);
    }

    public function test_page_styles_do_not_leak_into_the_shell(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $blade = $this->catalogBlade();

        $this->assertStringContainsString('container-fluid catalog-page', $blade);

        // Bare .table / .badge / .form-control reached the cart drawer and the
        // nav, because this sheet loads after the shell's own stylesheets.
        foreach ([
            '.catalog-page .table {',
            '.catalog-page .badge {',
            '.catalog-page .btn-link {',
            '.catalog-page .form-control-sm,',
        ] as $scoped) {
            $this->assertStringContainsString($scoped, $css);
        }

        $this->assertDoesNotMatchRegularExpression('/^\.table\s*\{/m', $css);
        $this->assertDoesNotMatchRegularExpression('/^\.badge\s*\{/m', $css);
        $this->assertDoesNotMatchRegularExpression('/^thead th\s*\{/m', $css);
    }

    public function test_the_page_stylesheet_loads_in_the_head(): void
    {
        $blade = $this->catalogBlade();
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));

        // Loading it with the body painted the page unstyled first.
        $this->assertStringContainsString("@push('page-styles')", $blade);
        $this->assertStringContainsString("@stack('page-styles')", $layout);

        // And it has to sit before the hover system so that sheet still wins.
        $this->assertLessThan(
            strpos($layout, 'hover-system.css'),
            strpos($layout, "@stack('page-styles')"),
            'page styles must load before the hover system'
        );
    }

    public function test_the_table_only_appears_where_its_columns_fit(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        // The table needs ~995px of columns. At md the sidebar leaves far less,
        // so Action — the column holding Buy — sat off the right edge.
        $this->assertStringContainsString('catalog-table-scroll d-none d-xl-block', $html);
        $this->assertStringContainsString('catalog-mobile-list d-xl-none', $html);
        $this->assertStringNotContainsString('catalog-table-scroll d-none d-md-block', $html);

        // Pixel floors on two columns forced the scrollbar before content did.
        $this->assertStringNotContainsString('style="min-width: 250px;"', $html);
        $this->assertStringNotContainsString('style="min-width: 180px;"', $html);
    }

    public function test_the_pinned_action_column_follows_the_row_it_belongs_to(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        // A hard white background left a pale seam beside blacklisted rows and
        // swallowed the hover wash on the one column that always stays put.
        // Bootstrap paints cells from a selector more specific than a bare
        // class, so inheriting the row needs the element in the selector.
        $this->assertMatchesRegularExpression(
            '/td\.catalog-td-action \{\s*\n\s*background-color: inherit/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/tr\.site-row \{[^}]*background-color: var\(--surface-1/',
            $css
        );

        // And the tint has to survive that resting background.
        $this->assertMatchesRegularExpression(
            '/tr\.site-row\.blacklisted-row \{\s*\n\s*background-color: #fff3f3/',
            $css
        );
    }

    public function test_cards_carry_the_details_the_table_keeps_in_its_expand_row(): void
    {
        $site = $this->makeSite([
            'description' => 'A short description that only the table used to show.',
            'publication_time' => '1year',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="card-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('aria-controls="card-details-'.$site->id.'"', $html);
        $this->assertStringContainsString('A short description that only the table used to show.', $html);
        $this->assertStringContainsString('1 year', $html);

        // Eye controls only exist in copy-strike hide mode — normals see full
        // identity with no toggle on the card.
        $cards = substr($html, (int) strpos($html, 'catalog-mobile-list'));
        $cards = substr($cards, 0, (int) strpos($cards, 'window.CatalogConfig'));
        $this->assertSame(0, substr_count($cards, 'reveal-url btn-icon-quiet'));
        $this->assertSame(0, substr_count($cards, 'toggle-url btn-icon-quiet'));
        $this->assertSame(0, substr_count($cards, 'catalog-url-eye'));
    }

    public function test_catalog_about_this_site_card_uses_plain_text_excerpt(): void
    {
        $site = $this->makeSite([
            'description' => '<p>Hello <strong>world</strong> editorial site for guest posts.</p>',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('About this site', $html);
        $this->assertStringContainsString('Hello world editorial site for guest posts.', $html);
        // Card excerpt must not dump escaped raw markup.
        $this->assertStringNotContainsString(e('<p>Hello'), $html);
        $this->assertStringNotContainsString('&lt;strong&gt;world&lt;/strong&gt;', $html);
        // Desktop expand may still show sanitizer-safe rich text.
        $this->assertStringContainsString('<strong>world</strong>', $site->safeDescriptionHtml());
        $blade = file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'));
        $this->assertStringContainsString('site_description_excerpt($site->description)', $blade);
        $this->assertStringContainsString('catalogDescriptionHtml()', $blade);
    }

    public function test_the_more_filters_drawer_fits_twelve_columns(): void
    {
        $blade = $this->catalogBlade();

        $drawerStart = strpos($blade, 'id="moreFiltersDrawer"');
        $this->assertNotFalse($drawerStart);
        $drawer = substr($blade, $drawerStart);
        $drawerEnd = strpos($drawer, '</form>');
        $this->assertNotFalse($drawerEnd);
        $drawer = substr($drawer, 0, $drawerEnd);

        // Old col-md-2/3 cells summed past 12 and wrapped mid-row. Keep the
        // shared 6/4/3 grid: four per lg row (Tag…Quality = 10 cells).
        $this->assertSame(0, substr_count($drawer, 'class="col-md-2"'));
        $this->assertSame(0, substr_count($drawer, 'class="col-md-3"'));
        $this->assertSame(12, substr_count($drawer, 'class="col-6 col-md-4 col-lg-3"'));

        // Preset chips make DA taller than Tag/Favorites/Blacklist. Bottom
        // alignment floated the DA label above that first row.
        $this->assertStringContainsString('row g-3 align-items-start', $drawer);
        $this->assertStringNotContainsString('align-items-end', $drawer);
    }

    public function test_catalog_sort_closed_control_matches_filter_select_sizing(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertStringContainsString('.catalog-sort-select', $css);
        $this->assertMatchesRegularExpression(
            '/\.catalog-sort-select \{[^}]*min-height:\s*31px;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-sort-select \{[^}]*border-radius:\s*var\(--radius-sm/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-page \.form-select-sm \{[^}]*min-height:\s*31px;/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.catalog-page \.multi-select-input\.form-control-sm \{[^}]*min-height:\s*31px;/s',
            $css
        );
    }

    public function test_filters_stay_visible_without_a_hide_show_toggle(): void
    {
        $this->makeSite();

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['search' => 'catalog']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="catalogFiltersPanel"', $html);
        $this->assertStringNotContainsString('d-none" id="catalogFiltersPanel"', $html);
        $this->assertStringNotContainsString('Hide filters', $html);
        $this->assertStringNotContainsString('Show filters', $html);
        $this->assertStringNotContainsString('toggleCatalogFilters', $html);
        $this->assertStringNotContainsString('filters_open', $html);
        $this->assertStringNotContainsString('filtersOpenField', $html);

        // Filters + sort + suggest sit immediately above the results table.
        $filtersPos = strpos($html, 'id="catalogFiltersPanel"');
        $resultsBarPos = strpos($html, 'catalog-results-bar');
        $suggestPos = strpos($html, 'btn-suggest-website');
        $tablePos = strpos($html, 'id="catalogResults"');
        $this->assertNotFalse($filtersPos);
        $this->assertNotFalse($resultsBarPos);
        $this->assertNotFalse($suggestPos);
        $this->assertNotFalse($tablePos);
        $this->assertLessThan($resultsBarPos, $filtersPos);
        $this->assertLessThan($suggestPos, $resultsBarPos);
        $this->assertLessThan($tablePos, $suggestPos);
    }

    public function test_every_filter_submit_syncs_the_multi_select_fields(): void
    {
        $js = $this->catalogJs();
        $blade = $this->catalogBlade();

        // Sorting called form.submit() directly, which posted the hidden fields
        // as rendered and dropped any tag ticked since page load.
        $this->assertStringNotContainsString("onchange=\"document.getElementById('filterForm').submit()\"", $blade);
        $this->assertStringContainsString('function syncCatalogFilterFields', $js);
        $this->assertStringContainsString("form.addEventListener('submit'", $js);
        $this->assertStringContainsString("sort.addEventListener('change'", $js);
        $this->assertStringContainsString('type="submit"', $blade);
        $this->assertStringContainsString('catalogSearchInput', $js);
        $this->assertStringNotContainsString('SEARCH_DEBOUNCE_MS', $js);
        $this->assertStringContainsString('catalogFilterSubmitInFlight', $js);
        $this->assertStringContainsString("reason === 'search'", $js);
        $this->assertStringContainsString('Searching…', $js);
        $this->assertStringContainsString('SlbLiveSearch.init', $js);
        $this->assertStringContainsString(
            "e.key !== 'Enter'",
            (string) file_get_contents(public_path('js/slb-live-search.js'))
        );
        // Phase 2/3 — navigations build an allowlisted query; live fetch swaps the fragment.
        $this->assertStringContainsString('CatalogLive.apply', $js);
        $this->assertStringContainsString('CatalogUrl.navigate', $js);
        $this->assertStringContainsString('window.location.replace', $js);
        $this->assertStringContainsString('history.replaceState', $js);
    }

    public function test_the_stale_duplicate_catalog_script_is_removed(): void
    {
        // public/js/catalog.js was 319 lines behind and loaded by nothing, so
        // editing it looked like a no-op.
        $this->assertFileDoesNotExist(public_path('js/catalog.js'));
        $this->assertFileExists(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('assets/js/catalog.js', $this->catalogBlade());
    }
}
