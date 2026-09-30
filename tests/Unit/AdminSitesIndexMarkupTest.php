<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdminSitesIndexMarkupTest extends TestCase
{
    public function test_sites_management_markup_has_no_merge_conflict_markers(): void
    {
        $blade = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $strip = file_get_contents(resource_path('views/admin/sites/partials/queue-strip.blade.php'));

        $this->assertStringNotContainsString('<<<<<<<', $blade);
        $this->assertStringNotContainsString('>>>>>>>', $blade);
        $this->assertStringNotContainsString('<<<<<<<', $strip);
        $this->assertSame(1, substr_count($blade, '<h4 class="mb-0 fw-bold">Sites Management</h4>'));
        $this->assertStringContainsString('staff-sites-strip', $strip);
        $this->assertStringContainsString('Needs review', $strip);
        $this->assertStringContainsString('Waiting on publisher', $strip);
        $this->assertStringContainsString('Live unverified', $strip);
        $this->assertStringContainsString('admin-sites-header', $blade);
    }
}
