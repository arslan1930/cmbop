<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUiTier3ConsolidationTest extends TestCase
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

    /**
     * @return list<string>
     */
    private function adminViewFiles(): array
    {
        $files = [];
        $dir = new \RecursiveDirectoryIterator(resource_path('views/admin'));
        foreach (new \RecursiveIteratorIterator($dir) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_no_admin_view_carries_an_inline_style_block(): void
    {
        $offenders = [];
        foreach ($this->adminViewFiles() as $path) {
            if (str_contains((string) file_get_contents($path), '<style>')) {
                $offenders[] = str_replace(resource_path('views/'), '', $path);
            }
        }

        $this->assertSame([], $offenders, 'Inline <style> blocks belong in a stylesheet: '.implode(', ', $offenders));
    }

    public function test_admin_layout_no_longer_redeclares_the_shared_shell(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));

        $this->assertStringNotContainsString('<style>', $layout);
        // Structure comes from app-shell.css tokens, not repeated pixel values.
        $this->assertStringNotContainsString('margin-left: 220px', $layout);
        $this->assertStringNotContainsString('left: -220px', $layout);

        foreach (['app-shell.css', 'admin-shell.css', 'admin-components.css', 'staff-sites.css'] as $sheet) {
            $this->assertStringContainsString($sheet, $layout);
        }
    }

    public function test_admin_overrides_load_after_the_shared_hover_system(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));

        $hover = strpos($layout, 'hover-system.css');
        $adminShell = strpos($layout, 'admin-shell.css');

        $this->assertNotFalse($hover);
        $this->assertNotFalse($adminShell);
        $this->assertGreaterThan(
            $hover,
            $adminShell,
            'admin-shell.css must load after hover-system.css or the brand hover is overridden'
        );
    }

    public function test_extracted_stylesheets_exist_and_keep_admin_identity(): void
    {
        $shell = file_get_contents(public_path('assets/css/admin-shell.css'));
        $this->assertStringContainsString('#sidebar a.active', $shell);
        $this->assertStringContainsString('var(--brand-primary-bg)', $shell);
        $this->assertStringContainsString('.admin-nav-section', $shell);
        $this->assertStringContainsString('.balance-block', $shell);

        $components = file_get_contents(public_path('assets/css/admin-components.css'));
        foreach (['.modern-table', '.status-badge', '.ec-kpi', '.blog-content', '.records-country-list'] as $selector) {
            $this->assertStringContainsString($selector, $components);
        }
        // Page rules must be scoped, not applied to every table in the shell.
        $this->assertStringContainsString('.admin-deposits-table tbody tr:hover', $components);
        $this->assertStringContainsString('.admin-blogs-table td', $components);

        $staff = file_get_contents(public_path('assets/css/staff-sites.css'));
        foreach (['.pulse-dot', '.site-row-preview', '.bulk-done-grid'] as $selector) {
            $this->assertStringContainsString($selector, $staff);
        }
    }

    public function test_marketing_layout_also_loads_the_shared_sites_stylesheet(): void
    {
        $marketing = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));

        $this->assertStringContainsString('staff-sites.css', $marketing);
    }

    public function test_shared_site_styles_reach_both_shells(): void
    {
        $adminHtml = $this->actingAs($this->admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('staff-sites.css', $adminHtml);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $marketingHtml = $this->actingAs($marketer)
            ->get(route('marketing.sites.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('staff-sites.css', $marketingHtml);
    }

    public function test_admin_toasts_go_through_the_shared_helper(): void
    {
        $offenders = [];
        foreach ($this->adminViewFiles() as $path) {
            $contents = (string) file_get_contents($path);
            if (preg_match('/toast\s*:\s*true/', $contents)) {
                $offenders[] = str_replace(resource_path('views/'), '', $path);
            }
        }

        $this->assertSame([], $offenders, 'Use showAppToast instead of a SweetAlert toast: '.implode(', ', $offenders));
    }

    public function test_blog_delete_uses_the_shared_confirm_not_a_bespoke_modal(): void
    {
        $blade = file_get_contents(resource_path('views/admin/blogs/index.blade.php'));

        $this->assertStringContainsString('data-slb-confirm', $blade);
        $this->assertStringNotContainsString('deleteModal', $blade);
        $this->assertStringNotContainsString('data-bs-target="#deleteModal', $blade);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.blogs.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-slb-confirm-danger="1"', $html);
        $this->assertStringContainsString('form="deleteBlog', $html);
    }

    public function test_paginated_admin_tables_share_one_renderer(): void
    {
        $js = file_get_contents(public_path('assets/js/admin-pagination.js'));
        $this->assertStringContainsString('renderAdminPagination', $js);
        $this->assertStringContainsString('aria-current="page"', $js);

        foreach (['payments', 'withdrawals', 'orders/index'] as $view) {
            $blade = file_get_contents(resource_path('views/admin/'.$view.'.blade.php'));
            $this->assertStringContainsString(
                'renderAdminPagination(',
                $blade,
                $view.' should use the shared pagination renderer'
            );
        }

        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $this->assertStringContainsString('admin-pagination.js', $layout);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('renderAdminPagination(', $html);
        // The hand-rolled markup is gone.
        $this->assertStringNotContainsString('<a class="page-link" href="#" data-page=', $html);
    }

    public function test_pagination_uses_brand_colours_for_every_admin_table(): void
    {
        $components = file_get_contents(public_path('assets/css/admin-components.css'));

        $this->assertStringContainsString('.pagination .page-item.active .page-link', $components);
        $this->assertStringContainsString('background-color: var(--brand-primary)', $components);
        $this->assertStringContainsString('.pagination .page-link:focus-visible', $components);

        // The colours used to be inline on payments only.
        $payments = file_get_contents(resource_path('views/admin/payments.blade.php'));
        $this->assertStringNotContainsString('#1a585e', $payments);
    }
}
