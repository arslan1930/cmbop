<?php

namespace App\Http\Controllers;

use App\Models\SiteAnnouncement;
use App\Services\PromotionTrackingService;

class AnnouncementClickController extends Controller
{
    public function __invoke(SiteAnnouncement $announcement, PromotionTrackingService $tracking)
    {
        return $tracking->followClick($announcement, request(), $announcement->cta_url);
    }
}
