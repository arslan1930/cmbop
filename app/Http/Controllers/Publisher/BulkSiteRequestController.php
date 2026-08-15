<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\BulkSiteRequestSubmitted;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use App\Services\SiteDescriptionSanitizer;
use App\Support\SiteDescriptionRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BulkSiteRequestController extends Controller
{
    public function store(Request $request)
    {
        $open = $this->openBlockingBulkRequest((int) auth()->id());
        if ($open) {
            return $this->redirectBecauseBulkAlreadyOpen($open);
        }

        $maxSites = BulkSiteRequest::MAX_SITES_PER_REQUEST;

        $validator = Validator::make($request->all(), [
            'sites' => 'required|array|min:2|max:'.$maxSites,
            'sites.*.url' => 'nullable|string|max:512',
            'sites.*.price' => 'nullable|numeric|min:0|max:999999.99',
            'publisher_note' => 'nullable|string|max:2000',
        ], [
            'sites.required' => 'Add at least two websites (URL + price).',
            'sites.min' => 'Add at least two websites (URL + price).',
            'sites.max' => "You can submit at most {$maxSites} websites at once.",
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
                $domain = is_string($host) && $host !== ''
                    ? Site::normalizeMarketplaceDomain($host)
                    : '';

                if ($domain === '' || ! filter_var($siteUrl, FILTER_VALIDATE_URL)) {
                    $validator->errors()->add("sites.$index.url", 'Enter a valid website URL.');

                    continue;
                }

                if (isset($seenDomains[$domain])) {
                    $validator->errors()->add("sites.$index.url", "Duplicate domain in this list: {$domain}");

                    continue;
                }
                $seenDomains[$domain] = true;

                $existing = Site::findOccupyingDomain($domain);
                if ($existing) {
                    $validator->errors()->add(
                        "sites.$index.url",
                        $existing->isArchived()
                            ? $existing->occupyingDomainMessage()
                            : "Already registered: {$domain}"
                    );

                    continue;
                }

                $pending = BulkSiteRequestItem::occupyingPendingDomainMessage($domain);
                if ($pending !== null) {
                    $validator->errors()->add("sites.$index.url", $pending);

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
            // Serialize per publisher so two concurrent submits cannot each pass
            // the pre-transaction blocking check and open duplicate batches (or
            // duplicate pending rows for the same domain on different bulk ids).
            $publisherId = (int) auth()->id();
            User::query()->whereKey($publisherId)->lockForUpdate()->first();

            if (BulkSiteRequest::query()
                ->where('publisher_id', $publisherId)
                ->blockingPublisher()
                ->exists()) {
                throw ValidationException::withMessages([
                    'sites' => ['You already have an open bulk request. Wait for our team to finish it, or message support.'],
                ]);
            }

            foreach ($parsedRows as $row) {
                $occupied = Site::findOccupyingDomain($row['domain'], lock: true);
                if ($occupied) {
                    throw ValidationException::withMessages([
                        'sites' => [$occupied->occupyingDomainMessage()],
                    ]);
                }
                $pending = BulkSiteRequestItem::occupyingPendingDomainMessage($row['domain'], lock: true);
                if ($pending !== null) {
                    throw ValidationException::withMessages([
                        'sites' => [$pending],
                    ]);
                }
            }

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
                if (empty($admin->email)) {
                    continue;
                }
                $openUrl = $admin instanceof User
                    ? route(staff_route_prefix_for($admin).'bulk-site-requests.show', $bulk)
                    : route('admin.bulk-site-requests.show', $bulk);
                Mail::to($admin->email)->send(new BulkSiteRequestSubmitted(
                    $bulk->load('items'),
                    $openUrl,
                    $admin instanceof User ? $admin : null
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email admins about bulk site request: '.$e->getMessage());
        }

        try {
            app(InAppNotificationService::class)->notifyStaffBulkSiteRequestSubmitted($bulk->load('items', 'publisher'));
        } catch (\Throwable $e) {
            Log::warning('Failed to send in-app bulk request notice: '.$e->getMessage());
        }

        // State the count. A browser bug once dropped every row past the second
        // before the POST left the page, and a generic "submitted" message let
        // that look like success — the publisher only found out much later.
        $saved = count($parsedRows);

        return redirect()
            ->route('publisher.websites', ['status' => 'pending'])
            ->with('success', $saved.' website'.($saved === 1 ? '' : 's').' submitted (URL + price). They appear under Pending while our marketer prepares them; then you’ll finish descriptions and listing details; we approve.');
    }

    public function completeIndex()
    {
        $sites = Site::query()
            ->where('publisher_id', auth()->id())
            ->notFromCancelledBulk()
            ->notArchived()
            ->whereIn('onboarding_status', [
                Site::ONBOARDING_AWAITING_DETAILS,
                Site::ONBOARDING_DETAILS_COMPLETE,
            ])
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn (Site $s) => $s->awaitsPublisherDetails() ? 0 : 1)
            ->values();

        $openRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('status', [
                BulkSiteRequest::STATUS_SEEDED,
                BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            ])
            ->latest()
            ->first();

        $detailsCompleteCount = $sites->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)->count();
        $awaitingCount = $sites->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)->count();

        return view('publisher.bulk-complete', compact(
            'sites',
            'openRequest',
            'detailsCompleteCount',
            'awaitingCount'
        ));
    }

    public function completeStore(Request $request, int $id)
    {
        $site = Site::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('onboarding_status', [
                Site::ONBOARDING_AWAITING_DETAILS,
                Site::ONBOARDING_DETAILS_COMPLETE,
            ])
            ->findOrFail($id);

        if ($site->bulkSiteRequest?->isCancelled()) {
            return redirect()
                ->route('publisher.websites', ['status' => 'pending'])
                ->with('error', 'This bulk request was cancelled. Those sites will not be prepared.');
        }

        if (! $this->siteStillCompletable($site)) {
            return redirect()
                ->route('publisher.websites', ['status' => 'pending'])
                ->with('error', 'This site is no longer waiting for details. It may already be in review.');
        }

        if ($request->filled('exampleUrl')) {
            $request->merge([
                'exampleUrl' => $this->normalizeHttpUrl($request->input('exampleUrl')),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'exampleUrl' => 'required|url|max:255',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string',
            'site_tag' => 'nullable|in:sponsored,partner_material,as_you_prefer',
            'price_sensitive.*' => 'nullable|numeric|min:0|max:99999999.99',
        ]);

        $existingCategories = collect($site->categories ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '' && strtolower($v) !== 'pending')
            ->values()
            ->all();

        $validator->after(function ($validator) use ($existingCategories) {
            if ($existingCategories === []) {
                $validator->errors()->add(
                    'categories',
                    'Niches are missing for this site. Contact support so marketing can add them before you submit.'
                );
            }
        });

        $validator->after(function ($validator) use ($request) {
            $rawDescription = $request->input('siteDescription', '');
            if (! is_string($rawDescription)) {
                return;
            }
            foreach (SiteDescriptionRules::errors($rawDescription) as $message) {
                $validator->errors()->add('siteDescription', $message);
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

        $blockedCancelled = false;
        $blockedUnavailable = false;
        try {
            DB::transaction(function () use ($site, $request, $cleanDescription, $existingCategories, &$blockedCancelled, &$blockedUnavailable) {
                $locked = Site::query()->whereKey($site->id)->lockForUpdate()->first();
                if (! $locked || $locked->bulkSiteRequest?->isCancelled()) {
                    $blockedCancelled = true;

                    return;
                }

                // A concurrent Review & submit (or staff verify) must not be
                // rewound to details_complete or have verified/active cleared.
                if (! $this->siteStillCompletable($locked)) {
                    $blockedUnavailable = true;

                    return;
                }

                $sensitivePrices = [];
                foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                    if ($request->input("sensitive.$topic")) {
                        $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                    }
                }

                $locked->applyMarketplaceListing([
                    'example_url' => $request->exampleUrl,
                    // Niches were set by marketing during Done / metrics edit — keep them.
                    'category' => Site::fitCategoryColumn(implode('|', $existingCategories), $existingCategories),
                    'categories' => $existingCategories,
                    'turnaround_time' => $request->turnaround_time,
                    'publication_time' => $request->publicationTime,
                    'link_type' => $request->link_type,
                    'description' => $cleanDescription,
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                    'verified' => false,
                    'active' => false,
                ]);

                $tag = $request->input('site_tag', 'as_you_prefer');
                $locked->sponsored = $tag === 'sponsored';
                $locked->partner_material = $tag === 'partner_material';
                $locked->as_you_prefer = $tag === 'as_you_prefer' || $tag === null || $tag === '';

                // Saved for Review & submit — not yet in the admin queue.
                // Persist listing fields first, then set onboarding_status safely.
                $locked->save();

                if (! $locked->markDetailsComplete()) {
                    throw new \RuntimeException('onboarding_status details_complete rejected by database');
                }
            });
        } catch (\Throwable $e) {
            Log::error('Publisher bulk completeStore failed', [
                'site_id' => $site->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            $hint = 'We could not save your website details. Please try again.';
            if (str_contains($e->getMessage(), 'onboarding_status')
                || str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'Data too long')) {
                $hint = 'We could not save because the database is missing a recent update. Please contact support.';
            }

            return redirect()
                ->route('publisher.bulk-sites.complete')
                ->withErrors(['siteDescription' => $hint])
                ->withInput()
                ->with('complete_site_id', $site->id);
        }

        if ($blockedCancelled) {
            return redirect()
                ->route('publisher.websites', ['status' => 'pending'])
                ->with('error', 'This bulk request was cancelled. Those sites will not be prepared.');
        }

        if ($blockedUnavailable) {
            return redirect()
                ->route('publisher.websites', ['status' => 'pending'])
                ->with('error', 'This site is no longer waiting for details. It may already be in review.');
        }

        $site->refresh();
        if ($site->bulk_site_request_id) {
            $site->bulkSiteRequest?->refreshProgressStatus();
        }

        $remainingAwaiting = Site::query()
            ->where('publisher_id', auth()->id())
            ->notFromCancelledBulk()
            ->notArchived()
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->count();

        if ($remainingAwaiting > 0) {
            return redirect()
                ->route('publisher.bulk-sites.complete')
                ->with('success', '“'.$site->site_name.'” saved. Finish the remaining sites, then review & submit.');
        }

        return redirect()
            ->route('publisher.bulk-sites.review')
            ->with('success', '“'.$site->site_name.'” saved. Review your sites below, then submit for admin review.');
    }

    /**
     * Final checklist before sites enter the admin review queue.
     */
    public function reviewIndex()
    {
        $sites = Site::query()
            ->where('publisher_id', auth()->id())
            ->notFromCancelledBulk()
            ->notArchived()
            ->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)
            ->orderByDesc('id')
            ->get();

        $awaitingCount = Site::query()
            ->where('publisher_id', auth()->id())
            ->notFromCancelledBulk()
            ->notArchived()
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->count();

        $openRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('status', [
                BulkSiteRequest::STATUS_SEEDED,
                BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            ])
            ->latest()
            ->first();

        return view('publisher.bulk-review', compact('sites', 'awaitingCount', 'openRequest'));
    }

    /**
     * Submit selected (or all) details_complete sites for admin review.
     */
    public function submitForReview(Request $request)
    {
        $validated = $request->validate([
            'site_ids' => 'nullable|array',
            'site_ids.*' => 'integer',
            'submit_all' => 'nullable|boolean',
        ]);

        $query = Site::query()
            ->where('publisher_id', auth()->id())
            ->notFromCancelledBulk()
            ->notArchived()
            ->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE);

        if (! ($validated['submit_all'] ?? false)) {
            $ids = array_values(array_unique(array_map('intval', $validated['site_ids'] ?? [])));
            if ($ids === []) {
                return redirect()
                    ->route('publisher.bulk-sites.review')
                    ->with('error', 'Select at least one site to submit, or use Submit all.');
            }
            $query->whereIn('id', $ids);
        }

        $sites = $query->with('bulkSiteRequest')->get();
        if ($sites->isEmpty()) {
            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('error', 'No sites ready to submit. Complete details first.');
        }

        $submitted = 0;
        $bulkIds = [];

        try {
            DB::transaction(function () use ($sites, &$submitted, &$bulkIds) {
                foreach ($sites as $site) {
                    if ($site->bulkSiteRequest?->isCancelled()) {
                        continue;
                    }

                    if (! $site->hasCompletedPublisherDetails()) {
                        continue;
                    }

                    if (! $site->markReadyForAdminReview()) {
                        continue;
                    }

                    $submitted++;

                    if ($site->bulk_site_request_id) {
                        $bulkIds[$site->bulk_site_request_id] = true;
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('Publisher bulk submitForReview failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('error', 'We could not submit your sites for review. Please try again or contact support if this continues.');
        }

        foreach (array_keys($bulkIds) as $bulkId) {
            BulkSiteRequest::find($bulkId)?->refreshProgressStatus();
        }

        $emails = app(EmailNotificationService::class);
        foreach ($sites as $site) {
            $site->refresh();
            if (! $site->isReadyForAdminReview() || $site->hasDetailsComplete() || $site->awaitsPublisherDetails()) {
                continue;
            }
            try {
                $emails->notifyAdminsNewSite($site, 'create');
            } catch (\Throwable $e) {
                Log::warning('Failed admin notify for bulk review submit: '.$e->getMessage());
            }
        }

        if ($submitted === 0) {
            $cancelled = $sites->contains(fn (Site $site) => $site->bulkSiteRequest?->isCancelled());

            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('error', $cancelled
                    ? 'This bulk request was cancelled. Those sites will not be prepared.'
                    : 'None of the selected sites have complete details yet.');
        }

        return redirect()
            ->route('publisher.websites', ['status' => 'pending'])
            ->with('success', $submitted === 1
                ? '1 site submitted for admin review — it stays in Pending until approved.'
                : $submitted.' sites submitted for admin review — they stay in Pending until approved.');
    }

    private function openBlockingBulkRequest(int $publisherId): ?BulkSiteRequest
    {
        return BulkSiteRequest::openBlockingForPublisher($publisherId);
    }

    private function redirectBecauseBulkAlreadyOpen(?BulkSiteRequest $open)
    {
        $publisherOwesWork = $open && $open->pendingPublisherCount() > 0;

        $message = $publisherOwesWork
            ? 'Finish your pending sites under Complete details before submitting another bulk request.'
            : 'You already have an open bulk request. Wait for our team to finish it, or message support.';

        return redirect()
            ->route('publisher.websites')
            ->with('error', $message);
    }

    /**
     * Listing fields may still be edited. Ready-for-review / live / archived
     * rows must not be rewound or unverified by a stale Complete form.
     */
    private function siteStillCompletable(Site $site): bool
    {
        if ($site->isArchived() || $site->bulkSiteRequest?->isCancelled()) {
            return false;
        }

        if ((bool) $site->verified || (bool) $site->active) {
            return false;
        }

        return in_array($site->onboarding_status, [
            Site::ONBOARDING_AWAITING_DETAILS,
            Site::ONBOARDING_DETAILS_COMPLETE,
        ], true);
    }

    private function normalizeHttpUrl(mixed $url): string
    {
        if (is_array($url)) {
            $flat = [];
            array_walk_recursive($url, function ($item) use (&$flat) {
                if (is_scalar($item)) {
                    $flat[] = $item;
                }
            });
            $url = $flat[0] ?? '';
        }

        if (! is_scalar($url) && $url !== null) {
            return '';
        }

        $url = trim((string) $url);
        if ($url === '') {
            return $url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
