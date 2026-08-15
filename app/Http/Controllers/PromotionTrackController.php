<?php

namespace App\Http\Controllers;

use App\Models\AdBanner;
use App\Models\SiteAnnouncement;
use App\Services\PromotionTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionTrackController extends Controller
{
    public function __invoke(Request $request, PromotionTrackingService $tracking): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:banner,announcement'],
            'subject_id' => ['required', 'integer', 'min:1'],
            'event' => ['required', 'in:impression,click'],
        ]);

        $subject = $data['subject_type'] === 'banner'
            ? AdBanner::query()->find($data['subject_id'])
            : SiteAnnouncement::query()->find($data['subject_id']);

        if (! $subject) {
            return response()->json(['ok' => false], 404);
        }

        $tracking->record($subject, $data['event'], $request);

        return response()->json(['ok' => true]);
    }
}
