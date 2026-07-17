<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AudienceInventoryService;
use Illuminate\Http\Request;

class AudienceController extends Controller
{
    public function index(Request $request, AudienceInventoryService $inventory)
    {
        $tab = $request->get('tab', 'advertisers');
        if (!in_array($tab, ['advertisers', 'publishers'], true)) {
            $tab = 'advertisers';
        }

        $role = $tab === 'publishers' ? 'publisher' : 'advertiser';
        $search = $request->get('q');
        $users = $inventory->paginate($role, $search);
        $stats = $inventory->stats();

        return view('admin.audiences.index', compact('tab', 'users', 'stats', 'search'));
    }

    public function export(Request $request, AudienceInventoryService $inventory)
    {
        $audience = $request->get('audience', 'advertisers');
        $role = match ($audience) {
            'publishers' => 'publisher',
            default => 'advertiser',
        };

        return $inventory->exportCsv($role);
    }
}
