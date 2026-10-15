<?php

namespace Tests\Feature;

use Tests\TestCase;

class CatalogComposerRemovedTest extends TestCase
{
    public function test_global_view_composer_no_longer_loads_projects(): void
    {
        $src = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringNotContainsString("View::composer('*'", $src);
        $this->assertStringNotContainsString('sidebarProjects', $src);
        $this->assertStringNotContainsString('Project::where', $src);
    }
}
