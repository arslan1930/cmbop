<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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
            'const id = $(this).data(\'id\') || siteHint.id;',
            $html,
            'Edit click handler must resolve the site id from data-id or data-site.'
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

    public function test_discount_badges_follow_better_of_and_explain_advertiser_rate(): void
    {
        $this->makeSite([
            'verified' => true,
            'active' => true,
            'price' => 100,
            'custom_discount_percent' => 20,
            'custom_discount_starts_at' => now()->subDay(),
            'custom_discount_ends_at' => now()->addDays(5),
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        // Configured sale stays on the badge; bulk membership stays visible even
        // when the timed sale wins packs (better-of, not stacked).
        $this->assertStringContainsString('−20%', $html);
        $this->assertStringContainsString('Timed sale −20% (configured)', $html);
        $this->assertStringContainsString('Advertisers see about −11.5%', $html);
        $this->assertStringContainsString('exclusive better-of with bulk, not stacked', $html);
        $this->assertStringContainsString('Bulk −15%', $html);
        $this->assertStringContainsString('Advertisers from €', $html);
        $this->assertStringContainsString('Timed sale is stronger on packs too', $html);
        $this->assertStringContainsString('site-row-actions__offers', $html);
        $this->assertStringContainsString('Sale −20%', $html);
        $this->assertStringContainsString('site-offer-chip', $html);
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
        $this->assertStringContainsString('sitesFilterArchived', $html);
        $this->assertStringContainsString('What Invites means', $html);
        $this->assertStringContainsString('What Archive means', $html);
        $this->assertStringContainsString('ACTIVE_SITES_SEEN_KEY', $js);
        $this->assertStringContainsString('acknowledgeNewActive', $js);
        $this->assertStringContainsString('syncNewActiveBadges', $js);
        $this->assertStringContainsString('initSitePreviewZoom', $js);
        $this->assertStringContainsString('data-glass-tip', $html);
        $this->assertTrue(
            strpos($html, 'id="sitesFilterActive"') < strpos($html, 'id="sitesFilterPending"'),
            'Active filter should appear before Pending'
        );
        $this->assertTrue(
            strpos($html, 'id="sitesFilterInvites"') < strpos($html, 'id="sitesFilterArchived"'),
            'Invites filter should appear before Archive'
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
        $this->assertStringNotContainsString('placeholder', $ajaxHtml);
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
        $this->assertStringContainsString('data-archived="2"', $activeHtml);
        $this->assertStringContainsString('data-archived="2"', $pendingHtml);
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
        $this->assertStringContainsString('site-status-filter is-active', $html);
        $css = file_get_contents(public_path('assets/css/publisher-websites.css'));
        $this->assertStringContainsString('.site-status-filter.is-active', $css);
        $this->assertStringContainsString('background: #0f766e', $css);

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

    public function test_archive_tab_shows_restore_and_live_rows_show_archive(): void
    {
        $live = $this->makeSite([
            'site_name' => 'Live For Archive',
            'site_url' => 'https://live-for-archive.example',
            'domain' => 'live-for-archive.example',
            'verified' => true,
            'active' => true,
        ]);
        $draft = $this->makeSite([
            'site_name' => 'Draft No Archive',
            'site_url' => 'https://draft-no-archive.example',
            'domain' => 'draft-no-archive.example',
        ]);

        $activeHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('btn-archive-site', $activeHtml);
        $this->assertStringContainsString('data-id="'.$live->id.'"', $activeHtml);
        $this->assertStringNotContainsString('btn-unarchive-site', $activeHtml);
        $this->assertStringNotContainsString('btn-delete', $activeHtml);

        $pendingHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Draft No Archive', $pendingHtml);
        $this->assertStringContainsString('btn-delete', $pendingHtml);
        $this->assertStringNotContainsString('btn-archive-site', $pendingHtml);
        $this->assertStringContainsString((string) $draft->id, $pendingHtml);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.archive', $live->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $archivedHtml = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'archived']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live For Archive', $archivedHtml);
        $this->assertStringContainsString('btn-unarchive-site', $archivedHtml);
        $this->assertStringNotContainsString('btn-archive-site', $archivedHtml);
        $this->assertStringContainsString('data-archived="1"', $archivedHtml);
        $this->assertStringContainsString('data-status="archived"', $archivedHtml);

        $activeAfter = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Live For Archive', $activeAfter);
        $this->assertStringContainsString('are archived', $activeAfter);
        $this->assertStringContainsString('data-switch-status="archived"', $activeAfter);
    }

    public function test_empty_active_with_only_archived_points_to_archive(): void
    {
        $this->makeSite([
            'site_name' => 'Only Archived',
            'site_url' => 'https://only-archived.example',
            'domain' => 'only-archived.example',
            'verified' => true,
            'active' => true,
            'archived_at' => now(),
        ]);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'active']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No live sites yet.', $html);
        $this->assertStringContainsString('are archived', $html);
        $this->assertStringContainsString('data-switch-status="archived"', $html);
        $this->assertStringContainsString('data-archived="1"', $html);
        $this->assertStringNotContainsString('Only Archived', $html);
        $this->assertStringNotContainsString('id="emptyAddSiteCta"', $html);
    }

    public function test_archived_empty_state_and_url_allow_list(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'archived']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No archived sites', $html);
        $this->assertStringContainsString('data-status="archived"', $html);

        $page = $this->actingAs($this->publisher)
            ->get(route('publisher.websites', ['status' => 'archived']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("status === 'archived'", $page);
        $this->assertStringContainsString('window.setSitesStatusFilter = function', $page);
        $this->assertMatchesRegularExpression(
            "/status === 'pending' \|\| status === 'invites' \|\| status === 'archived'/",
            $page
        );
    }

    public function test_my_sites_title_doors_and_rereview_confirm(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/websites.blade.php'));
        $this->assertStringContainsString("@section('title', 'My Sites')", $blade);
        $this->assertStringContainsString('id="sitesAddDoorsHint"', $blade);
        $this->assertStringContainsString('one site, you fill every field', $blade);
        $this->assertStringContainsString('send URL + price', $blade);
        $this->assertStringContainsString('Send this site for re-review?', $blade);
        $this->assertStringContainsString('function marketChangedFromSnapshot', $blade);
        $this->assertStringContainsString('window.siteRereviewConfirmed', $blade);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('My Sites — SEOLinkBuildings', $html);
        $this->assertStringContainsString('id="formHeader">My Sites</span>', $html);
        $this->assertStringContainsString('id="sitesAddDoorsHint"', $html);
        $this->assertStringContainsString('Add New Website', $html);
        $this->assertStringContainsString('I want to add many sites', $html);
        $this->assertStringContainsString('Bulk Import (Agency)', $html);
        $this->assertStringContainsString('Send this site for re-review?', $html);
        $this->assertSame(
            1,
            preg_match_all('/\blet\s+delayTimer\b/', $html),
            'Rendered My Sites page must declare delayTimer only once.'
        );
    }

    public function test_my_sites_does_not_reload_jquery_or_sweetalert(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/websites.blade.php'));
        $this->assertStringNotContainsString('cdnjs.cloudflare.com/ajax/libs/jquery', $blade);
        $this->assertStringNotContainsString('sweetalert2@11', $blade);
        $this->assertStringContainsString("@push('scripts')", $blade);
        $this->assertStringContainsString('window.__publisherWebsitesInlineLoaded = true', $blade);
        $this->assertStringNotContainsString('transition: all 0.2s ease', $blade);

        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'sweetalert2@11'),
            'My Sites should use the layout SweetAlert2, not a second copy.'
        );
        $this->assertSame(
            0,
            substr_count($html, 'cdnjs.cloudflare.com/ajax/libs/jquery'),
            'My Sites should use the layout jQuery, not a CDN reload.'
        );
        $this->assertStringContainsString('window.__publisherWebsitesInlineLoaded = true', $html);
        $this->assertSame(
            1,
            preg_match_all('/\blet\s+delayTimer\b/', $html),
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
    }
}
