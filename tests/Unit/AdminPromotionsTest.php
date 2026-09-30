<?php

namespace Tests\Unit;

use App\Support\AdminPromotions;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminPromotionsTest extends TestCase
{
    public function test_index_query_keeps_known_filters_and_page(): void
    {
        $request = Request::create('/admin/promotions/announcements', 'GET', [
            'status' => 'live',
            'audience' => 'advertiser',
            'type' => 'general',
            'q' => 'sale',
            'page' => 2,
            'placement' => 'header',
        ]);

        $this->assertSame([
            'status' => 'live',
            'audience' => 'advertiser',
            'q' => 'sale',
            'type' => 'general',
            'page' => 2,
        ], AdminPromotions::indexQuery($request, 'announcements'));
    }

    public function test_banner_query_keeps_placement_not_type(): void
    {
        $request = Request::create('/admin/promotions/banners', 'GET', [
            'placement' => 'header',
            'type' => 'general',
            'status' => 'bogus',
        ]);

        $this->assertSame([
            'placement' => 'header',
        ], AdminPromotions::indexQuery($request, 'banners'));
    }

    public function test_list_url_and_session_survive_missing_session(): void
    {
        $bare = Request::create('/admin/promotions/announcements?audience=public&page=2', 'GET');
        $this->assertSame(['audience' => 'public', 'page' => 2], AdminPromotions::rememberReturnQuery($bare, 'announcements'));
        $this->assertSame([], AdminPromotions::sessionReturnQuery($bare, 'announcements'));

        $withSession = Request::create('/admin/promotions/announcements/create', 'GET');
        $withSession->setLaravelSession(app('session.store'));
        $withSession->session()->put(AdminPromotions::SESSION_ANNOUNCEMENTS, [
            'audience' => 'public',
            'page' => ['3'],
            'q' => 'sale',
        ]);
        $this->assertSame(['audience' => 'public', 'q' => 'sale'], AdminPromotions::sessionReturnQuery($withSession, 'announcements'));

        $this->assertSame(
            route('admin.promotions.announcements.index', ['audience' => 'public', 'page' => 2]),
            AdminPromotions::listUrl('announcements', ['audience' => 'public', 'page' => 2])
        );
        $this->assertSame(route('admin.promotions.announcements.index'), AdminPromotions::listUrl('announcements', ['page' => ['2']]));
    }
}
