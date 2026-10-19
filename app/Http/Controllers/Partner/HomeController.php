<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Services\Partners\PartnerOrderQuery;
use App\Services\Partners\PartnerOrderStatus;
use App\Support\PartnerDashboard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request, PartnerOrderQuery $orders): View
    {
        $user = $request->user();

        return view('partner.home', [
            'counts' => $orders->counts($user),
            'labels' => PartnerOrderStatus::labels(),
            'partnerName' => $user->supplyPartner?->displayName() ?: $user->name,
            'mainSiteUrl' => PartnerDashboard::mainSiteUrl(),
        ]);
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow: /\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
