<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the three feedback-layer rules:
 *  - controllers never echo raw exception text to users
 *  - flash messages come from one accessible partial
 *  - AJAX failures go through the shared status-aware helper
 */
class UserFeedbackConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return string[]
     */
    private function controllerFiles(): array
    {
        $files = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'))
        );
        foreach ($dir as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_controllers_do_not_return_raw_exception_text_to_users(): void
    {
        // Log context arrays are fine; only response payloads and flashes matter.
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            // The Stripe webhook body is read by Stripe's dashboard, not a browser,
            // and the endpoint is signature-authenticated.
            if (str_ends_with($path, 'Api/StripeWebhookController.php')) {
                continue;
            }

            foreach (file($path) as $index => $line) {
                if (! str_contains($line, 'getMessage()')) {
                    continue;
                }
                if (str_contains($line, 'UserFacingError::')) {
                    continue;
                }

                $isResponsePayload = preg_match("/response\(\)->json|with\(\s*'error'/", $line) === 1;
                $isMessageKey = preg_match("/'message'\s*=>.*getMessage\(\)/", $line) === 1;

                if ($isResponsePayload || $isMessageKey) {
                    $offenders[] = str_replace(base_path().'/', '', $path).':'.($index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Raw exception text must not reach users. Wrap with UserFacingError::message():\n".implode("\n", $offenders)
        );
    }

    public function test_shared_flash_partial_exists_and_is_accessible(): void
    {
        $partial = resource_path('views/partials/session-flash.blade.php');
        $this->assertFileExists($partial);

        $markup = file_get_contents($partial);
        $this->assertStringContainsString('role="status"', $markup, 'Success should be announced politely');
        $this->assertStringContainsString('role="alert"', $markup, 'Errors should be announced assertively');
        $this->assertStringContainsString('aria-live', $markup);
        $this->assertStringContainsString('aria-label="Dismiss message"', $markup);
        $this->assertStringContainsString("session_text('error')", $markup);
        $this->assertStringContainsString("session_text('success')", $markup);
    }

    /**
     * @return string[]
     */
    private function layouts(): array
    {
        return [
            resource_path('views/layouts/app.blade.php'),
            resource_path('views/advertiser/layouts/app.blade.php'),
            resource_path('views/publisher/layouts/app.blade.php'),
            resource_path('views/admin/layouts/app.blade.php'),
            resource_path('views/marketing/layouts/app.blade.php'),
        ];
    }

    public function test_every_layout_renders_flash_toast_and_http_helper(): void
    {
        foreach ($this->layouts() as $layout) {
            $markup = file_get_contents($layout);
            $name = basename(dirname($layout, 2)).'/'.basename($layout);

            $this->assertStringContainsString('partials.session-flash', $markup, "{$name} must render flash messages");
            $this->assertStringContainsString('partials.app-toast', $markup, "{$name} must load the toast helper");
            $this->assertStringContainsString('js/slb-http.js', $markup, "{$name} must load the AJAX error helper");
        }
    }

    public function test_views_no_longer_duplicate_flash_blocks(): void
    {
        $offenders = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($dir as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if ($file->getFilename() === 'session-flash.blade.php') {
                continue;
            }

            $markup = file_get_contents($file->getPathname());
            if (preg_match("/@if\s*\(\s*session\(\s*'(success|error)'\s*\)\s*\)/", $markup)) {
                $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Views must rely on partials.session-flash instead of their own blocks:\n".implode("\n", $offenders)
        );
    }

    public function test_http_helper_maps_the_status_codes_users_actually_hit(): void
    {
        $js = file_get_contents(public_path('js/slb-http.js'));

        foreach (['419', '401', '403', '422', '429', '404'] as $status) {
            $this->assertStringContainsString($status, $js, "Helper must handle HTTP {$status}");
        }

        $this->assertStringContainsString('session expired', $js);
        $this->assertStringContainsString('permission', $js);
        $this->assertStringContainsString('slbHandleHttpError', $js);
        $this->assertStringContainsString('slbHttpMessage', $js);

        // A 5xx body may carry internals, so it must never be echoed back.
        $this->assertStringContainsString('status >= 500', $js);
    }

    public function test_publisher_tasks_uses_the_shared_http_error_handler(): void
    {
        $markup = file_get_contents(resource_path('views/publisher/tasks.blade.php'));

        $this->assertStringContainsString('slbHandleHttpError(xhr', $markup);
        $this->assertStringNotContainsString('errorMsg = xhr.responseJSON.message', $markup);
    }
}
