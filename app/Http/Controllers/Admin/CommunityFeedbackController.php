<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProblemReport;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use App\Services\SiteClaimTransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommunityFeedbackController extends Controller
{
    public function __construct(private SiteClaimTransferService $claimTransfers) {}

    public function index(Request $request)
    {
        $tab = scalar_text($request->get('tab', 'problems'));
        if (! in_array($tab, ['problems', 'suggestions', 'websites', 'claims'], true)) {
            $tab = 'problems';
        }

        $status = scalar_text($request->get('status'));
        $q = trim(scalar_text($request->get('q', '')));

        $problems = ProblemReport::query()
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->when($tab === 'problems' && $status, fn ($query) => $query->where('status', $status))
            ->when($tab === 'problems' && $q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('subject', 'like', "%{$q}%")
                        ->orWhere('message', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->latest('id')
            ->paginate(25, ['*'], 'problems_page')
            ->withQueryString();

        $suggestions = Suggestion::query()
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->when($tab === 'suggestions' && $status, fn ($query) => $query->where('status', $status))
            ->when($tab === 'suggestions' && $q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('message', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->latest('id')
            ->paginate(25, ['*'], 'suggestions_page')
            ->withQueryString();

        $websites = WebsiteSuggestion::query()
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->when($tab === 'websites' && $status, fn ($query) => $query->where('status', $status))
            ->when($tab === 'websites' && $q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('website_name', 'like', "%{$q}%")
                        ->orWhere('website_url', 'like', "%{$q}%")
                        ->orWhere('domain', 'like', "%{$q}%")
                        ->orWhere('notes', 'like', "%{$q}%");
                });
            })
            ->latest('id')
            ->paginate(25, ['*'], 'websites_page')
            ->withQueryString();

        $claims = SiteClaim::query()
            ->with([
                'site:id,site_name,domain,site_url,publisher_id',
                'site.publisher:id,name,email',
                'claimer:id,name,email',
                'reviewer:id,name',
            ])
            ->when($tab === 'claims' && $status, fn ($query) => $query->where('status', $status))
            ->when($tab === 'claims' && $q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('website_name', 'like', "%{$q}%")
                        ->orWhere('domain', 'like', "%{$q}%")
                        ->orWhere('proof_message', 'like', "%{$q}%")
                        ->orWhereHas('claimer', function ($u) use ($q) {
                            $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
                        });
                });
            })
            ->latest('id')
            ->paginate(25, ['*'], 'claims_page')
            ->withQueryString();

        $counts = [
            'problems' => ProblemReport::where('status', 'pending')->count(),
            'suggestions' => Suggestion::where('status', 'pending')->count(),
            'websites' => WebsiteSuggestion::where('status', 'pending')->count(),
            'claims' => SiteClaim::where('status', 'pending')->count(),
        ];

        // Open (in-flight) order / dispute counts per pending claim so the approve dialog can warn.
        $claimOpenOrders = [];
        $claimOpenDisputes = [];
        foreach ($claims as $claim) {
            if ($claim->status === 'pending' && $claim->site) {
                $claimOpenOrders[$claim->id] = $this->claimTransfers->openOrderItemsCount($claim->site);
                $claimOpenDisputes[$claim->id] = $this->claimTransfers->openDisputesCount($claim->site);
            }
        }

        return view('admin.community.index', compact(
            'tab',
            'problems',
            'suggestions',
            'websites',
            'claims',
            'counts',
            'claimOpenOrders',
            'claimOpenDisputes'
        ));
    }

    public function updateProblem(Request $request, int $id)
    {
        return $this->updateStatus(ProblemReport::findOrFail($id), $request, 'problem.report_updated');
    }

    public function updateSuggestion(Request $request, int $id)
    {
        return $this->updateStatus(Suggestion::findOrFail($id), $request, 'suggestion.updated');
    }

    public function updateWebsiteSuggestion(Request $request, int $id)
    {
        return $this->updateStatus(WebsiteSuggestion::findOrFail($id), $request, 'website.suggestion_updated');
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

    private function updateStatus($model, Request $request, string $activityType)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,reviewed,resolved,rejected,accepted',
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $model->forceFill([
            'status' => $data['status'],
            'admin_notes' => $data['admin_notes'] ?? $model->admin_notes,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ])->save();

        ActivityLogger::log(
            $activityType,
            auth()->user()->name.' updated '.$activityType.' #'.$model->id,
            $model,
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'Updated.',
            'item' => $model->fresh(['user:id,name,email', 'reviewer:id,name']),
        ]);
    }
}
