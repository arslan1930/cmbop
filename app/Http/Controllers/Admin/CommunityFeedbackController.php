<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProblemReport;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use App\Services\CommunityInboxNotifier;
use App\Services\SiteClaimTransferService;
use App\Support\CommunityInbox;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunityFeedbackController extends Controller
{
    public function __construct(
        private SiteClaimTransferService $claimTransfers,
        private CommunityInboxNotifier $inboxNotifier,
    ) {}

    public function index(Request $request)
    {
        $q = search_text($request->get('q'));
        $tabs = CommunityInbox::TABS;
        $counts = [
            'problems' => ProblemReport::where('status', 'pending')->count(),
            'suggestions' => Suggestion::where('status', 'pending')->count(),
            'websites' => WebsiteSuggestion::where('status', 'pending')->count(),
            'claims' => SiteClaim::where('status', 'pending')->count(),
        ];

        $tabProvided = search_text($request->query('tab')) !== '';
        $tab = $tabProvided
            ? CommunityInbox::normalizeTab($request->query('tab'))
            : CommunityInbox::landingTab($counts);
        $status = CommunityInbox::normalizeStatus($tab, $request->get('status'));
        $statuses = CommunityInbox::statusesFor($tab);
        $filtered = $q !== '' || $status !== null;
        $tabQueries = [];
        foreach (array_keys($tabs) as $key) {
            $tabQueries[$key] = CommunityInbox::tabQuery($key, $q, $request->get('status'));
        }

        $problems = $tab === 'problems'
            ? ProblemReport::query()
                ->with(['user:id,name,email', 'reviewer:id,name'])
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($q !== '', function ($query) use ($q) {
                    $query->where(function ($inner) use ($q) {
                        CommunityInbox::constrainSearch($inner, ['subject', 'message', 'email', 'name', 'page_url'], $q);
                        $inner->orWhereHas('user', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q));
                    });
                })
                ->latest('id')
                ->paginate(25, ['*'], 'problems_page')
                ->withQueryString()
            : CommunityInbox::emptyPage($request, 'problems_page');

        $suggestions = $tab === 'suggestions'
            ? Suggestion::query()
                ->with(['user:id,name,email', 'reviewer:id,name'])
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($q !== '', function ($query) use ($q) {
                    $query->where(function ($inner) use ($q) {
                        CommunityInbox::constrainSearch($inner, ['message', 'email', 'name', 'page_url'], $q);
                        $inner->orWhereHas('user', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q));
                    });
                })
                ->latest('id')
                ->paginate(25, ['*'], 'suggestions_page')
                ->withQueryString()
            : CommunityInbox::emptyPage($request, 'suggestions_page');

        $websites = $tab === 'websites'
            ? WebsiteSuggestion::query()
                ->with(['user:id,name,email', 'reviewer:id,name'])
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($q !== '', function ($query) use ($q) {
                    $query->where(function ($inner) use ($q) {
                        CommunityInbox::constrainSearch($inner, ['website_name', 'website_url', 'domain', 'notes'], $q);
                        $inner->orWhereHas('user', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q));
                    });
                })
                ->latest('id')
                ->paginate(25, ['*'], 'websites_page')
                ->withQueryString()
            : CommunityInbox::emptyPage($request, 'websites_page');

        $claims = $tab === 'claims'
            ? SiteClaim::query()
                ->with([
                    'site:id,site_name,domain,site_url,publisher_id,verified',
                    'site.publisher:id,name,email',
                    'claimer:id,name,email',
                    'claimer.roles',
                    'reviewer:id,name',
                ])
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($q !== '', function ($query) use ($q) {
                    $query->where(function ($inner) use ($q) {
                        CommunityInbox::constrainSearch($inner, ['website_name', 'domain', 'proof_message', 'contact_email'], $q);
                        $inner->orWhereHas('claimer', fn ($u) => CommunityInbox::constrainSearch($u, ['name', 'email'], $q));
                        $inner->orWhereHas('site', fn ($s) => CommunityInbox::constrainSearch($s, ['site_name', 'domain'], $q));
                    });
                })
                ->latest('id')
                ->paginate(25, ['*'], 'claims_page')
                ->withQueryString()
            : CommunityInbox::emptyPage($request, 'claims_page');

        $occupyingSites = $tab === 'websites'
            ? CommunityInbox::occupyingSitesFor($websites)
            : [];

        $claimOpenOrders = [];
        $claimOpenDisputes = [];
        $claimContexts = [];
        $claimSiblingPending = [];
        $siteIds = $claims->getCollection()->pluck('site_id')->filter()->unique()->values();
        $pendingBySite = $siteIds->isEmpty()
            ? collect()
            : SiteClaim::query()
                ->whereIn('site_id', $siteIds)
                ->where('status', 'pending')
                ->selectRaw('site_id, COUNT(*) as aggregate')
                ->groupBy('site_id')
                ->pluck('aggregate', 'site_id');

        foreach ($claims as $claim) {
            if ($claim->status === 'pending' && $claim->site) {
                $claimOpenOrders[$claim->id] = $this->claimTransfers->openOrderItemsCount($claim->site);
                $claimOpenDisputes[$claim->id] = $this->claimTransfers->openDisputesCount($claim->site);
            }
            $pendingOnSite = (int) ($pendingBySite[$claim->site_id] ?? 0);
            $claimSiblingPending[$claim->id] = max(0, $pendingOnSite - ($claim->status === 'pending' ? 1 : 0));
            $claimContexts[$claim->id] = [
                'open_orders' => $claimOpenOrders[$claim->id] ?? 0,
                'open_disputes' => $claimOpenDisputes[$claim->id] ?? 0,
                'verified' => (bool) ($claim->site?->verified),
                'name_matches' => (bool) $claim->name_matches,
                'claimer_has_publisher_role' => (bool) ($claim->claimer?->roles?->contains('name', 'publisher')),
            ];
        }

        return view('admin.community.index', compact(
            'tab',
            'tabs',
            'status',
            'statuses',
            'q',
            'filtered',
            'tabQueries',
            'problems',
            'suggestions',
            'websites',
            'claims',
            'counts',
            'claimOpenOrders',
            'claimOpenDisputes',
            'claimContexts',
            'claimSiblingPending',
            'occupyingSites'
        ));
    }

    public function updateProblem(Request $request, int $id)
    {
        return $this->updateStatus(
            ProblemReport::findOrFail($id),
            $request,
            'problem.report_updated',
            CommunityInbox::TAB_PROBLEMS
        );
    }

    public function updateSuggestion(Request $request, int $id)
    {
        return $this->updateStatus(
            Suggestion::findOrFail($id),
            $request,
            'suggestion.updated',
            CommunityInbox::TAB_SUGGESTIONS
        );
    }

    public function updateWebsiteSuggestion(Request $request, int $id)
    {
        return $this->updateStatus(
            WebsiteSuggestion::findOrFail($id),
            $request,
            'website.suggestion_updated',
            CommunityInbox::TAB_WEBSITES
        );
    }

    public function approveClaim(Request $request, int $id)
    {
        $claim = SiteClaim::with('site')->findOrFail($id);
        if ($claim->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This claim was already reviewed.'], 422);
        }

        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $this->claimTransfers->approve($claim, $request->user(), $data['admin_notes'] ?? null);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'This claim could not be approved.',
                'open_orders' => $claim->site
                    ? $this->claimTransfers->openOrderItemsCount($claim->site)
                    : 0,
                'open_disputes' => $claim->site
                    ? $this->claimTransfers->openDisputesCount($claim->site)
                    : 0,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Claim approved. Listing ownership transferred to the claimer.',
        ]);
    }

    public function rejectClaim(Request $request, int $id)
    {
        $claim = SiteClaim::with('site')->findOrFail($id);
        if ($claim->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This claim was already reviewed.'], 422);
        }

        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $this->claimTransfers->reject($claim, $request->user(), $data['admin_notes'] ?? null);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'This claim could not be rejected.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Claim rejected.',
        ]);
    }

    private function updateStatus($model, Request $request, string $activityType, string $tab)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(CommunityInbox::statusesFor($tab))],
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $goingPending = $data['status'] === 'pending';
        $leavingPending = $model->status === 'pending' && ! $goingPending;

        $model->forceFill([
            'status' => $data['status'],
            'admin_notes' => $data['admin_notes'] ?? $model->admin_notes,
            'reviewed_at' => $goingPending ? null : now(),
            'reviewed_by' => $goingPending
                ? null
                : ($leavingPending ? auth()->id() : ($model->reviewed_by ?: auth()->id())),
        ])->save();

        ActivityLogger::log(
            $activityType,
            auth()->user()->name.' updated '.$activityType.' #'.$model->id,
            $model,
            $data
        );

        if ($leavingPending) {
            $this->inboxNotifier->notifySubmitterReviewed($model->fresh(['user']), $tab);
        }

        return response()->json([
            'success' => true,
            'message' => 'Updated.',
            'item' => $model->fresh(['user:id,name,email', 'reviewer:id,name']),
        ]);
    }
}
