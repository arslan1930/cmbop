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
            'event' => ['required', 'in:impression'],
        ]);

        try {
            $subject = $data['subject_type'] === 'banner'
                ? AdBanner::query()->find($data['subject_id'])
                : SiteAnnouncement::query()->find($data['subject_id']);
            if ($subject) {
                $tracking->record($subject, $data['event'], $request);
            }
        } catch (\Throwable) {
            // Missing table / paused / unknown id must look the same.
        }

        return response()->json(['ok' => true]);
    }
}
