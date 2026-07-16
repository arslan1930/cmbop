<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProblemReport;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityFeedbackController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'problems');
        if (! in_array($tab, ['problems', 'suggestions', 'websites', 'claims'], true)) {
            $tab = 'problems';
        }

        $status = $request->get('status');
        $q = trim((string) $request->get('q', ''));

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

        return view('admin.community.index', compact(
            'tab',
            'problems',
            'suggestions',
            'websites',
            'claims',
            'counts'
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

        DB::transaction(function () use ($claim, $data) {
            $site = Site::lockForUpdate()->findOrFail($claim->site_id);
            $previousPublisherId = $site->publisher_id;

            $site->publisher_id = $claim->claimer_id;
            $site->save();

            $claim->forceFill([
                'status' => 'approved',
                'admin_notes' => $data['admin_notes'] ?? $claim->admin_notes,
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
            ])->save();

            SiteClaim::query()
                ->where('site_id', $site->id)
                ->where('id', '!=', $claim->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'admin_notes' => 'Closed because another claim was approved.',
                    'reviewed_at' => now(),
                    'reviewed_by' => auth()->id(),
                ]);

            ActivityLogger::log(
                'site.claim_approved',
                auth()->user()->name.' approved site claim #'.$claim->id.' (publisher '.$previousPublisherId.' → '.$claim->claimer_id.')',
                $site,
                [
                    'claim_id' => $claim->id,
                    'previous_publisher_id' => $previousPublisherId,
                    'new_publisher_id' => $claim->claimer_id,
                ],
                $site->site_name
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Claim approved. Listing ownership transferred to the claimer.',
        ]);
    }

    public function rejectClaim(Request $request, int $id)
    {
        $claim = SiteClaim::findOrFail($id);
        if ($claim->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This claim was already reviewed.'], 422);
        }

        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:2000',
        ]);

        $claim->forceFill([
            'status' => 'rejected',
            'admin_notes' => $data['admin_notes'] ?? $claim->admin_notes,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ])->save();

        ActivityLogger::log(
            'site.claim_rejected',
            auth()->user()->name.' rejected site claim #'.$claim->id,
            $claim->site,
            ['claim_id' => $claim->id],
            $claim->website_name
        );

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
