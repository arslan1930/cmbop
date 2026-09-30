<?php

namespace Tests\Unit;

use App\Support\AdminBlog;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminBlogFiltersTest extends TestCase
{
    public function test_list_helpers_reject_junk_query_values(): void
    {
        $this->assertSame('', AdminBlog::normalizeStatus(['draft']));
        $this->assertSame('draft', AdminBlog::normalizeStatus('draft'));
        $this->assertSame('', AdminBlog::normalizeKind(['curated']));
        $this->assertSame('newest', AdminBlog::normalizeSort(['published']));
        $this->assertSame('published', AdminBlog::normalizeSort('published'));
        $this->assertFalse(AdminBlog::normalizeIncomplete(['1']));
        $this->assertTrue(AdminBlog::normalizeIncomplete('1'));
        $this->assertSame('', AdminBlog::normalizeLocale(['de']));
        $this->assertSame('de', AdminBlog::normalizeLocale('de'));
        $this->assertFalse(AdminBlog::normalizeIncomplete(['on']));
    }

    public function test_form_locales_default_to_english(): void
    {
        $request = Request::create('/admin/blogs/create', 'GET');
        $this->assertSame(['en'], AdminBlog::formLocales(null, $request));

        $withDe = Request::create('/admin/blogs/create?add_locale=de', 'GET');
        $this->assertSame(['en', 'de'], AdminBlog::formLocales(null, $withDe));
    }
}
