<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesignConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_advertiser_layout_uses_app_shell_without_duplicate_sidebar_block(): void
    {
        $layout = file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('css/app-shell.css', $layout);
        $this->assertStringContainsString('css/cart.css', $layout);
        // Compact wordmarks after logo-crush / wrap fix (sidebar 48, topbar 44).
        $this->assertStringContainsString('height="48"', $layout);
        $this->assertStringContainsString('height="44"', $layout);
        $this->assertStringContainsString('background:transparent', $layout);
        $this->assertStringNotContainsString('#sidebar {', $layout);
        $this->assertStringNotContainsString("font-family: 'Poppins'", $layout);
        $this->assertStringNotContainsString('transition: all 0.3s', $layout);

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('css/app-shell.css', false)
            ->assertSee('css/cart.css', false);
    }

    public function test_publisher_layout_uses_app_shell_without_duplicate_sidebar_block(): void
    {
        $layout = file_get_contents(resource_path('views/publisher/layouts/app.blade.php'));
        $this->assertStringContainsString('css/app-shell.css', $layout);
        $this->assertStringNotContainsString('#sidebar {', $layout);
        $this->assertStringNotContainsString("font-family: 'Poppins'", $layout);
    }

    public function test_checkout_payment_tiles_use_brand_tokens_not_raw_cyan(): void
    {
        $checkout = file_get_contents(resource_path('views/advertiser/checkout.blade.php'));
        $this->assertStringNotContainsString('border: 2px solid #5bc4c7', $checkout);
        $this->assertStringNotContainsString('color:#5bc4c7', $checkout);
        $this->assertStringContainsString('var(--brand-primary-tint', $checkout);
        $this->assertStringContainsString('var(--radius-lg', $checkout);
        $this->assertStringContainsString('payment-option-card', $checkout);
        $this->assertDoesNotMatchRegularExpression(
            '/\.payment-option-card\.recommended\s*\{[^}]*background:\s*var\(--brand-primary-bg/',
            $checkout
        );
        $this->assertMatchesRegularExpression(
            '/\.payment-option\.selected\s+\.payment-option-card\s*\{[^}]*background:\s*var\(--brand-primary-bg/',
            $checkout
        );
    }

    public function test_select_css_uses_border_and_focus_tokens(): void
    {
        $multi = file_get_contents(public_path('assets/css/multi-select.css'));
        $single = file_get_contents(public_path('assets/css/single-select.css'));

        $this->assertStringContainsString('var(--border-default', $multi);
        $this->assertStringContainsString('var(--focus-ring', $multi);
        $this->assertStringContainsString('min-height: 38px', $multi);

        $this->assertStringContainsString('var(--border-default', $single);
        $this->assertStringContainsString('var(--focus-ring', $single);
        $this->assertStringContainsString('min-height: 38px', $single);
    }

    public function test_dash_panel_uses_radius_lg_token(): void
    {
        $spacing = file_get_contents(public_path('assets/css/spacing-system.css'));
        $this->assertStringContainsString('.dash-panel', $spacing);
        $this->assertStringContainsString('border-radius: var(--radius-lg', $spacing);
    }

    public function test_cart_drawer_is_wider_than_legacy_360(): void
    {
        $cart = file_get_contents(public_path('assets/css/cart.css'));
        $this->assertStringContainsString('width: min(420px, 94vw)', $cart);
        $this->assertStringContainsString('right: -420px', $cart);
        $this->assertStringNotContainsString('width: min(360px, 92vw)', $cart);
    }

    public function test_shell_wordmark_is_display_sized_and_preloaded(): void
    {
        $webp = public_path('assets/img/logo-sidebar.webp');
        $png = public_path('assets/img/logo-sidebar.png');
        $full = public_path('assets/img/logo1.png');
        $this->assertFileExists($webp);
        $this->assertFileExists($png);
        $this->assertLessThan(filesize($full), filesize($webp));
        $this->assertLessThan(16 * 1024, filesize($webp));
        $this->assertSame(6, ord(file_get_contents($png)[25]));

        $url = brand_shell_wordmark_url();
        $this->assertStringContainsString('assets/img/logo-sidebar.webp', $url);

        foreach ([
            'advertiser/layouts/app.blade.php',
            'publisher/layouts/app.blade.php',
            'admin/layouts/app.blade.php',
            'marketing/layouts/app.blade.php',
        ] as $layout) {
            $blade = file_get_contents(resource_path('views/'.$layout));
            $this->assertStringContainsString('partials.shell-wordmark-preload', $blade, $layout);
            $this->assertStringContainsString('brand_shell_wordmark_url()', $blade, $layout);
            $this->assertStringContainsString('id="logoSidebar"', $blade, $layout);
            $this->assertStringContainsString('fetchpriority="high"', $blade, $layout);
            $this->assertStringNotContainsString("asset('assets/img/logo1.png')", $blade, $layout);
        }
    }

    public function test_primary_wordmark_png_is_transparent(): void
    {
        $path = public_path('assets/img/logo1.png');
        $this->assertFileExists($path);
        $bytes = file_get_contents($path);
        // PNG signature + IHDR; then look for tRNS or color type 6 (RGBA) in IHDR.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
        $ihdr = substr($bytes, 8, 25); // length+IHDR+data start
        $this->assertStringContainsString('IHDR', $ihdr);
        // Color type is byte 25 of file (offset 16+8+4+4 = wait):
        // 8 sig + 4 len + 4 'IHDR' + 4 width + 4 height + 1 bitdepth + 1 color = offset 25
        $colorType = ord($bytes[25]);
        $this->assertSame(6, $colorType, 'logo1.png should be RGBA (color type 6) for transparency');
    }

    public function test_brand_tokens_use_mist_teal_pair(): void
    {
        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));
        $assets = file_get_contents(public_path('assets/css/brand-colors.css'));
        $shell = file_get_contents(public_path('assets/css/app-shell.css'));

        $this->assertStringContainsString('Teal Trust', $brand);
        $this->assertStringContainsString('--brand-primary: #1a585e', $brand);
        $this->assertStringContainsString('--brand-neutral: #76797c', $brand);
        $this->assertStringContainsString('--brand-logo-grey: #76797c', $brand);
        $this->assertStringContainsString('--brand-primary-soft: #3faeb2', $brand);
        $this->assertStringContainsString('--brand-primary-bg: #e6f5f5', $brand);
        $this->assertStringContainsString('--brand-live: #0ea5e9', $brand);
        $this->assertStringContainsString('--surface-2: #f7fafb', $brand);
        $this->assertStringContainsString('--brand-warning: #b45309', $brand);
        $this->assertStringContainsString('--bs-primary-rgb: 26, 88, 94', $brand);
        $this->assertStringContainsString('--grad-hero:', $brand);
        $this->assertStringContainsString('--grad-cta:', $brand);
        $this->assertStringContainsString('--grad-wash-page:', $brand);
        $this->assertStringNotContainsString('#667eea', $brand);
        $this->assertStringNotContainsString('#764ba2', $brand);

        $this->assertStringContainsString('--brand-primary: #1a585e', $assets);
        $this->assertStringContainsString('--brand-neutral: #76797c', $assets);
        $this->assertStringContainsString('--brand-logo-grey: #76797c', $assets);
        $this->assertStringContainsString('--brand-live: #0ea5e9', $assets);

        // Reject the older near-teal primary drift hex in token sources / shell.
        $this->assertStringNotContainsString('#185054', $brand);
        $this->assertStringNotContainsString('#185054', $assets);
        $this->assertStringNotContainsString('#185054', $shell);
        $this->assertStringNotContainsString('rgba(24, 80, 84', $brand);
        $this->assertStringNotContainsString('rgba(24, 80, 84', $assets);
    }

    public function test_pending_status_badges_are_not_white_on_white(): void
    {
        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));
        $shell = file_get_contents(public_path('assets/css/app-shell.css'));

        // Bootstrap badges default to white text; light warning/info slabs must force ink.
        $this->assertStringContainsString('--bs-badge-color: var(--brand-ink', $brand);
        $this->assertStringContainsString('.badge.bg-warning', $brand);
        $this->assertStringContainsString('color: var(--brand-ink, #1e293b) !important', $brand);

        // Pending chips use soft amber + dark ink (never white/white).
        $this->assertStringContainsString('background: #fff7ed !important', $brand);
        $this->assertStringContainsString('color: #9a3412 !important', $brand);
        $this->assertStringContainsString('background: #fff7ed !important', $shell);
        $this->assertStringContainsString('.status-pending', $shell);
        $this->assertStringNotContainsString(
            ".status-pending {\n  background: #fff !important;\n  color: #64748b !important;",
            $shell
        );
    }

    public function test_warning_info_toasts_force_ink_contrast(): void
    {
        $assets = file_get_contents(public_path('assets/css/brand-colors.css'));
        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));

        foreach ([$assets, $brand] as $css) {
            $this->assertStringContainsString('.toast.bg-warning', $css);
            $this->assertStringContainsString('.toast.bg-info', $css);
            $this->assertStringContainsString('.toast.bg-warning .toast-body', $css);
            $this->assertStringContainsString('.toast.bg-warning .btn-close-white', $css);
            $this->assertStringContainsString('color: var(--brand-ink, #1e293b) !important', $css);
            $this->assertStringContainsString('.toast.bg-success', $css);
        }

        $register = file_get_contents(resource_path('views/auth/register.blade.php'));
        $this->assertStringContainsString("const textClass = solid ? 'text-white' : 'text-dark'", $register);
        $this->assertStringContainsString("const closeClass = solid ? 'btn-close btn-close-white' : 'btn-close'", $register);
        $this->assertStringNotContainsString('text-white border-0 bg-${type}', $register);
        $this->assertStringNotContainsString('text-white border-0 bg-` + type', $register);

        $login = file_get_contents(resource_path('views/auth/login.blade.php'));
        $this->assertStringContainsString('function buildAuthToast', $login);
        $this->assertStringContainsString("solid ? 'text-white ' : 'text-dark '", $login);
        $this->assertStringContainsString("const closeClass = solid ? 'btn-close btn-close-white' : 'btn-close'", $login);

        $appToast = file_get_contents(resource_path('views/partials/app-toast.blade.php'));
        $this->assertStringContainsString("const textClass = solid ? 'text-white' : 'text-dark'", $appToast);
        $this->assertStringContainsString("const closeClass = solid ? 'btn-close btn-close-white' : 'btn-close'", $appToast);
    }

    public function test_marketing_back_to_top_uses_brand_primary(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('id="backToTop"', $layout);
        $this->assertStringContainsString('btn btn-primary', $layout);
        $this->assertStringNotContainsString('btn btn-danger rounded-circle', $layout);
    }

    public function test_publisher_websites_focus_uses_brand_ring_not_purple(): void
    {
        $css = file_get_contents(public_path('assets/css/publisher-websites.css'));
        $this->assertStringNotContainsString('rgba(84, 105, 212', $css);
        $this->assertStringContainsString('var(--focus-ring', $css);
        $this->assertStringNotContainsString('.live-bulk-table', $css);
    }
}
