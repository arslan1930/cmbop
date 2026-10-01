<?php

namespace Tests\Unit;

use App\Support\AdminCatalogActivity;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminCatalogActivityTest extends TestCase
{
    public function test_index_query_keeps_known_filters(): void
    {
        $request = Request::create('/admin/catalog-activity', 'GET', [
            'days' => 30,
            'copy' => 'all',
            'q' => 'ada@',
            'user' => 9,
            'bogus' => 'x',
        ]);

        $this->assertSame([
            'days' => 30,
            'copy' => 'all',
            'q' => 'ada@',
        ], AdminCatalogActivity::indexQuery($request));
    }

    public function test_defaults_and_junk_drop_out(): void
    {
        $this->assertSame([], AdminCatalogActivity::indexQuery(
            Request::create('/admin/catalog-activity', 'GET', [
                'days' => 7,
                'copy' => 'attention',
                'q' => ['injected'],
                'user' => ['x'],
                'days_junk' => ['14'],
            ])
        ));
        $this->assertSame(7, AdminCatalogActivity::days(
            Request::create('/admin/catalog-activity', 'GET', ['days' => ['14']])
        ));
        $this->assertSame(0, AdminCatalogActivity::focusUserId(
            Request::create('/admin/catalog-activity', 'GET', ['user' => ['9'], 'q' => ''])
        ));
    }

    public function test_user_pin_survives_without_search(): void
    {
        $this->assertSame(['user' => 9], AdminCatalogActivity::indexQuery(
            Request::create('/admin/catalog-activity', 'GET', ['user' => 9])
        ));
    }

    public function test_list_url_and_session_survive_missing_session(): void
    {
        $bare = Request::create('/admin/catalog-activity?days=30&copy=all', 'GET');
        $this->assertSame(['days' => 30, 'copy' => 'all'], AdminCatalogActivity::rememberReturnQuery($bare));
        $this->assertSame([], AdminCatalogActivity::sessionReturnQuery($bare));

        $withSession = Request::create('/admin/catalog-activity/1', 'GET');
        $withSession->setLaravelSession(app('session.store'));
        $withSession->session()->put(AdminCatalogActivity::SESSION_KEY, [
            'days' => 30,
            'copy' => 'all',
            'q' => 'ada@',
            'user' => ['9'],
        ]);
        $this->assertSame(
            ['days' => 30, 'copy' => 'all', 'q' => 'ada@'],
            AdminCatalogActivity::sessionReturnQuery($withSession)
        );

        $this->assertSame(
            route('admin.catalog-activity', ['days' => 30, 'copy' => 'all']),
            AdminCatalogActivity::listUrl(['days' => 30, 'copy' => 'all'])
        );
        $this->assertSame(route('admin.catalog-activity'), AdminCatalogActivity::listUrl(['user' => ['x']]));
    }

    public function test_queue_url_does_not_keep_a_different_account_pin(): void
    {
        $request = Request::create('/admin/catalog-activity/50', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(AdminCatalogActivity::SESSION_KEY, ['user' => 108]);

        $this->assertSame(
            route('admin.catalog-activity', ['user' => 50]),
            AdminCatalogActivity::queueUrl($request, 50)
        );

        $request->session()->put(AdminCatalogActivity::SESSION_KEY, [
            'days' => 30,
            'copy' => 'all',
        ]);
        $this->assertSame(
            route('admin.catalog-activity', ['days' => 30, 'copy' => 'all']),
            AdminCatalogActivity::queueUrl($request, 50)
        );
    }
}
