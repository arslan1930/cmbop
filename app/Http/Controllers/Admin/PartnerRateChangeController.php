<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerSiteRateChange;
use App\Services\Partners\PartnerRateChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartnerRateChangeController extends Controller
{
    public function __construct(private PartnerRateChangeService $changes) {}

    public function index(Request $request): View
    {
        $status = $request->input('status', PartnerSiteRateChange::STATUS_PENDING);
        $query = PartnerSiteRateChange::query()->with('item.partner.user')->orderByDesc('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $changes = $query->paginate(40)->withQueryString();

        return view('admin.partners.rate-changes', compact('changes', 'status'));
    }

    public function approve(Request $request, PartnerSiteRateChange $rate_change): RedirectResponse
    {
        $request->validate([
            'raise_slb' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);
        $this->changes->approve($rate_change, $request->user(), $request->boolean('raise_slb', true), $request->input('reason'));

        return back()->with('success', 'Rate change approved. Open orders keep the frozen partner rate.');
    }

    public function decline(Request $request, PartnerSiteRateChange $rate_change): RedirectResponse
    {
        $this->changes->decline($rate_change, $request->user(), $request->input('reason'));

        return back()->with('success', 'Rate change declined. The live partner rate is unchanged.');
    }
}
