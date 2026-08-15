<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkSiteItemsRejected;
use App\Mail\BulkSiteRequestCancelled;
use App\Mail\BulkSitesSeededNotification;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Support\CommunityInbox;
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
        $status = search_text($request->input('status'));

        $query = BulkSiteRequest::query()
            ->with(['publisher', 'handler'])
            ->withCount([
                'sites' => fn ($q) => $q->notArchived(),
                'sites as awaiting_details_count' => fn ($q) => $q->notArchived()
                    ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS),
                'sites as ready_count' => fn ($q) => $q->notArchived()
                    ->where('onboarding_status', Site::ONBOARDING_READY_FOR_REVIEW),
                'items as pending_items_count' => fn ($q) => $q->whereNull('site_id'),
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
            'sites' => fn ($q) => $q->notArchived()->orderBy('id'),
        ])->findOrFail($id);

        // Heal stuck batches: completed-with-pending-rows, or drafts deleted so
        // only URL+price rows remain (status still says waiting on publisher).
        $hasPendingItems = $bulkRequest->items->whereNull('site_id')->isNotEmpty();
        $needsHeal = $bulkRequest->status !== BulkSiteRequest::STATUS_CANCELLED
            && (
                ($hasPendingItems && (
                    $bulkRequest->status === BulkSiteRequest::STATUS_COMPLETED
                    || $bulkRequest->sites->isEmpty()
                ))
                || ($bulkRequest->sites->isEmpty()
                    && in_array($bulkRequest->status, [
                        BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
                        BulkSiteRequest::STATUS_SEEDED,
                    ], true))
            );
        if ($needsHeal) {
            $bulkRequest->refreshProgressStatus();
            $bulkRequest->refresh();
            $bulkRequest->load([
                'items' => fn ($q) => $q->orderBy('id'),
                'sites' => fn ($q) => $q->notArchived()->orderBy('id'),
            ]);
        }

        $countries = Country::marketplace()->orderBy('name')->get();
        $languages = Language::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();
        $history = ActivityLog::forBulkSiteRequest($bulkRequest->id);
        $canDeleteDrafts = auth()->user()?->isAdmin() || auth()->user()?->isMarketing();
        $pendingItems = $bulkRequest->items->whereNull('site_id')->values();

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
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $bulkRequest = BulkSiteRequest::findOrFail($id);
        $bulkRequest->forceFill([
            'admin_notes' => $validated['admin_notes'] ?? null,
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
        $reason = trim((string) $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ])['reason']);

        $bulkRequest = BulkSiteRequest::findOrFail($id);
        if ($bulkRequest->isCancelled()) {
            return redirect()
                ->to(staff_route('bulk-site-requests.index'))
                ->with('error', 'This request is already cancelled.');
        }

        $previous = $bulkRequest->status;
        $removedDrafts = 0;
        $archivedLive = 0;

        $alreadyCancelled = false;

        DB::transaction(function () use ($bulkRequest, $reason, &$removedDrafts, &$archivedLive, &$alreadyCancelled) {
            $locked = BulkSiteRequest::query()->lockForUpdate()->find($bulkRequest->id);
            if (! $locked || $locked->isCancelled()) {
                $alreadyCancelled = true;

                return;
            }

            $drafts = $locked->sites()
                ->where(function ($q) {
                    $q->where('verified', 0)->orWhereNull('verified');
                })
                ->where(function ($q) {
                    $q->where('active', 0)->orWhereNull('active');
                })
                ->lockForUpdate()
                ->get();

            foreach ($drafts as $site) {
                if (! $site->canBeHardDeleted()) {
                    continue;
                }
                $site->delete();
                $removedDrafts++;
            }

            $survivors = $locked->sites()->notArchived()->lockForUpdate()->get();
            foreach ($survivors as $site) {
                if ($site->archiveByStaff($reason)) {
                    $archivedLive++;
                }
            }

            $locked->items()->whereNull('site_id')->delete();

            $locked->forceFill([
                'status' => BulkSiteRequest::STATUS_CANCELLED,
                'handled_by' => auth()->id(),
            ])->save();
        });

        if ($alreadyCancelled) {
            return redirect()
                ->to(staff_route('bulk-site-requests.index'))
                ->with('error', 'This request is already cancelled.');
        }

        ActivityLogger::log(
            'bulk_request.cancelled',
            (auth()->user()->name ?? 'Staff').' cancelled bulk request #'.$bulkRequest->id,
            $bulkRequest,
            [
                'bulk_site_request_id' => $bulkRequest->id,
                'publisher_id' => $bulkRequest->publisher_id,
                'from_status' => $previous,
                'reason' => $reason,
                'drafts_removed' => $removedDrafts,
                'sites_archived' => $archivedLive,
                'sites_remaining' => $bulkRequest->sites()->notArchived()->count(),
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

        $flash = 'Bulk request cancelled. The publisher has been notified. History is kept.';
        if ($archivedLive > 0) {
            $flash .= ' '.$archivedLive.' live listing'.($archivedLive === 1 ? ' was' : 's were').' archived.';
        }

        return redirect()
            ->to(staff_route('bulk-site-requests.index'))
            ->with('success', $flash);
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

        $pendingItems = $bulkRequest->items->whereNull('site_id')->keyBy(fn ($item) => (int) $item->id);
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

        $rejectedItemIds = $this->pendingRejectedItemIds($request, $pendingIds);

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

        // A filled row is kept (seeded). Delete only applies to unfinished pending rows.
        $rejectedItemIds = array_values(array_diff($rejectedItemIds, $completeItemIds));
        $partialItemIds = array_values(array_diff($partialItemIds, $rejectedItemIds));
        $request->merge([
            'rejected_item_ids' => $rejectedItemIds,
            'rejection_note' => trim((string) $request->input('rejection_note', '')),
        ]);

        $maxSites = BulkSiteRequest::MAX_SITES_PER_REQUEST;

        $validator = Validator::make($request->all(), [
            'items' => 'nullable|array|max:'.$maxSites,
            'rejected_item_ids' => 'nullable|array|max:'.$maxSites,
            'rejected_item_ids.*' => 'nullable|integer',
            'rejection_note' => 'nullable|string|max:1000',
        ], [
            'items.max' => "You can Done at most {$maxSites} websites per submission (same limit as publisher bulk).",
            'rejection_note.max' => 'The publisher note must be at most 1000 characters.',
        ]);

        $validator->after(function ($validator) use (
            $request,
            $inputItems,
            $pendingIds,
            $completeItemIds,
            $partialItemIds,
            $rejectedItemIds,
            $allowedCountries,
            $allowedLanguages
        ) {
            if ($completeItemIds === [] && $rejectedItemIds === [] && $partialItemIds === []) {
                $validator->errors()->add(
                    'items',
                    'Fill at least one complete website block, or delete sites you will not add, before clicking Done.'
                );

                return;
            }

            foreach ($inputItems as $itemId => $row) {
                $itemId = (int) $itemId;
                if (in_array($itemId, $rejectedItemIds, true)) {
                    continue;
                }
                // Stale keys (already seeded, or leftover draft ids) must not
                // block Done on the remaining pending rows.
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

            // After item errors so $errors->first() is a box message when both
            // a short note and unfinished fields are present (title says boxes).
            if ($rejectedItemIds !== []) {
                $note = trim((string) $request->input('rejection_note', ''));
                if (mb_strlen($note) < 10) {
                    $validator->errors()->add(
                        'rejection_note',
                        'Add a note for the publisher about the removed sites (at least 10 characters).'
                    );
                }
            }

        });

        if ($validator->fails()) {
            $itemErrors = collect($validator->errors()->keys())
                ->contains(fn ($key) => $key === 'items' || str_starts_with((string) $key, 'items.'));
            $flash = (! $itemErrors && $validator->errors()->has('rejection_note'))
                ? (string) $validator->errors()->first('rejection_note')
                : 'Finish each started block completely, or clear it and submit only the finished blocks.';

            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', $flash);
        }

        if ($completeItemIds === [] && $rejectedItemIds === []) {
            return back()
                ->withInput()
                ->with('error', 'Fill at least one complete website block, or delete sites you will not add, before clicking Done.');
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

        $rejectedItems = [];
        foreach ($rejectedItemIds as $itemId) {
            $item = $pendingItems->get($itemId);
            if (! $item) {
                continue;
            }
            $rejectedItems[] = [
                'id' => (int) $item->id,
                'domain' => (string) $item->domain,
                'site_url' => (string) $item->site_url,
            ];
        }

        $rejectionNote = $rejectedItems === []
            ? null
            : trim((string) $request->input('rejection_note', ''));

        return $this->createDraftSitesAndNotify(
            $bulkRequest,
            $rows,
            [],
            'bulk_request.done',
            $rejectedItems,
            $rejectionNote
        );
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

        if (! $bulkRequest->canAddDraftSites()) {
            return back()->with('error', 'This request has no pending websites to seed.');
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|string|min:3|max:200000',
        ], [
            'rows.max' => 'Paste is too large. Seed at most 200 sites, or split into smaller batches.',
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

        $pendingItems = $bulkRequest->items()->whereNull('site_id')->get(['id', 'domain']);
        if ($pendingItems->isNotEmpty()) {
            $pendingDomains = $pendingItems
                ->map(fn ($item) => Site::normalizeMarketplaceDomain((string) $item->domain))
                ->filter()
                ->values()
                ->all();

            $allowed = [];
            foreach ($parsed['rows'] as $row) {
                $domain = Site::normalizeMarketplaceDomain((string) ($row['domain'] ?? ''));
                if ($domain === '' || ! in_array($domain, $pendingDomains, true)) {
                    $parsed['failures'][] = [
                        'line' => $row['line'] ?? 0,
                        'url' => $row['site_url'] ?? $domain,
                        'errors' => ['Not in this request’s pending URL + price list: '.$domain],
                    ];

                    continue;
                }
                $allowed[] = $row;
            }
            $parsed['rows'] = $allowed;
        }

        if ($parsed['rows'] === []) {
            return back()
                ->with('error', $parsed['failures'] === []
                    ? 'No rows found. Paste one site per line: url,price,da,dr,traffic,country,language'
                    : 'All rows failed validation.')
                ->with('seed_failures', $parsed['failures'])
                ->withInput();
        }

        return $this->createDraftSitesAndNotify($bulkRequest, $parsed['rows'], $parsed['failures'], 'bulk_request.seeded');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $failures
     * @param  'bulk_request.done'|'bulk_request.seeded'  $action
     * @param  list<array{id:int,domain:string,site_url:string}>  $rejectedItems
     */
    private function createDraftSitesAndNotify(
        BulkSiteRequest $bulkRequest,
        array $rows,
        array $failures,
        string $action,
        array $rejectedItems = [],
        ?string $rejectionNote = null
    ) {
        if (! in_array($action, ['bulk_request.done', 'bulk_request.seeded'], true)) {
            throw new \InvalidArgumentException('Unsupported bulk history action.');
        }

        $created = 0;
        $createdDomains = [];
        $deletedCount = 0;
        $deletedDomains = [];
        $abortedCancelled = false;
        $rejectedIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['id'],
            $rejectedItems
        )));

        DB::transaction(function () use (
            $bulkRequest,
            $rows,
            $action,
            $rejectedItems,
            $rejectedIds,
            &$created,
            &$failures,
            &$createdDomains,
            &$deletedCount,
            &$deletedDomains,
            &$abortedCancelled
        ) {
            $locked = BulkSiteRequest::query()->lockForUpdate()->find($bulkRequest->id);
            if (! $locked || $locked->isCancelled()) {
                $abortedCancelled = true;

                return;
            }

            foreach ($rows as $row) {
                $domain = Site::normalizeMarketplaceDomain((string) ($row['domain'] ?? ''));
                if ($domain === '') {
                    $failures[] = [
                        'line' => $row['line'] ?? 0,
                        'url' => $row['site_url'] ?? '',
                        'errors' => ['Invalid domain'],
                    ];

                    continue;
                }

                Site::releaseCancelledBulkDomain($domain, (int) $bulkRequest->publisher_id);
                $existing = Site::findOccupyingDomain($domain, lock: true);

                if ($existing) {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => [$existing->isArchived()
                            ? $existing->occupyingDomainMessage()
                            : 'Domain already registered: '.$domain],
                    ];

                    continue;
                }

                $siteUrl = $this->listingUrlForDomain($domain, (string) ($row['site_url'] ?? ''));
                if ($siteUrl === '') {
                    $failures[] = [
                        'line' => $row['line'] ?? 0,
                        'url' => $row['site_url'] ?? $domain,
                        'errors' => ['Invalid website URL'],
                    ];

                    continue;
                }

                $site = new Site;
                $site->applyMarketplaceListing([
                    'publisher_id' => $bulkRequest->publisher_id,
                    'bulk_site_request_id' => $bulkRequest->id,
                    'publisher_accepted_at' => now(),
                    'assigned_by_user_id' => null,
                    'site_name' => ($row['site_name'] ?? '') !== '' ? $row['site_name'] : $domain,
                    'site_url' => $siteUrl,
                    'domain' => $domain,
                    'example_url' => $siteUrl,
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
                try {
                    $site->save();
                } catch (\Throwable $e) {
                    Log::warning('Failed to create bulk draft site: '.$e->getMessage(), [
                        'bulk_site_request_id' => $bulkRequest->id,
                        'domain' => $domain,
                    ]);
                    $failures[] = [
                        'line' => $row['line'] ?? 0,
                        'url' => $siteUrl,
                        'errors' => ['Could not add this domain. It may already be registered.'],
                    ];

                    continue;
                }

                $pending = $bulkRequest->items()->whereNull('site_id')->get(['id', 'domain']);
                $doneItemId = $action === 'bulk_request.done' ? (int) ($row['line'] ?? 0) : 0;
                if ($doneItemId > 0) {
                    $itemIds = $pending->firstWhere('id', $doneItemId) ? [$doneItemId] : [];
                } else {
                    $itemIds = $pending
                        ->filter(fn ($item) => Site::normalizeMarketplaceDomain((string) $item->domain) === $domain)
                        ->pluck('id')
                        ->take(1)
                        ->all();
                }
                $attached = 0;
                if ($itemIds !== []) {
                    $attached = $bulkRequest->items()
                        ->whereNull('site_id')
                        ->whereIn('id', $itemIds)
                        ->update(['site_id' => $site->id]);
                }
                if ($attached < 1 && ($action === 'bulk_request.done' || $pending->isNotEmpty())) {
                    $site->delete();
                    $failures[] = [
                        'line' => $row['line'] ?? 0,
                        'url' => $siteUrl,
                        'errors' => ['Could not attach this row. Refresh and try again.'],
                    ];

                    continue;
                }

                $created++;
                $createdDomains[] = $domain;
            }

            if ($rejectedIds !== []) {
                $kept = $bulkRequest->items()
                    ->whereIn('id', $rejectedIds)
                    ->whereNull('site_id')
                    ->get(['id', 'domain']);
                $deletedDomains = $kept->pluck('domain')->filter()->map(fn ($d) => (string) $d)->values()->all();
                $deletedCount = $bulkRequest->items()
                    ->whereIn('id', $rejectedIds)
                    ->whereNull('site_id')
                    ->delete();
                if ($deletedCount > 0 && $deletedDomains === []) {
                    $deletedDomains = array_values(array_filter(array_map(
                        static function (array $item): string {
                            $domain = trim((string) ($item['domain'] ?? ''));

                            return $domain !== '' ? $domain : trim((string) ($item['site_url'] ?? ''));
                        },
                        $rejectedItems
                    )));
                }

                if ($deletedCount > 0) {
                    $locked->forceFill([
                        'estimated_count' => $locked->items()->count(),
                        'handled_by' => auth()->id(),
                    ])->save();
                }
            }

            if ($created > 0) {
                $locked->forceFill([
                    'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
                    'seeded_at' => $locked->seeded_at ?? now(),
                    'handled_by' => auth()->id(),
                    'completed_at' => null,
                ])->save();
            }
        });

        if ($abortedCancelled) {
            return back()->with('error', $action === 'bulk_request.seeded'
                ? 'Cannot seed a cancelled request.'
                : 'Cannot complete a cancelled request.');
        }

        $fresh = $bulkRequest->fresh(['publisher']);
        $publisher = $fresh?->publisher;

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

            try {
                if ($publisher?->email) {
                    Mail::to($publisher->email)->send(
                        new BulkSitesSeededNotification($fresh, $created, $publisher, $createdDomains)
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to email publisher after bulk Done: '.$e->getMessage());
            }

            try {
                if ($fresh) {
                    app(InAppNotificationService::class)->notifyPublisherBulkSitesAdded($fresh, $created);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send in-app bulk Done notice: '.$e->getMessage());
            }
        }

        if ($deletedCount > 0) {
            $note = trim((string) $rejectionNote);
            ActivityLogger::log(
                'bulk_request.items_rejected',
                (auth()->user()->name ?? 'Staff').' removed '.$deletedCount.' pending site(s) from bulk request #'.$bulkRequest->id,
                $bulkRequest,
                [
                    'bulk_site_request_id' => $bulkRequest->id,
                    'publisher_id' => $bulkRequest->publisher_id,
                    'rejected_count' => $deletedCount,
                    'domains' => $deletedDomains,
                    'note' => $note,
                ],
                'Bulk request #'.$bulkRequest->id
            );

            try {
                if ($fresh && $publisher?->email) {
                    Mail::to($publisher->email)->send(
                        new BulkSiteItemsRejected($fresh, $publisher, $deletedDomains, $note, $rejectedIds)
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to email publisher after bulk item reject: '.$e->getMessage());
            }

            try {
                if ($fresh) {
                    app(InAppNotificationService::class)
                        ->notifyPublisherBulkItemsRejected($fresh, $deletedDomains, $note);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send in-app bulk item reject notice: '.$e->getMessage());
            }
        }

        $remaining = $bulkRequest->items()->whereNull('site_id')->count();
        $headline = $action === 'bulk_request.done' ? 'Done' : 'Seed';
        $parts = [];
        if ($created > 0) {
            $parts[] = "{$headline} — {$created} site(s) added to the publisher’s Pending sites. Publisher notified (email + in-app). Still inactive until they finish details and you verify.";
        }
        if ($deletedCount > 0) {
            $parts[] = $deletedCount === 1
                ? '1 site was removed and the publisher was notified.'
                : "{$deletedCount} sites were removed and the publisher was notified.";
        }
        $message = $parts !== [] ? implode(' ', $parts) : 'No sites were added.';
        if ($remaining > 0 && ($created > 0 || $deletedCount > 0)) {
            $message .= $created > 0
                ? " {$remaining} website(s) still pending — fill and submit them when ready."
                : " {$remaining} website(s) still pending.";
        }
        if ($failures !== []) {
            $message .= ' '.count($failures).' row(s) failed.';
        }

        $didWork = $created > 0 || $deletedCount > 0;
        if ($didWork) {
            $bulkRequest->refreshProgressStatus();
            $bulkRequest->refresh();
            // Reject-all with no drafts must not stay "requested" — that blocks
            // the publisher from submitting a new bulk and still enables seed.
            if ($bulkRequest->pendingItemsCount() === 0
                && $bulkRequest->sites()->doesntExist()
                && in_array($bulkRequest->status, [
                    BulkSiteRequest::STATUS_REQUESTED,
                    BulkSiteRequest::STATUS_SHEET_SENT,
                    BulkSiteRequest::STATUS_SEEDED,
                ], true)) {
                $bulkRequest->forceFill([
                    'status' => BulkSiteRequest::STATUS_COMPLETED,
                    'completed_at' => $bulkRequest->completed_at ?? now(),
                ])->save();
            }
        }

        $response = back()
            ->with($didWork ? 'success' : 'error', $message)
            ->with('seed_failures', $failures);
        if ($failures !== []) {
            $response->withInput();
        }

        return $response;
    }

    /**
     * @param  list<int>  $pendingIds
     * @return list<int>
     */
    private function pendingRejectedItemIds(Request $request, array $pendingIds): array
    {
        $raw = $request->input('rejected_item_ids', []);
        if (! is_array($raw)) {
            $raw = ($raw === null || $raw === '') ? [] : [$raw];
        }

        return collect($raw)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && in_array($id, $pendingIds, true))
            ->unique()
            ->values()
            ->all();
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
        $seenDomains = [];
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
            $domain = is_string($host) && $host !== ''
                ? Site::normalizeMarketplaceDomain($host)
                : null;

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

            if ($price === null || $price < 0 || $price > 99999999.99) {
                $errors[] = 'Invalid price (0–99999999.99)';
            }
            if (strlen($siteUrl) > 255) {
                $errors[] = 'URL is too long';
            }
            if (is_string($siteName) && strlen($siteName) > 255) {
                $errors[] = 'Site name is too long';
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

            if (isset($seenDomains[$domain])) {
                $failures[] = [
                    'line' => $lineNum,
                    'url' => $siteUrl,
                    'errors' => ['Duplicate domain in this list: '.$domain],
                ];

                continue;
            }
            $seenDomains[$domain] = true;

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

    /**
     * Listing URL must be http(s) and belong to the marketplace domain.
     * Stored javascript:/host-mismatch rows are rewritten to https://{domain}.
     */
    private function listingUrlForDomain(string $domain, string $url): string
    {
        $siteUrl = $this->normalizeHttpUrl($url);
        $host = parse_url($siteUrl, PHP_URL_HOST);
        $urlDomain = is_string($host) && $host !== ''
            ? Site::normalizeMarketplaceDomain($host)
            : '';

        if ($siteUrl !== '' && $urlDomain === $domain) {
            return $siteUrl;
        }

        return $this->normalizeHttpUrl('https://'.$domain);
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, "\0") || preg_match('/\s/u', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (preg_match('#^https?://#i', $url) !== 1) {
            if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $url, $schemeMatch) === 1) {
                $scheme = strtolower($schemeMatch[1]);
                $hasAuthority = preg_match('~^'.preg_quote($schemeMatch[1], '~').'://~i', $url) === 1;
                if ($hasAuthority || in_array($scheme, ['javascript', 'data', 'mailto', 'vbscript', 'file', 'about', 'blob', 'ftp', 'ftps'], true)) {
                    return '';
                }
                if (! str_contains($scheme, '.')) {
                    return '';
                }
            }
            $url = 'https://'.$url;
        }

        return CommunityInbox::safeHttpUrl($url) ?? '';
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
