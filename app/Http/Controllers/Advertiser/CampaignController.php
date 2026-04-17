<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    /**
     * Show websites for a specific project
     */
    public function websites(Project $project)
    {
        // Security check
        if ($project->user_id !== auth()->id()) {
            abort(403);
        }

        // Load active sites (same logic as catalog)
        $query = Site::where('active', 1);

        // Optional: you can later filter based on project (category, country, etc.)
        // Example:
        // if ($project->category) {
        //     $query->where('category', $project->category);
        // }

        $sites = $query->latest()->get();

        // Use SAME view for consistency
        return view('advertiser.campaigns.websites', [
            'sites' => $sites,
            'project' => $project
        ]);
    }
}