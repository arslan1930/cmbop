<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRating;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUiTier2LayoutA11yTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    public function test_admin_tables_css_defines_the_id_clamp_utility(): void
    {
        $css = file_get_contents(public_path('assets/css/admin-tables.css'));

        $this->assertStringContainsString('.admin-id-clamp', $css);
        $this->assertStringContainsString('text-overflow: ellipsis', $css);
        $this->assertStringContainsString('white-space: nowrap', $css);
        $this->assertStringContainsString('.admin-id-col', $css);
        $this->assertStringContainsString('.admin-actions-wide-col', $css);
    }

    public function test_orders_and_payments_clamp_long_identifiers(): void
    {
        $orders = $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('admin-id-col', $orders);
        $this->assertStringContainsString('admin-id-clamp', $orders);

        $payments = $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('admin-id-col', $payments);
        // Both the order number and the reference code get clamped.
        $this->assertSame(2, substr_count($payments, 'admin-id-clamp'));
    }

    public function test_invoice_order_number_is_clamped_with_full_value_in_title(): void
    {
        $longOrderNumber = 'ORD-'.str_repeat('LONG-', 30);

        Invoice::create([
            'invoice_number' => 'INV-TIER2-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'user_id' => $this->admin->id,
            'customer_name' => 'Tier Two Customer',
            'customer_email' => 'tier2@example.com',
            'order_number' => $longOrderNumber,
            'subtotal' => 100,
            'total_amount' => 100,
            'status' => Invoice::STATUS_ISSUED,
            'invoice_date' => now(),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.invoices.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('admin-id-clamp', $html);
        $this->assertStringContainsString('title="'.$longOrderNumber.'"', $html);
    }

    public function test_promotions_previews_stick_below_the_topbar(): void
    {
        $shell = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('.sticky-below-topbar', $shell);
        $this->assertStringContainsString('calc(var(--shell-topbar-height) + 1rem)', $shell);

        foreach (['admin.promotions.banners.create', 'admin.promotions.announcements.create'] as $route) {
            $html = $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('sticky-below-topbar', $html);
            $this->assertStringNotContainsString('sticky-top" style="top: 1rem;"', $html);
        }
    }

    public function test_modal_close_buttons_have_accessible_names(): void
    {
        $routes = ['admin.payments', 'admin.deposits', 'admin.withdrawals', 'admin.blogs.index'];

        foreach ($routes as $route) {
            $html = $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->getContent();

            $this->assertGreaterThan(0, substr_count($html, 'btn-close'), $route.' should render a close button');
            $this->assertDoesNotMatchRegularExpression(
                '/class="btn-close[^"]*"(?![^>]*aria-label)[^>]*>/',
                $html,
                $route.' has a btn-close without aria-label'
            );
        }
    }

    public function test_blog_row_actions_are_labelled_for_screen_readers(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.blogs.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="View ', $html);
        $this->assertStringContainsString('aria-label="Edit ', $html);
        $this->assertStringContainsString('aria-label="Delete ', $html);
        $this->assertMatchesRegularExpression('/aria-label="(Publish|Unpublish) /', $html);
    }

    public function test_filter_inputs_are_associated_with_labels(): void
    {
        $logs = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->getContent();

        foreach (['logUser', 'logAction', 'logFrom', 'logTo'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $logs);
            $this->assertStringContainsString('id="'.$id.'"', $logs);
        }

        $users = $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('for="userSearch"', $users);

        $payments = $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();
        foreach (['searchInput', 'paymentStatusFilter', 'paymentMethodFilter', 'orderStatusFilter'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $payments);
        }
        $this->assertStringContainsString('aria-label="From date"', $payments);
        $this->assertStringContainsString('aria-label="To date"', $payments);
        $this->assertStringContainsString('for="dateFrom"', $payments);
        $this->assertStringContainsString('aria-label="Date field"', $payments);

        $withdrawals = $this->actingAs($this->admin)
            ->get(route('admin.withdrawals'))
            ->assertOk()
            ->getContent();
        foreach (['queueFilter', 'statusFilter', 'paymentMethodFilter', 'dateFrom', 'dateTo', 'searchInput'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $withdrawals);
        }
        $this->assertStringContainsString('aria-label="Requested from date"', $withdrawals);
        $this->assertStringContainsString('aria-label="Requested to date"', $withdrawals);
        $this->assertStringContainsString('for="selectAll"', $withdrawals);
    }

    public function test_rating_stars_are_announced_once(): void
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Rated Site',
            'site_url' => 'https://rated.example',
            'domain' => 'rated.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 900,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Rated site for a11y test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => 1,
        ]);

        SiteRating::create([
            'site_id' => $site->id,
            'user_id' => $this->admin->id,
            'rating' => 4,
            'comment' => 'Solid publisher',
            'status' => 'approved',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.site-ratings.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="4 out of 5 stars"', $html);
        $this->assertStringContainsString('fa-star text-warning" aria-hidden="true"', $html);
        $this->assertStringNotContainsString('<th width="160">', $html);
    }

    public function test_community_action_columns_drop_pixel_widths(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.community.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('width="200"', $html);
        $this->assertStringNotContainsString('width="220"', $html);
        $this->assertStringContainsString('admin-actions-wide-col', $html);
    }
}
