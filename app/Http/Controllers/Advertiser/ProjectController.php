<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Project;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index()
    {
        $empty = $this->emptyProjectsView();

        if (! Project::tableAvailable()) {
            return $empty;
        }

        try {
            $userId = (int) auth()->id();

            $projects = Project::where('user_id', $userId)
                ->latest()
                ->get();

            $orders = collect();
            try {
                $itemWith = Schema::hasColumn('order_items', 'content_submission_id')
                    ? ['items.contentSubmission']
                    : ['items'];

                $orders = Order::query()
                    ->where('user_id', $userId)
                    ->with($itemWith)
                    ->get();
            } catch (\Throwable $e) {
                report($e);
            }

            $assigned = $orders;
            $unassigned = $orders;
            try {
                if (Schema::hasColumn('orders', 'project_id')) {
                    $assigned = $orders->filter(fn (Order $order) => (int) ($order->project_id ?? 0) > 0);
                    $unassigned = $orders->filter(fn (Order $order) => (int) ($order->project_id ?? 0) <= 0);
                } else {
                    $assigned = collect();
                }
            } catch (\Throwable) {
                $assigned = collect();
                $unassigned = $orders;
            }

            $countsByProject = Project::stageCountsByAssignedProject($assigned);
            $countsByHost = Project::stageCountsByHost($unassigned);

            foreach ($projects as $project) {
                $host = Project::hostFromUrl($project->project_url);
                $project->setAttribute(
                    'stage_counts',
                    Project::mergeStageCounts(
                        $countsByProject[$project->id] ?? Project::emptyStageCounts(),
                        $countsByHost[$host] ?? Project::emptyStageCounts()
                    )
                );
            }

            $projects = $projects
                ->sortByDesc(fn (Project $project) => $project->created_at)
                ->sortByDesc(fn (Project $project) => Project::cardPriorityFrom(
                    $project->stage_counts ?? Project::emptyStageCounts()
                ))
                ->values();

            $attentionProjectsList = $projects->filter(
                fn (Project $project) => Project::needsYouCountFrom(
                    $project->stage_counts ?? Project::emptyStageCounts()
                ) > 0
            );
            $attentionPlacements = (int) $attentionProjectsList->sum(
                fn (Project $project) => Project::needsYouCountFrom(
                    $project->stage_counts ?? Project::emptyStageCounts()
                )
            );
            $attentionProjects = $attentionProjectsList->count();
            $attentionProjectId = $attentionProjects === 1
                ? $attentionProjectsList->first()?->id
                : null;

            return view('advertiser.campaigns', compact(
                'projects',
                'attentionPlacements',
                'attentionProjects',
                'attentionProjectId',
            ));
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load projects. Please refresh and try again.')
            );

            return $empty;
        }
    }

    public function store(Request $request)
    {
        if (! Project::tableAvailable()) {
            return back()->with('error', 'Projects are temporarily unavailable. Please try again shortly.');
        }

        $validated = $request->validate($this->projectRules(), $this->projectMessages());

        try {
            Project::create([
                'user_id' => auth()->id(),
                'project_name' => $validated['project_name'],
                'project_url' => $validated['project_url'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'Could not create that project. Please try again.')
            );
        }

        return back()->with('success', 'Project created successfully.');
    }

    public function update(Request $request, Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        if (! Project::tableAvailable()) {
            return back()->with('error', 'Projects are temporarily unavailable. Please try again shortly.');
        }

        $validated = $request->validate($this->projectRules($project->id), $this->projectMessages());

        try {
            $project->update([
                'project_name' => $validated['project_name'],
                'project_url' => $validated['project_url'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'Could not update that project. Please try again.')
            );
        }

        return back()->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        if (! Project::tableAvailable()) {
            return back()->with('error', 'Projects are temporarily unavailable. Please try again shortly.');
        }

        try {
            $project->delete();
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'Could not delete that project. Please try again.')
            );
        }

        return back()->with('success', 'Project deleted successfully.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function projectRules(?int $ignoreId = null): array
    {
        $userId = (int) auth()->id();

        $nameUnique = Rule::unique('projects', 'project_name')->where('user_id', $userId);
        $urlUnique = Rule::unique('projects', 'project_url')->where('user_id', $userId);
        if ($ignoreId !== null) {
            $nameUnique->ignore($ignoreId);
            $urlUnique->ignore($ignoreId);
        }

        return [
            'project_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9\s\-]+$/',
                $nameUnique,
            ],
            'project_url' => [
                'required',
                'url:http,https',
                'max:255',
                $urlUnique,
                function (string $attribute, mixed $value, \Closure $fail) use ($userId, $ignoreId) {
                    if (Project::hostTakenByUser($userId, (string) $value, $ignoreId)) {
                        $fail('You already have a project for this website.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function projectMessages(): array
    {
        return [
            'project_name.regex' => 'Use letters, numbers, spaces, and hyphens only.',
            'project_name.unique' => 'You already have a project with this name.',
            'project_url.url' => 'Enter a full website URL, including https://.',
            'project_url.unique' => 'You already have a project with this URL.',
        ];
    }

    private function emptyProjectsView(): View
    {
        return view('advertiser.campaigns', [
            'projects' => collect(),
            'attentionPlacements' => 0,
            'attentionProjects' => 0,
            'attentionProjectId' => null,
        ]);
    }
}
