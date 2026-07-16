<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\AdvertiserAnalyticsService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private AdvertiserAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        $view = $request->get('view', 'month');
        if (!in_array($view, ['order', 'day', 'month'], true)) {
            $view = 'month';
        }

        $analytics = $this->analytics->build($request->user());

        return view('advertiser.analytics', compact('analytics', 'view'));
    }
}
