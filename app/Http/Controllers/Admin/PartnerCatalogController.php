<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerPricingRule;
use App\Services\Partners\PartnerCatalogGoLiveService;
use App\Services\Partners\PartnerPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerCatalogController extends Controller
{
    public function __construct(
        private PartnerCatalogGoLiveService $goLive,
        private PartnerPricingService $pricing,
    ) {}

    public function index(Request $request): View
    {
        $query = PartnerCatalogItem::query()->with('partner.user');
        if ($request->filled('partner_id')) {
            $query->where('partner_id', (int) $request->input('partner_id'));
        }
        if ($request->filled('status')) {
            $query->where('listing_status', $request->input('status'));
        }
        if ($request->filled('country')) {
            $query->where('country', strtolower((string) $request->input('country')));
        }
        if ($request->filled('language')) {
            $query->where('language', strtolower((string) $request->input('language')));
        }
        if ($request->filled('q')) {
            $q = '%'.str_replace(['%', '_'], '', (string) $request->input('q')).'%';
            $query->where(function ($inner) use ($q) {
                $inner->where('domain', 'like', $q)->orWhere('site_name', 'like', $q);
            });
        }
        if ($request->filled('dr_min')) {
            $query->where('dr', '>=', (int) $request->input('dr_min'));
        }
        if ($request->filled('dr_max')) {
            $query->where('dr', '<=', (int) $request->input('dr_max'));
        }
        if ($request->filled('traffic_min')) {
            $query->where('traffic', '>=', (int) $request->input('traffic_min'));
        }
        if ($request->filled('price_min')) {
            $query->where('partner_rate_publish_eur', '>=', (float) $request->input('price_min'));
        }
        if ($request->filled('price_max')) {
            $query->where('partner_rate_publish_eur', '<=', (float) $request->input('price_max'));
        }
        if ($request->filled('niche')) {
            $niche = (string) $request->input('niche');
            $query->where(function ($inner) use ($niche) {
                $inner->where('niches', 'like', '%'.$niche.'%');
            });
        }
        if ($request->boolean('flag_duplicate')) {
            $query->where('duplicate', true);
        }
        if ($request->boolean('flag_unreachable')) {
            $query->where('unreachable', true);
        }
        if ($request->filled('job_id')) {
            $query->where('last_job_id', (int) $request->input('job_id'));
        }

        $items = $query->orderByDesc('id')->paginate(50)->withQueryString();
        $partners = Partner::query()->with('user')->orderByDesc('id')->get();
        $rules = PartnerPricingRule::query()->where('active', true)->orderBy('name')->get();

        return view('admin.partners.catalog', compact('items', 'partners', 'rules'));
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:go_live,send_to_review,keep_unused,reject,pause,set_price,export',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'override_margin' => 'nullable|boolean',
            'override_duplicate' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:500',
            'pricing_rule_id' => 'nullable|integer',
            'reject_reason' => 'nullable|string|max:64',
        ]);
        $ids = array_map('intval', $data['ids']);
        $actor = $request->user();

        return match ($data['action']) {
            'go_live' => $this->goLiveResult($ids, $actor, $data),
            'send_to_review' => back()->with('success', $this->goLive->sendToReview($ids, $actor).' sent to review.'),
            'keep_unused' => back()->with('success', $this->goLive->keepUnused($ids, $actor).' kept unused.'),
            'reject' => back()->with('success', $this->goLive->reject($ids, $actor, $data['reject_reason'] ?? 'other').' rejected.'),
            'pause' => $this->pauseResult($ids, $actor),
            'set_price' => $this->setPrice($ids, $data['pricing_rule_id'] ?? null),
            default => back(),
        };
    }

    public function export(Request $request): StreamedResponse
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        $items = PartnerCatalogItem::query()->with('partner.user')->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'partner', 'domain', 'status', 'country', 'language', 'dr', 'traffic', 'partner_rate_eur', 'slb_price_eur', 'commission_eur', 'margin_pct']);
            foreach ($items as $item) {
                fputcsv($out, [
                    $item->id,
                    $item->partner?->displayName(),
                    $item->domain,
                    $item->listing_status,
                    $item->country,
                    $item->language,
                    $item->dr,
                    $item->traffic,
                    $item->partner_rate_publish_eur,
                    $item->slb_price_publish_eur,
                    $item->commissionPublishEur(),
                    $item->marginPercent(),
                ]);
            }
            fclose($out);
        }, 'partner-catalog-internal.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $data
     */
    private function goLiveResult(array $ids, $actor, array $data): RedirectResponse
    {
        $result = $this->goLive->goLiveItems($ids, $actor, [
            'override_margin' => (bool) ($data['override_margin'] ?? false),
            'override_duplicate' => (bool) ($data['override_duplicate'] ?? false),
            'override_reason' => $data['override_reason'] ?? '',
            'pricing_rule_id' => $data['pricing_rule_id'] ?? null,
        ]);
        $msg = count($result['live']).' live.';
        if ($result['refused'] !== []) {
            $reasons = collect($result['refused'])->map(fn ($r) => '#'.$r['id'].' '.$r['reason'])->take(8)->implode('; ');
            $msg .= ' Refused: '.$reasons;

            return back()->with('warning', $msg);
        }

        return back()->with('success', $msg);
    }

    /**
     * @param  list<int>  $ids
     */
    private function pauseResult(array $ids, $actor): RedirectResponse
    {
        $this->goLive->unlistItems($ids, $actor, 'paused');

        return back()->with('success', 'Paused selected live sites. Open orders keep their frozen prices.');
    }

    /**
     * @param  list<int>  $ids
     */
    private function setPrice(array $ids, mixed $ruleId): RedirectResponse
    {
        $rule = $ruleId ? PartnerPricingRule::query()->find($ruleId) : $this->pricing->defaultRule();
        $n = 0;
        foreach (PartnerCatalogItem::query()->whereIn('id', $ids)->get() as $item) {
            $this->pricing->applySuggested($item, $rule, overwriteManual: true);
            $item->save();
            $n++;
        }

        return back()->with('success', 'Suggested SLB prices applied to '.$n.' site(s).');
    }
}
