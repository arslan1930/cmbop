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
        $this->assertSame(1, substr_count($blade, '$publishersTabOn ? \'Publishers\' : \'Sites Management\''));
        $this->assertStringContainsString('Ready to activate', $blade);
        $this->assertStringContainsString("staff_route('sites.index')", $blade);
        $this->assertStringContainsString("['publishers' => 1]", $blade);
        $this->assertStringContainsString('staff-catalog-health', $blade);
        $this->assertStringContainsString('below quality bar', $blade);
        $this->assertStringContainsString('unverified active', $blade);
        $this->assertStringContainsString('placeholder', $blade);
        $this->assertStringContainsString('missing cover', $blade);
        $this->assertStringContainsString('staff-sites-strip', $strip);
        $this->assertStringContainsString('is-zero', $strip);
        $this->assertStringContainsString('Needs review', $strip);
        $this->assertStringContainsString('Waiting on publisher', $strip);
        $this->assertStringContainsString('Live unverified', $strip);
        $this->assertStringContainsString('admin-sites-header', $blade);
        $this->assertStringContainsString('admin-sites-tabs', $blade);
        $this->assertStringContainsString('Actions', $blade);
        $this->assertStringContainsString('staffSitesEditUrl', $blade);
        $this->assertStringContainsString("params.has('page') && !params.get('publisher') && !siteId", $blade);
        $this->assertStringContainsString("params.get('sites_page')", $blade);
        $this->assertStringContainsString('initSitePreviewZoom(document)', $blade);
        $this->assertStringContainsString('data-staff-compact-toggle', file_get_contents(resource_path('views/admin/sites/partials/all-sites-table.blade.php')));
    }
}
