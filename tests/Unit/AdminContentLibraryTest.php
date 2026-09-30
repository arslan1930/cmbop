<?php

namespace Tests\Unit;

use App\Support\AdminContentLibrary;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminContentLibraryTest extends TestCase
{
    public function test_index_query_keeps_known_filters_and_page(): void
    {
        $request = Request::create('/admin/content-library', 'GET', [
            'availability' => 'needs_fix',
            'q' => 'guide',
            'advertiser' => 'ada@example.com',
            'user_id' => 9,
            'country' => 'us',
            'language' => 'en',
            'sort' => 'expires',
            'attachment' => 'order',
            'expiring' => 'soon',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'page' => 2,
        ]);

        $this->assertSame([
            'availability' => 'needs_fix',
            'q' => 'guide',
            'advertiser' => 'ada@example.com',
            'user_id' => 9,
            'country' => 'us',
            'language' => 'en',
            'sort' => 'expires',
            'attachment' => 'order',
            'expiring' => 'soon',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'page' => 2,
        ], AdminContentLibrary::indexQuery($request));
    }

    public function test_defaults_and_junk_drop_out(): void
    {
        $this->assertSame([], AdminContentLibrary::indexQuery(
            Request::create('/admin/content-library', 'GET', [
                'availability' => 'all',
                'sort' => 'latest',
                'language' => 'all',
                'attachment' => 'maybe',
                'expiring' => 'later',
                'page' => 1,
            ])
        ));
        $this->assertSame(
            ['availability' => 'available'],
            AdminContentLibrary::indexQuery(
                Request::create('/admin/content-library', 'GET', ['status' => 'approved'])
            )
        );
    }

    public function test_list_url_and_session_survive_missing_session(): void
    {
        $bare = Request::create('/admin/content-library?availability=expired&page=2', 'GET');
        $this->assertSame(['availability' => 'expired', 'page' => 2], AdminContentLibrary::rememberReturnQuery($bare));
        $this->assertSame([], AdminContentLibrary::sessionReturnQuery($bare));

        $withSession = Request::create('/admin/content-library/1', 'GET');
        $withSession->setLaravelSession(app('session.store'));
        $withSession->session()->put(AdminContentLibrary::SESSION_KEY, [
            'availability' => 'needs_fix',
            'q' => 'Piece',
            'page' => ['3'],
        ]);
        $this->assertSame(
            ['availability' => 'needs_fix', 'q' => 'Piece'],
            AdminContentLibrary::sessionReturnQuery($withSession)
        );
        $this->assertSame(
            route('admin.content-library.index', ['availability' => 'needs_fix', 'q' => 'Piece']),
            AdminContentLibrary::listUrl(['availability' => 'needs_fix', 'q' => 'Piece'])
        );
    }
}
