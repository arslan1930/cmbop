<?php

namespace App\Http\Controllers;

use App\Models\AdBanner;

class BannerClickController extends Controller
{
    public function __invoke(AdBanner $banner)
    {
        if ($banner->isCurrentlyLive()) {
            $banner->recordClick();
        }

        $url = $banner->link_url ?: url('/');

        return redirect()->away($url);
    }
}
