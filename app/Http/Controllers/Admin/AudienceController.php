<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AudienceInventoryService;
use Illuminate\Http\Request;

class AudienceController extends Controller
{
    public function index(Request $request, AudienceInventoryService $inventory)
    {
        $tab = scalar_text($request->get('tab', 'advertisers'));
        if (! in_array($tab, ['advertisers', 'publishers', 'no_orders', 'no_sites', 'never_deposited'], true)) {
            $tab = 'advertisers';
        }

        $audienceKey = match ($tab) {
            'publishers' => AudienceInventoryService::AUDIENCE_PUBLISHERS,
            'no_orders' => AudienceInventoryService::AUDIENCE_ADVERTISERS_NO_ORDERS,
            'no_sites' => AudienceInventoryService::AUDIENCE_PUBLISHERS_NO_SITES,
            'never_deposited' => AudienceInventoryService::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
            default => AudienceInventoryService::AUDIENCE_ADVERTISERS,
        };

        $search = trim(scalar_text($request->get('q')));
        $users = $inventory->paginate($audienceKey, $search !== '' ? $search : null);
        $stats = $inventory->stats();
        $campaignAudience = $audienceKey;

        return view('admin.audiences.index', compact('tab', 'users', 'stats', 'search', 'campaignAudience'));
    }

    public function export(Request $request, AudienceInventoryService $inventory)
    {
        $audience = scalar_text($request->get('audience', 'advertisers'));
        $audienceKey = match ($audience) {
            'publishers' => AudienceInventoryService::AUDIENCE_PUBLISHERS,
            'no_orders', AudienceInventoryService::AUDIENCE_ADVERTISERS_NO_ORDERS => AudienceInventoryService::AUDIENCE_ADVERTISERS_NO_ORDERS,
            'no_sites', AudienceInventoryService::AUDIENCE_PUBLISHERS_NO_SITES => AudienceInventoryService::AUDIENCE_PUBLISHERS_NO_SITES,
            'never_deposited', AudienceInventoryService::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => AudienceInventoryService::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
            default => AudienceInventoryService::AUDIENCE_ADVERTISERS,
        };

        return $inventory->exportCsv($audienceKey);
    }
}
