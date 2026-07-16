<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteRating;
use Illuminate\Http\Request;

class SiteRatingController extends Controller
{
    public function store(Request $request, int $siteId)
    {
        $site = Site::query()->where('id', $siteId)->where('active', 1)->firstOrFail();

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $rating = SiteRating::updateOrCreate(
            [
                'site_id' => $site->id,
                'user_id' => auth()->id(),
            ],
            [
                'rating' => (int) $data['rating'],
                'comment' => $data['comment'] ?? null,
                'status' => SiteRating::STATUS_APPROVED,
                'is_admin' => false,
            ]
        );

        SiteRating::refreshSiteAggregate($site->id);
        $site->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Thanks — your rating was saved.',
            'rating' => $rating,
            'rating_avg' => (float) $site->rating_avg,
            'rating_count' => (int) $site->rating_count,
            'label' => $site->ratingStarsLabel(),
        ]);
    }
}
