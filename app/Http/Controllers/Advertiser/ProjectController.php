<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use Illuminate\Support\Str;

class ProjectController extends Controller
{

    public function index()
    {
        $projects = Project::where('user_id', auth()->id())
            ->latest()
            ->get();

        return view('advertiser.campaigns', compact('projects'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_name' => [
                'required',
                'string',
                'max:255',
                // ✅ unique per user
                'unique:projects,project_name,NULL,id,user_id,' . auth()->id(),
            ],
            'project_url' => [
                'required',
                'url',
                'max:255',
                // ✅ unique per user
                'unique:projects,project_url,NULL,id,user_id,' . auth()->id(),
            ],
        ]);

        $slug = Str::slug($validated['project_name']);

        Project::create([
            'user_id'      => auth()->id(),
            'project_name' => $validated['project_name'],
            'project_url'  => $validated['project_url'],
            'slug'         => $slug,
        ]);

        return back()->with('success', 'Project created successfully.');
    }

    public function update(Request $request, Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'project_name' => [
            'required',
            'string',
            'max:255',
            'regex:/^[a-zA-Z0-9\s\-]+$/', // clean names only
            'unique:projects,project_name,NULL,id,user_id,' . auth()->id(),
        ],
            'project_url' => [
                'required',
                'url',
                'max:255',
                'unique:projects,project_url,' . $project->id . ',id,user_id,' . auth()->id(),
            ],
        ]);

        $slug = Str::slug($validated['project_name']);

        $project->update([
            'project_name' => $validated['project_name'],
            'project_url'  => $validated['project_url'],
            'slug'         => $slug,
        ]);

        return back()->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        $project->delete();

        return back()->with('success', 'Project deleted successfully.');
    }


}