<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSitesVerticalLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function adminUser(): User
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_admin_tables_css_defines_vertical_fit_rules(): void
    {
        $css = file_get_contents(public_path('assets/css/admin-tables.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('.admin-table-fit', $css);
        $this->assertStringContainsString('table-layout: fixed', $css);
        $this->assertStringContainsString('overflow-x: clip', $css);
        $this->assertStringContainsString('.admin-manage-dropdown', $css);
        $this->assertStringContainsString('.admin-contained-scroll', $css);
        $this->assertStringContainsString('overflow-wrap: break-word', $css);
        $this->assertStringContainsString('.admin-col-email', $css);
        $this->assertStringContainsString('.admin-role-badges', $css);
        $this->assertStringContainsString('.admin-sites-count-col', $css);
        $this->assertStringContainsString('.admin-sites-count-badges', $css);
        $this->assertFileEquals(
            public_path('assets/css/admin-tables.css'),
            public_path('assets/css/admin-tables.css')
        );
    }

    public function test_admin_and_marketing_layouts_load_admin_tables_css(): void
    {
        $admin = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $marketing = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));

        $this->assertStringContainsString('admin-tables.css', $admin);
        $this->assertStringContainsString('admin-tables.css', $marketing);
    }

    public function test_sites_management_uses_vertical_fit_layout(): void
    {
        $blade = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $css = file_get_contents(public_path('assets/css/admin-tables.css'));

        $this->assertStringContainsString('admin-table-fit', $blade);
        $this->assertStringContainsString('admin-sites-count-col', $blade);
        $this->assertStringContainsString('admin-sites-count-badges', $blade);
        $this->assertStringContainsString('formatSitesCount', $blade);
        $this->assertStringContainsString('number_format($totalSitesCount)', $blade);
        $this->assertStringContainsString('admin-manage-dropdown', $blade);
        $this->assertStringContainsString('admin-manage-menu', $blade);
        $this->assertStringContainsString('toggle-site-details', $blade);
        $this->assertStringContainsString('activate_block_reason', $blade);
        $this->assertStringContainsString('Cannot activate', $blade);
        $this->assertStringContainsString('${STAFF_BASE}/sites/${site.id}/edit', $blade);
        $this->assertStringContainsString('Metrics &amp; image', $blade);
        $this->assertStringContainsString('admin-expand-row', $blade);
        $this->assertStringContainsString('setSiteDetailsOpen', $blade);
        $this->assertStringContainsString('setSiteDetailsOpen(highlightId, true)', $blade);
        $this->assertStringNotContainsString("details.classList.remove('d-none')", $blade);
        $this->assertStringContainsString('.admin-expand-row.is-open', $css);
        $this->assertStringContainsString('admin-site-info-stack', $blade);
        $this->assertStringContainsString('Manage', $blade);
        $this->assertStringContainsString('revealAllPublisherSites', $blade);
        $this->assertStringContainsString('dropNeedsReviewQueryParam', $blade);
        $this->assertStringContainsString('data?.sites', $blade);
        $this->assertStringContainsString('data-bs-popper-config', $blade);
        // Detail "Needs review only" must not be server-prechecked from the queue filter.
        $this->assertDoesNotMatchRegularExpression(
            '/id="sitesNeedsReviewOnly"[^>]*@checked/',
            $blade
        );
        $this->assertStringContainsString("strategy: 'fixed'", $blade);
        $this->assertStringNotContainsString('data-bs-display="static"', $blade);
        $this->assertStringNotContainsString('btn-action-group', $blade);
        $this->assertStringNotContainsString('width="220"', $blade);
        $this->assertStringNotContainsString('width: 136px', $blade);
        $this->assertStringContainsString('overflow: visible', $css);
        $this->assertStringContainsString('max-height: min(60vh, 280px)', $css);
        $this->assertStringContainsString('overflow-y: auto', $css);
        $this->assertStringContainsString('z-index: 1080', $css);
        $this->assertStringContainsString('is-manage-open', $css);

        $manageJs = file_get_contents(public_path('assets/js/admin-manage-dropdown.js'));
        $this->assertStringContainsString('show.bs.dropdown', $manageJs);
        $this->assertStringContainsString('is-manage-open', $manageJs);

        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $this->assertStringContainsString('admin-manage-dropdown.js', $layout);

        $this->actingAs($this->adminUser())
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('admin-table-fit', false)
            ->assertSee('admin-tables.css', false)
            ->assertSee('admin-manage-dropdown.js', false)
            ->assertSee('Sites Management', false);
    }

    public function test_other_high_risk_admin_pages_use_table_fit(): void
    {
        $files = [
            'views/admin/users.blade.php',
            'views/admin/payments.blade.php',
            'views/admin/withdrawals.blade.php',
            'views/admin/deposits.blade.php',
            'views/admin/orders/index.blade.php',
        ];

        foreach ($files as $relative) {
            $contents = file_get_contents(resource_path($relative));
            $this->assertStringContainsString(
                'admin-table-fit',
                $contents,
                "Expected admin-table-fit in {$relative}"
            );
        }

        $users = file_get_contents(resource_path('views/admin/users.blade.php'));
        $this->assertStringContainsString('admin-manage-dropdown', $users);
        $this->assertStringNotContainsString('width="260"', $users);
        $this->assertDoesNotMatchRegularExpression(
            '/\.modern-table\s*\{[^}]*overflow:\s*hidden/s',
            $users
        );
        $this->assertStringContainsString('admin-col-email', $users);
        $this->assertStringContainsString('admin-role-badges', $users);

        $adminLayout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $this->assertStringContainsString('admin-manage-dropdown.js', $adminLayout);

        $manageJs = file_get_contents(public_path('assets/js/admin-manage-dropdown.js'));
        $this->assertStringContainsString('is-manage-open', $manageJs);
        $this->assertStringContainsString('show.bs.dropdown', $manageJs);

        $withdrawals = file_get_contents(resource_path('views/admin/withdrawals.blade.php'));
        $this->assertStringContainsString('admin-manage-dropdown', $withdrawals);
        $this->assertStringNotContainsString('min-width:220px', $withdrawals);

        $bulk = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString('admin-contained-scroll', $bulk);
        $this->assertStringNotContainsString('min-width: 920px', $bulk);
    }
}
