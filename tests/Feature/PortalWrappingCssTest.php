<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalWrappingCssTest extends TestCase
{
    public function test_app_shell_prevents_logo_and_topbar_crush(): void
    {
        $css = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertIsString($css);

        $this->assertStringContainsString('#logoNavbar', $css);
        $this->assertStringContainsString('shell-logo-wordmark', $css);
        $this->assertStringContainsString('shell-logo-mark', $css);
        $this->assertStringContainsString('.top-navbar .mobile-left', $css);
        $this->assertStringContainsString('min-width: 0', $css);
        $this->assertStringContainsString('overflow-x: clip', $css);
        // Viewport wrap lives on body/html — not on #content, where one-axis
        // clip shears page titles (Payout documents, Withdraw, Invoices).
        $this->assertMatchesRegularExpression(
            '/body,\s*html\s*\{[^}]*overflow-x:\s*clip/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/#content,\s*#main-content\s*\{[^}]*overflow-x:\s*clip/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#content,\s*#main-content\s*\{[^}]*overflow-x:\s*visible/s',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/#content,\s*#main-content\s*\{[^}]*min-height:\s*calc\(\s*100vh/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#content \.row > \[class\*="col"\],[\s\S]*#main-content \.row > \[class\*="col"\]\s*\{[^}]*min-width:\s*0/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.app-shell-footer__grid\s*\{[^}]*flex-wrap:\s*wrap/s',
            $css
        );
        // Role switch dropdown lives in .mobile-left — must not be clipped.
        $this->assertMatchesRegularExpression(
            '/\.top-navbar\s+\.mobile-left\s*\{[^}]*overflow:\s*visible/s',
            $css
        );
        $this->assertStringContainsString('.top-navbar .role-switch-dropdown', $css);
        $this->assertStringContainsString('max-width: min(150px, 30vw)', $css);
        $this->assertStringContainsString('.balance-block .balance-label', $css);
        $this->assertStringNotContainsString('#sidebar.collapsed a { font-size: 0', $css);
    }

    public function test_page_titles_keep_ascenders_inside_the_line_box(): void
    {
        $css = file_get_contents(public_path('assets/css/type-system.css'));
        $this->assertIsString($css);

        $this->assertStringContainsString('--type-line-title: 1.4', $css);
        $this->assertMatchesRegularExpression(
            '/#content h2\.fw-semibold,[\s\S]*#main-content h2\s*\{[\s\S]*line-height:\s*var\(--type-line-title\)/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/#content h2\.fw-semibold,[\s\S]*#main-content h2\s*\{[\s\S]*overflow:\s*visible/',
            $css
        );
    }

    public function test_interaction_css_includes_text_break_helpers(): void
    {
        $css = file_get_contents(public_path('assets/css/interaction.css'));
        $this->assertIsString($css);

        $this->assertStringContainsString('.slb-text-break', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('.catalog-site-url', $css);
        $this->assertStringContainsString('.blog-content a', $css);
        $this->assertStringContainsString('body.role-shell-marketing', $css);
    }

    public function test_admin_and_marketing_layouts_drop_font_size_zero_collapse(): void
    {
        $admin = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $marketing = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));

        $this->assertIsString($admin);
        $this->assertIsString($marketing);

        $this->assertStringNotContainsString('font-size: 0;', $admin);
        $this->assertStringNotContainsString('font-size: 0;', $marketing);
        $this->assertStringContainsString('mobile-sidebar-logo', $admin);
        $this->assertStringContainsString('mobile-sidebar-logo', $marketing);
        $this->assertStringContainsString('shell-logo-mark', $admin);
        $this->assertStringContainsString('shell-logo-mark', $marketing);
    }

    public function test_marketing_brand_line_scales_without_ellipsis_clip(): void
    {
        $blade = file_get_contents(resource_path('views/components/marketing-brand-line.blade.php'));
        $this->assertIsString($blade);
        $this->assertStringContainsString('clamp(1.05rem, 4.6vw, 1.85rem)', $blade);
        $this->assertStringNotContainsString('text-overflow: ellipsis', $blade);
    }

    /**
     * public/css used to be a byte-for-byte mirror of public/assets/css that no
     * page ever loaded, so edits silently landed in the dead copy. Keep it gone.
     */
    public function test_stylesheets_live_in_a_single_directory(): void
    {
        $this->assertDirectoryDoesNotExist(
            public_path('css'),
            'public/css is a stale mirror; stylesheets belong in public/assets/css.'
        );

        foreach (['app-shell.css', 'interaction.css', 'auth-pages.css'] as $stylesheet) {
            $this->assertFileExists(public_path('assets/css/'.$stylesheet));
        }
    }

    public function test_layouts_only_reference_the_assets_stylesheet_directory(): void
    {
        $layouts = glob(resource_path('views/**/layouts/app.blade.php'))
            + [resource_path('views/layouts/app.blade.php')];

        foreach (array_filter($layouts, 'is_file') as $layout) {
            $this->assertStringNotContainsString(
                "asset('css/",
                file_get_contents($layout),
                basename(dirname($layout, 2)).' layout must load stylesheets from assets/css'
            );
        }
    }
}
