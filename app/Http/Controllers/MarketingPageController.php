<?php

namespace App\Http\Controllers;

class MarketingPageController extends Controller
{
    public function about()
    {
        return view('pages.about');
    }

    public function faq()
    {
        return view('pages.faq');
    }

    public function pricing()
    {
        return view('pages.pricing');
    }

    public function marketplace()
    {
        return view('pages.marketplace');
    }

    public function howItWorks()
    {
        return view('pages.how-it-works');
    }

    public function becomePublisher()
    {
        return view('pages.become-a-publisher');
    }

    public function whyChooseUs()
    {
        return view('pages.why-choose-us');
    }

    public function cookiePolicy()
    {
        return view('pages.cookie-policy');
    }

    public function refundPolicy()
    {
        return view('pages.refund-policy');
    }
}
