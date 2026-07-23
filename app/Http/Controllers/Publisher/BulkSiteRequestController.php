<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\BulkSiteRequestSubmitted;
use App\Models\BulkSiteRequest;
use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\SiteDescriptionSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class BulkSiteRequestController extends Controller
{
    public function store(Request $request)
    {
        $open = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereNotIn('status', [
                BulkSiteRequest::STATUS_COMPLETED,
                BulkSiteRequest::STATUS_CANCELLED,
            ])
            ->exists();

        if ($open) {
            return redirect()
                ->route('publisher.websites')
                ->with('error', 'You already have an open bulk request. Wait for our team to finish it, or message support.');
        }

        $validator = Validator::make($request->all(), [
            'estimated_count' => 'required|integer|min:5|max:200',
            'publisher_note' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('publisher.websites')
                ->withErrors($validator)
                ->withInput()
                ->with('open_bulk_request_modal', true);
        }

        $bulk = BulkSiteRequest::create([
            'publisher_id' => auth()->id(),
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => (int) $request->estimated_count,
            'publisher_note' => $request->publisher_note,
        ]);

        ActivityLogger::log(
            'bulk_request.created',
            (auth()->user()->name ?? 'Publisher').' requested bulk onboarding for ~'.(int) $request->estimated_count.' site(s)',
            $bulk,
            [
                'bulk_site_request_id' => $bulk->id,
                'publisher_id' => $bulk->publisher_id,
                'estimated_count' => $bulk->estimated_count,
            ],
            'Bulk request #'.$bulk->id
        );

        try {
            $admins = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'marketing']))
                ->get();

            $recipients = $admins->isNotEmpty()
                ? $admins
                : collect([(object) ['email' => config('mail.admin_email', 'admin@yourdomain.com')]]);

            foreach ($recipients as $admin) {
                if (! empty($admin->email)) {
                    Mail::to($admin->email)->send(new BulkSiteRequestSubmitted($bulk));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email admins about bulk site request: '.$e->getMessage());
        }

        return redirect()
            ->route('publisher.websites')
            ->with('success', 'Bulk request sent. We’ll email you a simple sheet (URLs + prices). Our team adds metrics; you finish the rest; we approve.');
    }

    public function completeIndex()
    {
        $sites = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->orderByDesc('id')
            ->get();

        $categories = Category::orderBy('name')->get();
        $openRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('status', [
                BulkSiteRequest::STATUS_SEEDED,
                BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            ])
            ->latest()
            ->first();

        return view('publisher.bulk-complete', compact('sites', 'categories', 'openRequest'));
    }

    public function completeStore(Request $request, int $id)
    {
        $site = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->findOrFail($id);

        $categories = $this->parseCategoryList($request->input('categories', []));
        $request->merge(['categories' => $categories]);

        if ($request->filled('exampleUrl')) {
            $request->merge([
                'exampleUrl' => $this->normalizeHttpUrl((string) $request->input('exampleUrl')),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'exampleUrl' => 'required|url|max:255',
            'categories' => 'required|array|min:1|max:7',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'site_tag' => 'nullable|in:sponsored,partner_material,as_you_prefer',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        $validCategoryNamesLower = Category::query()->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();
        $validator->after(function ($validator) use ($categories, $validCategoryNamesLower) {
            foreach ($categories as $cat) {
                if (! in_array(strtolower($cat), $validCategoryNamesLower, true)) {
                    $validator->errors()->add('categories', "Unknown category: {$cat}");
                }
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('publisher.bulk-sites.complete')
                ->withErrors($validator)
                ->withInput()
                ->with('complete_site_id', $site->id);
        }

        $cleanDescription = app(SiteDescriptionSanitizer::class)
            ->sanitize((string) $request->siteDescription);
        $primaryCategory = implode('|', $categories);

        DB::transaction(function () use ($site, $request, $cleanDescription, $categories, $primaryCategory) {
            $sensitivePrices = [];
            foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                if ($request->input("sensitive.$topic")) {
                    $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                }
            }

            $site->applyMarketplaceListing([
                'example_url' => $request->exampleUrl,
                'category' => $primaryCategory,
                'categories' => $categories,
                'turnaround_time' => $request->turnaround_time,
                'publication_time' => $request->publicationTime,
                'link_type' => $request->link_type,
                'description' => $cleanDescription,
                'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                'verified' => false,
                'active' => false,
                'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            ]);

            $tag = $request->input('site_tag', 'as_you_prefer');
            $site->sponsored = $tag === 'sponsored';
            $site->partner_material = $tag === 'partner_material';
            $site->as_you_prefer = $tag === 'as_you_prefer' || $tag === null || $tag === '';

            $site->save();
        });

        $site->refresh();
        if ($site->bulk_site_request_id) {
            $site->bulkSiteRequest?->refreshProgressStatus();
        }

        // Notify for each site as it is submitted (publishers often finish one now, others later).
        try {
            app(InAppNotificationService::class)->notifyAdminsNewSite($site, 'create');
        } catch (\Throwable $e) {
            Log::warning('Failed admin bell for bulk site completion: '.$e->getMessage());
        }

        return redirect()
            ->route('publisher.bulk-sites.complete')
            ->with('success', '“'.$site->site_name.'” submitted for review. Complete any remaining sites next.');
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function parseCategoryList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $raw)));
        }

        $str = trim((string) $raw);
        if ($str === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\|/', $str) ?: [])));
    }
}
