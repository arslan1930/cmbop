<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $query = Site::where('active', 1);

        // 🔍 Search (by URL or category)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('site_url', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        // ✅ Verified filter
        if ($request->filled('verified') && $request->verified == 1) {
            $query->where('verified', 1);
        }

        // 📊 DA range
        if ($request->filled('da_min')) {
            $query->where('da', '>=', (int)$request->da_min);
        }

        if ($request->filled('da_max')) {
            $query->where('da', '<=', (int)$request->da_max);
        }

        // 📊 DR range
        if ($request->filled('dr_min')) {
            $query->where('dr', '>=', (int)$request->dr_min);
        }

        if ($request->filled('dr_max')) {
            $query->where('dr', '<=', (int)$request->dr_max);
        }

        // 📊 Traffic range
        if ($request->filled('traffic_min')) {
            $query->where('traffic', '>=', (int)$request->traffic_min);
        }

        if ($request->filled('traffic_max')) {
            $query->where('traffic', '<=', (int)$request->traffic_max);
        }

        // 🌍 Language filter - FIXED with debug
        if ($request->filled('language') && !empty($request->language)) {
            $query->where('language', $request->language);
            
            // Debug log
            \Log::info('Language filter applied: ' . $request->language);
        }

        // ✅ Pagination (20 per page)
        $sites = $query->latest()->paginate(20)->withQueryString();

        // Get unique languages for the filter dropdown
        $availableLanguages = Site::where('active', 1)
            ->whereNotNull('language')
            ->where('language', '!=', '')
            ->select('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language');

        return view('advertiser.catalog', compact('sites', 'availableLanguages'));
    }
}