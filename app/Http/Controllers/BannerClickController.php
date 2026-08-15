<?php

namespace App\Http\Controllers;

use App\Models\AdBanner;
use App\Services\PromotionTrackingService;

class BannerClickController extends Controller
{
    public function __invoke(AdBanner $banner, PromotionTrackingService $tracking)
    {
        return $tracking->followClick($banner, request(), $banner->link_url);
    }
}
