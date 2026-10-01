<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdminThemeSelectsTest extends TestCase
{
    public function test_theme_selects_do_not_wrap_quill_toolbar_dropdowns(): void
    {
        $js = file_get_contents(public_path('assets/js/admin-theme-selects.js'));

        $this->assertStringContainsString('.ql-toolbar', $js);
        $this->assertStringContainsString('.ql-picker', $js);
        $this->assertStringContainsString("querySelectorAll('.admin-deposits-filters select')", $js);
    }

    public function test_blog_create_and_edit_share_quill_and_sit_in_filter_forms(): void
    {
        $create = file_get_contents(resource_path('views/admin/blogs/create.blade.php'));
        $edit = file_get_contents(resource_path('views/admin/blogs/edit.blade.php'));
        $editors = file_get_contents(resource_path('views/admin/blogs/partials/quill-editors.blade.php'));

        $this->assertStringContainsString('admin-deposits-filters', $create);
        $this->assertStringContainsString('admin-deposits-filters', $edit);
        $this->assertStringContainsString("id=\"blogForm\"", $create);
        $this->assertStringContainsString("id=\"blogForm\"", $edit);
        $this->assertStringContainsString('quill.snow.css', $editors);
        $this->assertStringContainsString('initBlogQuill', $editors);
    }
}
