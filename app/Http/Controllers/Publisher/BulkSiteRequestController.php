<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\BulkSiteRequestSubmitted;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
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
            'sites' => 'required|array|min:2|max:200',
            'sites.*.url' => 'nullable|string|max:512',
            'sites.*.price' => 'nullable|numeric|min:0|max:999999.99',
            'publisher_note' => 'nullable|string|max:2000',
        ], [
            'sites.required' => 'Add at least two websites (URL + price).',
            'sites.min' => 'Add at least two websites (URL + price).',
        ]);

        $parsedRows = [];
        $validator->after(function ($validator) use ($request, &$parsedRows) {
            $rawSites = $request->input('sites', []);
            if (! is_array($rawSites)) {
                return;
            }

            $seenDomains = [];
            foreach ($rawSites as $index => $row) {
                $urlRaw = trim((string) ($row['url'] ?? ''));
                $priceRaw = $row['price'] ?? null;
                if ($urlRaw === '' && ($priceRaw === null || $priceRaw === '')) {
                    continue;
                }

                $siteUrl = $this->normalizeHttpUrl($urlRaw);
                $host = parse_url($siteUrl, PHP_URL_HOST);
                $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;

                if (! $domain || ! filter_var($siteUrl, FILTER_VALIDATE_URL)) {
                    $validator->errors()->add("sites.$index.url", 'Enter a valid website URL.');

                    continue;
                }

                if (isset($seenDomains[$domain])) {
                    $validator->errors()->add("sites.$index.url", "Duplicate domain in this list: {$domain}");

                    continue;
                }
                $seenDomains[$domain] = true;

                if (Site::where('domain', $domain)->exists()) {
                    $validator->errors()->add("sites.$index.url", "Already registered: {$domain}");

                    continue;
                }

                if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $validator->errors()->add("sites.$index.price", 'Enter a valid price.');

                    continue;
                }

                $parsedRows[] = [
                    'site_url' => $siteUrl,
                    'domain' => $domain,
                    'price' => round((float) $priceRaw, 2),
                ];
            }

            if (count($parsedRows) < 2) {
                $validator->errors()->add('sites', 'Add at least two websites with URL and price.');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('publisher.websites')
                ->withErrors($validator)
                ->withInput()
                ->with('open_bulk_request_modal', true);
        }

        $bulk = DB::transaction(function () use ($request, $parsedRows) {
            $bulk = BulkSiteRequest::create([
                'publisher_id' => auth()->id(),
                'status' => BulkSiteRequest::STATUS_REQUESTED,
                'estimated_count' => count($parsedRows),
                'publisher_note' => $request->publisher_note,
            ]);

            foreach ($parsedRows as $row) {
                BulkSiteRequestItem::create([
                    'bulk_site_request_id' => $bulk->id,
                    'site_url' => $row['site_url'],
                    'domain' => $row['domain'],
                    'price' => $row['price'],
                ]);
            }

            return $bulk;
        });

        ActivityLogger::log(
            'bulk_request.created',
            (auth()->user()->name ?? 'Publisher').' submitted '.count($parsedRows).' site URL(s) + price(s) for bulk onboarding',
            $bulk,
            [
                'bulk_site_request_id' => $bulk->id,
                'publisher_id' => $bulk->publisher_id,
                'estimated_count' => $bulk->estimated_count,
                'domains' => array_column($parsedRows, 'domain'),
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
                    Mail::to($admin->email)->send(new BulkSiteRequestSubmitted($bulk->load('items')));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email admins about bulk site request: '.$e->getMessage());
        }

        try {
            app(InAppNotificationService::class)->notifyStaffBulkSiteRequestSubmitted($bulk->load('items', 'publisher'));
        } catch (\Throwable $e) {
            Log::warning('Failed to send in-app bulk request notice: '.$e->getMessage());
        }

        return redirect()
            ->route('publisher.websites')
            ->with('success', 'Bulk sites submitted (URL + price). Our marketer will add them to your Pending sites next; then you’ll finish descriptions and listing details; we approve.');
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
