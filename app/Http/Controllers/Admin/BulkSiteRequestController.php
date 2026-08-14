<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkSiteRequestCancelled;
use App\Mail\BulkSiteRequestItemRejected;
use App\Mail\BulkSitesSeededNotification;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Support\MarketingOpsQueues;
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
                'items as pending_items_count' => fn ($q) => $q->pending(),
            ])
            ->latest();

        MarketingOpsQueues::applyBulkIndexStatus($query, $status);

        $requests = $query->paginate(20)->withQueryString();
        $selectedStatus = $status !== '' ? $status : 'all';

        return view('admin.bulk-site-requests.index', [
            'requests' => $requests,
            'status' => $selectedStatus,
            'filtersActive' => $selectedStatus !== 'all',
            'waitingOnYouCount' => MarketingOpsQueues::bulkWaitingOnMarketer()->count(),
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

        // Heal stuck "completed" batches that still have URL+price rows for Done.
        if ($bulkRequest->status === BulkSiteRequest::STATUS_COMPLETED
            && $bulkRequest->items->contains(fn ($item) => $item->isPending())) {
            $bulkRequest->refreshProgressStatus();
            $bulkRequest->refresh();
            $bulkRequest->load([
                'items' => fn ($q) => $q->orderBy('id'),
                'sites' => fn ($q) => $q->orderBy('id'),
            ]);
        }

        $countries = Country::marketplace()->orderBy('name')->get();
        $languages = Language::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();
        $history = ActivityLog::forBulkSiteRequest($bulkRequest->id);
        $canDeleteDrafts = auth()->user()?->isAdmin() || auth()->user()?->isMarketing();
        $pendingItems = $bulkRequest->items->filter(fn ($item) => $item->isPending())->values();

        return view('admin.bulk-site-requests.show', compact(
            'bulkRequest',
            'countries',
            'languages',
            'categories',
            'countryLanguageMap',
            'history',
            'canDeleteDrafts',
            'pendingItems'
        ));
    }

    public function markSheetSent(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);

        if (! $bulkRequest->canMarkSheetSent()) {
            return back()->with('error', 'Sheet emailed can only be marked before drafts are added.');
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

    public function cancel(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);

        $request->merge([
            'reason' => trim((string) $request->input('reason')),
        ]);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Give a short reason for cancelling this request.',
            'reason.min' => 'Give a short reason for cancelling this request.',
            'reason.max' => 'Give a short reason for cancelling this request.',
        ]);
        $reason = $validated['reason'];

        $previous = $bulkRequest->status;
        $bulkRequest->forceFill([
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'cancel_reason' => $reason,
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
                'reason' => $reason,
            ],
            'Bulk request #'.$bulkRequest->id
        );

        // Cancelling was silent, so the request simply vanished from the
        // publisher's queue — which reads as us losing their work.
        $publisher = $bulkRequest->publisher;

        try {
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(
                    new BulkSiteRequestCancelled($bulkRequest->fresh(), $publisher, $reason)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email publisher after bulk cancel: '.$e->getMessage());
        }

        try {
            app(InAppNotificationService::class)
                ->notifyPublisherBulkRequestCancelled($bulkRequest->fresh(), $reason);
        } catch (\Throwable $e) {
            Log::warning('Failed to send in-app bulk cancel notice: '.$e->getMessage());
        }

        return redirect()
            ->to(staff_route('bulk-site-requests.index'))
            ->with('success', 'Bulk request cancelled. The publisher has been notified. History is kept.');
    }

    public function rejectItem(Request $request, int $id, int $itemId)
    {
        $bulkRequest = BulkSiteRequest::with(['publisher', 'items'])->findOrFail($id);
        $item = BulkSiteRequestItem::query()
            ->where('bulk_site_request_id', $bulkRequest->id)
            ->whereKey($itemId)
            ->firstOrFail();

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'Cannot reject a site on a cancelled request.');
        }

        if (! $item->isPending()) {
            return back()->with('error', 'That website is already added or rejected.');
        }

        $request->merge([
            'reason' => trim((string) $request->input('reason')),
        ]);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Give a short reason for rejecting this website.',
            'reason.min' => 'Give a short reason for rejecting this website.',
            'reason.max' => 'Give a short reason for rejecting this website.',
        ]);

        $reason = $validated['reason'];

        $item->forceFill([
            'rejected_at' => now(),
            'rejected_by' => auth()->id(),
            'reject_reason' => $reason,
        ])->save();

        $bulkRequest->forceFill([
            'handled_by' => auth()->id(),
        ])->save();
        $bulkRequest->refreshProgressStatus();

        ActivityLogger::log(
            'bulk_request.item_rejected',
            (auth()->user()->name ?? 'Staff').' rejected '.$item->domain.' on bulk request #'.$bulkRequest->id,
            $bulkRequest,
            [
                'bulk_site_request_id' => $bulkRequest->id,
                'publisher_id' => $bulkRequest->publisher_id,
                'item_id' => $item->id,
                'site_url' => $item->site_url,
                'domain' => $item->domain,
                'price' => $item->price,
                'reason' => $reason,
            ],
            $item->domain
        );

        $fresh = $bulkRequest->fresh(['publisher']);
        $publisher = $fresh?->publisher;

        try {
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(
                    new BulkSiteRequestItemRejected($fresh, $item->fresh(), $publisher, $reason)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email publisher after bulk item reject: '.$e->getMessage());
        }

        try {
            app(InAppNotificationService::class)
                ->notifyPublisherBulkItemRejected($fresh, $item->fresh(), $reason);
        } catch (\Throwable $e) {
            Log::warning('Failed to send in-app bulk item reject notice: '.$e->getMessage());
        }

        return back()->with('success', 'Rejected '.$item->domain.'. The rest of the batch stays open. Publisher notified.');
    }

    /**
     * Done: create draft sites from publisher-submitted URL+price items, then notify publisher.
     * Drafts stay inactive until the publisher finishes details and staff verify/activate.
     * Marketer can submit one or more fully filled blocks; empty pending rows stay for later.
     */
    public function done(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::with(['publisher', 'items'])->findOrFail($id);

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'Cannot complete a cancelled request.');
        }

        $pendingItems = $bulkRequest->items->filter(fn ($item) => $item->isPending())->keyBy(fn ($item) => (int) $item->id);
        if ($pendingItems->isEmpty()) {
            return back()->with('error', 'No pending URL + price rows left to add. Use advanced seed if you need to add more.');
        }

        $pendingIds = $pendingItems->keys()->map(fn ($v) => (int) $v)->all();
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        // Pre-resolve niches per row so group aliases (Technology) and HTML entities match.

        $inputItems = $request->input('items', []);
        if (! is_array($inputItems)) {
            $inputItems = [];
        }

        // Only validate rows the marketer started or completed. Empty pending rows stay for later.
        $completeItemIds = [];
        $partialItemIds = [];
        foreach ($inputItems as $itemId => $row) {
            $itemId = (int) $itemId;
            if (! in_array($itemId, $pendingIds, true) || ! is_array($row)) {
                continue;
            }
            $fill = $this->classifyDoneRowFill($row);
            if ($fill === 'empty') {
                continue;
            }
            if ($fill === 'complete') {
                $completeItemIds[] = $itemId;
            } else {
                $partialItemIds[] = $itemId;
            }
        }

        $maxSites = BulkSiteRequest::MAX_SITES_PER_REQUEST;

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1|max:'.$maxSites,
        ], [
            'items.required' => 'Fill at least one complete website block (Language, Country, DA, DR, Traffic, Niches) before Done.',
            'items.max' => "You can Done at most {$maxSites} websites per submission (same limit as publisher bulk).",
        ]);

        $validator->after(function ($validator) use (
            $inputItems,
            $pendingIds,
            $completeItemIds,
            $partialItemIds,
            $allowedCountries,
            $allowedLanguages
        ) {
            if ($completeItemIds === [] && $partialItemIds === []) {
                $validator->errors()->add(
                    'items',
                    'Fill at least one complete website block before clicking Done.'
                );

                return;
            }

            foreach ($inputItems as $itemId => $row) {
                $itemId = (int) $itemId;
                // Stale ids (already added in another tab) must not fail the rest of the batch.
                if (! in_array($itemId, $pendingIds, true) || ! is_array($row)) {
                    continue;
                }

                $fill = $this->classifyDoneRowFill($row);
                if ($fill === 'empty') {
                    continue;
                }

                if ($fill === 'partial') {
                    foreach ($this->missingDoneRowFields($row) as $field) {
                        $validator->errors()->add(
                            'items.'.$itemId.'.'.$field,
                            'Finish this field, or clear the row and submit only complete blocks.'
                        );
                    }

                    continue;
                }

                $rules = Validator::make($row, [
                    'language' => 'required|string|max:10',
                    'country' => 'required|string|max:10',
                    'da' => 'required|integer|min:0|max:100',
                    'dr' => 'required|integer|min:0|max:100',
                    // Monthly visitors — not a 0–100 score. Cap at MySQL UNSIGNED INT.
                    'traffic' => 'required|integer|min:0|max:4294967295',
                    'categories' => 'required',
                ], [
                    'country.required' => 'Country is required.',
                    'language.required' => 'Language is required.',
                    'da.required' => 'DA is required.',
                    'dr.required' => 'DR is required.',
                    'traffic.required' => 'Traffic is required.',
                    'categories.required' => 'Select at least one niche.',
                    'da.max' => 'DA must be between 0 and 100.',
                    'dr.max' => 'DR must be between 0 and 100.',
                    'traffic.max' => 'Traffic must be a monthly visitor count (0–4,294,967,295).',
                ]);
                foreach ($rules->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add('items.'.$itemId.'.'.$field, $message);
                    }
                }

                $language = strtolower(trim((string) ($row['language'] ?? '')));
                $country = strtolower(trim((string) ($row['country'] ?? '')));
                if ($language !== '' && ! in_array($language, $allowedLanguages, true)) {
                    $validator->errors()->add('items.'.$itemId.'.language', 'Choose a valid marketplace language.');
                }
                if ($country !== '' && ! in_array($country, $allowedCountries, true)) {
                    $validator->errors()->add('items.'.$itemId.'.country', 'Choose a valid marketplace country.');
                }
                if ($country !== '' && $language !== '' && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                    $validator->errors()->add(
                        'items.'.$itemId.'.language',
                        'That language is not allowed for the selected country.'
                    );
                }

                $resolved = Category::resolveNicheNames($row['categories'] ?? []);
                $categories = $resolved['resolved'];
                if ($categories === [] && $resolved['unknown'] === []) {
                    $validator->errors()->add('items.'.$itemId.'.categories', 'Select at least one niche (max 7).');
                } elseif (count($categories) > 7) {
                    $validator->errors()->add('items.'.$itemId.'.categories', 'Select at most 7 niches.');
                }
                foreach ($resolved['unknown'] as $cat) {
                    $validator->errors()->add('items.'.$itemId.'.categories', 'Unknown niche: '.$cat);
                }
            }

        });

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Finish each started block completely, or click Clear and submit only the finished blocks.');
        }

        if ($completeItemIds === []) {
            return back()
                ->withInput()
                ->with('error', 'Fill at least one complete website block before clicking Done.');
        }

        if (count($completeItemIds) > $maxSites) {
            return back()
                ->withInput()
                ->with('error', "You can Done at most {$maxSites} websites per submission (same limit as publisher bulk).");
        }

        $rows = [];
        foreach ($completeItemIds as $itemId) {
            $item = $pendingItems->get($itemId);
            if (! $item) {
                continue;
            }
            $row = $inputItems[$itemId] ?? $inputItems[(string) $itemId] ?? [];
            $categories = Category::resolveNicheNames($row['categories'] ?? [])['resolved'];
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
                'categories' => $categories,
                'category' => implode('|', $categories),
            ];
        }

        return $this->createDraftSitesAndNotify($bulkRequest, $rows, [], 'bulk_request.done');
    }

    /**
     * Seed draft sites from pasted rows:
     * url,price,da,dr,traffic,country,language[,site_name]
     */
    public function seed(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::with('publisher')->findOrFail($id);

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'Cannot seed a cancelled request.');
        }

        if ($bulkRequest->items()->exists()) {
            return back()->with('error', 'Use Done for submitted URL + price rows. Advanced Seed is only for legacy requests without that list.');
        }

        if (! $bulkRequest->canAddDraftSites()) {
            return back()->with('error', 'This request is closed. Advanced Seed is only for open legacy requests.');
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
            return back()->with('error', 'No rows found. Paste one site per line: url,price,da,dr,traffic,country,language')->withInput();
        }

        if ($parsed['rows'] === []) {
            return back()
                ->with('error', 'All rows failed validation.')
                ->with('seed_failures', $parsed['failures'])
                ->withInput();
        }

        $maxSites = BulkSiteRequest::MAX_SITES_PER_REQUEST;
        if (count($parsed['rows']) > $maxSites) {
            return back()
                ->with('error', "Seed at most {$maxSites} sites per submission (same limit as publisher bulk). Split into batches if needed.")
                ->withInput();
        }

        return $this->createDraftSitesAndNotify($bulkRequest, $parsed['rows'], $parsed['failures'], 'bulk_request.seeded');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $failures
     * @param  'bulk_request.done'|'bulk_request.seeded'  $action
     */
    private function createDraftSitesAndNotify(BulkSiteRequest $bulkRequest, array $rows, array $failures, string $action)
    {
        if (! in_array($action, ['bulk_request.done', 'bulk_request.seeded'], true)) {
            throw new \InvalidArgumentException('Unsupported bulk history action.');
        }

        $created = 0;
        $createdDomains = [];

        DB::transaction(function () use ($bulkRequest, $rows, $action, &$created, &$failures, &$createdDomains) {
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
                    'publisher_accepted_at' => now(),
                    'assigned_by_user_id' => null,
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
                    'category' => $row['category'] ?? 'Pending',
                    'categories' => $row['categories'] ?? null,
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

                $this->attachCreatedSiteToBulkItem($bulkRequest, $row, $site, $action);

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
            $verb = $action === 'bulk_request.done'
                ? 'marked Done and added'
                : 'seeded';

            ActivityLogger::log(
                $action,
                (auth()->user()->name ?? 'Staff').' '.$verb.' '.$created.' draft site(s) to publisher panel on bulk request #'.$bulkRequest->id,
                $bulkRequest,
                [
                    'bulk_site_request_id' => $bulkRequest->id,
                    'publisher_id' => $bulkRequest->publisher_id,
                    'created_count' => $created,
                    'failed_count' => count($failures),
                    'domains' => $createdDomains,
                    'source' => $action === 'bulk_request.done' ? 'done' : 'seed',
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

        $remaining = $bulkRequest->pendingItemsCount();
        $headline = $action === 'bulk_request.done' ? 'Done' : 'Seed';
        $message = $created > 0
            ? "{$headline} — {$created} site(s) added to the publisher’s Pending sites. Publisher notified (email + in-app). Still inactive until they finish details and you verify."
            : 'No sites were added.';
        if ($created > 0 && $remaining > 0) {
            $message .= " {$remaining} website(s) still pending — fill and submit them when ready.";
        }
        if ($failures !== []) {
            $message .= ' '.count($failures).' row(s) failed.';
        }

        $response = back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('seed_failures', $failures);

        // Failed rows stay pending. Echo their boxes back even if the draft
        // was pruned before the browser unloaded.
        if ($failures !== []) {
            $response->withInput();
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  'bulk_request.done'|'bulk_request.seeded'  $action
     */
    private function attachCreatedSiteToBulkItem(BulkSiteRequest $bulkRequest, array $row, Site $site, string $action): void
    {
        $query = $bulkRequest->items()->pending();

        // Done rows carry the item id in `line`. Never attach by domain — two
        // pending URLs can share a host, and only the submitted row should link.
        if ($action === 'bulk_request.done') {
            $itemId = (int) ($row['line'] ?? 0);
            if ($itemId > 0) {
                $query->whereKey($itemId)->update(['site_id' => $site->id]);

                return;
            }
        }

        $query->where('domain', $row['domain'])->update(['site_id' => $site->id]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return 'empty'|'partial'|'complete'
     */
    private function classifyDoneRowFill(array $row): string
    {
        $missing = $this->missingDoneRowFields($row);
        if ($missing === []) {
            return 'complete';
        }

        $started = false;
        foreach (['language', 'country', 'da', 'dr', 'traffic', 'categories'] as $field) {
            if ($this->doneRowFieldFilled($row, $field)) {
                $started = true;
                break;
            }
        }

        return $started ? 'partial' : 'empty';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function missingDoneRowFields(array $row): array
    {
        $missing = [];
        foreach (['language', 'country', 'da', 'dr', 'traffic', 'categories'] as $field) {
            if (! $this->doneRowFieldFilled($row, $field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function doneRowFieldFilled(array $row, string $field): bool
    {
        if ($field === 'categories') {
            return $this->parseCategoryList($row['categories'] ?? []) !== [];
        }

        if (in_array($field, ['da', 'dr', 'traffic'], true)) {
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                return false;
            }

            return is_numeric($row[$field]);
        }

        return trim((string) ($row[$field] ?? '')) !== '';
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
                    'errors' => ['Need 7 columns: url,price,da,dr,traffic,country,language'],
                ];

                continue;
            }

            [$urlRaw, $priceRaw, $daRaw, $drRaw, $trafficRaw, $countryRaw, $langRaw] = array_slice($parts, 0, 7);
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
            if ($traffic === null || $traffic < 0 || $traffic > 4294967295) {
                $errors[] = 'Invalid traffic (monthly visitors, 0–4294967295)';
            }
            if (! in_array($language, $allowedLanguages, true)) {
                $errors[] = 'Unknown language code';
            }
            if (! in_array($country, $allowedCountries, true)) {
                $errors[] = 'Unknown country code';
            }
            if ($errors === [] && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                $errors[] = 'Language not allowed for country';
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

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function parseCategoryList($raw): array
    {
        return Category::normalizeNicheInputs($raw);
    }
}
