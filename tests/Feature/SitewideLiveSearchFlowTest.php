<?php

namespace Tests\Feature;

use App\Support\MarketingCssBundle;
use Tests\TestCase;

/**
 * Catalog main-search contract is shared sitewide via SlbLiveSearch.
 */
class SitewideLiveSearchFlowTest extends TestCase
{
    private function liveSearchJs(): string
    {
        return (string) file_get_contents(public_path('js/slb-live-search.js'));
    }

    public function test_shared_helper_matches_catalog_main_search_contract(): void
    {
        $js = $this->liveSearchJs();

        $this->assertStringContainsString('DEBOUNCE_MS = 350', $js);
        $this->assertStringContainsString('MIN_CHARS = 2', $js);
        $this->assertStringContainsString("e.key !== 'Enter'", $js);
        $this->assertStringContainsString("historyMode: 'replace'", $js);
        $this->assertStringContainsString("historyMode: 'push'", $js);
        $this->assertStringContainsString("reason: 'clear'", $js);
        $this->assertStringContainsString('Type at least 2 characters to search', $js);
        $this->assertStringContainsString('slb:livesearch', $js);
        $this->assertStringContainsString("reason === 'enter' || reason === 'clear'", $js);
        $this->assertStringContainsString('isAdminPanel', $js);
        $this->assertStringContainsString('form-live', $js);
        $this->assertStringContainsString('role-shell-admin', $js);
    }

    public function test_every_layout_loads_the_shared_live_search_assets(): void
    {
        $layouts = [
            'advertiser/layouts/app.blade.php',
            'publisher/layouts/app.blade.php',
            'admin/layouts/app.blade.php',
            'marketing/layouts/app.blade.php',
            'layouts/app.blade.php',
        ];

        foreach ($layouts as $layout) {
            $markup = (string) file_get_contents(resource_path('views/'.$layout));
            $this->assertStringContainsString('js/slb-live-search.js', $markup, $layout);

            if ($layout === 'layouts/app.blade.php') {
                $this->assertStringContainsString('MarketingCssBundle', $markup, $layout);
                $this->assertStringContainsString('assets/css/slb-icons.css', $markup, $layout);
                $this->assertStringContainsString('partials.slb-icon-draw', $markup, $layout);
                $this->assertStringNotContainsString('font-awesome', $markup, $layout);
                $this->assertContains('slb-live-search.css', MarketingCssBundle::FILES);
                $files = MarketingCssBundle::FILES;
                $this->assertSame('hover-system.css', $files[array_key_last($files)]);
                $this->assertStringContainsString('assets/css/slb-icons.css', $markup, $layout);
                $this->assertStringContainsString('partials.slb-icon-draw', $markup, $layout);
                $this->assertStringNotContainsString('font-awesome', $markup, $layout);

                continue;
            }

            $this->assertStringContainsString('assets/css/slb-live-search.css', $markup, $layout);
            $this->assertStringContainsString('assets/css/slb-icons.css', $markup, $layout);
            $this->assertStringContainsString('partials.slb-icon-draw', $markup, $layout);
            $this->assertStringNotContainsString('font-awesome', $markup, $layout);

            preg_match_all('/<link[^>]+assets\/css\/([a-z-]+)\.css/', $markup, $matches);
            // hover-system must stay last in the assets/css cascade.
            $this->assertContains('hover-system', $matches[1], $layout);
            $this->assertContains('slb-live-search', $matches[1], $layout);
            $this->assertSame('hover-system', end($matches[1]), $layout.' must keep hover-system last among assets/css');
        }

        $advertiserLayout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('assets/css/slb-pagination.css', $advertiserLayout);
    }

    public function test_catalog_and_orders_delegate_to_shared_helper(): void
    {
        $catalog = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $orders = (string) file_get_contents(public_path('assets/js/advertiser-orders.js'));

        $this->assertStringContainsString('SlbLiveSearch.init', $catalog);
        $this->assertStringContainsString('scheduleLiveSearch', $catalog);
        $this->assertStringContainsString('SlbLiveSearch.init', $orders);
        $this->assertStringContainsString('scheduleOrdersLiveSearch', $orders);
    }

    public function test_high_traffic_search_bars_use_catalog_parity_flow(): void
    {
        $paths = [
            resource_path('views/publisher/tasks.blade.php'),
            resource_path('views/admin/orders/index.blade.php'),
            resource_path('views/admin/payments.blade.php'),
            resource_path('views/admin/withdrawals.blade.php'),
            public_path('assets/js/publisher-websites.js'),
        ];

        foreach ($paths as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('SlbLiveSearch', $body, basename($path));
        }

        $mustWaitForHelper = [
            resource_path('views/admin/orders/index.blade.php'),
            resource_path('views/admin/withdrawals.blade.php'),
            resource_path('views/admin/sites.blade.php'),
        ];
        foreach ($mustWaitForHelper as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('DOMContentLoaded', $body, basename($path).' must wait for slb-live-search.js');
            $this->assertStringContainsString('SlbLiveSearch.init', $body, basename($path));
        }

        $forms = [
            resource_path('views/notifications/all.blade.php'),
            resource_path('views/advertiser/billing/index.blade.php'),
            resource_path('views/publisher/billing/index.blade.php'),
            resource_path('views/admin/deposits.blade.php'),
            resource_path('views/admin/invoices/index.blade.php'),
            resource_path('views/admin/community/index.blade.php'),
            resource_path('views/admin/audiences/index.blade.php'),
            resource_path('views/admin/activity-logs.blade.php'),
            resource_path('views/admin/site-ratings.blade.php'),
            resource_path('views/admin/finance-ledger.blade.php'),
            resource_path('views/marketing/history.blade.php'),
            resource_path('views/admin/content-library/index.blade.php'),
            resource_path('views/admin/users.blade.php'),
        ];

        foreach ($forms as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('<x-slb-search-field', $body, basename($path));
        }

        $library = (string) file_get_contents(resource_path('views/advertiser/content-library.blade.php'));
        $this->assertStringNotContainsString('data-slb-live-search="form"', $library);
        $this->assertStringContainsString('for="librarySearchInput">Search</label>', $library);
        $this->assertStringContainsString('id="librarySearchClear"', $library);
        $this->assertStringContainsString('id="librarySearchStatus"', $library);
        $this->assertStringContainsString('libraryResultsUrl', $library);

        $libraryJs = (string) file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('SlbLiveSearch.init', $libraryJs);
        $this->assertStringContainsString('fetchLibraryResults', $libraryJs);
        $this->assertStringContainsString('librarySameOriginPath', $libraryJs);
        $this->assertStringContainsString('syncLibraryFiltersFromParams', $libraryJs);
        $this->assertStringContainsString('libraryUploadUrl', $libraryJs);
        $this->assertStringContainsString('normalizeLibraryFilters', $libraryJs);
        $this->assertStringContainsString('libraryModifiedClick', $libraryJs);
        $this->assertStringContainsString("if (availability === 'published') availability = 'completed';", $libraryJs);
        $this->assertStringNotContainsString("if (availability === 'completed') availability = 'published';", $libraryJs);
        $this->assertStringContainsString('refreshLibraryListAfterRowChange', $libraryJs);
        $this->assertStringContainsString('libraryCountryFilter', $libraryJs);
        $this->assertStringContainsString("route('advertiser.content-library.results', absolute: false)", $library);
        $this->assertStringContainsString("route('advertiser.content-library.upload', absolute: false)", $library);
        $this->assertStringNotContainsString('this.form.submit()', $library);
    }

    public function test_admin_sites_does_not_persist_add_message_from_query_string(): void
    {
        $blade = (string) file_get_contents(resource_path('views/admin/sites.blade.php'));
        $this->assertStringNotContainsString("request()->query('site') > 0 && (int) request()->query('publisher') > 0", $blade);
        $this->assertStringNotContainsString('Site added. The publisher must open My Sites → Invites and Accept before it appears under Pending.', $blade);
    }

    public function test_shared_search_field_component_has_catalog_chrome(): void
    {
        $component = (string) file_get_contents(resource_path('views/components/slb-search-field.blade.php'));
        $this->assertStringContainsString('slb-search-wrap', $component);
        $this->assertStringContainsString('slb-search-clear', $component);
        $this->assertStringContainsString('slb-search-status', $component);
        $this->assertStringContainsString('data-slb-live-search', $component);
        $this->assertStringContainsString('Type at least 2 characters', $component);
    }

    public function test_ajax_and_off_contract_bars_have_clear_and_status(): void
    {
        $ajax = [
            resource_path('views/admin/orders/index.blade.php'),
            resource_path('views/admin/payments.blade.php'),
            resource_path('views/admin/withdrawals.blade.php'),
            resource_path('views/publisher/tasks.blade.php'),
        ];
        foreach ($ajax as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('slb-search-wrap', $body, basename($path));
            $this->assertStringContainsString('slb-search-clear', $body, basename($path));
            $this->assertStringContainsString('form-text slb-search-status', $body, basename($path));
            $this->assertStringNotContainsString('visually-hidden" role="status"', $body, basename($path));
        }

        $offContractComponents = [
            resource_path('views/advertiser/add-funds.blade.php'),
            resource_path('views/admin/sites.blade.php'),
            resource_path('views/admin/users.blade.php'),
            resource_path('views/publisher/websites.blade.php'),
        ];
        foreach ($offContractComponents as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('<x-slb-search-field', $body, basename($path));
        }

        $offContractInline = [
            resource_path('views/pages/blog.blade.php'),
            resource_path('views/advertiser/partials/catalog-bulk-deals.blade.php'),
        ];
        foreach ($offContractInline as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('slb-search-wrap', $body, basename($path));
            $this->assertStringContainsString('slb-search-clear', $body, basename($path));
            $this->assertStringContainsString('slb-search-status', $body, basename($path));
        }
    }

    public function test_icons_are_local_lucide_not_font_awesome_cdn(): void
    {
        $this->assertFileExists(public_path('assets/css/slb-icons.css'));
        $this->assertFileExists(public_path('js/slb-icon-draw.js'));
        $iconDraw = (string) file_get_contents(public_path('js/slb-icon-draw.js'));
        $this->assertStringContainsString('requestIdleCallback', $iconDraw);
        $this->assertStringContainsString('urlByClass', $iconDraw);
        $this->assertStringContainsString('BUDGET_MS', $iconDraw);
        $this->assertStringContainsString('hydrateMapFromSheets', $iconDraw);
        $this->assertStringContainsString('slb-icons.css', $iconDraw);
        $this->assertFileExists(public_path('assets/icons/lucide/plus.svg'));
        $this->assertFileExists(public_path('assets/icons/brands/facebook.svg'));
        $css = (string) file_get_contents(public_path('assets/css/slb-icons.css'));
        $this->assertStringContainsString('../icons/lucide/plus.svg', $css);
        $this->assertStringNotContainsString('font-awesome', $css);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $css);

        $public = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('assets/css/slb-icons.css', $public);
        $this->assertStringContainsString('partials.slb-icon-draw', $public);
        $this->assertStringNotContainsString('font-awesome', $public);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com/ajax/libs/font-awesome', $public);
    }
}
