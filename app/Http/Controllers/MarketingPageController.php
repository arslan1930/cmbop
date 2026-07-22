<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\PlatformFeeService;

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
        $teasers = collect();
        try {
            $fee = app(PlatformFeeService::class);
            $teasers = Site::query()
                ->where('active', true)
                ->where(function ($q) {
                    $q->where('verified', true)->orWhere('verified', 1);
                })
                ->orderByDesc('dr')
                ->orderByDesc('da')
                ->limit(8)
                ->get(['id', 'site_name', 'site_url', 'domain', 'country', 'language', 'da', 'dr', 'price'])
                ->map(function (Site $site) use ($fee) {
                    $host = $site->domain ?: (parse_url((string) $site->site_url, PHP_URL_HOST) ?: $site->site_url);
                    $host = preg_replace('/^www\./i', '', (string) $host);

                    return [
                        'name' => $site->site_name ?: $host,
                        'domain_masked' => $this->maskDomain((string) $host),
                        'country' => $site->country,
                        'language' => $site->language,
                        'da' => $site->da,
                        'dr' => $site->dr,
                        'price' => $fee->advertiserBase((float) $site->price),
                    ];
                });
        } catch (\Throwable $e) {
            $teasers = collect();
        }

        return view('pages.marketplace', [
            'teasers' => $teasers,
        ]);
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

    private function maskDomain(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '••••••.com';
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return substr($host, 0, 1).str_repeat('*', max(3, strlen($host) - 1));
        }

        $tld = array_pop($parts);
        $name = implode('.', $parts);
        $visible = substr($name, 0, 1);

        return $visible.str_repeat('*', max(3, min(8, strlen($name) - 1))).'.'.$tld;
    }
}
