<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Services\Partners\PartnerOrderPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function index(Request $request, PartnerOrderPresenter $presenter): View
    {
        $partner = Partner::forUser($request->user());
        $items = PartnerCatalogItem::query()
            ->where('partner_id', $partner?->id ?? 0)
            ->orderByDesc('id')
            ->paginate(30);

        $rows = [];
        foreach ($items as $item) {
            $status = (string) $item->listing_status;
            $rows[] = [
                'domain' => (string) $item->domain,
                'name' => (string) ($item->site_name ?: $item->domain),
                'status' => $status,
                'status_label' => $presenter->listingStatusLabel($status),
                'your_rate' => $presenter->money((float) $item->partner_rate_publish_eur, 'EUR'),
                'currency' => (string) ($item->partner_rate_currency ?: 'EUR'),
                'original_rate' => $item->partner_rate_currency && $item->partner_rate_currency !== 'EUR'
                    ? $presenter->money((float) $item->partner_rate_publish, (string) $item->partner_rate_currency)
                    : null,
            ];
        }

        return view('partner.sites.index', [
            'rows' => $rows,
            'paginator' => $items,
        ]);
    }
}
