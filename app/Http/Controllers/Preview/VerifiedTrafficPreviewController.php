<?php

namespace App\Http\Controllers\Preview;

use App\Http\Controllers\Controller;
use App\Support\VerifiedTrafficPreview;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Static HTML mockups for the Verified traffic draft. Demo data only.
 * No OAuth, no jobs, no writes. Owner must approve before a real build.
 */
class VerifiedTrafficPreviewController extends Controller
{
    public function __construct()
    {
        if (! VerifiedTrafficPreview::enabled()) {
            abort(404);
        }
    }

    public function index(): View
    {
        return view('preview.verified-traffic.index', [
            'shell' => 'publisher',
            'nav' => 'index',
        ]);
    }

    public function publisher(Request $request): View
    {
        $state = (string) $request->query('state', 'all');
        if (! in_array($state, ['all', 'connect', 'picker', 'connected'], true)) {
            $state = 'all';
        }

        return view('preview.verified-traffic.publisher', [
            'shell' => 'publisher',
            'nav' => 'publisher',
            'state' => $state,
            'site' => $this->demoPublisherSite(),
        ]);
    }

    public function catalog(): View
    {
        return view('preview.verified-traffic.catalog', [
            'shell' => 'advertiser',
            'nav' => 'catalog',
            'verified' => $this->demoCatalogSite(true),
            'manual' => $this->demoCatalogSite(false),
        ]);
    }

    public function catalogDetail(): View
    {
        return view('preview.verified-traffic.catalog-detail', [
            'shell' => 'advertiser',
            'nav' => 'catalog',
            'verified' => $this->demoCatalogSite(true),
            'manual' => $this->demoCatalogSite(false),
        ]);
    }

    public function admin(): View
    {
        return view('preview.verified-traffic.admin', [
            'shell' => 'admin',
            'nav' => 'admin',
            'site' => $this->demoAdminSite(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function demoPublisherSite(): array
    {
        return [
            'name' => 'Health Insights',
            'url' => 'https://healthinsights.example',
            'domain' => 'healthinsights.example',
            'manual_traffic' => 55000,
            'ga4_sessions' => 48200,
            'gsc_clicks' => 12410,
            'google_email' => 'maria@publisher.example',
            'ga4_property' => 'GA4 — Health Insights',
            'ga4_property_id' => 'properties/123456789',
            'gsc_property' => 'sc-domain:healthinsights.example',
            'last_sync' => '3 hours ago',
            'next_refresh' => 'Tuesday 04:15 UTC',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function demoCatalogSite(bool $verified): array
    {
        if ($verified) {
            return [
                'id' => 91001,
                'name' => 'Health Insights',
                'host' => 'healthinsights.example',
                'traffic' => 48200,
                'da' => 42,
                'dr' => 38,
                'country' => 'United Kingdom',
                'country_code' => 'GB',
                'category' => 'Health',
                'price' => '€89',
                'verified_traffic' => true,
                'via' => 'GA4',
                'via_label' => 'via GA4',
            ];
        }

        return [
            'id' => 91002,
            'name' => 'Local Notes',
            'host' => 'localnotes.example',
            'traffic' => 12000,
            'da' => 28,
            'dr' => 24,
            'country' => 'Germany',
            'country_code' => 'DE',
            'category' => 'News',
            'price' => '€45',
            'verified_traffic' => false,
            'via' => null,
            'via_label' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function demoAdminSite(): array
    {
        return array_merge($this->demoPublisherSite(), [
            'publisher_name' => 'Maria Publisher',
            'publisher_email' => 'maria@publisher.example',
            'source' => 'ga4',
            'status' => 'connected',
            'override' => null,
            'listing_traffic' => 48200,
        ]);
    }
}
