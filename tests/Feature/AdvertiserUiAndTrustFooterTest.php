<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserUiAndTrustFooterTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->advertiser->roles()->attach($role->id);
    }

    private function bladeSource(string $name): string
    {
        return (string) file_get_contents(resource_path('views/advertiser/'.$name.'.blade.php'));
    }

    public function test_order_modal_links_go_through_the_url_guard(): void
    {
        $blade = $this->bladeSource('orders');

        // safeUrl() rejects anything that is not http(s) or root-relative; plain
        // escaping would still let a stored javascript: URL become clickable.
        foreach (['item.site_url', 'item.content_link', 'item.target_url', 'item.feature_image_url', 'details.website_url'] as $field) {
            $this->assertStringContainsString('safeUrl('.$field.')', $blade);
            $this->assertStringNotContainsString('href="${escapeHtml('.$field.')}"', $blade);
        }

        $this->assertDoesNotMatchRegularExpression('/href="\$\{escapeHtml\(/', $blade);
    }

    public function test_orders_page_defines_escape_helper_once(): void
    {
        $this->assertSame(1, substr_count($this->bladeSource('orders'), 'function escapeHtml('));
    }

    public function test_checkout_shows_one_reference_code(): void
    {
        $blade = $this->bladeSource('checkout');

        // Two separate mt_rand() calls used to print two different codes in the
        // same panel, then JavaScript replaced both with a third.
        $this->assertSame(0, substr_count($blade, "sprintf('%06d', mt_rand(1, 999999))"));
        $this->assertSame(1, substr_count($blade, '$checkoutReference = sprintf'));
        $this->assertSame(2, substr_count($blade, '{{ $checkoutReference }}'));
        $this->assertStringNotContainsString(
            'let referenceCode = Math.floor(100000 + Math.random() * 900000).toString();',
            $blade
        );
    }

    public function test_checkout_does_not_reload_font_awesome(): void
    {
        $this->assertStringNotContainsString('font-awesome/6.0.0/css/all.min.css', $this->bladeSource('checkout'));
    }

    public function test_every_close_button_on_these_pages_has_a_name(): void
    {
        foreach (['content-library', 'add-funds', 'checkout', 'orders'] as $page) {
            $blade = $this->bladeSource($page);
            $this->assertGreaterThan(0, substr_count($blade, 'btn-close'), $page.' should have a close button');
            $this->assertDoesNotMatchRegularExpression(
                '/class="btn-close[^"]*"(?![^>]*aria-label)[^>]*>/',
                $blade,
                $page.' has a btn-close without aria-label'
            );
        }
    }

    public function test_icon_only_copy_buttons_are_labelled(): void
    {
        foreach (['checkout', 'add-funds'] as $page) {
            $this->assertStringContainsString('aria-label="Copy reference code"', $this->bladeSource($page));
        }
    }

    public function test_filters_are_wired_to_their_labels(): void
    {
        $orders = $this->bladeSource('orders');
        foreach (['searchInput', 'statusFilter', 'paymentMethodFilter', 'paymentStatusFilter', 'dateFrom'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $orders);
        }
        $this->assertStringContainsString('aria-label="Orders from date"', $orders);
        $this->assertStringContainsString('aria-label="Orders to date"', $orders);

        $library = $this->bladeSource('content-library');
        foreach (['librarySearchInput', 'libraryCountryFilter', 'libraryLanguageFilter'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $library);
        }

        $funds = $this->bladeSource('add-funds');
        foreach (['customAmount', 'walletHistorySearch', 'walletHistoryType', 'walletHistoryStatus'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $funds);
        }
    }

    public function test_library_table_scrolls_unless_a_row_menu_is_open(): void
    {
        $blade = $this->bladeSource('content-library');

        $this->assertStringContainsString('.library-table .table-responsive', $blade);
        $this->assertStringContainsString('overflow-x: auto', $blade);
        $this->assertStringContainsString('.library-table.is-menu-open .table-responsive', $blade);
        // The class is only useful if something toggles it.
        $this->assertStringContainsString("classList.add('is-menu-open')", $blade);
        $this->assertStringContainsString("classList.remove('is-menu-open')", $blade);
    }

    public function test_orders_pagination_is_windowed_and_counts_results(): void
    {
        $blade = $this->bladeSource('orders');

        $this->assertStringContainsString('aria-current="page"', $blade);
        $this->assertStringContainsString('Math.max(1, current - 2)', $blade);
        // Enumerating every page breaks once an advertiser has hundreds of orders.
        $this->assertStringNotContainsString('for (let i = 1; i <= pagination.last_page; i++)', $blade);
        // #resultsCount used to be cleared and never filled.
        $this->assertStringContainsString('of ${total} order', $blade);
    }

    public function test_orders_drops_dead_markup_and_fixed_widths(): void
    {
        $blade = $this->bladeSource('orders');

        $this->assertStringNotContainsString('width="150"', $blade);
        $this->assertStringContainsString('orders-action-col', $blade);
        $this->assertStringNotContainsString('/* Dark mode styles */', $blade);
        $this->assertStringContainsString('orders-id-clamp', $blade);
    }

    public function test_the_four_pages_still_render(): void
    {
        foreach (['advertiser.content-library', 'advertiser.add-funds', 'advertiser.orders'] as $route) {
            $this->actingAs($this->advertiser)->get(route($route))->assertOk();
        }
    }

    public function test_footer_links_the_trustpilot_profile(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(config('services.trustpilot.review_url'), $html);
        $this->assertStringContainsString('trustpilot-trust', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('Read our reviews', $html);
    }

    public function test_the_trust_badge_claims_no_rating_we_cannot_prove(): void
    {
        $partial = (string) file_get_contents(resource_path('views/partials/trustpilot-trust.blade.php'));

        // Printing a score or review count we do not hold would be misleading.
        $this->assertDoesNotMatchRegularExpression('/\d(\.\d)?\s*(\/\s*5|out of 5|stars?)/i', $partial);
        $this->assertStringNotContainsString('reviews)', $partial);
    }

    public function test_review_and_evaluate_urls_are_used_for_their_own_purpose(): void
    {
        $this->assertStringContainsString('/review/', (string) config('services.trustpilot.review_url'));
        $this->assertStringContainsString('/evaluate/', (string) config('services.trustpilot.evaluate_url'));

        // The email asks for a review, so it must open the write-a-review form.
        $mail = (string) file_get_contents(app_path('Mail/TrustpilotReviewRequest.php'));
        $this->assertStringContainsString("config('services.trustpilot.evaluate_url')", $mail);
    }

    public function test_trustpilot_strings_exist_in_every_locale(): void
    {
        foreach (['en', 'de', 'fr', 'nl'] as $locale) {
            $messages = require resource_path('lang/'.$locale.'/messages.php');
            foreach (['trustpilot_read_reviews', 'trustpilot_aria'] as $key) {
                $this->assertArrayHasKey($key, $messages, $locale.' is missing '.$key);
                $this->assertNotSame('', trim((string) $messages[$key]));
            }
        }
    }
}
