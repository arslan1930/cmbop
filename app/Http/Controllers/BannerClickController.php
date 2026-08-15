<?php

namespace App\Http\Controllers;

use App\Models\AdBanner;
use App\Services\PromotionTrackingService;
use App\Support\PromotionUrl;

class BannerClickController extends Controller
{
    public function __invoke(AdBanner $banner, PromotionTrackingService $tracking)
    {
        if (! $banner->isCurrentlyLive()) {
            return redirect()->to('/');
        }

        $tracking->record($banner, PromotionTrackingService::EVENT_CLICK, request());

        $href = PromotionUrl::href($banner->link_url) ?: url('/');

        return redirect()->away($href);
    }
}
