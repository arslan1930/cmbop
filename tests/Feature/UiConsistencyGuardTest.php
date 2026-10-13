<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the interaction-layer conventions:
 *  - no native alert()/confirm() outside the slb-confirm library
 *  - buttons use the design system rather than inline colours
 *  - one registration CTA label
 *  - auth validation errors are announced and tied to their input
 */
class UiConsistencyGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return string[]
     */
    private function frontendFiles(): array
    {
        $paths = [];
        foreach ([resource_path('views'), public_path('js'), public_path('assets/js')] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                if (! preg_match('/\.(blade\.php|js)$/', $file->getFilename())) {
                    continue;
                }
                // slb-confirm.js is the one place allowed to fall back to native dialogs.
                if ($file->getFilename() === 'slb-confirm.js') {
                    continue;
                }
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    public function test_no_native_alert_or_confirm_outside_the_confirm_library(): void
    {
        $offenders = [];

        foreach ($this->frontendFiles() as $path) {
            foreach (file($path) as $index => $line) {
                $trimmed = trim($line);

                // Skip comments — prose like "after confirm (…)" is not a call.
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '{{--')) {
                    continue;
                }

                if (preg_match('/(?<![\w.$])(?:window\.)?(alert|confirm)\s*\(/', $line)) {
                    // slbAlert / slbConfirm / showAppToast are the sanctioned helpers.
                    if (preg_match('/slbAlert|slbConfirm|showAppToast/', $line)) {
                        continue;
                    }
                    // HTML/copy false positives: "Low balance alert (€)", etc.
                    if (preg_match('/\balert\s*\(\s*[€$£¥]/u', $line)) {
                        continue;
                    }
                    $offenders[] = str_replace(base_path().'/', '', $path).':'.($index + 1).'  '.$trimmed;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Use slbAlert()/slbConfirm() instead of native dialogs:\n".implode("\n", $offenders)
        );
    }

    public function test_buttons_do_not_carry_inline_background_colours(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $index => $line) {
                // Only flag elements that opt into the button system and then override it.
                if (preg_match('/class="[^"]*\bbtn\b[^"]*"[^>]*style="[^"]*background/', $line)) {
                    $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname()).':'.($index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Buttons must use button-system classes, not inline colours:\n".implode("\n", $offenders)
        );
    }

    public function test_button_classes_used_in_markup_are_defined_in_css(): void
    {
        $css = '';
        foreach (glob(public_path('assets/css/*.css')) as $file) {
            $css .= file_get_contents($file);
        }

        // These previously appeared in markup with no rule anywhere, so they
        // rendered as unstyled Bootstrap buttons.
        foreach (['btn-outline-live', 'btn-outline-teal'] as $ghost) {
            $this->assertSame(
                0,
                (int) shell_exec(sprintf(
                    'grep -rl %s %s %s 2>/dev/null | wc -l',
                    escapeshellarg($ghost),
                    escapeshellarg(resource_path('views')),
                    escapeshellarg(public_path('js'))
                )),
                "{$ghost} is not defined in any stylesheet; use a real button-system class."
            );
        }

        $this->assertStringContainsString('.btn-live-url', $css);
    }

    public function test_registration_ctas_share_one_label(): void
    {
        $labels = [];

        foreach ([
            'components/cta.blade.php',
            'components/pricing.blade.php',
            'pages/about.blade.php',
            'pages/how-it-works.blade.php',
            'pages/marketplace.blade.php',
        ] as $view) {
            $markup = file_get_contents(resource_path('views/'.$view));
            if (preg_match("/url\('\/register'\).*?\n?.*?messages\.([a-z_]+)/s", $markup, $m)) {
                $labels[$view] = $m[1];
            }
        }

        $this->assertNotEmpty($labels);
        $this->assertSame(
            ['get_started'],
            array_values(array_unique($labels)),
            'Page-level registration CTAs must share one label: '.json_encode($labels)
        );
    }

    public function test_login_links_validation_messages_to_their_inputs(): void
    {
        $markup = file_get_contents(resource_path('views/auth/login.blade.php'));

        $this->assertStringContainsString('aria-describedby="emailError"', $markup);
        $this->assertStringContainsString('aria-describedby="passwordError"', $markup);
        $this->assertStringContainsString('id="emailError" role="alert"', $markup);
        $this->assertStringContainsString('id="passwordError" role="alert"', $markup);

        // The feedback divs used to render but never be populated.
        $this->assertStringContainsString('function showFieldErrors', $markup);
        $this->assertStringContainsString("setAttribute('aria-invalid', 'true')", $markup);
    }

    public function test_register_links_validation_messages_to_their_inputs(): void
    {
        $markup = file_get_contents(resource_path('views/auth/register.blade.php'));

        foreach (['nameError', 'emailError', 'passwordError', 'password_confirmationError'] as $field) {
            $this->assertStringContainsString('aria-describedby="'.$field.'"', $markup);
            $this->assertStringContainsString('id="'.$field.'" role="alert"', $markup);
        }
    }

    public function test_every_toast_uses_one_stack_position(): void
    {
        // Auth pages anchored top-right while the rest of the app used
        // bottom-right, and the bottom-right stack sat on top of the help FAB.
        $css = file_get_contents(public_path('assets/css/interaction.css'));
        $this->assertStringContainsString('.slb-toast-stack', $css);
        $this->assertStringContainsString('padding-bottom: 84px', $css, 'Toast stack must clear the help FAB');

        $views = [
            'partials/app-toast.blade.php',
            'auth/login.blade.php',
            'auth/register.blade.php',
            'auth/forgot-password.blade.php',
            'auth/reset-password.blade.php',
        ];

        foreach ($views as $view) {
            $markup = file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString('slb-toast-stack', $markup, "{$view} must use the shared toast stack");
            $this->assertStringNotContainsString('top-0 end-0 p-3', $markup, "{$view} must not anchor toasts top-right");
            $this->assertStringNotContainsString('bottom-0 end-0 p-3', $markup, "{$view} must not hand-position the toast stack");
        }
    }

    public function test_dynamic_auth_toasts_are_announced(): void
    {
        foreach (['login', 'register', 'forgot-password', 'reset-password'] as $page) {
            $markup = file_get_contents(resource_path('views/auth/'.$page.'.blade.php'));
            $this->assertStringContainsString(
                "setAttribute('role'",
                $markup,
                "{$page} builds toasts dynamically and must set an ARIA role"
            );
            $this->assertStringContainsString("setAttribute('aria-live'", $markup);
        }
    }

    public function test_shell_and_dashboards_keep_recent_orders_clear_of_the_footer(): void
    {
        $css = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('--shell-footer-clearance:', $css);
        $this->assertStringContainsString('--shell-topbar-offset:', $css);
        $this->assertStringContainsString('padding: 28px 28px var(--shell-footer-clearance)', $css);
        $this->assertStringContainsString('.dash-recent-col', $css);
        $this->assertStringContainsString('.dash-page-end', $css);

        $advertiser = file_get_contents(resource_path('views/advertiser/dashboard.blade.php'));
        $this->assertDoesNotMatchRegularExpression(
            '/\.recent-orders-glass\s*\{[^}]*\bheight:\s*100%/',
            $advertiser,
            'height:100% on Recent orders overflowed the spend strip into the footer'
        );
        $this->assertStringContainsString('col-lg-8 dash-recent-col', $advertiser);
        $this->assertStringContainsString('dash-page-end', $advertiser);
        $this->assertStringContainsString('align-self: start', $advertiser);
        $this->assertStringContainsString('flex: 0 0 auto', $advertiser);
        $this->assertStringContainsString('View all orders', $advertiser);
        $this->assertStringNotContainsString("site_name ?? '—'", $advertiser);

        $publisher = file_get_contents(resource_path('views/publisher/dashboard.blade.php'));
        $this->assertStringContainsString('container-fluid dash-page-end', $publisher);
        $this->assertStringContainsString('dash-recent-col', $publisher);
        $this->assertStringContainsString('Recent tasks', $publisher);
    }
}
