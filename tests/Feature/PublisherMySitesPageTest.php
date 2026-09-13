<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\PlatformFeeService;
use App\Support\CatalogBuyerReadiness;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublisherMySitesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    public function test_my_sites_inline_script_does_not_redeclare_delay_timer(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/websites.blade.php'));
        $this->assertSame(
            1,
            preg_match_all('/\blet\s+delayTimer\b/', $blade),
            'Duplicate let delayTimer in websites.blade.php breaks the page script and leaves My Sites blank.'
        );

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match_all('/\blet\s+delayTimer\b/', $html),
            'Rendered My Sites page must declare delayTimer only once.'
        );
        $this->assertStringContainsString('window.loadSites = fetchSites', $html);
        $this->assertStringContainsString('id="sitesTableWrapper"', $html);
        $this->assertStringContainsString(
            'window.publisherSitePreviewOnError',
            $html,
            'My Sites must define preview onerror so ajax row thumbs can fall back /media → /storage.'
        );
        $this->assertStringContainsString(
            "img.closest('.site-preview-zoom')",
            $html,
            'Cover fallback must mark .site-preview-zoom broken so the View mock can show No cover yet.'
        );
        $this->assertStringContainsString(
            'const id = $(this).data(\'id\') || siteHint.id;',
            $html,
            'Edit click handler must resolve the site id from data-id or data-site.'
        );
        $this->assertStringContainsString(
            'const wizardStepHint = parseInt($(this).data(\'wizardStep\'), 10);',
            $html,
            'Gap CTAs must jump to the wizard step that holds the missing field.'
        );
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => "O'Reilly News",
            'site_url' => 'https://oreilly-news.example',
            'domain' => 'oreilly-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'description' => "It's a publisher site with apostrophes and \"quotes\".",
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_ajax_table_ok_when_promo_dates_are_unparseable(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Leftover Promo Dates',
            'verified' => true,
            'active' => true,
            'featured_until' => now()->addDays(3),
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'featured_until' => 'not-a-date',
            'custom_discount_starts_at' => 'not-a-date',
            'custom_discount_ends_at' => 'also-bad',
        ]);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->assertSee('Leftover Promo Dates', false)
            ->assertDontSee('Something went wrong');
    }

    public function test_discount_badges_show_publisher_list_sale_not_advertiser_fee_floor(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'price' => 100,
            'custom_discount_percent' => 10,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
        ]);
        $this->makeSite([
            'site_name' => 'Sale Floor List',
            'site_url' => 'https://sale-floor-list.example',
            'domain' => 'sale-floor-list.example',
            'verified' => true,
            'active' => true,
            'price' => 304,
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 12,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('€100.00', $html);
        $this->assertStringContainsString('€90.00', $html);
        $this->assertStringContainsString('−10%', $html);
        $this->assertStringContainsString('Timed sale −10% (configured)', $html);
        $this->assertStringContainsString('Off your list (€100.00 → €90.00)', $html);
        $this->assertStringContainsString('€304.00', $html);
        $this->assertStringContainsString('€258.40', $html);
        $this->assertStringContainsString('−15%', $html);
        $this->assertStringContainsString('Off your list (€304.00 → €258.40)', $html);
        $this->assertStringContainsString('Advertisers pay your list plus the platform fee, then this same percent', $html);
        $this->assertStringContainsString('Exclusive better-of with bulk, not stacked', $html);
        $this->assertStringContainsString('Timed sale is stronger on packs too', $html);
        $this->assertStringContainsString('Pack of 3 from €775.20', $html);
        $this->assertStringContainsString('site-row-actions__offers', $html);
        $this->assertStringContainsString('Sale −10%', $html);
        $this->assertStringContainsString('site-offer-chip', $html);
        $this->assertStringNotContainsString('Advertisers from €', $html);
        $this->assertStringNotContainsString('Advertisers see about', $html);
        $this->assertStringNotContainsString('after the fee floor', $html);
        $this->assertStringNotContainsString('−10.7%', $html);
        $this->assertStringNotContainsString('−11.5%', $html);
    }

    public function test_offer_chips_are_labeled_and_promo_js_is_single_path(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'price' => 304,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-row-actions__manage', $html);
        $this->assertStringContainsString('site-row-actions__offers', $html);
        $this->assertStringContainsString('>Offers</span>', $html);
        $this->assertStringContainsString('Feature · €10', $html);
        $this->assertStringContainsString('>Sale</span>', $html);
        $this->assertStringContainsString('>Bulk</span>', $html);
        $this->assertStringContainsString('10–80% off when an advertiser buys', $html);
        $this->assertStringContainsString('site-offer-chip btn-feature-site', $html);
        $this->assertStringContainsString('Paid from publisher balance or card', $html);
        $this->assertStringNotContainsString('class="btn-icon-quiet btn-feature-site', $html);
        $this->assertStringContainsString('€304.00', $html);
        $this->assertStringNotContainsString('Advertisers from €', $html);
        $this->assertStringNotContainsString('after the fee floor', $html);
        $this->assertStringContainsString('Advertisers pay your list plus the platform fee, then this same percent', $html);

        $page = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();
        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));
        $this->assertStringNotContainsString("$(document).on('click', '.btn-feature-site'", $page);
        $this->assertSame(1, substr_count($js, "$(document).on('click', '.btn-feature-site'"));
        $this->assertStringContainsString('__publisherPromoHandlersBound', $js);
        $this->assertStringContainsString('__publisherSitesList', $js);
        $this->assertStringContainsString('reloadSitesAfterPromo', $js);
        $this->assertStringContainsString('promoEscapeHtml', $js);
        $this->assertStringContainsString('Extend featuring', $js);
        $this->assertStringContainsString('adds another', $js);
        $this->assertStringContainsString("attr('data-featured-until')", $js);
        $this->assertStringContainsString("attr('data-ends')", $js);
        $this->assertStringContainsString('function promoDaysLeft', $js);
        $this->assertStringContainsString('Update timed sale', $js);
        $this->assertStringContainsString('End sale now', $js);
        $this->assertStringContainsString('Publish sale', $js);
        $this->assertStringContainsString('Leave programme', $js);
        $this->assertStringContainsString('Update percent', $js);
        $this->assertStringContainsString("$(document).on('click', '.btn-bulk-site'", $js);
        $this->assertStringNotContainsString("$(document).on('click', '.btn-bulk-join'", $js);
        $this->assertStringContainsString('cfg.bulkMinPercent', $js);
        $this->assertStringContainsString('cfg.bulkMaxPercent', $js);
        $this->assertStringContainsString('Enter a percent from', $js);
        $this->assertStringNotContainsString('Discount % for 3–5 articles (10–15)', $js);
        $this->assertStringContainsString('bulkMaxPercent: 80', $page);
        $this->assertDoesNotMatchRegularExpression('/routes:\s*\{[^}]*bulkMaxPercent/', $page);
    }

    public function test_offer_dialogs_keep_get_verified_on_manage_and_note_unverified_feature(): void
    {
        $unverified = $this->makeSite([
            'site_name' => 'Unverified Live',
            'site_url' => 'https://unverified-live.example',
            'domain' => 'unverified-live.example',
            'verified' => false,
            'active' => true,
            'price' => 80,
        ]);
        $featured = $this->makeSite([
            'site_name' => 'Featured Sale',
            'site_url' => 'https://featured-sale.example',
            'domain' => 'featured-sale.example',
            'verified' => true,
            'active' => true,
            'price' => 100,
            'featured_until' => now()->addDays(4),
            'custom_discount_percent' => 15,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 12,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-verified="0"', $html);
        $this->assertStringContainsString(
            'This site is active but not verified. Featuring still works; advertisers may trust it less.',
            $html
        );
        $this->assertStringContainsString('data-featured-until=', $html);
        $this->assertStringContainsString('data-ends=', $html);
        $this->assertStringContainsString('btn-bulk-site', $html);
        $this->assertStringContainsString('data-joined="1"', $html);
        $this->assertStringContainsString('Edit or leave bulk', $html);
        $this->assertStringNotContainsString('btn-discount-clear', $html);
        $this->assertStringNotContainsString('btn-bulk-join', $html);
        $this->assertStringNotContainsString('btn-bulk-leave', $html);
        $this->assertStringContainsString('10–80% off when an advertiser buys', $html);

        $this->assertStringContainsString('Get Verified', $html);
        $this->assertMatchesRegularExpression(
            '/site-row-actions__manage[\s\S]*btn-verify-site[\s\S]*Get Verified[\s\S]*<\/div>\s*<div class="site-row-actions__offers"/',
            $html,
            'Get Verified must stay on the manage row, before Offers.'
        );

        $blade = file_get_contents(resource_path('views/publisher/sites/partials/table.blade.php'));
        $verifyInBlade = strpos($blade, 'aria-label="Get Verified"');
        $offersInBlade = strpos($blade, 'class="site-row-actions__offers"');
        $manageInBlade = strpos($blade, '<div class="site-row-actions__manage">');
        $this->assertNotFalse($verifyInBlade);
        $this->assertGreaterThan($manageInBlade, $verifyInBlade);
        $this->assertLessThan($offersInBlade, $verifyInBlade);

        $this->assertStringContainsString((string) $unverified->id, $html);
        $this->assertStringContainsString((string) $featured->id, $html);

        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));
        $this->assertStringContainsString('Featuring still works; advertisers may trust it less.', $js);
        $this->assertStringContainsString('promoBetterOfNote', $js);
        $this->assertStringContainsString('promoListAfterDiscount', $js);
        $this->assertStringContainsString('Off your list:', $js);
        $this->assertStringContainsString('off your list price', $js);
        $this->assertStringNotContainsString('btn-discount-clear', $js);
    }

    public function test_ajax_metrics_keep_traffic_out_of_market_column(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'traffic' => 1250000,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/\.modern-table col\.col-metrics \{ width: 188px; \}/', $html);
        $this->assertStringContainsString('formattedTraffic', file_get_contents(resource_path('views/publisher/sites/partials/table.blade.php')));
        $this->assertStringContainsString('Tr <strong>1.3M</strong>', $html);
        $this->assertStringContainsString('Traffic 1,250,000', $html);
        $this->assertStringContainsString('data-label="Market"', $html);
        $this->assertStringContainsString('country-flag', $html);
        $this->assertStringContainsString('padding-left: 14px', $html);
    }

    public function test_my_sites_page_and_ajax_table_render(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
        ]);

        $page = $this->actingAs($this->publisher)->get(route('publisher.websites'));
        $page->assertOk();
        $html = $page->getContent();
        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));
        $this->assertStringContainsString('publisher-websites.js', $html);
        $this->assertStringContainsString('publisher-websites.css', $html);
        $this->assertStringContainsString('PublisherWebsitesConfig', $html);
        $this->assertStringContainsString('function fetchSites', $js);
        $this->assertStringContainsString('window.loadSites = fetchSites', $js);
        $this->assertStringContainsString("$(document).on('click', '.action-view'", $js);
        $this->assertStringContainsString("$(document).on('click', '.btn-delete'", $js);
        $this->assertStringContainsString('sitesFilterPending', $html);
        $this->assertStringContainsString('sitesFilterActive', $html);
        $this->assertStringContainsString('sitesFilterInvites', $html);
        $this->assertStringContainsString('What Invites means', $html);
        $this->assertStringContainsString('ACTIVE_SITES_SEEN_KEY', $js);
        $this->assertStringContainsString('acknowledgeNewActive', $js);
        $this->assertStringContainsString('syncNewActiveBadges', $js);
        $this->assertStringContainsString('initSitePreviewZoom', $js);
        $this->assertStringContainsString('data-glass-tip', $html);
        $this->assertTrue(
            strpos($html, 'id="sitesFilterActive"') < strpos($html, 'id="sitesFilterPending"'),
            'Active filter should appear before Pending'
        );
        $this->assertStringContainsString('Approved / live', $html);
        $this->assertStringContainsString('Bulk drafts with the marketer', $html);
        $this->assertStringContainsString('What Active means', $html);
        $this->assertStringContainsString('What Pending means', $html);
        $this->assertStringNotContainsString('filter-denote', $html);
        $this->assertStringContainsString('let sitesStatusFilter =', $js);
        $this->assertStringContainsString("URLSearchParams(window.location.search).get('status')", $js);
        $this->assertStringContainsString('sitesStatusFilter', $js);
        $this->assertStringNotContainsString('sitesNewActiveBadge', $html.$js);
        $this->assertStringContainsString('openSiteVerificationDialog', $js);
        $this->assertStringContainsString('Verify this website', $js);
        $this->assertStringContainsString('.btn-verify-site', $js);
        $this->assertStringContainsString('verificationErrorTitle', $js);

        // Category picker matches Catalog main-search flow (shared multi-select.js).
        $this->assertStringContainsString('js/multi-select.js', $html);
        $this->assertStringContainsString('assets/css/multi-select.css', $html);
        $this->assertStringContainsString('id="categoryEmpty"', $html);
        $this->assertStringContainsString('No categories found', $html);
        $this->assertStringContainsString('Type to search categories', $html);
        $this->assertStringContainsString('window.initMultiSelect({', $js);
        $this->assertStringContainsString("emptyId: 'categoryEmpty'", $js);
        $multiJs = file_get_contents(public_path('js/multi-select.js'));
        $this->assertStringContainsString("e.key === 'Enter'", $multiJs);
        $this->assertStringContainsString("e.key === 'Backspace'", $multiJs);
        $this->assertStringContainsString('selectSoleOrFocused', $multiJs);
        $this->assertStringContainsString('removeLast', $multiJs);
        // A–Z Catalog niche list (not group→name).
        $this->assertStringContainsString('Category::catalogPickerNames()', file_get_contents(app_path('Http/Controllers/Publisher/SiteController.php')));

        $ajax = $this->actingAs($this->publisher)->get(route('publisher.sites.ajax', ['status' => 'active']));
        $ajax->assertOk();
        $ajaxHtml = $ajax->getContent();
        $this->assertTrue(
            str_contains($ajaxHtml, "O'Reilly News") || str_contains($ajaxHtml, 'O&#039;Reilly News'),
            'Ajax table should include the site name'
        );
        $this->assertStringContainsString('btn-edit', $ajaxHtml);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*btn-edit[^"]*"[^>]*data-id="\d+"/s',
            $ajaxHtml,
            'Edit buttons must expose data-id so the inline edit handler can call edit-data.'
        );
        $this->assertStringNotContainsString('<script', $ajaxHtml);
        $this->assertStringContainsString('🇺🇸', $ajaxHtml);
        $this->assertStringContainsString('sitesStatusMeta', $ajaxHtml);
        $this->assertStringContainsString('site-row-preview', $ajaxHtml);
        $this->assertStringContainsString('site-preview-zoom-pop', $ajaxHtml);
        $this->assertStringContainsString('object-fit: contain', $ajaxHtml);
        $this->assertStringContainsString('padding-top: 62.5%', $ajaxHtml);

        // Desktop 16:10 frame in the Preview column (Safari-safe padding hack).
        // Hover still opens a larger desktop popover.
        $this->assertMatchesRegularExpression(
            '/\.site-row-preview \{[^}]*width: 136px;/s',
            $ajaxHtml
        );
        $this->assertStringContainsString('col-preview', $ajaxHtml);
        $this->assertStringContainsString('>Preview</th>', $ajaxHtml);
        $this->assertStringContainsString('padding: 14px 16px', $ajaxHtml);
        $this->assertStringNotContainsString('width: 72px', $ajaxHtml);
        $this->assertStringNotContainsString('height: 48px', $ajaxHtml);
        $this->assertStringContainsString('data-label="Preview"', $ajaxHtml);
        $this->assertStringContainsString('>Preview</th>', $ajaxHtml);
        $this->assertStringContainsString('site-row-metrics', $ajaxHtml);
        $this->assertStringContainsString('btn-icon-quiet', $ajaxHtml);
        $this->assertStringContainsString('btn-edit', $ajaxHtml);
        $this->assertStringContainsString('site-status', $ajaxHtml);
        $this->assertStringContainsString('data-glass-tip', $ajaxHtml);
        $this->assertStringContainsString('sites-row-new-badge', $ajaxHtml);
        $this->assertStringContainsString('data-site-new-badge', $ajaxHtml);
        $this->assertStringNotContainsString('yt-tooltip', $ajaxHtml);
        $this->assertDoesNotMatchRegularExpression('/site-row-preview[^>]*(target="_blank"|href=)/', $ajaxHtml);
        $this->assertStringNotContainsString('<strong>Screenshot:</strong>', $ajaxHtml);
        $this->assertStringNotContainsString('btn-warning', $ajaxHtml);
        $this->assertStringNotContainsString('btn-outline-success', $ajaxHtml);
        $this->assertStringNotContainsString('badge bg-info status-badge', $ajaxHtml);
    }

    public function test_ajax_row_shows_screenshot_preview_when_present(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'screenshot_thumb_path' => 'sites/screenshots/thumb-demo.jpg',
            'screenshot_path' => 'sites/screenshots/demo.jpg',
        ]);

        $ajaxHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('site-row-preview', $ajaxHtml);
        $this->assertStringContainsString('/media/sites/screenshots/thumb-demo.jpg', $ajaxHtml);
        $this->assertStringContainsString('/storage/sites/screenshots/thumb-demo.jpg', $ajaxHtml);
        $this->assertStringContainsString('data-zoom-src', $ajaxHtml);
        $this->assertStringContainsString('data-zoom-chain', $ajaxHtml);
        $this->assertStringContainsString('/media/sites/screenshots/demo.jpg', $ajaxHtml);
        $this->assertStringContainsString('data-preview-chain', $ajaxHtml);
        $this->assertStringContainsString('alt="O&#039;Reilly News preview"', $ajaxHtml);
        $this->assertDoesNotMatchRegularExpression('/site-row-preview[^>]*(target="_blank"|href=)/', $ajaxHtml);
    }

    public function test_ajax_row_prefers_uploaded_cover_over_screenshot(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'site_image' => 'sites/admin-cover.webp',
            'screenshot_thumb_path' => 'site-screenshots/auto-thumb.webp',
            'screenshot_path' => 'site-screenshots/auto-full.webp',
        ]);

        $ajaxHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="site-row-preview"[^>]*>\s*<img[^>]+src="[^"]*\/media\/sites\/admin-cover\.webp"/',
            $ajaxHtml
        );
        $this->assertStringContainsString('/media/sites/admin-cover.webp', $ajaxHtml);
        $this->assertStringContainsString('/storage/sites/admin-cover.webp', $ajaxHtml);
    }

    public function test_ajax_row_skips_placeholder_screenshot_when_cover_exists(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'site_image' => 'sites/real-cover.webp',
            'screenshot_thumb_path' => 'site-screenshots/home-placeholder.webp',
            'screenshot_path' => 'site-screenshots/home-placeholder-full.webp',
        ]);

        $ajaxHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/media/sites/real-cover.webp', $ajaxHtml);
        $this->assertStringNotContainsString('home-placeholder', $ajaxHtml);
        $this->assertStringNotContainsString('site-screenshots/', $ajaxHtml);
    }

    public function test_ajax_filters_pending_active_and_invites_sites(): void
    {
        $pending = $this->makeSite([
            'site_name' => 'Pending Site',
            'site_url' => 'https://pending-site.example',
            'domain' => 'pending-site.example',
            'verified' => false,
            'active' => false,
        ]);
        $active = $this->makeSite([
            'site_name' => 'Active Site',
            'site_url' => 'https://active-site.example',
            'domain' => 'active-site.example',
            'verified' => true,
            'active' => true,
        ]);
        $invite = $this->makeSite([
            'site_name' => 'Invite Site',
            'site_url' => 'https://invite-site.example',
            'domain' => 'invite-site.example',
            'verified' => false,
            'active' => false,
            'publisher_accepted_at' => null,
            'assigned_by_user_id' => User::factory()->create([
                'email_verified_at' => now(),
            ])->id,
        ]);

        $pendingHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Pending Site', $pendingHtml);
        $this->assertStringNotContainsString('Active Site', $pendingHtml);
        $this->assertStringNotContainsString('Invite Site', $pendingHtml);
        $this->assertStringContainsString('data-pending="1"', $pendingHtml);
        $this->assertStringContainsString('data-active="1"', $pendingHtml);
        $this->assertStringContainsString('data-active-ids="'.$active->id.'"', $pendingHtml);

        $activeHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Active Site', $activeHtml);
        $this->assertStringNotContainsString('Pending Site', $activeHtml);
        $this->assertStringNotContainsString('Invite Site', $activeHtml);
        $this->assertTrue($pending->id !== $active->id);

        $inviteHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Invite Site', $inviteHtml);
        $this->assertStringNotContainsString('Pending Site', $inviteHtml);
        $this->assertStringNotContainsString('Active Site', $inviteHtml);
        $this->assertStringContainsString('data-status="invites"', $inviteHtml);
        $this->assertStringContainsString('site-status--invite', $inviteHtml);
        $this->assertStringContainsString('btn-accept-assignment', $inviteHtml);
        $this->assertStringContainsString('btn-reject-assignment', $inviteHtml);
    }

    public function test_leftover_publisher_accepted_at_stays_in_invites_not_my_sites(): void
    {
        $staff = User::factory()->create(['email_verified_at' => now()]);
        $invite = $this->makeSite([
            'site_name' => 'Leftover Accept Stamp',
            'site_url' => 'https://leftover-accept.example',
            'domain' => 'leftover-accept.example',
            'verified' => false,
            'active' => false,
            'assigned_by_user_id' => $staff->id,
            'publisher_accepted_at' => now(),
        ]);
        DB::table('sites')->where('id', $invite->id)->update([
            'publisher_accepted_at' => 'not-a-date',
        ]);

        $invite->refresh();
        $this->assertTrue($invite->isPendingPublisherAcceptance());
        $this->assertFalse($invite->isAcceptedByPublisher());
        $this->assertFalse($invite->needsAdminReview());
        $this->assertTrue(
            Site::query()->whereKey($invite->id)->pendingPublisherAcceptance()->exists()
        );
        $this->assertFalse(
            Site::query()->whereKey($invite->id)->acceptedByPublisher()->exists()
        );

        $inviteHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Leftover Accept Stamp', $inviteHtml);
        $this->assertStringContainsString('btn-accept-assignment', $inviteHtml);

        $pendingHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Leftover Accept Stamp', $pendingHtml);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.edit-data', $invite->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_active_and_pending_counts_exclude_archived_sites(): void
    {
        $live = $this->makeSite([
            'site_name' => 'Live Active',
            'site_url' => 'https://live-active.example',
            'domain' => 'live-active.example',
            'verified' => true,
            'active' => true,
        ]);
        $this->makeSite([
            'site_name' => 'Archived Active',
            'site_url' => 'https://archived-active.example',
            'domain' => 'archived-active.example',
            'verified' => true,
            'active' => true,
            'archived_at' => now(),
        ]);
        $this->makeSite([
            'site_name' => 'Live Pending',
            'site_url' => 'https://live-pending.example',
            'domain' => 'live-pending.example',
            'verified' => false,
            'active' => false,
        ]);
        $this->makeSite([
            'site_name' => 'Archived Pending',
            'site_url' => 'https://archived-pending.example',
            'domain' => 'archived-pending.example',
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $activeHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live Active', $activeHtml);
        $this->assertStringNotContainsString('Archived Active', $activeHtml);
        $this->assertStringContainsString('data-active="1"', $activeHtml);
        $this->assertStringContainsString('data-active-ids="'.$live->id.'"', $activeHtml);

        $pendingHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live Pending', $pendingHtml);
        $this->assertStringNotContainsString('Archived Pending', $pendingHtml);
        $this->assertStringContainsString('data-pending="1"', $pendingHtml);
    }

    public function test_accept_decline_verify_handlers_bind_when_inline_owns_page(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));

        $this->assertStringContainsString('window.__publisherWebsitesInlineLoaded = true', $html);
        $this->assertStringContainsString('window.setSitesStatusFilter', $html);
        $this->assertStringContainsString('syncSitesStatusUrl', $html);
        $this->assertStringContainsString('history.replaceState', $html);
        $this->assertStringContainsString('.site-status-filter.is-active', $html);
        $this->assertStringContainsString('background: #0f766e', $html);

        $gateEnd = strpos($js, '})(); // publisherWebsitesExternalBoot');
        $alwaysOn = strpos($js, 'publisherWebsitesAlwaysOnActions');
        $accept = strpos($js, "$(document).on('click', '.btn-accept-assignment'");
        $reject = strpos($js, "$(document).on('click', '.btn-reject-assignment'");
        $verify = strpos($js, "$(document).on('click', '.btn-verify-site'");

        $this->assertNotFalse($gateEnd);
        $this->assertNotFalse($alwaysOn);
        $this->assertNotFalse($accept);
        $this->assertNotFalse($reject);
        $this->assertNotFalse($verify);
        $this->assertGreaterThan($gateEnd, $alwaysOn, 'Always-on boot must run after the inline skip gate closes');
        $this->assertGreaterThan($alwaysOn, $accept, 'Accept handler must live in always-on boot');
        $this->assertGreaterThan($alwaysOn, $reject);
        $this->assertGreaterThan($alwaysOn, $verify);
        $this->assertStringContainsString('setPublisherSitesFilter', $js);
        $this->assertStringContainsString('reloadPublisherSitesTable', $js);
        $this->assertStringContainsString('window.loadSites', $js);
    }

    public function test_pending_ajax_shows_bulk_waiting_items_and_stage_chips(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://waiting-a.example',
            'domain' => 'waiting-a.example',
            'price' => 120,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://waiting-b.example',
            'domain' => 'waiting-b.example',
            'price' => 90,
        ]);

        $needsDetails = $this->makeSite([
            'site_name' => 'Needs Details Site',
            'site_url' => 'https://needs-details.example',
            'domain' => 'needs-details.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'verified' => false,
            'active' => false,
        ]);
        $readyReview = $this->makeSite([
            'site_name' => 'Ready Review Site',
            'site_url' => 'https://ready-review.example',
            'domain' => 'ready-review.example',
            'onboarding_status' => Site::ONBOARDING_DETAILS_COMPLETE,
            'verified' => false,
            'active' => false,
        ]);
        $withAdmin = $this->makeSite([
            'site_name' => 'With Admin Site',
            'site_url' => 'https://with-admin.example',
            'domain' => 'with-admin.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'verified' => false,
            'active' => false,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('waiting-a.example', $html);
        $this->assertStringContainsString('waiting-b.example', $html);
        $this->assertStringContainsString('With marketer', $html);
        $this->assertStringContainsString('No edit yet', $html);
        $this->assertStringContainsString('Needs your details', $html);
        $this->assertStringContainsString('Ready to review', $html);
        $this->assertStringContainsString('With admin', $html);
        $this->assertStringContainsString('data-bulk-waiting="2"', $html);
        $this->assertStringContainsString('data-open-bulk="1"', $html);
        // 2 waiting items + 3 pending sites
        $this->assertStringContainsString('data-pending="5"', $html);
        $this->assertStringContainsString((string) $needsDetails->id, $html);
        $this->assertStringContainsString((string) $readyReview->id, $html);
        $this->assertStringContainsString((string) $withAdmin->id, $html);
    }

    public function test_pending_empty_state_mentions_open_bulk_request(): void
    {
        BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 5,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bulk request #', $html);
        $this->assertStringContainsString('is in progress', $html);
        $this->assertStringContainsString('data-open-bulk="1"', $html);
        $this->assertStringNotContainsString('No pending sites waiting for admin approval', $html);
    }

    public function test_empty_active_with_only_pending_points_to_pending(): void
    {
        $this->makeSite([
            'site_name' => 'Draft Only',
            'site_url' => 'https://draft-only.example',
            'domain' => 'draft-only.example',
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No live sites yet.', $html);
        $this->assertStringContainsString('in Pending', $html);
        $this->assertStringContainsString('data-switch-status="pending"', $html);
        $this->assertStringContainsString('data-pending="1"', $html);
        $this->assertStringContainsString('data-active="0"', $html);
        $this->assertStringNotContainsString('Draft Only', $html);
        $this->assertStringNotContainsString('id="emptyAddSiteCta"', $html);
    }

    public function test_empty_active_with_only_invite_points_to_invites(): void
    {
        $this->makeSite([
            'site_name' => 'Invite Only',
            'site_url' => 'https://invite-only.example',
            'domain' => 'invite-only.example',
            'publisher_accepted_at' => null,
            'assigned_by_user_id' => User::factory()->create([
                'email_verified_at' => now(),
            ])->id,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No live sites yet.', $html);
        $this->assertStringContainsString('in Invites', $html);
        $this->assertStringContainsString('data-switch-status="invites"', $html);
        $this->assertStringContainsString('data-invites="1"', $html);
        $this->assertStringContainsString('data-pending="0"', $html);
        $this->assertStringNotContainsString('Invite Only', $html);
        $this->assertStringNotContainsString('id="emptyAddSiteCta"', $html);
    }

    public function test_empty_active_with_no_sites_keeps_add_cta(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="emptyAddSiteCta"', $html);
        $this->assertStringContainsString('Add your first site', $html);
        $this->assertStringContainsString('Add New Website', $html);
        $this->assertStringNotContainsString('in Pending', $html);
        $this->assertStringNotContainsString('data-switch-status="pending"', $html);
        $this->assertStringNotContainsString('data-switch-status="invites"', $html);
        $this->assertStringContainsString('data-pending="0"', $html);
        $this->assertStringContainsString('data-invites="0"', $html);
    }

    public function test_explicit_status_active_stays_on_active_when_pending_exist(): void
    {
        $this->makeSite([
            'site_name' => 'Still Pending',
            'site_url' => 'https://still-pending.example',
            'domain' => 'still-pending.example',
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-status="active"', $html);
        $this->assertStringContainsString('data-switch-status="pending"', $html);
        $this->assertStringNotContainsString('Still Pending', $html);

        $page = $this->actingAs($this->publisher)
            ->get(route('publisher.websites', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('sitesStatusExplicit = params.has(\'status\')', $page);
        $this->assertStringContainsString('!sitesStatusExplicit', $page);
        $this->assertStringContainsString("window.setSitesStatusFilter('pending')", $page);
        $this->assertStringContainsString('sitesAutoOpenPendingChecked', $page);
        $this->assertStringContainsString('sitesStatusFilter === \'active\'', $page);
    }

    public function test_my_sites_wires_empty_state_switch_and_auto_opens_pending_once(): void
    {
        $page = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("$(document).on('click', '[data-switch-status]'", $page);
        $this->assertStringContainsString('window.setSitesStatusFilter(next)', $page);
        $this->assertStringContainsString('Auto-open Pending when Active is empty and the URL did not set ?status=', $page);
        $this->assertSame(
            1,
            preg_match_all('/\blet\s+delayTimer\b/', $page),
            'Rendered My Sites page must declare delayTimer only once.'
        );
    }

    public function test_dual_role_advertiser_active_can_load_pending_sites_ajax(): void
    {
        // Typical marketplace account: Advertiser + Publisher, still active as Advertiser.
        // Deep link / My Sites Pending must auto-activate Publisher instead of 403.
        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $user->roles()->attach([$advertiserRole->id, $publisherRole->id]);

        $this->makeSite([
            'publisher_id' => $user->id,
            'site_name' => 'Dual Role Pending',
            'site_url' => 'https://dual-pending.example',
            'domain' => 'dual-pending.example',
            'verified' => false,
            'active' => false,
        ]);

        $this->assertSame('advertiser', $user->fresh()->activeRole());

        $html = $this->actingAs($user)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Dual Role Pending', $html);
        $this->assertSame('publisher', $user->fresh()->activeRole());
        $this->assertStringContainsString('Catalog preview of your listing', $html);
        $this->assertStringContainsString('Open in catalog', $html);
        $this->assertStringContainsString(
            route('advertiser.catalog', ['site' => Site::query()->where('site_name', 'Dual Role Pending')->value('id')], false),
            $html
        );
    }

    public function test_ajax_expand_shows_buyer_preview_without_catalog_link_for_publisher_only(): void
    {
        $site = $this->makeSite([
            'verified' => true,
            'active' => true,
            'example_url' => 'https://oreilly-news.example/sample',
            'site_image' => 'sites/covers/oreilly.webp',
            'sponsored' => true,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('mysites-buyer-preview', $html);
        $this->assertStringContainsString('Catalog preview of your listing', $html);
        $this->assertStringNotContainsString('How advertisers see this', $html);
        $this->assertStringContainsString('Prices are your list — not the advertiser total.', $html);
        $this->assertStringContainsString('mysites-catalog-preview', $html);
        $this->assertStringContainsString('mysites-catalog-preview__table', $html);
        $this->assertStringNotContainsString('mysites-catalog-preview catalog-page', $html);
        $this->assertStringContainsString('catalog-site-name', $html);
        $this->assertStringContainsString('Homepage preview', $html);
        $this->assertStringContainsString('catalog-expand-grid', $html);
        $this->assertStringContainsString('mysites-catalog-preview__details-fold', $html);
        $this->assertStringNotContainsString('<details class="mysites-catalog-preview__details-fold" open', $html);
        $this->assertStringContainsString('Add to cart', $html);
        $this->assertStringContainsString('Preview only', $html);
        $this->assertStringContainsString('Listing checklist', $html);
        $this->assertStringContainsString('All 6 ready', $html);
        $this->assertStringContainsString('mysites-buyer-preview__ready-chip', $html);
        $this->assertStringNotContainsString('What you still own on this listing.', $html);
        $this->assertStringNotContainsString('to fix', $html);
        $this->assertStringContainsString('Marketplace country', $html);
        $this->assertStringContainsString('Sample article URL', $html);
        $this->assertStringContainsString('Publication duration', $html);
        $this->assertStringContainsString('Turnaround', $html);
        $this->assertStringContainsString('publisherSitePreviewOnError', $html);
        $this->assertStringNotContainsString('Cover or screenshot', $html);
        $this->assertStringNotContainsString('Example URL:', $html);
        $this->assertStringNotContainsString('Publication Duration:', $html);
        $this->assertStringNotContainsString('Turnaround Time:', $html);
        $this->assertStringNotContainsString('catalog-expand-trust', $html);
        $this->assertStringContainsString('site-trust-compact', $html);
        $this->assertStringContainsString('catalog-price', $html);
        $this->assertStringContainsString('€80.00', $html);
        $this->assertStringNotContainsString('Your price:', $html);
        $this->assertStringNotContainsString('(your list)', $html);
        $this->assertStringNotContainsString('You pay:', $html);
        $this->assertStringNotContainsString('catalogPricesForViewer', $html);
        $advertiserPay = app(PlatformFeeService::class)
            ->advertiserBase((float) $site->price);
        $this->assertNotEquals(80.0, $advertiserPay);
        $this->assertStringNotContainsString('€'.number_format($advertiserPay, 2), $html);
        $this->assertStringNotContainsString('Open in catalog', $html);
        $this->assertStringNotContainsString(route('advertiser.catalog', ['site' => $site->id]), $html);

        $page = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('catalog.css', $page);
        $this->assertStringContainsString('publisher-websites.css', $page);
        $this->assertGreaterThan(
            strpos($page, 'catalog.css'),
            strpos($page, 'publisher-websites.css'),
            'My Sites CSS must load after catalog.css so preview padding wins over .catalog-expand-cell.'
        );
    }

    public function test_ajax_empty_preview_says_no_cover_yet_not_publisher_error(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'site_image' => null,
            'screenshot_path' => null,
            'screenshot_thumb_path' => null,
        ]);

        $this->assertNull(Site::publicDiskUrl(null));

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No cover yet', $html);
        $this->assertStringContainsString('Staff will add a homepage screenshot', $html);
        $this->assertStringContainsString('catalog-cover-empty', $html);
        $this->assertStringContainsString('mysites-catalog-preview', $html);
        $this->assertStringContainsString('site-row-preview is-empty', $html);
        $this->assertStringNotContainsString('Cover missing', $html);
        $this->assertStringNotContainsString('Cover or screenshot', $html);
        $this->assertStringNotContainsString('aria-label="No preview"', $html);

        $keys = array_column(CatalogBuyerReadiness::checklist(Site::query()->first()), 'key');
        $this->assertNotContains('cover', $keys);
        $this->assertContains('example_url', $keys);
        $this->assertContains('brief', $keys);
    }

    public function test_ajax_preview_empty_country_says_no_country(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'country' => '',
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No country', $html);
        $this->assertStringContainsString('data-label="Country"', $html);
        $this->assertStringContainsString('Marketplace country', $html);
        $this->assertStringContainsString('Set country', $html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*mysites-buyer-preview__gap-cta[^"]*"[^>]*data-wizard-step="2"[^>]*>\s*Set country/s',
            $html,
            'Set country must open wizard step 2 (market + niche).'
        );
        $this->assertStringContainsString('to fix', $html);
        $this->assertTrue(
            strpos($html, 'mysites-buyer-preview__gap') < strpos($html, 'mysites-buyer-preview__ready-chip'),
            'Gaps must render before ready chips.'
        );
    }

    public function test_ajax_preview_checklist_puts_tag_gap_first_with_cta(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'example_url' => 'https://oreilly-news.example/sample',
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1 to fix', $html);
        $this->assertStringContainsString('5 of 6 ready', $html);
        $this->assertStringContainsString('Set listing tag', $html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*mysites-buyer-preview__gap-cta[^"]*btn-edit[^"]*"[^>]*data-id="\d+"[^>]*data-wizard-step="3"/s',
            $html,
            'Set listing tag must open Edit on wizard step 3 (tags).'
        );
        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));
        $this->assertStringContainsString("if ($(this).data('id'))", $js);
        $this->assertStringContainsString('Checklist CTAs only have data-id', $js);
        $this->assertStringContainsString("$(this).data('wizardStep')", $js);
        $this->assertStringContainsString('Sponsored, Partner article, or As you prefer', $html);
        $this->assertStringNotContainsString('What you still own on this listing.', $html);
        $gapPos = strpos($html, 'Set listing tag');
        $readyPos = strpos($html, 'mysites-buyer-preview__ready-chip');
        $this->assertNotFalse($gapPos);
        $this->assertNotFalse($readyPos);
        $this->assertLessThan($readyPos, $gapPos);
    }

    public function test_ajax_preview_sensitive_addons_use_publisher_amounts_without_repeating_list(): void
    {
        $site = $this->makeSite([
            'verified' => true,
            'active' => true,
            'sensitive_prices' => ['crypto' => 15],
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sensitive topics', $html);
        $this->assertStringContainsString('add-on +€15.00', $html);
        $this->assertStringContainsString('€80.00', $html);
        $this->assertStringNotContainsString('Your price:', $html);
        $this->assertStringNotContainsString('You pay:', $html);
        $advertiserPay = app(PlatformFeeService::class)
            ->advertiserBase((float) $site->price);
        $this->assertStringNotContainsString('€'.number_format($advertiserPay, 2), $html);
    }
}
