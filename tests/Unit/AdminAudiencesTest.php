<?php

namespace Tests\Unit;

use App\Services\AudienceInventoryService;
use App\Support\AdminAudiences;
use App\Support\AdminCampaigns;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminAudiencesTest extends TestCase
{
    public function test_filters_drop_array_junk(): void
    {
        $request = Request::create('/admin/audiences', 'GET', [
            'q' => ['ada@example.com'],
            'verified' => ['yes'],
            'country' => ['DE'],
            'marketing' => ['opted_in'],
            'exclude_dual_role' => ['1'],
            'sort' => ['registered'],
            'dir' => ['desc'],
        ]);

        $filters = AdminAudiences::filtersFromRequest($request);

        $this->assertSame('all', $filters['verified']);
        $this->assertSame('', $filters['country']);
        $this->assertSame('all', $filters['marketing']);
        $this->assertFalse($filters['exclude_dual_role']);
        $this->assertSame('', AdminAudiences::searchFromRequest($request));
        $this->assertSame([], AdminAudiences::filterQuery('', $filters));
    }

    public function test_filter_query_keeps_send_and_sort_keys(): void
    {
        $request = Request::create('/admin/audiences', 'GET', [
            'q' => 'ada@example.com',
            'verified' => 'yes',
            'country' => 'DE',
            'marketing' => 'opted_in',
            'exclude_dual_role' => '1',
            'sort' => 'registered',
            'dir' => 'desc',
        ]);

        $search = AdminAudiences::searchFromRequest($request);
        $filters = AdminAudiences::filtersFromRequest($request);

        $this->assertSame([
            'q' => 'ada@example.com',
            'verified' => 'yes',
            'country' => 'DE',
            'marketing' => 'opted_in',
            'exclude_dual_role' => 1,
            'sort' => 'registered',
            'dir' => 'desc',
        ], AdminAudiences::filterQuery($search, $filters));

        $this->assertSame([
            'q' => 'ada@example.com',
            'verified' => 'yes',
            'country' => 'DE',
            'marketing' => 'opted_in',
            'exclude_dual_role' => 1,
        ], AdminAudiences::handoffFields(AdminAudiences::snapshotFromRequest($request)));

        $this->assertTrue(AdminAudiences::hasListFilters($search, $filters));
        $this->assertTrue(AdminAudiences::hasSendFilters($search, $filters));
    }

    public function test_sort_only_is_list_filter_not_send_snapshot(): void
    {
        $request = Request::create('/admin/audiences', 'GET', [
            'sort' => 'registered',
            'dir' => 'desc',
        ]);
        $search = AdminAudiences::searchFromRequest($request);
        $filters = AdminAudiences::filtersFromRequest($request);

        $this->assertTrue(AdminAudiences::hasListFilters($search, $filters));
        $this->assertFalse(AdminAudiences::hasSendFilters($search, $filters));
        $this->assertSame(['search' => '', 'filters' => []], AdminAudiences::snapshotFromRequest($request));
    }

    public function test_compose_only_inventory_handoff_does_not_wipe_return_query(): void
    {
        $session = app('session.store');
        $session->start();
        $session->put(AdminCampaigns::SESSION_KEY, ['status' => 'failed', 'page' => 2]);

        $compose = Request::create('/admin/campaigns', 'GET', [
            'audience' => 'advertisers',
            'country' => 'DE',
            'verified' => 'yes',
        ]);
        $compose->setLaravelSession($session);
        AdminCampaigns::rememberReturnQuery($compose);

        $this->assertSame(['status' => 'failed', 'page' => 2], $session->get(AdminCampaigns::SESSION_KEY));
        $this->assertTrue(AdminCampaigns::isComposeOnly($compose));
        $this->assertTrue(AdminCampaigns::isComposeOnly(
            Request::create('/admin/campaigns', 'GET', ['country' => 'DE'])
        ));
    }

    public function test_export_filename_uses_label_and_date(): void
    {
        $this->assertSame(
            'no-paid-orders-'.now()->format('Y-m-d').'.csv',
            AudienceInventoryService::exportFilename('advertisers_no_paid_orders')
        );
    }

    public function test_summary_names_active_filters(): void
    {
        $this->assertSame(
            'search “ada” · verified · DE · not opted out · no dual-role',
            AdminAudiences::summary([
                'search' => 'ada',
                'filters' => [
                    'verified' => 'yes',
                    'country' => 'DE',
                    'marketing' => 'opted_in',
                    'exclude_dual_role' => true,
                ],
            ])
        );
    }
}
