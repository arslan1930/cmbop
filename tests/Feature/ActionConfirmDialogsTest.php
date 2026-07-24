<?php

namespace Tests\Feature;

use Tests\TestCase;

class ActionConfirmDialogsTest extends TestCase
{
    public function test_shared_confirm_helper_is_present(): void
    {
        $path = public_path('js/slb-confirm.js');
        $this->assertFileExists($path);

        $js = file_get_contents($path);
        $this->assertStringContainsString('function slbConfirm', $js);
        $this->assertStringContainsString('function slbAlert', $js);
        $this->assertStringContainsString('data-slb-confirm', $js);
        $this->assertStringContainsString('global.slbConfirm', $js);
    }

    public function test_role_layouts_include_confirm_helper(): void
    {
        foreach ([
            resource_path('views/advertiser/layouts/app.blade.php'),
            resource_path('views/publisher/layouts/app.blade.php'),
            resource_path('views/admin/layouts/app.blade.php'),
        ] as $layout) {
            $this->assertFileExists($layout);
            $html = file_get_contents($layout);
            $this->assertStringContainsString('js/slb-confirm.js', $html, $layout);
            $this->assertStringContainsString('sweetalert2@11', $html, $layout);
        }
    }

    public function test_destructive_action_views_use_confirm_hooks(): void
    {
        $cases = [
            resource_path('views/advertiser/campaigns.blade.php') => 'data-slb-confirm="This project will be removed',
            resource_path('views/advertiser/scheduled-orders.blade.php') => 'data-slb-confirm="Cancel this scheduled order?',
            resource_path('views/advertiser/content-library.blade.php') => 'window.slbConfirm',
            resource_path('views/advertiser/saved-sites.blade.php') => 'window.slbConfirm',
            resource_path('views/admin/emails/index.blade.php') => 'data-slb-confirm="Retry failed queue jobs',
            resource_path('views/admin/invoices/show.blade.php') => 'data-slb-confirm="Cancel this invoice?',
            resource_path('views/admin/promotions/banners/index.blade.php') => 'data-slb-confirm="Delete this banner?',
            resource_path('views/admin/promotions/announcements/index.blade.php') => 'data-slb-confirm="Delete this announcement?',
            resource_path('views/admin/moderation/index.blade.php') => 'data-slb-confirm="Approve this submission',
            resource_path('views/admin/campaigns/index.blade.php') => 'data-slb-confirm="Send this campaign',
            resource_path('views/admin/bulk-site-requests/show.blade.php') => 'data-slb-confirm="Cancel this bulk request?',
        ];

        foreach ($cases as $path => $needle) {
            $this->assertFileExists($path);
            $html = file_get_contents($path);
            $this->assertStringContainsString($needle, $html, $path);
            $this->assertStringNotContainsString('onsubmit="return confirm(', $html, $path);
            $this->assertStringNotContainsString('onclick="return confirm(', $html, $path);
        }
    }
}
