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
use App\Services\SiteClaimTransferService;
use App\Services\SiteDescriptionSanitizer;
use App\Services\SiteEnrichment\ImageOptimizationService;
use App\Support\MarketingOpsQueues;
use App\Support\SiteDescriptionRules;
use App\Support\SiteImageUpload;
use App\Support\SiteTag;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BulkSiteRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = search_text($request->input('status'));
        $selectedStatus = $status !== '' ? $status : 'all';

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

            $query = BulkSiteRequest::query()
                ->with(['publisher', 'handler'])
                ->withCount($withCount)
                ->latest();

            MarketingOpsQueues::applyBulkIndexStatus($query, $status);

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
            'filtersActive' => $selectedStatus !== 'all',
            'waitingOnYouCount' => $waitingOnYouCount,
        ]);
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

                $description = $this->plainListingDescription((string) ($row['description'] ?? ''));
                foreach (SiteDescriptionRules::errors($description) as $message) {
                    $validator->errors()->add('items.'.$itemId.'.description', $message);
                }

                $image = $row['site_image'] ?? null;
                if (! $image instanceof UploadedFile || ! $image->isValid()) {
                    $validator->errors()->add(
                        'items.'.$itemId.'.site_image',
                        'Upload a site image (JPEG, PNG, GIF, or WebP, up to '.SiteImageUpload::maxMegabytesLabel().' MB).'
                    );
                } else {
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
                'description' => $this->plainListingDescription((string) ($row['description'] ?? '')),
                'example_url' => $this->normalizeHttpUrl((string) ($row['example_url'] ?? '')),
                'turnaround_time' => (string) ($row['turnaround_time'] ?? ''),
                'publication_time' => (string) ($row['publication_time'] ?? ''),
                'link_type' => (string) ($row['link_type'] ?? ''),
                'site_tag' => (string) ($row['site_tag'] ?? ''),
                'sensitive_prices' => $this->doneSensitivePrices($row),
                'site_image_file' => $row['site_image'] ?? null,
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

        foreach ($parsed['rows'] as $row) {
            $parsed['failures'][] = [
                'line' => $row['line'] ?? 0,
                'url' => $row['site_url'] ?? ($row['domain'] ?? ''),
                'errors' => ['Upload the site image on Done. A pasted row cannot include the cover, so this website was not published.'],
            ];
        }
        $parsed['rows'] = [];

        if ($parsed['rows'] === []) {
            return back()
                ->with('error', $parsed['failures'] === []
                    ? 'No rows found. Paste one site per line: url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description'
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
            &$deletedDomains
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
                    $this->plainListingDescription((string) ($row['description'] ?? ''))
                );
                if (! SiteDescriptionRules::isValid($description)) {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => ['A description of at least '.SiteDescriptionRules::MIN_CHARS.' characters is required.'],
                    ];

                    continue;
                }

                $imageFile = $row['site_image_file'] ?? null;
                $imagePath = $imageFile instanceof UploadedFile
                    ? app(ImageOptimizationService::class)->storeSafePublicImage($imageFile, 'sites')
                    : null;
                if (! is_string($imagePath) || $imagePath === '') {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => ['Upload a site image before this website goes live.'],
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

                $site = new Site;
                $site->applyMarketplaceListing(array_merge([
                    'publisher_id' => $bulkRequest->publisher_id,
                    'bulk_site_request_id' => $bulkRequest->id,
                    'publisher_accepted_at' => now(),
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
                    'site_image' => $imagePath,
                    'verified' => false,
                    'active' => true,
                    'enrichment_status' => 'pending',
                    'onboarding_status' => null,
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
            $verb = $action === 'bulk_request.done'
                ? 'marked Done and published'
                : 'published';

            ActivityLogger::tryLog(
                $action,
                (auth()->user()->name ?? 'Staff').' '.$verb.' '.$created.' active site(s) on bulk request #'.$bulkRequest->id,
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
            $parts[] = "{$headline} — {$created} site(s) are now active on the publisher’s account (not verified). Publisher notified (email + in-app).";
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
    private function classifyDoneRowFill(array $row): string
    {
        $missing = $this->missingDoneRowFields($row);
        if ($missing === []) {
            return 'complete';
        }

        return $this->doneRowStarted($row) ? 'partial' : 'empty';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function missingDoneRowFields(array $row): array
    {
        $missing = [];
        foreach ($this->doneRowFields() as $field) {
            if (! $this->doneRowFieldFilled($row, $field)) {
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
    private function doneRowStarted(array $row): bool
    {
        if (trim((string) ($row['description'] ?? '')) !== '') {
            return true;
        }

        foreach ($this->doneRowFields() as $field) {
            if ($field === 'description') {
                continue;
            }
            if ($this->doneRowFieldFilled($row, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function doneRowFieldFilled(array $row, string $field): bool
    {
        if ($field === 'categories') {
            return $this->parseCategoryList($row['categories'] ?? []) !== [];
        }

        if ($field === 'site_image') {
            $file = $row['site_image'] ?? null;

            return $file instanceof UploadedFile && $file->isValid();
        }

        if ($field === 'description') {
            return SiteDescriptionRules::isValid($this->plainListingDescription((string) ($row['description'] ?? '')));
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
                $descriptionRaw = $this->plainListingDescription($descriptionRaw);
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
     * Listing copy is plain text. Drop leftover markdown **bold** markers so
     * they are not stored or shown on the bulk request page.
     */
    private function plainListingDescription(string $raw): string
    {
        $text = SiteDescriptionRules::forTextarea($raw);
        $text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text) ?? $text;

        return str_replace('**', '', $text);
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
