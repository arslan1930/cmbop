<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        // Base: only active sites
        $query = Site::where('active', 1);

        // Optional filter: verified sites
        if ($request->has('verified') && $request->verified == 1) {
            $query->where('verified', 1);
        }

        $sites = $query->latest()->get();

        // ✅ Get logged-in user's projects
        $projects = auth()->user()
            ->projects()
            ->select('id', 'project_name', 'slug')
            ->get();

        return view('advertiser.catalog.index', compact('sites', 'projects'));
    }
}