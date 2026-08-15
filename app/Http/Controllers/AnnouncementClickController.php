<?php

namespace App\Http\Controllers;

use App\Models\SiteAnnouncement;
use App\Services\PromotionTrackingService;
use App\Support\PromotionUrl;

class AnnouncementClickController extends Controller
{
    public function __invoke(SiteAnnouncement $announcement, PromotionTrackingService $tracking)
    {
        if (! $announcement->isCurrentlyLive() || ! $announcement->cta_url) {
            return redirect()->to('/');
        }

        $tracking->record($announcement, PromotionTrackingService::EVENT_CLICK, request());

        $href = PromotionUrl::href($announcement->cta_url) ?: url('/');

        return redirect()->away($href);
    }
}
