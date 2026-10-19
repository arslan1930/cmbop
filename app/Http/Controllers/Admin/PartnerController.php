<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use App\Services\Partners\PartnerAccountService;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function __construct(private PartnerAccountService $accounts) {}

    public function index(Request $request): View
    {
        $partners = Partner::query()
            ->with('user')
            ->withCount('catalogItems')
            ->orderByDesc('id')
            ->paginate(40);

        return view('admin.partners.index', compact('partners'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'legal_name' => 'nullable|string|max:255',
            'trusted' => 'nullable|boolean',
            'agreement_signed' => 'nullable|boolean',
        ]);

        $user = User::query()->findOrFail((int) $data['user_id']);
        if (! $user->hasRole('publisher')) {
            return back()->with('error', 'That account is not a publisher.');
        }

        $this->accounts->mark($user, $request->user(), [
            'legal_name' => $data['legal_name'] ?? $user->name,
            'trusted' => $request->boolean('trusted'),
            'agreement_signed' => $request->boolean('agreement_signed'),
        ]);

        return redirect()->route('admin.partners.index')->with('success', 'Publisher marked as a catalog partner. They keep the normal publisher dashboard — Teqno LTD charges them no fees.');
    }

    public function show(Partner $partner): View
    {
        $partner->load(['user', 'feedConnection', 'catalogItems' => fn ($q) => $q->latest('id')->limit(20)]);
        $publisherRole = Role::where('name', 'publisher')->first();

        return view('admin.partners.show', compact('partner', 'publisherRole'));
    }

    public function update(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => 'nullable|string|max:255',
            'trusted' => 'nullable|boolean',
            'agreement_signed' => 'nullable|boolean',
            'status' => 'nullable|in:active,suspended',
            'feed_url' => 'nullable|url|max:2000',
            'notes_internal' => 'nullable|string|max:5000',
        ]);

        try {
            $this->accounts->mark($partner->user, $request->user(), [
                'legal_name' => $data['legal_name'] ?? $partner->legal_name,
                'trusted' => $request->boolean('trusted'),
                'agreement_signed' => $request->boolean('agreement_signed'),
                'status' => $data['status'] ?? $partner->status,
            ]);
            $partner->notes_internal = $data['notes_internal'] ?? $partner->notes_internal;
            $partner->save();
            $this->accounts->saveFeedUrl($partner, $data['feed_url'] ?? null, $request->user());
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not save the partner.'))->withInput();
        }

        return back()->with('success', 'Partner saved.');
    }
}
