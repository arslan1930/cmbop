<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkSitesSeededNotification;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class BulkSiteRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString();

        $query = BulkSiteRequest::query()
            ->with(['publisher', 'handler'])
            ->withCount([
                'sites',
                'sites as awaiting_details_count' => fn ($q) => $q->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS),
                'sites as ready_count' => fn ($q) => $q->where('onboarding_status', Site::ONBOARDING_READY_FOR_REVIEW),
            ])
            ->latest();

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(20)->withQueryString();

        return view('admin.bulk-site-requests.index', [
            'requests' => $requests,
            'status' => $status !== '' ? $status : 'all',
            'openCount' => BulkSiteRequest::query()
                ->whereNotIn('status', [BulkSiteRequest::STATUS_COMPLETED, BulkSiteRequest::STATUS_CANCELLED])
                ->count(),
        ]);
    }

    public function show(int $id)
    {
        $bulkRequest = BulkSiteRequest::with([
            'publisher',
            'handler',
            'items' => fn ($q) => $q->orderBy('id'),
            'sites' => fn ($q) => $q->orderBy('id'),
        ])->findOrFail($id);

        $countries = Country::marketplace()->orderBy('name')->get();
        $languages = Language::marketplace()->orderBy('name')->get();
        $history = ActivityLog::forBulkSiteRequest($bulkRequest->id);
        $canDeleteDrafts = auth()->user()?->isAdmin() || auth()->user()?->isMarketing();
        $pendingItems = $bulkRequest->items->whereNull('site_id')->values();

        return view('admin.bulk-site-requests.show', compact(
            'bulkRequest',
            'countries',
            'languages',
            'history',
            'canDeleteDrafts',
            'pendingItems'
        ));
    }

    public function markSheetSent(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'This request was cancelled.');
        }

        $bulkRequest->forceFill([
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'sheet_sent_at' => now(),
            'handled_by' => auth()->id(),
            'admin_notes' => $request->input('admin_notes', $bulkRequest->admin_notes),
        ])->save();

        ActivityLogger::log(
            'bulk_request.sheet_sent',
            (auth()->user()->name ?? 'Staff').' marked bulk request #'.$bulkRequest->id.' as sheet emailed',
            $bulkRequest,
            [
                'bulk_site_request_id' => $bulkRequest->id,
                'publisher_id' => $bulkRequest->publisher_id,
            ],
            'Bulk request #'.$bulkRequest->id
        );

        return back()->with('success', 'Marked as sheet emailed. Prefer Done from the URL + price list the publisher already submitted.');
    }

    public function updateNotes(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);
        $bulkRequest->forceFill([
            'admin_notes' => $request->input('admin_notes'),
            'handled_by' => auth()->id(),
        ])->save();

        ActivityLogger::log(
            'bulk_request.notes_updated',
            (auth()->user()->name ?? 'Staff').' updated notes on bulk request #'.$bulkRequest->id,
            $bulkRequest,
            [
                'bulk_site_request_id' => $bulkRequest->id,
                'publisher_id' => $bulkRequest->publisher_id,
            ],
            'Bulk request #'.$bulkRequest->id
        );

        return back()->with('success', 'Notes saved.');
    }

    public function cancel(int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);
        $previous = $bulkRequest->status;
        $bulkRequest->forceFill([
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'handled_by' => auth()->id(),
        ])->save();

        ActivityLogger::log(
            'bulk_request.cancelled',
            (auth()->user()->name ?? 'Staff').' cancelled bulk request #'.$bulkRequest->id,
            $bulkRequest,
            [
                'bulk_site_request_id' => $bulkRequest->id,
                'publisher_id' => $bulkRequest->publisher_id,
                'from_status' => $previous,
                'sites_remaining' => $bulkRequest->sites()->count(),
            ],
            'Bulk request #'.$bulkRequest->id
        );

        return redirect()
            ->route('admin.bulk-site-requests.index')
            ->with('success', 'Bulk request cancelled. History is kept.');
    }

    /**
     * Done: create draft sites from publisher-submitted URL+price items, then notify publisher.
     * Drafts stay inactive until the publisher finishes details and staff verify/activate.
     * Marketer must fill language, country, DA, DR, and traffic for each pending site.
     */
    public function done(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::with(['publisher', 'items'])->findOrFail($id);

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'Cannot complete a cancelled request.');
        }

        $pendingItems = $bulkRequest->items->whereNull('site_id')->values();
        if ($pendingItems->isEmpty()) {
            return back()->with('error', 'No pending URL + price rows left to add. Use advanced seed if you need to add more.');
        }

        $pendingIds = $pendingItems->pluck('id')->map(fn ($v) => (int) $v)->all();
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.language' => 'required|string|max:10',
            'items.*.country' => 'required|string|max:10',
            'items.*.da' => 'required|integer|min:0|max:100',
            'items.*.dr' => 'required|integer|min:0|max:100',
            'items.*.traffic' => 'required|integer|min:0',
        ], [
            'items.required' => 'Fill language, country, DA, DR, and traffic for every pending website before Done.',
            'items.*.language.required' => 'Language is required for each website.',
            'items.*.country.required' => 'Country is required for each website.',
            'items.*.da.required' => 'DA is required for each website.',
            'items.*.dr.required' => 'DR is required for each website.',
            'items.*.traffic.required' => 'Traffic is required for each website.',
        ]);

        $validator->after(function ($validator) use ($request, $pendingIds, $allowedCountries, $allowedLanguages) {
            $items = $request->input('items', []);
            if (! is_array($items)) {
                return;
            }

            foreach ($pendingIds as $pendingId) {
                if (! array_key_exists((string) $pendingId, $items) && ! array_key_exists($pendingId, $items)) {
                    $validator->errors()->add(
                        'items.'.$pendingId,
                        'Fill all fields for every pending website before clicking Done.'
                    );
                }
            }

            foreach ($items as $itemId => $row) {
                $itemId = (int) $itemId;
                if (! in_array($itemId, $pendingIds, true)) {
                    $validator->errors()->add('items.'.$itemId, 'This row is not a pending website on this request.');
                    continue;
                }
                if (! is_array($row)) {
                    $validator->errors()->add('items.'.$itemId, 'Invalid row data.');
                    continue;
                }

                $language = strtolower(trim((string) ($row['language'] ?? '')));
                $country = strtolower(trim((string) ($row['country'] ?? '')));
                if ($language !== '' && ! in_array($language, $allowedLanguages, true)) {
                    $validator->errors()->add('items.'.$itemId.'.language', 'Choose a valid marketplace language.');
                }
                if ($country !== '' && ! in_array($country, $allowedCountries, true)) {
                    $validator->errors()->add('items.'.$itemId.'.country', 'Choose a valid marketplace country.');
                }
            }
        });

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Finish every Language, Country, DA, DR, and Traffic box before clicking Done.');
        }

        $inputItems = $request->input('items', []);
        $rows = [];
        foreach ($pendingItems as $item) {
            $row = $inputItems[$item->id] ?? $inputItems[(string) $item->id] ?? [];
            $rows[] = [
                'line' => (int) $item->id,
                'site_url' => $item->site_url,
                'domain' => $item->domain,
                'site_name' => $item->domain,
                'price' => (float) $item->price,
                'da' => (int) $row['da'],
                'dr' => (int) $row['dr'],
                'traffic' => (int) $row['traffic'],
                'language' => strtolower(trim((string) $row['language'])),
                'country' => strtolower(trim((string) $row['country'])),
            ];
        }

        return $this->createDraftSitesAndNotify($bulkRequest, $rows, []);
    }

    /**
     * Seed draft sites from pasted rows:
     * url,price,da,dr,traffic,language,country[,site_name]
     */
    public function seed(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::with('publisher')->findOrFail($id);

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'Cannot seed a cancelled request.');
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        $parsed = $this->parseSeedRows((string) $request->input('rows'), $allowedCountries, $allowedLanguages);
        if ($parsed['rows'] === [] && $parsed['failures'] === []) {
            return back()->with('error', 'No rows found. Paste one site per line: url,price,da,dr,traffic,language,country')->withInput();
        }

        if ($parsed['rows'] === []) {
            return back()
                ->with('error', 'All rows failed validation.')
                ->with('seed_failures', $parsed['failures'])
                ->withInput();
        }

        return $this->createDraftSitesAndNotify($bulkRequest, $parsed['rows'], $parsed['failures']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $failures
     */
    private function createDraftSitesAndNotify(BulkSiteRequest $bulkRequest, array $rows, array $failures)
    {
        $created = 0;
        $createdDomains = [];

        DB::transaction(function () use ($bulkRequest, $rows, &$created, &$failures, &$createdDomains) {
            foreach ($rows as $row) {
                $domain = $row['domain'];

                if (Site::where('domain', $domain)->exists()) {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => ['Domain already registered: '.$domain],
                    ];

                    continue;
                }

                $site = new Site;
                $site->applyMarketplaceListing([
                    'publisher_id' => $bulkRequest->publisher_id,
                    'bulk_site_request_id' => $bulkRequest->id,
                    'site_name' => $row['site_name'],
                    'site_url' => $row['site_url'],
                    'domain' => $domain,
                    'example_url' => $row['site_url'],
                    'da' => $row['da'],
                    'dr' => $row['dr'],
                    'traffic' => $row['traffic'],
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                    'country' => $row['country'],
                    'countries' => [$row['country']],
                    'language' => $row['language'],
                    'languages' => [$row['language']],
                    'category' => 'Pending',
                    'categories' => null,
                    'price' => $row['price'],
                    'turnaround_time' => '3days',
                    'publication_time' => 'permanent',
                    'link_type' => 'dofollow',
                    'description' => 'Please replace this placeholder with a real site description (at least 50 characters) before submitting for review.',
                    'sponsored' => false,
                    'partner_material' => false,
                    'as_you_prefer' => true,
                    'verified' => false,
                    'active' => false,
                    'enrichment_status' => 'pending',
                    'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
                ]);
                $site->save();

                $bulkRequest->items()
                    ->where('domain', $domain)
                    ->whereNull('site_id')
                    ->update(['site_id' => $site->id]);

                $created++;
                $createdDomains[] = $domain;
            }

            if ($created > 0) {
                $bulkRequest->forceFill([
                    'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
                    'seeded_at' => $bulkRequest->seeded_at ?? now(),
                    'handled_by' => auth()->id(),
                    'completed_at' => null,
                ])->save();
            }
        });

        if ($created > 0) {
            ActivityLogger::log(
                'bulk_request.seeded',
                (auth()->user()->name ?? 'Staff').' added '.$created.' draft site(s) to publisher panel on bulk request #'.$bulkRequest->id,
                $bulkRequest,
                [
                    'bulk_site_request_id' => $bulkRequest->id,
                    'publisher_id' => $bulkRequest->publisher_id,
                    'created_count' => $created,
                    'failed_count' => count($failures),
                    'domains' => $createdDomains,
                ],
                'Bulk request #'.$bulkRequest->id
            );

            $fresh = $bulkRequest->fresh(['publisher']);
            $publisher = $fresh?->publisher;

            try {
                if ($publisher?->email) {
                    Mail::to($publisher->email)->send(new BulkSitesSeededNotification($fresh, $created, $publisher));
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to email publisher after bulk Done: '.$e->getMessage());
            }

            try {
                app(InAppNotificationService::class)->notifyPublisherBulkSitesAdded($fresh, $created);
            } catch (\Throwable $e) {
                Log::warning('Failed to send in-app bulk Done notice: '.$e->getMessage());
            }
        }

        $message = $created > 0
            ? "Done — {$created} site(s) added to the publisher’s Pending sites. Publisher notified (email + in-app). Still inactive until they finish details and you verify."
            : 'No sites were added.';
        if ($failures !== []) {
            $message .= ' '.count($failures).' row(s) failed.';
        }

        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('seed_failures', $failures);
    }

    /**
     * @param  list<string>  $allowedCountries
     * @param  list<string>  $allowedLanguages
     * @return array{rows: list<array<string, mixed>>, failures: list<array<string, mixed>>}
     */
    private function parseSeedRows(string $raw, array $allowedCountries, array $allowedLanguages): array
    {
        $rows = [];
        $failures = [];
        $lines = preg_split('/\R/', $raw) ?: [];

        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with(strtolower($trimmed), 'url')) {
                continue;
            }

            $parts = preg_split('/[\t,;]+/', $trimmed) ?: [];
            $parts = array_map('trim', $parts);

            if (count($parts) < 7) {
                $failures[] = [
                    'line' => $lineNum,
                    'url' => $parts[0] ?? '',
                    'errors' => ['Need 7 columns: url,price,da,dr,traffic,language,country'],
                ];

                continue;
            }

            [$urlRaw, $priceRaw, $daRaw, $drRaw, $trafficRaw, $langRaw, $countryRaw] = array_slice($parts, 0, 7);
            $siteName = isset($parts[7]) && $parts[7] !== '' ? $parts[7] : null;

            $siteUrl = $this->normalizeHttpUrl($urlRaw);
            $host = parse_url($siteUrl, PHP_URL_HOST);
            $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;

            $errors = [];
            if (! $domain) {
                $errors[] = 'Invalid URL';
            }

            $price = is_numeric($priceRaw) ? (float) $priceRaw : null;
            $da = is_numeric($daRaw) ? (int) $daRaw : null;
            $dr = is_numeric($drRaw) ? (int) $drRaw : null;
            $traffic = is_numeric($trafficRaw) ? (int) $trafficRaw : null;
            $language = strtolower($langRaw);
            $country = strtolower($countryRaw);

            if ($price === null || $price < 0) {
                $errors[] = 'Invalid price';
            }
            if ($da === null || $da < 0 || $da > 100) {
                $errors[] = 'Invalid DA';
            }
            if ($dr === null || $dr < 0 || $dr > 100) {
                $errors[] = 'Invalid DR';
            }
            if ($traffic === null || $traffic < 0) {
                $errors[] = 'Invalid traffic';
            }
            if (! in_array($language, $allowedLanguages, true)) {
                $errors[] = 'Unknown language code';
            }
            if (! in_array($country, $allowedCountries, true)) {
                $errors[] = 'Unknown country code';
            }

            if ($errors !== []) {
                $failures[] = ['line' => $lineNum, 'url' => $siteUrl, 'errors' => $errors];

                continue;
            }

            $rows[] = [
                'line' => $lineNum,
                'site_url' => $siteUrl,
                'domain' => $domain,
                'site_name' => $siteName ?: $domain,
                'price' => $price,
                'da' => $da,
                'dr' => $dr,
                'traffic' => $traffic,
                'language' => $language,
                'country' => $country,
            ];
        }

        return compact('rows', 'failures');
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
}
