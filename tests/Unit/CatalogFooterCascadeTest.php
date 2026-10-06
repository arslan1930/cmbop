<?php

namespace Tests\Unit;

use Tests\TestCase;

class CatalogFooterCascadeTest extends TestCase
{
    public function test_catalog_page_rules_beat_shared_pager_and_outline_hover(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $shared = (string) file_get_contents(public_path('assets/css/slb-pagination.css'));
        $layout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $results = (string) file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'));
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('.catalog-page .catalog-pagination', $css);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr)', $css);
        $this->assertStringContainsString('padding: 0.85rem 1.25rem 1rem', $css);
        $this->assertStringContainsString('.catalog-page .catalog-pagination__perpage', $css);
        $this->assertStringContainsString('padding-left: 0.35rem', $css);
        $this->assertStringContainsString('.catalog-page .catalog-pagination__links', $css);
        $this->assertStringContainsString('justify-self: center', $css);

        $this->assertStringContainsString('flex-direction: column', $shared);
        $this->assertStringContainsString('.catalog-pagination__links', $shared);
        $this->assertStringContainsString('width: 100%', $shared);

        $this->assertLessThan(
            strpos($layout, "asset('assets/css/slb-pagination.css')"),
            strpos($layout, "@stack('page-styles')")
        );

        $this->assertStringContainsString('--bs-btn-hover-color: #fff', $css);
        $this->assertStringContainsString('--bs-btn-hover-bg: #146c43', $css);
        $this->assertStringContainsString('.catalog-page .btn-suggest-website.btn-outline-success:hover', $css);

        $this->assertStringContainsString('catalog-pagination__perpage', $results);
        $this->assertStringContainsString("'selectId' => 'catalogPerPage'", $results);
        $this->assertStringContainsString("@if(\$sites->lastPage() > 1)", $results);
        $this->assertStringContainsString('catalog-pagination__links', $results);

        $this->assertStringContainsString('catalogPerPage', $js);
        $this->assertStringContainsString("'per_page'", $js);
    }
}
