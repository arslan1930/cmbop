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

    public function test_store_status_prefers_intent_then_status_then_draft(): void
    {
        $this->assertSame('published', AdminBlog::resolveStoreStatus(Request::create('/admin/blogs', 'POST', [
            'intent' => 'publish',
            'status' => 'draft',
        ])));
        $this->assertSame('draft', AdminBlog::resolveStoreStatus(Request::create('/admin/blogs', 'POST', [
            'intent' => 'draft',
            'status' => 'published',
        ])));
        $this->assertSame('published', AdminBlog::resolveStoreStatus(Request::create('/admin/blogs', 'POST', [
            'status' => 'published',
        ])));
        $this->assertSame('draft', AdminBlog::resolveStoreStatus(Request::create('/admin/blogs', 'POST', [])));
        $this->assertSame('draft', AdminBlog::resolveStoreStatus(Request::create('/admin/blogs', 'POST', [
            'intent' => ['publish'],
            'status' => ['published'],
        ])));
    }

    public function test_public_blog_path_hint_shapes_en_and_prefixed_locales(): void
    {
        $this->assertSame('/blog/your-slug', AdminBlog::publicBlogPathHint('en', ''));
        $this->assertSame('/blog/hello-world', AdminBlog::publicBlogPathHint('en', 'hello-world'));
        $this->assertSame('/de/blog/hallo', AdminBlog::publicBlogPathHint('de', 'hallo'));
        $this->assertSame('/blog/x', AdminBlog::publicBlogPathHint('zz', 'x'));
    }

    public function test_return_query_survives_missing_session_and_junk_session(): void
    {
        $bare = Request::create('/admin/blogs/create?status=draft', 'GET');
        $this->assertSame(['status' => 'draft'], AdminBlog::rememberReturnQuery($bare));

        $this->assertSame([], AdminBlog::storedReturnQuery($bare));

        $withSession = Request::create('/admin/blogs/edit', 'GET');
        $withSession->setLaravelSession(app('session.store'));
        $withSession->session()->put('admin_blogs_return', [
            'status' => ['draft'],
            'q' => 'pillar',
            'page' => ['2'],
        ]);
        $this->assertSame(['q' => 'pillar'], AdminBlog::storedReturnQuery($withSession));
        $this->assertSame(route('admin.blogs.index', ['q' => 'pillar']), AdminBlog::listUrl($withSession->session()->get('admin_blogs_return')));
        $this->assertSame(route('admin.blogs.index'), AdminBlog::listUrl(['status' => ['draft']]));
    }
}
