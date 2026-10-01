<?php

namespace Tests\Unit;

use App\Support\AdminModeration;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    public function test_index_query_keeps_known_filters_and_page(): void
    {
        $request = Request::create('/admin/moderation', 'GET', [
            'status' => 'needs',
            'category' => 'gambling',
            'q' => 'casino',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'page' => 2,
            'bogus' => 'x',
        ]);

        $this->assertSame([
            'status' => 'needs',
            'category' => 'gambling',
            'q' => 'casino',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'page' => 2,
        ], AdminModeration::indexQuery($request));
    }

    public function test_all_and_unknown_status_drop_out(): void
    {
        $request = Request::create('/admin/moderation', 'GET', [
            'status' => 'all',
            'category' => 'all',
            'page' => 1,
        ]);

        $this->assertSame([], AdminModeration::indexQuery($request));
        $this->assertSame([], AdminModeration::indexQuery(
            Request::create('/admin/moderation', 'GET', ['status' => 'pending'])
        ));
    }

    public function test_list_url_and_session_survive_missing_session(): void
    {
        $bare = Request::create('/admin/moderation?status=error&page=2', 'GET');
        $this->assertSame(['status' => 'error', 'page' => 2], AdminModeration::rememberReturnQuery($bare));
        $this->assertSame([], AdminModeration::sessionReturnQuery($bare));

        $withSession = Request::create('/admin/moderation/logs/1', 'GET');
        $withSession->setLaravelSession(app('session.store'));
        $withSession->session()->put(AdminModeration::SESSION_KEY, [
            'status' => 'needs',
            'page' => ['3'],
            'q' => 'title',
        ]);
        $this->assertSame(['status' => 'needs', 'q' => 'title'], AdminModeration::sessionReturnQuery($withSession));

        $this->assertSame(
            route('admin.moderation.index', ['status' => 'needs', 'page' => 2]),
            AdminModeration::listUrl(['status' => 'needs', 'page' => 2])
        );
        $this->assertSame(route('admin.moderation.index'), AdminModeration::listUrl(['page' => ['2']]));
    }
}
