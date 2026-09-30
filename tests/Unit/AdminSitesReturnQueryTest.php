<?php

namespace Tests\Unit;

use App\Support\AdminSites;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminSitesReturnQueryTest extends TestCase
{
    public function test_index_query_keeps_list_page_and_drops_publisher_in_all_sites_mode(): void
    {
        $request = Request::create('/admin/sites', 'GET', [
            'all' => 1,
            'page' => 3,
            'per_page' => 50,
            'publisher' => 9,
            'site' => 44,
            'q' => 'example',
        ]);

        $this->assertSame([
            'q' => 'example',
            'all' => 1,
            'page' => 3,
            'per_page' => 50,
        ], AdminSites::indexQuery($request));
    }

    public function test_publisher_view_uses_sites_page_not_list_page(): void
    {
        $request = Request::create('/admin/sites', 'GET', [
            'publisher' => 12,
            'page' => 4,
            'sites_page' => 2,
        ]);

        $this->assertSame([
            'publisher' => 12,
            'sites_page' => 2,
        ], AdminSites::indexQuery($request));
    }

    public function test_return_query_survives_missing_session_and_junk_page(): void
    {
        $bare = Request::create('/admin/sites/1/edit?all=1&page=2', 'GET');
        $this->assertSame(['all' => 1, 'page' => 2], AdminSites::rememberReturnQuery($bare));
        $this->assertSame(['all' => 1, 'page' => 2], AdminSites::storedReturnQuery($bare));

        $withSession = Request::create('/admin/sites/1/edit', 'GET');
        $withSession->setLaravelSession(app('session.store'));
        $withSession->session()->put(AdminSites::SESSION_KEY, [
            'all' => ['1'],
            'page' => ['3'],
            'q' => 'niche',
        ]);

        $this->assertSame(['q' => 'niche'], AdminSites::storedReturnQuery($withSession));
        $this->assertSame(
            staff_route('sites.index', ['q' => 'niche']),
            AdminSites::listUrl($withSession->session()->get(AdminSites::SESSION_KEY))
        );
        $this->assertSame(staff_route('sites.index'), AdminSites::listUrl(['page' => ['2']]));
    }
}
