<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkSiteItemsRejected;
use App\Mail\BulkSiteRequestCancelled;
use App\Mail\BulkSitesReadyForPublisherReview;
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
use App\Services\SiteClaimTransferService;
use App\Services\SiteDescriptionSanitizer;
use App\Services\SiteEnrichment\ImageOptimizationService;
use App\Support\MarketingOpsQueues;
use App\Support\SiteDescriptionRules;
use App\Support\SiteImageUpload;
use App\Support\SiteTag;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkSiteRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = search_text($request->input('status'));
        if ($status === BulkSiteRequest::STATUS_COMPLETED) {
            $status = '';
        }
        $selectedStatus = $status !== '' ? $status : 'all';
        $q = search_text($request->input('q'));

        try {
            $withCount = [
                'sites' => fn ($q) => $q->notArchived(),
                'items as pending_items_count' => fn ($q) => $q->whereNull('site_id'),
            ];
            if (Site::hasSitesColumn('onboarding_status')) {
                $withCount['sites as awaiting_details_count'] = fn ($q) => $q->notArchived()
                    ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS);
                $withCount['sites as ready_count'] = fn ($q) => $q->notArchived()
                    ->where('onboarding_status', Site::ONBOARDING_READY_FOR_REVIEW);
            }

            $this->healUnfinishedCompletedBulkRequests();

            $query = BulkSiteRequest::query()
                ->with(['publisher', 'handler'])
                ->withCount($withCount)
                ->latest();

            MarketingOpsQueues::applyBulkIndexStatus($query, $status);
            if ($status === '' || $status === 'all') {
                $query->where(function ($visible) {
                    $visible->whereNotIn('status', [
                        BulkSiteRequest::STATUS_COMPLETED,
                        BulkSiteRequest::STATUS_CANCELLED,
                    ])->orWhere(function ($unfinished) {
                        $unfinished->where('status', BulkSiteRequest::STATUS_COMPLETED);
                        $this->constrainBulkRequestStillOpen($unfinished);
                    });
                });
            } elseif ($status !== MarketingOpsQueues::FILTER_NEEDS_MARKETER) {
                $query->where('status', '!=', BulkSiteRequest::STATUS_COMPLETED);
            }
            $this->applyBulkIndexSearch($query, $q);

            $requests = $query->paginate(20)->withQueryString();
            $waitingOnYouCount = MarketingOpsQueues::bulkWaitingOnMarketer()->count();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load bulk site requests. Please refresh and try again.')
            );

            $requests = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
            $waitingOnYouCount = 0;
        }

        return view('admin.bulk-site-requests.index', [
            'requests' => $requests,
            'status' => $selectedStatus,
            'q' => $q,
            'filtersActive' => $selectedStatus !== 'all' || $q !== '',
            'waitingOnYouCount' => $waitingOnYouCount,
        ]);
    }

    /**
     * A completed label with URL rows or publisher review still open is stuck
     * work, not history. Correct it before the folder hides finished batches.
     */
    private function healUnfinishedCompletedBulkRequests(): void
    {
        BulkSiteRequest::query()
            ->where('status', BulkSiteRequest::STATUS_COMPLETED)
            ->where(function ($work) {
                $this->constrainBulkRequestStillOpen($work);
            })
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (BulkSiteRequest $bulk) {
                try {
                    $bulk->healProgressStatusIfStale();
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }

    /**
     * @param  Builder<BulkSiteRequest>  $query
     */
    private function constrainBulkRequestStillOpen($query): void
    {
        $query->where(function ($work) {
            $work->whereHas('items', fn ($items) => $items->whereNull('site_id'));
            if (Site::hasSitesColumn('onboarding_status')) {
                $work->orWhereHas('sites', function ($sites) {
                    $sites->notArchived()->whereIn('onboarding_status', [
                        Site::ONBOARDING_AWAITING_DETAILS,
                        Site::ONBOARDING_DETAILS_COMPLETE,
                    ]);
                });
            }
        });
    }

    private function applyBulkIndexSearch($query, string $q): void
    {
        if ($q === '') {
            return;
        }

        $like = '%'.addcslashes($q, '%_\\').'%';
        $query->where(function ($outer) use ($q, $like) {
            if (ctype_digit($q)) {
                $outer->orWhere('id', (int) $q);
            }
            $outer->orWhereHas('publisher', function ($pub) use ($like) {
                $pub->where('name', 'like', $like)->orWhere('email', 'like', $like);
            });
            $outer->orWhereHas('items', function ($items) use ($like) {
                $items->where('domain', 'like', $like)->orWhere('site_url', 'like', $like);
            });
        });
    }

    public function show(int $id)
    {
        try {
            return $this->renderShow($id);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->to(staff_route('bulk-site-requests.index'))
                ->with('error', UserFacingError::message($e, 'We could not load that bulk request. Please try again.'));
        }
    }

    private function renderShow(int $id)
    {
        $bulkRequest = BulkSiteRequest::with([
            'publisher',
            'handler',
            'items' => fn ($q) => $q->orderBy('id'),
            'sites' => fn ($q) => $q->notArchived()->orderBy('id'),
        ])->findOrFail($id);

        // Heal stuck batches: completed-with-pending-rows, drafts deleted so
        // only URL+price rows remain, staff verified/activated every draft
        // while status still says waiting on publisher (blocks a new bulk),
        // or unverify restored publisher work while status still says completed.
        if ($bulkRequest->needsProgressHeal()) {
            try {
                $bulkRequest->refreshProgressStatus();
                $bulkRequest->refresh();
                $bulkRequest->load([
                    'items' => fn ($q) => $q->orderBy('id'),
                    'sites' => fn ($q) => $q->notArchived()->orderBy('id'),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Bulk request progress heal failed', [
                    'bulk_site_request_id' => $bulkRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $countries = Country::marketplace()->orderBy('name')->get();
        $languages = Language::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();
        $history = collect();
        try {
            $history = ActivityLog::forBulkSiteRequest($bulkRequest->id);
        } catch (\Throwable $e) {
            Log::warning('Bulk request history failed', [
                'bulk_site_request_id' => $bulkRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
        $canDeleteDrafts = auth()->user()?->isAdmin() || auth()->user()?->isMarketing();
        $pendingItems = $bulkRequest->items->whereNull('site_id')->values();
        $occupyingMessages = [];
        foreach ($pendingItems as $item) {
            $existing = Site::findOccupyingDomain((string) $item->domain);
            if (! $existing) {
                continue;
            }
            $occupyingMessages[(int) $item->id] = $existing->isArchived()
                ? $existing->occupyingDomainMessage()
                : 'Domain already registered: '.$item->domain;
        }
        $keptCovers = $this->freshKeptCovers((int) $bulkRequest->id);
        $textDraft = is_array(old('items')) ? ['items' => []] : $this->loadTextDraft((int) $bulkRequest->id);
        $homepageDays = config('site_placement.homepage_days', [1, 7, 30]);
        $socialChannels = config('site_placement.social_channels', ['facebook', 'instagram', 'x']);

        return view('admin.bulk-site-requests.show', compact(
            'bulkRequest',
            'countries',
            'languages',
            'categories',
            'countryLanguageMap',
            'history',
            'canDeleteDrafts',
            'pendingItems',
            'occupyingMessages',
            'keptCovers',
            'textDraft',
            'homepageDays',
            'socialChannels'
        ));
    }

    public function markSheetSent(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);

        if (! $bulkRequest->canMarkSheetSent()) {
            return back()->with('error', 'Sheet emailed can only be marked before drafts are added.');
        }

        $alreadySent = $bulkRequest->status === BulkSiteRequest::STATUS_SHEET_SENT;
        $notes = $request->input('admin_notes', $bulkRequest->admin_notes);

        $bulkRequest->forceFill([
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'sheet_sent_at' => ($alreadySent && $bulkRequest->sheet_sent_at) ? $bulkRequest->sheet_sent_at : now(),
            'handled_by' => auth()->id(),
            'admin_notes' => $notes,
        ])->save();

        if (! $alreadySent) {
            ActivityLogger::tryLog(
                'bulk_request.sheet_sent',
                (auth()->user()->name ?? 'Staff').' marked bulk request #'.$bulkRequest->id.' as sheet emailed',
                $bulkRequest,
                [
                    'bulk_site_request_id' => $bulkRequest->id,
                    'publisher_id' => $bulkRequest->publisher_id,
                ],
                'Bulk request #'.$bulkRequest->id
            );
        }

        return back()->with('success', 'Marked as sheet emailed. Prefer Done from the URL + price list the publisher already submitted.');
    }

    public function updateNotes(Request $request, int $id)
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $bulkRequest = BulkSiteRequest::findOrFail($id);
        $from = (string) ($bulkRequest->admin_notes ?? '');
        $to = (string) ($validated['admin_notes'] ?? '');

        if ($from === $to) {
            return back()->with('success', 'Notes saved.');
        }

        $bulkRequest->forceFill([
            'admin_notes' => $validated['admin_notes'] ?? null,
            'handled_by' => auth()->id(),
        ])->save();

        ActivityLogger::tryLog(
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
        $blockedByOpenOrders = null;

        try {
            DB::transaction(function () use ($bulkRequest, $reason, &$removedDrafts, &$archivedLive, &$alreadyCancelled, &$blockedByOpenOrders) {
                $locked = BulkSiteRequest::query()->lockForUpdate()->find($bulkRequest->id);
                if (! $locked || $locked->isCancelled()) {
                    $alreadyCancelled = true;

                    return;
                }

                $orderGuard = app(SiteClaimTransferService::class);
                $openOn = [];
                foreach ($locked->sites()->notArchived()->lockForUpdate()->get() as $site) {
                    $open = $orderGuard->openOrderItemsCount($site);
                    if ($open > 0) {
                        $label = Site::normalizeMarketplaceDomain((string) $site->domain);
                        if ($label === '') {
                            $label = (string) $site->site_name;
                        }
                        $openOn[] = $label.' ('.$open.')';
                    }
                }
                if ($openOn !== []) {
                    $blockedByOpenOrders = 'Cannot cancel while these listings have open orders: '
                        .implode(', ', $openOn)
                        .'. Finish, cancel, or resolve those orders first.';

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
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                UserFacingError::message($e, 'We could not cancel this request. Please try again.')
            );
        }

        if ($alreadyCancelled) {
            return redirect()
                ->to(staff_route('bulk-site-requests.index'))
                ->with('error', 'This request is already cancelled.');
        }

        if (is_string($blockedByOpenOrders)) {
            return back()->with('error', $blockedByOpenOrders);
        }

        ActivityLogger::tryLog(
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
     * Done: create sites from publisher-submitted URL+price items.
     * Publish now makes them active and unverified. Send for review leaves them
     * inactive so the publisher can check them and submit them to Needs review.
     * Empty pending rows stay for later.
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
        $inputItems = $this->attachDoneRowImages($inputItems, $request);

        $maxSites = BulkSiteRequest::MAX_SITES_PER_REQUEST;
        if (count($inputItems) > $maxSites) {
            throw ValidationException::withMessages([
                'items' => "You can Done at most {$maxSites} websites per submission (same limit as publisher bulk).",
            ]);
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
            $fill = $this->classifyDoneRowFill($row, $pendingItems->get($itemId), (int) $bulkRequest->id);
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
            $pendingItems,
            $pendingIds,
            $completeItemIds,
            $partialItemIds,
            $rejectedItemIds,
            $allowedCountries,
            $allowedLanguages,
            $bulkRequest
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

                $fill = $this->classifyDoneRowFill($row, $pendingItems->get($itemId), (int) $bulkRequest->id);
                if ($fill === 'empty') {
                    continue;
                }

                if ($fill === 'partial') {
                    foreach ($this->missingDoneRowFields($row, (int) $bulkRequest->id, $itemId) as $field) {
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
                    'example_url' => 'required|url|max:255',
                    'turnaround_time' => 'required|in:24h,48h,3days,5days,7days',
                    'publication_time' => 'required|in:6months,1year,permanent',
                    'link_type' => 'required|in:dofollow,nofollow',
                    'site_tag' => 'required|in:none,sponsored,partner_material,as_you_prefer',
                    'description' => 'required|string',
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
                    'example_url.required' => 'Sample article URL is required.',
                    'example_url.url' => 'Sample article URL must be a valid URL.',
                    'turnaround_time.required' => 'Turnaround is required.',
                    'publication_time.required' => 'Publication time is required.',
                    'link_type.required' => 'Link type is required.',
                    'site_tag.required' => 'Listing tag is required.',
                    'description.required' => 'Description is required.',
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

                $description = trim((string) ($row['description'] ?? ''));
                foreach (SiteDescriptionRules::errors($description) as $message) {
                    $validator->errors()->add('items.'.$itemId.'.description', $message);
                }

                $image = $row['site_image'] ?? null;
                $keptCover = $this->keptCoverPath((int) $bulkRequest->id, $itemId, $row);
                if ($keptCover === null && (! $image instanceof UploadedFile || ! $image->isValid())) {
                    $validator->errors()->add(
                        'items.'.$itemId.'.site_image',
                        'Upload a site image (JPEG, PNG, GIF, or WebP, up to '.SiteImageUpload::maxMegabytesLabel().' MB).'
                    );
                } elseif ($image instanceof UploadedFile && $image->isValid()) {
                    $extension = strtolower($image->getClientOriginalExtension());
                    if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $validator->errors()->add(
                            'items.'.$itemId.'.site_image',
                            'Site image must be a JPEG, PNG, GIF, or WebP file.'
                        );
                    } elseif ($image->getSize() > SiteImageUpload::maxKilobytes() * 1024) {
                        $validator->errors()->add(
                            'items.'.$itemId.'.site_image',
                            'Site image must be '.SiteImageUpload::maxMegabytesLabel().' MB or smaller.'
                        );
                    }
                }

                $siteName = trim((string) ($row['site_name'] ?? ''));
                if (strlen($siteName) > 255) {
                    $validator->errors()->add('items.'.$itemId.'.site_name', 'Site name must be at most 255 characters.');
                }
                $priceRaw = $row['price'] ?? null;
                if ($priceRaw !== null && $priceRaw !== '' && (! is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 99999999.99)) {
                    $validator->errors()->add('items.'.$itemId.'.price', 'Price must be between 0 and 99999999.99.');
                }

                foreach (config('site_placement.homepage_days', [1, 7, 30]) as $days) {
                    $flags = is_array($row['homepage'] ?? null) ? $row['homepage'] : [];
                    $amounts = is_array($row['price_homepage'] ?? null) ? $row['price_homepage'] : [];
                    $flag = $flags[$days] ?? $flags[(string) $days] ?? null;
                    $offered = ! in_array($flag, [null, '', '0', 0, false], true);
                    if (! $offered) {
                        continue;
                    }
                    $price = $amounts[$days] ?? $amounts[(string) $days] ?? null;
                    if ($price === null || $price === '' || ! is_numeric($price) || (float) $price < 0 || (float) $price > 999999.99) {
                        $validator->errors()->add(
                            'items.'.$itemId.'.price_homepage.'.$days,
                            'Enter a fee for the '.$days.'-day homepage offer (0 is free).'
                        );
                    }
                }

                foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                    $flags = is_array($row['sensitive'] ?? null) ? $row['sensitive'] : [];
                    $amounts = is_array($row['price_sensitive'] ?? null) ? $row['price_sensitive'] : [];
                    $flag = $flags[$topic] ?? null;
                    $offered = ! in_array($flag, [null, '', '0', 0, false], true);
                    if (! $offered) {
                        continue;
                    }
                    $price = $amounts[$topic] ?? null;
                    if ($price === null || $price === '' || ! is_numeric($price) || (float) $price < 0 || (float) $price > 999999.99) {
                        $validator->errors()->add(
                            'items.'.$itemId.'.price_sensitive.'.$topic,
                            'Enter a price for '.$topic.' when that topic is offered.'
                        );
                    }
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

            $this->stashDoneCovers((int) $bulkRequest->id, $inputItems);
            $this->rememberTextDraft((int) $bulkRequest->id, $request);

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
            throw ValidationException::withMessages([
                'items' => "You can Done at most {$maxSites} websites per submission (same limit as publisher bulk).",
            ]);
        }

        $rows = [];
        foreach ($completeItemIds as $itemId) {
            $item = $pendingItems->get($itemId);
            if (! $item) {
                continue;
            }
            $row = $inputItems[$itemId] ?? $inputItems[(string) $itemId] ?? [];
            $categories = Category::resolveNicheNames($row['categories'] ?? [])['resolved'];
            $siteName = trim((string) ($row['site_name'] ?? ''));
            $priceRaw = $row['price'] ?? null;
            $rows[] = [
                'line' => (int) $item->id,
                'site_url' => $item->site_url,
                'domain' => $item->domain,
                'site_name' => $siteName !== '' ? $siteName : $item->domain,
                'price' => ($priceRaw !== null && $priceRaw !== '' && is_numeric($priceRaw))
                    ? (float) $priceRaw
                    : (float) $item->price,
                'da' => (int) $row['da'],
                'dr' => (int) $row['dr'],
                'traffic' => (int) $row['traffic'],
                'language' => strtolower(trim((string) $row['language'])),
                'country' => strtolower(trim((string) $row['country'])),
                'categories' => $categories,
                'category' => implode('|', $categories),
                'description' => trim((string) ($row['description'] ?? '')),
                'example_url' => $this->normalizeHttpUrl((string) ($row['example_url'] ?? '')),
                'turnaround_time' => (string) ($row['turnaround_time'] ?? ''),
                'publication_time' => (string) ($row['publication_time'] ?? ''),
                'link_type' => (string) ($row['link_type'] ?? ''),
                'site_tag' => (string) ($row['site_tag'] ?? ''),
                'sensitive_prices' => $this->doneSensitivePrices($row),
                'homepage_placement_prices' => $this->doneHomepagePrices($row),
                'social_promotion' => $this->doneSocialPromotion($row),
                'site_image_file' => $row['site_image'] ?? null,
                'kept_image_path' => $this->keptCoverPath((int) $bulkRequest->id, (int) $item->id, $row),
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

        $doneModeRaw = $request->input('done_mode');
        if (is_array($doneModeRaw)) {
            $doneModeRaw = end($doneModeRaw);
        }
        $doneMode = trim((string) (is_scalar($doneModeRaw) ? $doneModeRaw : '')) === 'review' ? 'review' : 'publish';

        return $this->createDraftSitesAndNotify(
            $bulkRequest,
            $rows,
            [],
            'bulk_request.done',
            $rejectedItems,
            $rejectionNote,
            $doneMode
        );
    }

    /**
     * Check pasted listing rows. A cover image cannot be pasted, so these rows
     * are not published — staff upload the image and publish from Done.
     * url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description
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
            'rows' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        $parsed = $this->parseSeedRows((string) $request->input('rows'), $allowedCountries, $allowedLanguages);
        if ($parsed['rows'] === [] && $parsed['failures'] === []) {
            return back()->with('error', 'No rows found. Paste one site per line: url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description')->withInput();
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

        $pendingDomains = $bulkRequest->items()
            ->whereNull('site_id')
            ->pluck('domain')
            ->map(fn ($domain) => Site::normalizeMarketplaceDomain((string) $domain))
            ->filter()
            ->values()
            ->all();

        if ($pendingDomains !== []) {
            $allowed = [];
            foreach ($parsed['rows'] as $row) {
                $domain = Site::normalizeMarketplaceDomain((string) ($row['domain'] ?? ''));
                if (! in_array($domain, $pendingDomains, true)) {
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

        $pendingByDomain = [];
        foreach ($bulkRequest->items()->whereNull('site_id')->get() as $item) {
            $domain = Site::normalizeMarketplaceDomain((string) $item->domain);
            if ($domain !== '') {
                $pendingByDomain[$domain] = $item;
            }
        }

        if ($pendingByDomain === []) {
            foreach ($parsed['rows'] as $row) {
                $domain = (string) ($row['domain'] ?? '');
                $parsed['failures'][] = [
                    'line' => $row['line'] ?? 0,
                    'url' => $row['site_url'] ?? $domain,
                    'errors' => ['Not in this request’s pending URL + price list: '.$domain],
                ];
            }
            $parsed['rows'] = [];
        }

        if ($parsed['rows'] === []) {
            return back()
                ->with('error', $parsed['failures'] === []
                    ? 'No rows found. Paste one site per line: url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description'
                    : 'All rows failed validation.')
                ->with('seed_failures', $parsed['failures'])
                ->withInput();
        }

        $oldItems = [];
        foreach ($parsed['rows'] as $row) {
            $domain = Site::normalizeMarketplaceDomain((string) ($row['domain'] ?? ''));
            $item = $pendingByDomain[$domain] ?? null;
            if (! $item) {
                continue;
            }
            $oldItems[(int) $item->id] = [
                'site_name' => $row['site_name'],
                'price' => $row['price'],
                'da' => $row['da'],
                'dr' => $row['dr'],
                'traffic' => $row['traffic'],
                'language' => $row['language'],
                'country' => $row['country'],
                'example_url' => $row['example_url'],
                'turnaround_time' => $row['turnaround_time'],
                'publication_time' => $row['publication_time'],
                'link_type' => $row['link_type'],
                'site_tag' => $row['site_tag'],
                'categories' => implode('|', $row['categories'] ?? []),
                'description' => $row['description'],
            ];
        }

        $this->rememberTextDraftPayload((int) $bulkRequest->id, [
            'items' => $oldItems,
            'rejected' => [],
            'rejection_note' => '',
        ]);

        $filled = count($oldItems);
        $message = $filled === 1
            ? '1 pasted row is in the Done form. Upload a cover image, then Done.'
            : $filled.' pasted rows are in the Done form. Upload a cover image on each, then Done.';

        $redirect = back()
            ->withInput([
                'items' => $oldItems,
                'rows' => (string) $request->input('rows'),
            ])
            ->with('success', $message);

        if ($parsed['failures'] !== []) {
            $redirect->with('seed_failures', $parsed['failures']);
        }

        return $redirect;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $failures
     * @param  'bulk_request.done'|'bulk_request.seeded'  $action
     * @param  list<array{id:int,domain:string,site_url:string}>  $rejectedItems
     * @param  'publish'|'review'  $doneMode
     */
    private function createDraftSitesAndNotify(
        BulkSiteRequest $bulkRequest,
        array $rows,
        array $failures,
        string $action,
        array $rejectedItems = [],
        ?string $rejectionNote = null,
        string $doneMode = 'publish'
    ) {
        if (! in_array($action, ['bulk_request.done', 'bulk_request.seeded'], true)) {
            throw new \InvalidArgumentException('Unsupported bulk history action.');
        }

        if ($doneMode === 'review') {
            Site::ensureOnboardingStatusColumnAcceptsValues();
        }

        $created = 0;
        $createdDomains = [];
        $deletedCount = 0;
        $deletedDomains = [];
        $rejectedIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['id'],
            $rejectedItems
        )));

        DB::transaction(function () use (
            $bulkRequest,
            $rows,
            $rejectedItems,
            $rejectedIds,
            &$created,
            &$failures,
            &$createdDomains,
            &$deletedCount,
            &$deletedDomains,
            $doneMode
        ) {
            foreach ($rows as $row) {
                $domain = $row['domain'];

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

                $description = app(SiteDescriptionSanitizer::class)->sanitize(
                    SiteDescriptionRules::forTextarea((string) ($row['description'] ?? ''))
                );
                if (! SiteDescriptionRules::isValid($description)) {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => ['A description of at least '.SiteDescriptionRules::MIN_CHARS.' characters is required.'],
                    ];

                    continue;
                }

                $categories = $row['categories'] ?? [];
                if (! is_array($categories) || $categories === []) {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => ['Select at least one niche.'],
                    ];

                    continue;
                }

                $imageFile = $row['site_image_file'] ?? null;
                $keptPath = is_string($row['kept_image_path'] ?? null) ? $row['kept_image_path'] : null;
                $imagePath = $imageFile instanceof UploadedFile
                    ? app(ImageOptimizationService::class)->storeSafePublicImage($imageFile, 'sites')
                    : null;
                if ((! is_string($imagePath) || $imagePath === '') && $keptPath !== null) {
                    $imagePath = $this->promoteKeptCover($keptPath);
                }
                if (! is_string($imagePath) || $imagePath === '') {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => [$doneMode === 'review'
                            ? 'Upload a site image before sending this website for review.'
                            : 'Upload a site image before this website goes live.'],
                    ];

                    continue;
                }

                $publishNow = $doneMode !== 'review';
                $site = new Site;
                $site->applyMarketplaceListing(array_merge([
                    'publisher_id' => $bulkRequest->publisher_id,
                    'bulk_site_request_id' => $bulkRequest->id,
                    'added_from_bulk_request' => true,
                    'publisher_accepted_at' => $publishNow ? now() : null,
                    'assigned_by_user_id' => null,
                    'site_name' => $row['site_name'],
                    'site_url' => $row['site_url'],
                    'domain' => $domain,
                    'example_url' => (string) ($row['example_url'] ?? ''),
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
                    'category' => $row['category'] ?? implode('|', $categories),
                    'categories' => $categories,
                    'price' => $row['price'],
                    'turnaround_time' => (string) ($row['turnaround_time'] ?? ''),
                    'publication_time' => (string) ($row['publication_time'] ?? ''),
                    'link_type' => (string) ($row['link_type'] ?? ''),
                    'description' => $description,
                    'sensitive_prices' => $row['sensitive_prices'] ?? null,
                    'homepage_placement_prices' => $row['homepage_placement_prices'] ?? null,
                    'social_promotion' => $row['social_promotion'] ?? null,
                    'site_image' => $imagePath,
                    'verified' => false,
                    'active' => $publishNow,
                    'enrichment_status' => 'pending',
                    'onboarding_status' => $publishNow ? null : Site::ONBOARDING_DETAILS_COMPLETE,
                ], SiteTag::flags(SiteTag::normalize($row['site_tag'] ?? null))));
                $site->save();

                $candidates = Site::domainLookupCandidates($domain);
                $normalized = Site::normalizeMarketplaceDomain($domain);
                $bulkRequest->items()
                    ->whereNull('site_id')
                    ->where(function ($q) use ($candidates, $normalized) {
                        if ($candidates !== []) {
                            $q->whereIn('domain', $candidates);
                        }
                        if ($normalized !== '') {
                            $escaped = addcslashes($normalized, '%_\\');
                            $q->orWhere('domain', 'like', $escaped.':%')
                                ->orWhere('domain', 'like', 'www.'.$escaped.':%');
                        }
                    })
                    ->update(['site_id' => $site->id]);

                $created++;
                $createdDomains[] = $domain;
                if ($imageFile instanceof UploadedFile && $keptPath !== null && $keptPath !== $imagePath) {
                    $this->deletePublicFile($keptPath);
                }
                $this->forgetDraftItem((int) $bulkRequest->id, (int) ($row['line'] ?? 0));
                $this->forgetKeptCover((int) $bulkRequest->id, (int) ($row['line'] ?? 0));
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
                    $bulkRequest->forceFill([
                        'estimated_count' => $bulkRequest->items()->count(),
                        'handled_by' => auth()->id(),
                    ])->save();
                }
            }

            if ($created > 0) {
                $bulkRequest->forceFill([
                    'seeded_at' => $bulkRequest->seeded_at ?? now(),
                    'handled_by' => auth()->id(),
                ])->save();
                $bulkRequest->refreshProgressStatus();
            }
        });

        $fresh = $bulkRequest->fresh(['publisher']);
        $publisher = $fresh?->publisher;

        if ($created > 0) {
            $sentForReview = $doneMode === 'review';
            $verb = $sentForReview
                ? 'sent '.$created.' site(s) to the publisher for review on bulk request #'
                : ($action === 'bulk_request.done'
                    ? 'marked Done and published '.$created.' active site(s) on bulk request #'
                    : 'published '.$created.' active site(s) on bulk request #');

            ActivityLogger::tryLog(
                $action,
                (auth()->user()->name ?? 'Staff').' '.$verb.$bulkRequest->id,
                $bulkRequest,
                [
                    'bulk_site_request_id' => $bulkRequest->id,
                    'publisher_id' => $bulkRequest->publisher_id,
                    'created_count' => $created,
                    'failed_count' => count($failures),
                    'domains' => $createdDomains,
                    'source' => $action === 'bulk_request.done' ? 'done' : 'seed',
                    'done_mode' => $sentForReview ? 'review' : 'publish',
                ],
                'Bulk request #'.$bulkRequest->id
            );

            try {
                if ($publisher?->email && $fresh) {
                    Mail::to($publisher->email)->send(
                        $sentForReview
                            ? new BulkSitesReadyForPublisherReview($fresh, $created, $publisher, $createdDomains)
                            : new BulkSitesSeededNotification($fresh, $created, $publisher, $createdDomains)
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to email publisher after bulk Done: '.$e->getMessage());
            }

            try {
                if ($fresh) {
                    $notifier = app(InAppNotificationService::class);
                    if ($sentForReview) {
                        $notifier->notifyPublisherBulkSitesReadyForReview($fresh, $created);
                    } else {
                        $notifier->notifyPublisherBulkSitesAdded($fresh, $created);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send in-app bulk Done notice: '.$e->getMessage());
            }
        }

        if ($deletedCount > 0) {
            $note = trim((string) $rejectionNote);
            ActivityLogger::tryLog(
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
            $parts[] = $doneMode === 'review'
                ? "{$created} site(s) were sent to the publisher for review. They are not live yet."
                : "{$headline} — {$created} site(s) are now active on the publisher’s account (not verified). Publisher notified (email + in-app).";
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
            try {
                $bulkRequest->refreshProgressStatus();
                $bulkRequest->refresh();
            } catch (\Throwable $e) {
                Log::warning('Bulk request post-Done status refresh failed', [
                    'bulk_site_request_id' => $bulkRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
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

        return back()
            ->with($didWork ? 'success' : 'error', $message)
            ->with('seed_failures', $failures);
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
    private function classifyDoneRowFill(array $row, $item, int $bulkId): string
    {
        $itemId = $item ? (int) $item->id : 0;
        $missing = $this->missingDoneRowFields($row, $bulkId, $itemId);
        if ($missing === []) {
            return 'complete';
        }

        return $this->doneRowStarted($row, $item) ? 'partial' : 'empty';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function missingDoneRowFields(array $row, int $bulkId = 0, int $itemId = 0): array
    {
        $missing = [];
        foreach ($this->doneRowFields() as $field) {
            if (! $this->doneRowFieldFilled($row, $field, $bulkId, $itemId)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function doneRowFields(): array
    {
        return [
            'language',
            'country',
            'da',
            'dr',
            'traffic',
            'categories',
            'example_url',
            'turnaround_time',
            'publication_time',
            'link_type',
            'site_tag',
            'description',
            'site_image',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function doneRowStarted(array $row, $item = null): bool
    {
        if (trim((string) ($row['description'] ?? '')) !== '') {
            return true;
        }

        $domain = strtolower(trim((string) ($item?->domain ?? '')));
        $siteName = strtolower(trim((string) ($row['site_name'] ?? '')));
        if ($siteName !== '' && $siteName !== $domain) {
            return true;
        }

        $defaultPrice = $item ? (float) $item->price : null;
        $priceRaw = $row['price'] ?? null;
        if ($priceRaw !== null && $priceRaw !== '' && is_numeric($priceRaw) && $defaultPrice !== null && abs((float) $priceRaw - $defaultPrice) > 0.0001) {
            return true;
        }

        foreach ($this->doneRowFields() as $field) {
            if ($field === 'description') {
                continue;
            }
            if ($this->doneRowFieldFilled($row, $field, 0, $item ? (int) $item->id : 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function doneRowFieldFilled(array $row, string $field, int $bulkId = 0, int $itemId = 0): bool
    {
        if ($field === 'categories') {
            return $this->parseCategoryList($row['categories'] ?? []) !== [];
        }

        if ($field === 'site_image') {
            $file = $row['site_image'] ?? null;
            if ($file instanceof UploadedFile && $file->isValid()) {
                return true;
            }

            return $bulkId > 0 && $itemId > 0 && $this->keptCoverPath($bulkId, $itemId, $row) !== null;
        }

        if ($field === 'description') {
            return SiteDescriptionRules::isValid(trim((string) ($row['description'] ?? '')));
        }

        if ($field === 'site_tag') {
            return in_array(strtolower(trim((string) ($row['site_tag'] ?? ''))), ['none', 'sponsored', 'partner_material', 'as_you_prefer'], true);
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
                    'errors' => ['Need url, price, DA, DR, traffic, country, language, site name, example URL, turnaround, publication, link type, tag, niches, and description.'],
                ];

                continue;
            }

            [$urlRaw, $priceRaw, $daRaw, $drRaw, $trafficRaw, $countryRaw, $langRaw] = array_slice($parts, 0, 7);
            $siteName = isset($parts[7]) && $parts[7] !== '' ? $parts[7] : null;
            $exampleRaw = $parts[8] ?? '';
            $turnaroundRaw = strtolower($parts[9] ?? '');
            $publicationRaw = strtolower($parts[10] ?? '');
            $linkTypeRaw = strtolower($parts[11] ?? '');
            $tagRaw = strtolower($parts[12] ?? '');
            $nichesRaw = $parts[13] ?? '';
            $descriptionRaw = count($parts) >= 15
                ? trim(implode(',', array_slice($parts, 14)))
                : '';

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

            $categories = [];
            $exampleUrl = '';
            if (count($parts) < 15) {
                $errors[] = 'Add site name, example URL, turnaround (24h, 48h, 3days, 5days, or 7days), publication (6months, 1year, or permanent), link type (dofollow or nofollow), tag (none, sponsored, partner_material, or as_you_prefer), niches separated by |, and a description of at least '.SiteDescriptionRules::MIN_CHARS.' characters.';
            } else {
                $exampleUrl = $this->normalizeHttpUrl($exampleRaw);
                if (filter_var($exampleUrl, FILTER_VALIDATE_URL) === false || strlen($exampleUrl) > 255) {
                    $errors[] = 'Invalid example URL';
                }
                if (! in_array($turnaroundRaw, ['24h', '48h', '3days', '5days', '7days'], true)) {
                    $errors[] = 'Invalid turnaround';
                }
                if (! in_array($publicationRaw, ['6months', '1year', 'permanent'], true)) {
                    $errors[] = 'Invalid publication time';
                }
                if (! in_array($linkTypeRaw, ['dofollow', 'nofollow'], true)) {
                    $errors[] = 'Invalid link type';
                }
                if (! in_array($tagRaw, ['none', 'sponsored', 'partner_material', 'as_you_prefer'], true)) {
                    $errors[] = 'Invalid listing tag';
                }
                $resolved = Category::resolveNicheNames($nichesRaw);
                $categories = $resolved['resolved'];
                if ($categories === [] && $resolved['unknown'] === []) {
                    $errors[] = 'Select at least one niche';
                } elseif (count($categories) > 7) {
                    $errors[] = 'Select at most 7 niches';
                }
                foreach ($resolved['unknown'] as $cat) {
                    $errors[] = 'Unknown niche: '.$cat;
                }
                if (! SiteDescriptionRules::isValid($descriptionRaw)) {
                    $errors[] = 'Description must be at least '.SiteDescriptionRules::MIN_CHARS.' characters';
                }
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
                'example_url' => $exampleUrl,
                'turnaround_time' => $turnaroundRaw,
                'publication_time' => $publicationRaw,
                'link_type' => $linkTypeRaw,
                'site_tag' => $tagRaw,
                'categories' => $categories,
                'category' => implode('|', $categories),
                'description' => $descriptionRaw,
            ];
        }

        return compact('rows', 'failures');
    }

    /**
     * File inputs are not in request input. Attach a valid cover upload onto its row.
     *
     * @param  array<mixed>  $inputItems
     * @return array<mixed>
     */
    private function attachDoneRowImages(array $inputItems, Request $request): array
    {
        $files = $request->file('items');
        if (! is_array($files)) {
            return $inputItems;
        }

        foreach ($files as $itemId => $fileRow) {
            if (! is_array($fileRow)) {
                continue;
            }
            $image = $fileRow['site_image'] ?? null;
            if (! $image instanceof UploadedFile) {
                continue;
            }
            $key = array_key_exists($itemId, $inputItems)
                ? $itemId
                : (array_key_exists((string) $itemId, $inputItems) ? (string) $itemId : $itemId);
            if (! isset($inputItems[$key]) || ! is_array($inputItems[$key])) {
                $inputItems[$key] = [];
            }
            $inputItems[$key]['site_image'] = $image;
        }

        return $inputItems;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function doneSensitivePrices(array $row): ?array
    {
        $flags = is_array($row['sensitive'] ?? null) ? $row['sensitive'] : [];
        $amounts = is_array($row['price_sensitive'] ?? null) ? $row['price_sensitive'] : [];
        $prices = [];
        foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
            $flag = $flags[$topic] ?? null;
            if (in_array($flag, [null, '', '0', 0, false], true)) {
                continue;
            }
            $prices[$topic] = $amounts[$topic] ?? null;
        }

        return $prices === [] ? null : $prices;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, float>|null
     */
    private function doneHomepagePrices(array $row): ?array
    {
        $flags = is_array($row['homepage'] ?? null) ? $row['homepage'] : [];
        $amounts = is_array($row['price_homepage'] ?? null) ? $row['price_homepage'] : [];
        $out = [];
        foreach (config('site_placement.homepage_days', [1, 7, 30]) as $days) {
            $flag = $flags[$days] ?? $flags[(string) $days] ?? null;
            if (in_array($flag, [null, '', '0', 0, false], true)) {
                continue;
            }
            $price = $amounts[$days] ?? $amounts[(string) $days] ?? null;
            if ($price === null || $price === '' || ! is_numeric($price)) {
                continue;
            }
            $out[(string) $days] = (float) $price;
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, true>|null
     */
    private function doneSocialPromotion(array $row): ?array
    {
        $flags = is_array($row['social'] ?? null) ? $row['social'] : [];
        $channels = [];
        foreach (config('site_placement.social_channels', ['facebook', 'instagram', 'x']) as $channel) {
            $flag = $flags[$channel] ?? null;
            if (! in_array($flag, [null, '', '0', 0, false], true)) {
                $channels[$channel] = true;
            }
        }

        return $channels === [] ? null : $channels;
    }

    private function coverSessionKey(int $bulkId): string
    {
        return 'bulk_done_covers.'.$bulkId;
    }

    private function draftCacheKey(int $bulkId): string
    {
        return 'bulk-done-text:'.$bulkId;
    }

    private function isBulkDraftCoverPath(int $bulkId, string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return $path !== ''
            && ! str_contains($path, '..')
            && str_starts_with($path, 'bulk-drafts/'.$bulkId.'/');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function keptCoverPath(int $bulkId, int $itemId, array $row): ?string
    {
        $claimed = trim((string) ($row['kept_image'] ?? ''));
        if ($claimed === '' || ! $this->isBulkDraftCoverPath($bulkId, $claimed)) {
            return null;
        }

        $stored = session($this->coverSessionKey($bulkId), []);
        $entry = is_array($stored) ? ($stored[$itemId] ?? $stored[(string) $itemId] ?? null) : null;
        if (! is_array($entry) || (string) ($entry['path'] ?? '') !== $claimed) {
            return null;
        }

        $storedAt = (int) ($entry['stored_at'] ?? 0);
        if ($storedAt > 0 && $storedAt < now()->subDay()->getTimestamp()) {
            return null;
        }

        return Storage::disk('public')->exists($claimed) ? $claimed : null;
    }

    /**
     * @param  array<mixed>  $inputItems
     */
    private function stashDoneCovers(int $bulkId, array $inputItems): void
    {
        $kept = session($this->coverSessionKey($bulkId), []);
        if (! is_array($kept)) {
            $kept = [];
        }

        foreach ($inputItems as $itemId => $row) {
            if (! is_array($row)) {
                continue;
            }
            $itemId = (int) $itemId;
            $image = $row['site_image'] ?? null;
            if (! $image instanceof UploadedFile || ! $image->isValid()) {
                continue;
            }
            $path = app(ImageOptimizationService::class)->storeSafePublicImage($image, 'bulk-drafts/'.$bulkId);
            if (! is_string($path) || $path === '') {
                continue;
            }
            $previous = $kept[$itemId]['path'] ?? null;
            if (is_string($previous) && $previous !== $path) {
                $this->deletePublicFile($previous);
            }
            $kept[$itemId] = [
                'path' => $path,
                'name' => $image->getClientOriginalName(),
                'stored_at' => now()->getTimestamp(),
            ];
        }

        session([$this->coverSessionKey($bulkId) => $this->pruneKeptCovers($bulkId, $kept)]);
    }

    /**
     * @return array<int, array{path:string,name:string,stored_at:int}>
     */
    private function freshKeptCovers(int $bulkId): array
    {
        $kept = session($this->coverSessionKey($bulkId), []);
        if (! is_array($kept)) {
            return [];
        }
        $fresh = $this->pruneKeptCovers($bulkId, $kept);
        session([$this->coverSessionKey($bulkId) => $fresh]);

        return $fresh;
    }

    /**
     * @param  array<mixed>  $kept
     * @return array<int, array{path:string,name:string,stored_at:int}>
     */
    private function pruneKeptCovers(int $bulkId, array $kept): array
    {
        $cutoff = now()->subDay()->getTimestamp();
        $fresh = [];
        foreach ($kept as $itemId => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $path = (string) ($entry['path'] ?? '');
            $storedAt = (int) ($entry['stored_at'] ?? 0);
            if ($path === '' || ! $this->isBulkDraftCoverPath($bulkId, $path) || ($storedAt > 0 && $storedAt < $cutoff) || ! Storage::disk('public')->exists($path)) {
                $this->deletePublicFile($path);

                continue;
            }
            $fresh[(int) $itemId] = [
                'path' => $path,
                'name' => (string) ($entry['name'] ?? basename($path)),
                'stored_at' => $storedAt,
            ];
        }

        return $fresh;
    }

    private function forgetKeptCover(int $bulkId, int $itemId): void
    {
        if ($itemId < 1) {
            return;
        }
        $kept = session($this->coverSessionKey($bulkId), []);
        if (! is_array($kept)) {
            return;
        }
        unset($kept[$itemId], $kept[(string) $itemId]);
        session([$this->coverSessionKey($bulkId) => $kept]);
    }

    private function dropKeptCover(int $bulkId, int $itemId): void
    {
        if ($itemId < 1) {
            return;
        }
        $kept = session($this->coverSessionKey($bulkId), []);
        $entry = is_array($kept) ? ($kept[$itemId] ?? $kept[(string) $itemId] ?? null) : null;
        if (is_array($entry) && is_string($entry['path'] ?? null)) {
            $this->deletePublicFile($entry['path']);
        }
        $this->forgetKeptCover($bulkId, $itemId);
    }

    private function promoteKeptCover(string $path): ?string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'webp');
        $dest = 'sites/'.Str::uuid()->toString().'.'.$ext;
        try {
            $disk->move($path, $dest);
        } catch (\Throwable $e) {
            Log::warning('Bulk draft cover promote failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return $dest;
    }

    private function deletePublicFile(string $path): void
    {
        if ($path === '' || str_contains($path, '..')) {
            return;
        }
        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            Log::notice('Bulk draft cover delete skipped', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    public function saveDraft(Request $request, int $id)
    {
        BulkSiteRequest::query()->findOrFail($id);
        $payload = $request->validate([
            'items' => 'nullable|array',
            'rejected' => 'nullable|array',
            'rejection_note' => 'nullable|string|max:1000',
            'forget_covers' => 'nullable|array',
            'forget_covers.*' => 'integer',
        ]);
        foreach ($payload['forget_covers'] ?? [] as $itemId) {
            $this->dropKeptCover($id, (int) $itemId);
        }
        $this->rememberTextDraftPayload($id, [
            'items' => is_array($payload['items'] ?? null) ? $payload['items'] : [],
            'rejected' => is_array($payload['rejected'] ?? null) ? $payload['rejected'] : [],
            'rejection_note' => (string) ($payload['rejection_note'] ?? ''),
        ]);

        return response()->json(['ok' => true]);
    }

    private function rememberTextDraft(int $bulkId, Request $request): void
    {
        $items = $request->input('items', []);
        if (! is_array($items)) {
            $items = [];
        }
        foreach ($items as $itemId => $row) {
            if (! is_array($row)) {
                unset($items[$itemId]);

                continue;
            }
            unset($row['site_image'], $row['kept_image']);
            $items[$itemId] = $row;
        }
        $this->rememberTextDraftPayload($bulkId, [
            'items' => $items,
            'rejected' => $request->input('rejected_item_ids', []),
            'rejection_note' => (string) $request->input('rejection_note', ''),
        ]);
    }

    /**
     * @param  array{items?:array,rejected?:array,rejection_note?:string}  $payload
     */
    private function rememberTextDraftPayload(int $bulkId, array $payload): void
    {
        $existing = $this->loadTextDraft($bulkId);
        $items = is_array($existing['items'] ?? null) ? $existing['items'] : [];
        $incoming = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($incoming as $itemId => $row) {
            if (! is_array($row)) {
                continue;
            }
            $items[$itemId] = $row;
        }
        Cache::put($this->draftCacheKey($bulkId), [
            'items' => $items,
            'rejected' => array_values($payload['rejected'] ?? []),
            'rejection_note' => (string) ($payload['rejection_note'] ?? ''),
            'saved_at' => now()->getTimestamp(),
        ], now()->addDays(7));
    }

    /**
     * @return array{items:array,rejected:array,rejection_note:string}
     */
    private function loadTextDraft(int $bulkId): array
    {
        $draft = Cache::get($this->draftCacheKey($bulkId));
        if (! is_array($draft)) {
            return ['items' => [], 'rejected' => [], 'rejection_note' => ''];
        }
        $savedAt = (int) ($draft['saved_at'] ?? 0);
        if ($savedAt > 0 && $savedAt < now()->subDays(7)->getTimestamp()) {
            Cache::forget($this->draftCacheKey($bulkId));

            return ['items' => [], 'rejected' => [], 'rejection_note' => ''];
        }

        return [
            'items' => is_array($draft['items'] ?? null) ? $draft['items'] : [],
            'rejected' => is_array($draft['rejected'] ?? null) ? $draft['rejected'] : [],
            'rejection_note' => (string) ($draft['rejection_note'] ?? ''),
        ];
    }

    private function forgetDraftItem(int $bulkId, int $itemId): void
    {
        if ($itemId < 1) {
            return;
        }
        $draft = $this->loadTextDraft($bulkId);
        unset($draft['items'][$itemId], $draft['items'][(string) $itemId]);
        if (($draft['items'] ?? []) === [] && ($draft['rejected'] ?? []) === [] && trim((string) ($draft['rejection_note'] ?? '')) === '') {
            Cache::forget($this->draftCacheKey($bulkId));

            return;
        }
        Cache::put($this->draftCacheKey($bulkId), $draft + ['saved_at' => now()->getTimestamp()], now()->addDays(7));
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
