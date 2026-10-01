<?php

namespace Tests\Unit;

use App\Support\AdminCampaigns;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminCampaignsTest extends TestCase
{
    public function test_index_query_drops_array_junk(): void
    {
        $request = Request::create('/admin/campaigns', 'GET', [
            'status' => ['failed'],
            'page' => ['2'],
        ]);

        $this->assertSame([], AdminCampaigns::indexQuery($request));
    }

    public function test_index_query_keeps_scalars_and_page(): void
    {
        $request = Request::create('/admin/campaigns', 'GET', [
            'status' => 'attention',
            'page' => 2,
        ]);

        $this->assertSame([
            'status' => 'attention',
            'page' => 2,
        ], AdminCampaigns::indexQuery($request));
    }

    public function test_return_url_keeps_filters(): void
    {
        $this->assertSame(
            route('admin.campaigns.index', ['status' => 'failed', 'page' => 2]),
            AdminCampaigns::listUrl(['status' => 'failed', 'page' => 2])
        );
    }

    public function test_compose_only_index_does_not_wipe_return_query(): void
    {
        $session = app('session.store');
        $session->start();
        $session->put(AdminCampaigns::SESSION_KEY, ['status' => 'failed', 'page' => 2]);

        $compose = Request::create('/admin/campaigns', 'GET', ['draft' => '9', 'audience' => 'advertisers']);
        $compose->setLaravelSession($session);
        AdminCampaigns::rememberReturnQuery($compose);

        $this->assertSame(['status' => 'failed', 'page' => 2], $session->get(AdminCampaigns::SESSION_KEY));
        $this->assertTrue(AdminCampaigns::isComposeOnly($compose));
        $this->assertTrue(AdminCampaigns::isComposeOnly(
            Request::create('/admin/campaigns', 'GET', ['template' => 'welcome'])
        ));

        $clear = Request::create('/admin/campaigns', 'GET');
        $clear->setLaravelSession($session);
        AdminCampaigns::rememberReturnQuery($clear);

        $this->assertSame([], $session->get(AdminCampaigns::SESSION_KEY));
    }

    public function test_session_return_ignores_show_recipient_query(): void
    {
        $session = app('session.store');
        $session->start();
        $session->put(AdminCampaigns::SESSION_KEY, ['status' => 'attention', 'page' => 2]);

        $show = Request::create('/admin/campaigns/3', 'GET', ['status' => 'failed', 'page' => 4, 'q' => 'ada@example.com']);
        $show->setLaravelSession($session);

        $this->assertSame(
            ['status' => 'attention', 'page' => 2],
            AdminCampaigns::sessionReturnQuery($show)
        );
        $this->assertSame(['status' => 'attention', 'page' => 2], $session->get(AdminCampaigns::SESSION_KEY));
    }
}
