<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteRating;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class SiteRatingController extends Controller
{
    public function index(Request $request)
    {
        $query = SiteRating::query()
            ->with(['site:id,site_name,domain,site_url,rating_avg,rating_count', 'user:id,name,email'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('site_id')) {
            $query->where('site_id', (int) $request->site_id);
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $query->where(function ($inner) use ($q) {
                $inner->where('comment', 'like', "%{$q}%")
                    ->orWhereHas('site', function ($s) use ($q) {
                        $s->where('site_name', 'like', "%{$q}%")
                            ->orWhere('domain', 'like', "%{$q}%");
                    })
                    ->orWhereHas('user', function ($u) use ($q) {
                        $u->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        $ratings = $query->paginate(30)->withQueryString();
        $sites = Site::query()->orderBy('site_name')->get(['id', 'site_name', 'domain']);

        return view('admin.site-ratings', compact('ratings', 'sites'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'user_id' => 'nullable|exists:users,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
            'status' => 'required|in:approved,hidden,pending',
        ]);

        $userId = $data['user_id'] ?? auth()->id();

        $rating = SiteRating::updateOrCreate(
            [
                'site_id' => (int) $data['site_id'],
                'user_id' => $userId,
            ],
            [
                'rating' => (int) $data['rating'],
                'comment' => $data['comment'] ?? null,
                'status' => $data['status'],
                'is_admin' => true,
            ]
        );

        SiteRating::refreshSiteAggregate((int) $data['site_id']);

        ActivityLogger::log(
            'site.rating_saved',
            auth()->user()->name.' saved a rating for site #'.$data['site_id'],
            $rating->site,
            ['rating_id' => $rating->id, 'rating' => $rating->rating, 'status' => $rating->status],
            $rating->site?->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Rating saved',
            'rating' => $rating->load(['site:id,site_name,domain', 'user:id,name,email']),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $rating = SiteRating::findOrFail($id);

        $data = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
            'status' => 'sometimes|in:approved,hidden,pending',
        ]);

        $rating->fill($data);
        $rating->is_admin = true;
        $rating->save();

        SiteRating::refreshSiteAggregate($rating->site_id);

        ActivityLogger::log(
            'site.rating_updated',
            auth()->user()->name.' updated rating #'.$rating->id,
            $rating->site,
            $data,
            $rating->site?->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Rating updated',
            'rating' => $rating->fresh(['site:id,site_name,domain', 'user:id,name,email']),
        ]);
    }

    public function destroy(int $id)
    {
        $rating = SiteRating::findOrFail($id);
        $siteId = $rating->site_id;
        $siteName = $rating->site?->site_name;

        $rating->delete();
        SiteRating::refreshSiteAggregate($siteId);

        ActivityLogger::log(
            'site.rating_deleted',
            auth()->user()->name.' deleted a site rating',
            null,
            ['site_id' => $siteId, 'rating_id' => $id],
            $siteName
        );

        return response()->json([
            'success' => true,
            'message' => 'Rating deleted',
        ]);
    }
}
