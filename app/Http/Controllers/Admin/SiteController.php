<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Jobs\EnrichSiteJob;
use App\Mail\AdminAssignedSiteNotification;
use App\Mail\PublisherListingNudge;
use App\Mail\SiteStatusNotification;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\SiteAdminNote;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CheckoutSchemaService;
use App\Services\CommunityInboxNotifier;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\SiteDescriptionSanitizer;
use App\Services\SiteEnrichment\ImageOptimizationService;
use App\Support\CatalogHealthQueue;
use App\Support\CatalogPlaceholderListing;
use App\Support\CommunityInbox;
use App\Support\MarketingOpsQueues;
use App\Support\PublicStorageLink;
use App\Support\SiteDescriptionRules;
use App\Support\SiteImageUpload;
use App\Support\SiteTag;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        try {
            return $this->renderSitesIndex($request);
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load sites. Please refresh and try again.')
            );

            return view('admin.sites', [
                'users' => new LengthAwarePaginator([], 0, 20, 1, [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]),
                'unverifiedFilter' => false,
                'needsReviewFilterActive' => false,
                'openReviewCount' => 0,
                'missingMarketCount' => 0,
                'healthCounts' => CatalogHealthQueue::emptyCounts(),
                'waitingOnPublisherFilterActive' => false,
                'waitingOnPublisherCount' => 0,
                'publisherSearch' => trim(scalar_text($request->query('q', ''))),
                'flatQueue' => $request->boolean('flat'),
                'flatQueueSites' => null,
                'healthFilter' => CatalogHealthQueue::fromRequest($request),
                'healthFilterActive' => CatalogHealthQueue::fromRequest($request) !== null,
            ]);
        }
    }

    private function renderSitesIndex(Request $request)
    {
        $needsReviewFilter = $request->boolean('needs_review')
            || $request->query('verified') === '0'
            || $request->query('verified') === 0;

        $waitingOnPublisherFilter = $request->boolean('waiting_on_publisher');
        if ($waitingOnPublisherFilter) {
            $needsReviewFilter = false;
        }

        $archivedFilter = $request->boolean('archived');
        $publisherSearch = trim(scalar_text($request->query('q', '')));
        $healthFilter = CatalogHealthQueue::fromRequest($request);
        $healthFilterActive = $healthFilter !== null;
        $flatQueue = $request->boolean('flat') || $healthFilterActive || $archivedFilter;

        if ($archivedFilter) {
            $healthFilter = null;
            $healthFilterActive = false;
            $needsReviewFilter = false;
            $waitingOnPublisherFilter = false;
        }

        if ($healthFilterActive) {
            $needsReviewFilter = false;
            $waitingOnPublisherFilter = false;
        }

        if ($publisherSearch !== ''
            && ! $request->filled('publisher')
            && ! $request->filled('site')
        ) {
            $exactSite = $this->uniqueStaffSiteForExactSearch($publisherSearch, $archivedFilter);
            if ($exactSite) {
                return redirect()->to(staff_route('sites.index', array_filter([
                    'q' => $publisherSearch,
                    'publisher' => $exactSite->publisher_id,
                    'site' => $exactSite->id,
                    'needs_review' => $needsReviewFilter ? 1 : null,
                    'waiting_on_publisher' => $waitingOnPublisherFilter ? 1 : null,
                    'archived' => $archivedFilter ? 1 : null,
                ], static fn ($value) => $value !== null && $value !== '')));
            }
        }

        $reviewQueue = function ($q) {
            $q->needsAdminReview()->notArchived();
        };

        $unverifiedFilter = $needsReviewFilter;
        $needsReviewFilterActive = $needsReviewFilter;
        $waitingOnPublisherFilterActive = $waitingOnPublisherFilter;
        $openReviewCount = MarketingOpsQueues::sitesReadyForStaffCount();
        $waitingOnPublisherCount = MarketingOpsQueues::sitesWaitingOnPublisherCount();
        $healthCounts = CatalogHealthQueue::counts();
        $missingMarketCount = (int) ($healthCounts[CatalogHealthQueue::MISSING_MARKET] ?? 0);
        $archivedCount = Site::hasSitesColumn('archived_at')
            ? (int) Site::query()->archived()->count()
            : 0;
        $archivedFilterActive = $archivedFilter;
        $flatQueueSites = null;

        if ($archivedFilterActive) {
            $users = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
            $flatQueueSites = Site::query()
                ->archived()
                ->with('publisher:id,name,email')
                ->when(Schema::hasTable('order_items'), fn ($q) => $q->withCount('orderItems'))
                ->orderByDesc('archived_at')
                ->orderByDesc('id');
            $this->applyStaffIndexSiteOrPublisherSearch($flatQueueSites, $publisherSearch);
            $flatQueueSites = $flatQueueSites
                ->paginate(30)
                ->appends($request->query());
        } elseif ($healthFilterActive) {
            $users = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
            $flatQueueSites = CatalogHealthQueue::apply(Site::query(), $healthFilter)
                ->with('publisher:id,name,email')
                ->when(Schema::hasTable('order_items'), fn ($q) => $q->withCount('orderItems'))
                ->orderBy('created_at')
                ->orderBy('id');
            $this->applyStaffIndexSiteOrPublisherSearch($flatQueueSites, $publisherSearch);
            $flatQueueSites = $flatQueueSites
                ->paginate(30)
                ->appends($request->query());
        } elseif ($flatQueue && $waitingOnPublisherFilter) {
            $users = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
            $flatQueueSites = MarketingOpsQueues::sitesWaitingOnPublisher()
                ->with('publisher:id,name,email')
                ->when(Schema::hasTable('order_items'), fn ($q) => $q->withCount('orderItems'))
                ->orderBy('created_at')
                ->orderBy('id');
            $this->applyStaffIndexSiteOrPublisherSearch($flatQueueSites, $publisherSearch);
            $flatQueueSites = $flatQueueSites
                ->paginate(30)
                ->appends($request->query());
        } elseif ($flatQueue && $needsReviewFilter) {
            $users = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
            $flatQueueSites = MarketingOpsQueues::sitesReadyForStaff()
                ->with('publisher:id,name,email')
                ->when(Schema::hasTable('order_items'), fn ($q) => $q->withCount('orderItems'))
                ->orderBy('created_at')
                ->orderBy('id');
            $this->applyStaffIndexSiteOrPublisherSearch($flatQueueSites, $publisherSearch);
            $flatQueueSites = $flatQueueSites
                ->paginate(30)
                ->appends($request->query());
        } else {
            // Counts only — do not eager-load every site row for the publisher list.
            $query = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
                ->withCount(['sites' => fn ($q) => $q->notArchived()])
                ->withCount(['sites as needs_review_sites_count' => $reviewQueue])
                ->withCount(['sites as waiting_on_publisher_sites_count' => function ($q) {
                    MarketingOpsQueues::constrainSitesWaitingOnPublisher($q);
                }]);

            if ($publisherSearch !== '') {
                $query->withCount(['sites as matched_sites_count' => function ($q) use ($publisherSearch) {
                    $q->notArchived();
                    $this->constrainStaffSiteSearch($q, $publisherSearch);
                }]);
                $this->applyStaffPublisherSearch($query, $publisherSearch);
            }

            // Ops queue: publishers with sites ready for admin decision (not unfinished drafts)
            if ($needsReviewFilter) {
                $query->whereHas('sites', $reviewQueue)
                    ->withCount(['sites as unverified_sites_count' => $reviewQueue]);
            }

            if ($waitingOnPublisherFilter) {
                $query->whereHas('sites', function ($q) {
                    MarketingOpsQueues::constrainSitesWaitingOnPublisher($q);
                });
            }

            $users = $query
                ->orderByDesc($waitingOnPublisherFilter ? 'waiting_on_publisher_sites_count' : 'needs_review_sites_count')
                ->orderByDesc('sites_count')
                ->orderBy('name')
                ->paginate(20)
                ->appends($request->query());
        }

        if ($flatQueueSites) {
            $this->hydrateStaffQueueMeta($flatQueueSites->getCollection());
        }

        return view('admin.sites', compact(
            'users',
            'unverifiedFilter',
            'needsReviewFilterActive',
            'waitingOnPublisherFilterActive',
            'archivedFilterActive',
            'archivedCount',
            'openReviewCount',
            'waitingOnPublisherCount',
            'missingMarketCount',
            'healthCounts',
            'publisherSearch',
            'flatQueue',
            'flatQueueSites',
            'healthFilter',
            'healthFilterActive'
        ));
    }

    /**
     * Admin records sheet: all websites with URL, countries, categories only.
     * Always reads live from the sites table.
     * Optional ?country=de (or other ISO code) filters to that market.
     * ?partial=1 or Accept: application/json returns table HTML for live filter swaps.
     */
    public function records(Request $request)
    {
        $filter = $this->recordsFilterState($request);
        $countryFilter = $filter['country'];
        $healthFilter = $filter['health'];
        $missingMarket = $filter['missing_market'];

        $wantsPartial = $request->boolean('partial')
            || $request->expectsJson()
            || str_contains(strtolower((string) $request->header('Accept', '')), 'application/json');

        try {
            $query = Site::query()->orderBy('domain')->orderBy('id');
            $this->applyRecordsFilters($query, $filter);

            $sites = $query
                ->paginate(100)
                ->appends($filter['query_params'])
                ->through(fn (Site $site) => $this->siteRecordRow($site));

            $countryCounts = $this->recordsCountryCounts();
            $totalSites = (int) Site::query()->count();
            $healthCounts = CatalogHealthQueue::counts();
            $missingMarketCount = (int) ($healthCounts[CatalogHealthQueue::MISSING_MARKET] ?? 0);
            $countries = Country::marketplace()
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(function (Country $country) use ($countryCounts) {
                    $code = strtolower(trim((string) $country->code));

                    return [
                        'code' => $code,
                        'name' => (string) $country->name,
                        'count' => (int) ($countryCounts[$code] ?? 0),
                    ];
                })
                ->values();

            $selectedCountry = $countryFilter;
            $exportUrl = route('admin.sites.records.export', $filter['query_params']);
        } catch (\Throwable $e) {
            report($e);

            if ($wantsPartial) {
                return response()->json([
                    'success' => false,
                    'message' => UserFacingError::message($e, 'We could not filter records. Please try again.'),
                ], 500);
            }

            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load site records. Please refresh and try again.')
            );

            $sites = new LengthAwarePaginator([], 0, 100, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
            $countries = collect();
            $selectedCountry = $countryFilter;
            $totalSites = 0;
            $healthCounts = CatalogHealthQueue::emptyCounts();
            $missingMarketCount = 0;
            $exportUrl = route('admin.sites.records.export', $filter['query_params']);

            return view('admin.sites.records', compact(
                'sites',
                'countries',
                'selectedCountry',
                'totalSites',
                'exportUrl',
                'missingMarket',
                'missingMarketCount',
                'healthFilter',
                'healthCounts'
            ));
        }

        if ($wantsPartial) {
            try {
                $tableHtml = view('admin.sites.partials.records-table', [
                    'sites' => $sites,
                    'selectedCountry' => $selectedCountry,
                    'missingMarket' => $missingMarket,
                    'healthFilter' => $healthFilter,
                ])->render();

                return response()->json([
                    'success' => true,
                    'selected_country' => $selectedCountry,
                    'missing_market' => $missingMarket,
                    'missing_market_count' => $missingMarketCount,
                    'health' => $healthFilter,
                    'health_counts' => $healthCounts,
                    'total' => $sites->total(),
                    'export_url' => $exportUrl,
                    'table_html' => $tableHtml,
                ]);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'success' => false,
                    'message' => UserFacingError::message($e, 'We could not filter records. Please try again.'),
                ], 500);
            }
        }

        return view('admin.sites.records', compact(
            'sites',
            'countries',
            'selectedCountry',
            'totalSites',
            'exportUrl',
            'missingMarket',
            'missingMarketCount',
            'healthFilter',
            'healthCounts'
        ));
    }

    /**
     * CSV download of the same live records sheet (honours country / missing-market filter).
     */
    public function exportRecords(Request $request): StreamedResponse|RedirectResponse
    {
        $filter = $this->recordsFilterState($request);
        $countryFilter = $filter['country'];
        $healthFilter = $filter['health'];
        $missingMarket = $filter['missing_market'];

        $suffix = $healthFilter !== null
            ? '-'.$healthFilter
            : ($countryFilter !== '' ? '-'.$countryFilter : '');
        $filename = 'websites-records'.$suffix.'-'.now()->format('Y-m-d').'.csv';

        try {
            $query = Site::query()->orderBy('domain')->orderBy('id');
            $this->applyRecordsFilters($query, $filter);
            $matchCount = (clone $query)->count();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.sites.records')
                ->with('error', UserFacingError::message($e, 'We could not export site records. Please try again.'));
        }

        ActivityLogger::tryLog(
            'sites.records_exported',
            ($request->user()?->name ?? 'Admin').' exported the websites records sheet ('.$matchCount.' row(s)).',
            null,
            [
                'country' => $countryFilter,
                'health' => $healthFilter,
                'missing_market' => $missingMarket,
                'rows_exported' => $matchCount,
            ]
        );

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['url', 'countries', 'categories', 'active', 'health']);

            foreach ($query->cursor() as $site) {
                $row = $this->siteRecordRow($site);
                fputcsv($out, [
                    $row['url'],
                    $row['countries'],
                    $row['categories'],
                    $site->active ? '1' : '0',
                    $row['health'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{
     *     country: string,
     *     health: ?string,
     *     missing_market: bool,
     *     query_params: array<string, int|string>
     * }
     */
    private function recordsFilterState(Request $request): array
    {
        $countryFilter = strtolower(trim(scalar_text($request->query('country', ''))));
        if ($countryFilter === 'all') {
            $countryFilter = '';
        }

        $health = CatalogHealthQueue::fromRequest($request);
        if ($health !== null) {
            $countryFilter = '';
        }

        return [
            'country' => $countryFilter,
            'health' => $health,
            'missing_market' => $health === CatalogHealthQueue::MISSING_MARKET,
            'query_params' => array_filter([
                'country' => $countryFilter !== '' ? $countryFilter : null,
                'health' => ($health !== null && $health !== CatalogHealthQueue::MISSING_MARKET)
                    ? $health
                    : null,
                'missing_market' => $health === CatalogHealthQueue::MISSING_MARKET ? 1 : null,
            ]),
        ];
    }

    /**
     * @param  Builder<Site>  $query
     * @param  array{country: string, health: ?string}  $filter
     */
    private function applyRecordsFilters($query, array $filter): void
    {
        if (is_string($filter['health'] ?? null) && $filter['health'] !== '') {
            CatalogHealthQueue::apply($query, $filter['health']);

            return;
        }

        $this->applyRecordsCountryFilter($query, (string) ($filter['country'] ?? ''));
    }

    /**
     * Tally sites per country code from legacy `country` + JSON `countries`.
     * Multi-market sites increment each matched code.
     *
     * @return array<string, int>
     */
    private function recordsCountryCounts(): array
    {
        $counts = [];
        $select = ['id', 'country'];
        if (Site::hasSitesColumn('countries')) {
            $select[] = 'countries';
        }

        try {
            foreach (Site::query()->select($select)->cursor() as $site) {
                foreach ($site->countryCodes() as $code) {
                    $code = strtolower(trim((string) $code));
                    if ($code === '') {
                        continue;
                    }
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Admin sites records country counts failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $counts;
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function applyRecordsCountryFilter($query, string $countryCode): void
    {
        $code = strtolower(trim($countryCode));
        if ($code === '') {
            return;
        }

        $hasCountriesJson = Site::hasSitesColumn('countries');
        $query->where(function ($q) use ($code, $hasCountriesJson) {
            $q->whereRaw('LOWER(country) = ?', [$code]);
            if ($hasCountriesJson) {
                $q->orWhereJsonContains('countries', $code);
            }
        });
    }

    /**
     * Build desktop preview URLs for staff Sites Management rows.
     *
     * @return array{thumb: ?string, full: ?string, fallbacks: list<string>}
     */
    /**
     * Staff preview URL for a public-disk path.
     * Prefer /{admin|marketing}/sites/media/... (Laravel disk stream) so Hostinger
     * broken public/storage symlinks do not blank row/detail previews.
     */
    private function staffPublicStorageUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        if ($normalized === '') {
            return null;
        }

        return rtrim(staff_base_path(), '/').'/sites/media/'.$normalized;
    }

    /**
     * Client onerror chain: staff media → /storage → public /media.
     *
     * @return list<string>
     */
    private function staffPublicStorageUrlFallbacks(?string $path): array
    {
        if (! is_string($path) || trim($path) === '') {
            return [];
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return [];
        }
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        if ($normalized === '') {
            return [];
        }

        $staff = $this->staffPublicStorageUrl($normalized);

        return array_values(array_unique(array_filter([
            $staff,
            '/storage/'.$normalized,
            '/media/'.$normalized,
        ])));
    }

    /**
     * Fast preview URLs for Sites Management rows (no per-path disk I/O).
     * Client onerror walks fallbacks when a path 404s.
     *
     * @return array{thumb: ?string, full: ?string, fallbacks: list<string>}
     */
    private function staffSitePreviewPayload(Site $site): array
    {
        $firstPath = static function (array $candidates): ?string {
            foreach ($candidates as $path) {
                if (is_string($path) && trim($path) !== '') {
                    return $path;
                }
            }

            return null;
        };

        // List: prefer uploaded cover, then screenshot thumb, then full capture.
        // Admin "Images" uploads must win over stale auto-screenshots or rows look blank.
        $thumbPath = $firstPath([
            $site->site_image,
            $site->screenshot_thumb_path,
            $site->screenshot_path,
        ]);

        // Hover/detail: prefer full desktop capture, then upload, then thumb.
        $fullPath = $firstPath([
            $site->screenshot_path,
            $site->site_image,
            $site->screenshot_thumb_path,
        ]);

        // onerror chain: upload → thumb → full
        $ordered = [];
        foreach ([
            $site->site_image,
            $site->screenshot_thumb_path,
            $site->screenshot_path,
        ] as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            if (! in_array($path, $ordered, true)) {
                $ordered[] = $path;
            }
        }

        $fallbacks = [];
        foreach ($ordered as $path) {
            foreach ($this->staffPublicStorageUrlFallbacks($path) as $url) {
                if (! in_array($url, $fallbacks, true)) {
                    $fallbacks[] = $url;
                }
            }
        }

        return [
            'thumb' => $this->staffPublicStorageUrl($thumbPath),
            'full' => $this->staffPublicStorageUrl($fullPath) ?: $this->staffPublicStorageUrl($thumbPath),
            'fallbacks' => $fallbacks,
        ];
    }

    /**
     * Slim JSON row for Sites Management (avoid full model dumps + disk I/O).
     *
     * @return array<string, mixed>
     */
    private function staffSiteListRow(Site $site): array
    {
        $preview = $this->staffSitePreviewPayload($site);
        $imageUrl = $this->staffPublicStorageUrl(
            is_string($site->site_image) ? $site->site_image : null
        );

        return [
            'id' => (int) $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'domain' => $site->domain,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $site->price,
            'active' => (bool) $site->active,
            'verified' => (bool) $site->verified,
            'country' => $site->country,
            'countries' => $site->countries,
            'language' => $site->language,
            'languages' => $site->languages,
            'category' => $site->category,
            'categories' => $site->categories,
            'link_type' => $site->link_type,
            'sponsored' => (bool) $site->sponsored,
            'description' => $site->description,
            'description_textarea' => SiteDescriptionRules::textareaValue((string) $site->description),
            'description_looks_english' => $site->descriptionLooksLikeEnglish(),
            'description_excerpt' => SiteDescriptionRules::excerpt($site->description, 200),
            'enrichment_status' => $site->enrichment_status,
            'enrichment_error' => $site->enrichment_error,
            'metrics_manual' => Site::hasSitesColumn('metrics_manual') && (bool) $site->metrics_manual,
            'metrics_fetched_at' => optional($site->metrics_fetched_at)?->toIso8601String(),
            'site_image' => $site->site_image,
            'screenshot_path' => $site->screenshot_path,
            'screenshot_thumb_path' => $site->screenshot_thumb_path,
            'needs_review' => $site->needsAdminReview(),
            'missing_market' => ! $site->hasMarketplaceCountry(),
            'below_quality_bar' => ! $site->hasGoodMetrics(),
            'listing_locked' => $site->isLockedForMarketingEdits(),
            'awaits_publisher_details' => $site->awaitsPublisherDetails(),
            'details_complete' => $site->hasDetailsComplete(),
            'pending_publisher_acceptance' => $site->isPendingPublisherAcceptance(),
            'agency_site_import_id' => Site::hasSitesColumn('agency_site_import_id')
                ? ($site->agency_site_import_id ? (int) $site->agency_site_import_id : null)
                : null,
            'csv_metrics_spot_check' => $site->isFromAgencyCsvImport() && (bool) $site->metrics_manual,
            'archived' => $site->isArchived(),
            'can_activate' => $this->staffCanActivateSite($site),
            'activate_block_reason' => $this->staffActivateBlockReason($site),
            'orders_count' => $site->orderItemsCount(),
            'created_at' => optional($site->created_at)?->toIso8601String(),
            'updated_at' => optional($site->updated_at)?->toIso8601String(),
            'status_reason' => Site::hasSitesColumn('status_reason')
                ? (filled($site->status_reason) ? (string) $site->status_reason : null)
                : null,
            'health_flags' => CatalogHealthQueue::flags($site),
            'placeholder' => CatalogPlaceholderListing::matches($site),
            'missing_cover' => ! $site->hasCatalogCover(),
            'can_nudge' => $this->staffCanNudgeSite($site),
            'notes_count' => (int) ($site->getAttribute('staff_notes_count') ?? 0),
            'latest_note' => $site->getAttribute('staff_latest_note'),
            'duplicate' => $site->getAttribute('staff_duplicate'),
            'preview_thumb_url' => $preview['thumb'],
            'preview_full_url' => $preview['full'],
            'preview_fallback_urls' => $preview['fallbacks'],
            'screenshot_url' => $preview['full'],
            'screenshot_thumb_url' => $preview['thumb'],
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @return array{url: string, countries: string, categories: string}
     */
    private function siteRecordRow(Site $site): array
    {
        $url = trim((string) ($site->site_url ?: ''));
        if ($url === '') {
            $domain = trim((string) ($site->domain ?: ''));
            $url = $domain !== '' ? 'https://'.$domain : '';
        }

        $countries = collect($site->countryCodesForDisplay())
            ->filter()
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->unique()
            ->values()
            ->implode('|');

        $categories = collect($site->categories_array)
            ->filter()
            ->map(fn ($cat) => trim((string) $cat))
            ->filter()
            ->unique()
            ->values()
            ->implode('|');

        $healthFlags = CatalogHealthQueue::flags($site);

        return [
            'url' => $url,
            'countries' => $countries,
            'categories' => $categories,
            'missing_market' => ! $site->hasMarketplaceCountry(),
            'active' => (bool) $site->active,
            'health_flags' => $healthFlags,
            'health' => implode('|', $healthFlags),
        ];
    }

    // Get sites of a user (AJAX, paginated)
    public function userSites(Request $request, $id)
    {
        $user = User::query()->find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Publisher not found',
                'publisher' => null,
                'sites' => [],
            ], 404);
        }

        try {
            return $this->userSitesPayload($request, $user);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not load this publisher\'s sites.'),
                'publisher' => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'sites' => [],
            ], 500);
        }
    }

    private function userSitesPayload(Request $request, User $user)
    {
        $columns = [
            'id',
            'publisher_id',
            'publisher_accepted_at',
            'assigned_by_user_id',
            'site_name',
            'site_url',
            'domain',
            'da',
            'dr',
            'traffic',
            'price',
            'active',
            'verified',
            'country',
            'countries',
            'language',
            'languages',
            'category',
            'categories',
            'link_type',
            'sponsored',
            'description',
            'enrichment_status',
            'enrichment_error',
            'metrics_fetched_at',
            'onboarding_status',
            'archived_at',
            'example_url',
            'site_image',
            'screenshot_path',
            'screenshot_thumb_path',
            'agency_site_import_id',
            'metrics_manual',
            'created_at',
            'updated_at',
            'status_reason',
        ];

        $select = array_values(array_filter(
            $columns,
            static fn (string $column) => in_array($column, [
                'id', 'publisher_id', 'site_name', 'site_url', 'domain',
                'da', 'dr', 'traffic', 'price', 'active', 'verified',
                'country', 'language', 'category', 'link_type', 'sponsored',
                'description', 'example_url', 'created_at', 'updated_at',
                // Always try to select image cols — blank row previews if omitted.
                'site_image', 'screenshot_path', 'screenshot_thumb_path',
            ], true) || Site::hasSitesColumn($column)
        ));

        $select = array_values(array_filter(
            $select,
            static function (string $column) {
                if (! in_array($column, ['site_image', 'screenshot_path', 'screenshot_thumb_path'], true)) {
                    return true;
                }

                return Site::hasSitesColumn($column);
            }
        ));

        $perPage = 50;
        $siteSearch = trim(scalar_text($request->query('q', '')));
        $needsReviewOnly = $request->boolean('needs_review');
        $archivedOnly = $request->boolean('archived');

        $sitesQuery = Site::query()
            ->where('publisher_id', $user->id)
            ->when($archivedOnly, fn ($q) => $q->archived(), fn ($q) => $q->notArchived())
            ->latest();

        if ($siteSearch !== '') {
            $this->constrainStaffSiteSearch($sitesQuery, $siteSearch);
        }

        if ($needsReviewOnly && ! $archivedOnly) {
            $sitesQuery->needsAdminReview();
        }

        if (Schema::hasTable('order_items')) {
            $sitesQuery->withCount('orderItems');
        }

        $paginator = $sitesQuery->paginate($perPage, $select);

        $this->hydrateStaffQueueMeta($paginator->getCollection());

        $sites = $paginator->getCollection()
            ->map(fn (Site $site) => $this->staffSiteListRow($site))
            ->values();

        // Include publisher meta so the detail view still loads when the publisher
        // is absent from a filtered "needs review" users table (e.g. after activate).
        return response()->json([
            'publisher' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ],
            'sites' => $sites,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'q' => $siteSearch,
                'needs_review' => $needsReviewOnly,
                'archived' => $archivedOnly,
            ],
        ]);
    }

    /**
     * Publishers whose name/email match, or who own a matching not-archived site.
     */
    private function applyStaffPublisherSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = like_contains($search);
        $query->where(function ($q) use ($search, $like) {
            $q->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
            if (ctype_digit($search)) {
                $q->orWhere('users.id', (int) $search);
            }
            $q->orWhereHas('sites', function ($sites) use ($search) {
                $sites->notArchived();
                $this->constrainStaffSiteSearch($sites, $search);
            });
        });
    }

    /**
     * Flat queues: match the site itself or its publisher name/email.
     */
    private function applyStaffIndexSiteOrPublisherSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = like_contains($search);
        $query->where(function ($q) use ($search, $like) {
            $this->constrainStaffSiteSearch($q, $search);
            $q->orWhereHas('publisher', function ($publisher) use ($like) {
                $publisher->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
            });
        });
    }

    /**
     * Match site name, domain, URL, or numeric id.
     */
    private function constrainStaffSiteSearch($sites, string $search): void
    {
        $like = like_contains($search);
        $host = $this->staffSearchHost($search);
        $candidates = $host !== null ? Site::domainLookupCandidates($host) : [];

        $sites->where(function ($q) use ($search, $like, $candidates) {
            $q->whereRaw('site_name LIKE ? ESCAPE ?', [$like, '\\'])
                ->orWhereRaw('domain LIKE ? ESCAPE ?', [$like, '\\'])
                ->orWhereRaw('site_url LIKE ? ESCAPE ?', [$like, '\\']);
            if (ctype_digit($search)) {
                $q->orWhere('id', (int) $search);
            }
            if ($candidates !== []) {
                $q->orWhereIn('domain', $candidates);
            }
        });
    }

    /**
     * Exact site-id or canonical domain hit — used to deep-link a unique result.
     */
    private function uniqueStaffSiteForExactSearch(string $search, bool $archived = false): ?Site
    {
        $query = $archived ? Site::query()->archived() : Site::query()->notArchived();

        if (ctype_digit($search)) {
            $matches = $query->where('id', (int) $search)->limit(2)->get();
        } else {
            $host = $this->staffSearchHost($search);
            if ($host === null) {
                return null;
            }
            $candidates = Site::domainLookupCandidates($host);
            if ($candidates === []) {
                return null;
            }
            $matches = $query->whereIn('domain', $candidates)->limit(2)->get();
        }

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function staffSearchHost(string $search): ?string
    {
        $raw = trim($search);
        if ($raw === '' || str_contains($raw, ' ') || str_contains($raw, '@')) {
            return null;
        }
        if (! str_contains($raw, '.') && ! str_contains($raw, '://')) {
            return null;
        }

        if (! str_contains($raw, '://')) {
            $raw = 'https://'.$raw;
        }

        $host = parse_url($raw, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $normalized = Site::normalizeMarketplaceDomain($host);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Staff form: add a complete listing for a publisher (pending their accept).
     */
    public function createForPublisher(Request $request): View
    {
        $rawSelectedPublisher = old('publisher_id', $request->query('publisher', 0));
        if (is_array($rawSelectedPublisher)) {
            $rawSelectedPublisher = reset($rawSelectedPublisher);
        }
        $selectedPublisherId = (int) $rawSelectedPublisher;

        $publishers = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
            ->where(function ($q) use ($selectedPublisherId) {
                $q->whereEmailVerified();
                if ($selectedPublisherId > 0) {
                    $q->orWhere('id', $selectedPublisherId);
                }
            })
            ->withCount('sites')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'email_verified_at']);

        $selectedPublisherUnverified = $selectedPublisherId > 0
            && $publishers->contains(
                fn (User $publisher) => (int) $publisher->id === $selectedPublisherId
                    && blank($publisher->email_verified_at)
            );

        $languages = Language::marketplace()->orderBy('name')->get();
        $countries = Country::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();
        $isMarketingEditor = $this->isMarketingEditor(auth()->user());
        $sitesBackUrl = $selectedPublisherId > 0
            ? staff_route('sites.index', ['publisher' => $selectedPublisherId])
            : staff_route('sites.index');

        $prefillSiteName = CommunityInbox::plainLine($request->query('site_name'));
        $prefillSiteUrl = CommunityInbox::safeHttpUrl($request->query('site_url')) ?? '';
        $prefillCountry = strtolower(search_text($request->query('country')));
        if (strlen($prefillCountry) !== 2) {
            $prefillCountry = '';
        }
        $prefillLanguage = strtolower(search_text($request->query('language')));
        if (strlen($prefillLanguage) !== 2) {
            $prefillLanguage = '';
        }
        $suggestionId = CommunityInbox::suggestionIdFrom($request->query('suggestion_id'));

        return view('admin.site-create', compact(
            'publishers',
            'languages',
            'countries',
            'categories',
            'countryLanguageMap',
            'selectedPublisherId',
            'selectedPublisherUnverified',
            'isMarketingEditor',
            'sitesBackUrl',
            'prefillSiteName',
            'prefillSiteUrl',
            'prefillCountry',
            'prefillLanguage',
            'suggestionId'
        ));
    }

    /**
     * Create a site for a publisher. Listing stays out of My Sites until they accept.
     */
    public function storeForPublisher(Request $request): RedirectResponse
    {
        if (! Site::hasSitesColumn('publisher_accepted_at') || ! Site::hasSitesColumn('assigned_by_user_id')) {
            return back()
                ->withErrors([
                    'save' => 'Database is missing the publisher-acceptance columns. Run migrations, then try again.',
                ])
                ->withInput();
        }

        $rawSiteUrl = $this->firstScalarString($request->input('site_url', $request->input('siteUrl', '')));
        $rawExampleUrl = $this->firstScalarString($request->input('example_url', $request->input('exampleUrl', '')));
        $urlErrors = $this->nonStringUrlErrors([
            'site_url' => $rawSiteUrl,
            'example_url' => $rawExampleUrl,
        ]);
        if ($urlErrors !== []) {
            return back()->withErrors($urlErrors)->withInput();
        }

        $siteUrl = $this->normalizeHttpUrl(is_string($rawSiteUrl) ? $rawSiteUrl : '');
        $exampleUrl = $this->normalizeHttpUrl(is_string($rawExampleUrl) ? $rawExampleUrl : '');

        // Coerce metric fields before validation (locale number inputs / "45.0" strings).
        $da = $this->normalizeMetricInt($request->input('da'));
        $dr = $this->normalizeMetricInt($request->input('dr'));
        $traffic = $this->normalizeMetricInt($request->input('traffic'));

        $suggestionId = CommunityInbox::suggestionIdFrom($request->input('suggestion_id'));
        $request->merge([
            'site_url' => $siteUrl,
            'example_url' => $exampleUrl,
            'da' => $da,
            'dr' => $dr,
            'traffic' => $traffic,
            'site_name' => is_string($request->input('site_name'))
                ? $this->normalizeSiteName($request->input('site_name'))
                : $request->input('site_name'),
            'suggestion_id' => $suggestionId > 0 ? $suggestionId : null,
        ]);

        $host = parse_url($siteUrl, PHP_URL_HOST);
        $domain = is_string($host) && $host !== '' ? $this->normalizeDomain($host) : '';
        if ($domain === '' || ! $this->isMarketplaceHost($domain)) {
            return back()->withErrors(['site_url' => 'Invalid URL'])->withInput();
        }
        $exampleHost = parse_url($exampleUrl, PHP_URL_HOST);
        $exampleDomain = is_string($exampleHost) && $exampleHost !== '' ? $this->normalizeDomain($exampleHost) : '';
        if ($exampleDomain === '' || ! $this->isMarketplaceHost($exampleDomain)) {
            return back()->withErrors(['example_url' => 'Invalid URL'])->withInput();
        }

        $resolvedNiches = Category::resolveNicheNames(
            $this->nicheNamesInput($request->input('categories', $request->input('category')))
        );
        $categories = $resolvedNiches['resolved'];
        $unknownNiches = $resolvedNiches['unknown'];
        $primaryCategory = ! empty($categories) ? implode('|', $categories) : scalar_text($request->input('category', ''));
        $categoriesArray = ! empty($categories) ? $categories : null;

        $countryCodes = array_slice($this->parseCodeList($request->input('country', $request->input('countries'))), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($request->input('language', $request->input('languages'))), 0, 1);

        $request->merge([
            'country' => $countryCodes[0] ?? null,
            'language' => $languageCodes[0] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
        ]);

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        if ($allowedCountries === [] || $allowedLanguages === []) {
            Log::error('Staff site-for-publisher store blocked: empty marketplace country/language lists', [
                'user_id' => auth()->id(),
                'countries' => count($allowedCountries),
                'languages' => count($allowedLanguages),
            ]);

            return redirect()->back()
                ->withErrors([
                    'country' => 'Marketplace countries or languages are not configured. Please contact support — your listing was not saved.',
                ])
                ->withInput();
        }

        if (trim(scalar_text($request->input('site_tag'))) === '') {
            $request->merge(['site_tag' => null]);
        }

        $validator = Validator::make($request->all(), [
            'publisher_id' => 'required|integer|exists:users,id',
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:255',
            'example_url' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0|max:999999.99',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'description' => 'nullable|string|max:20000',
            'site_image' => SiteImageUpload::uploadedFileRules(false),
            'site_tag' => 'nullable|in:sponsored,partner_material,as_you_prefer,none',
            'written_request' => 'accepted',
            'suggestion_id' => 'nullable|integer',
        ] + $this->placementOfferValidationRules(), array_merge($this->siteImageValidationMessages(), [
            'written_request.accepted' => 'Confirm you have a written request from this publisher’s account email.',
            'price.max' => 'Price must be at most €999,999.99.',
        ]), $this->placementOfferValidationAttributes());

        $cleanDescription = '';
        $validator->after(function ($validator) use ($request, $domain, $countryCodes, $languageCodes, $unknownNiches, &$cleanDescription) {
            $publisherId = (int) $request->input('publisher_id');
            $publisher = User::query()
                ->whereKey($publisherId)
                ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
                ->first();

            if (! $publisher) {
                $validator->errors()->add('publisher_id', 'Choose a valid publisher account.');
            }

            $existing = $this->findSiteByDomain($domain);
            if ($existing) {
                $validator->errors()->add('site_url', $this->domainAlreadyRegisteredMessage($existing));
            }

            if ($this->exampleUrlHostDiffers($request->input('site_url'), $request->input('example_url'))) {
                $validator->errors()->add('example_url', 'Example URL must be on the same website domain.');
            }

            $country = $countryCodes[0] ?? null;
            $language = $languageCodes[0] ?? null;
            if ($country && $language && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                $validator->errors()->add(
                    'language',
                    'That language is not allowed for the selected country. Pick country first, then a paired language.'
                );
            }

            foreach ($unknownNiches as $cat) {
                $validator->errors()->add('categories', 'Unknown niche: '.$cat);
            }

            foreach (SiteDescriptionRules::errors(scalar_text($request->input('description', ''))) as $message) {
                $validator->errors()->add('description', $message);
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = app(SiteDescriptionSanitizer::class)
            ->sanitize(scalar_text($request->input('description')));

        $site = null;
        $storedImagePath = null;
        $publisherId = (int) $request->input('publisher_id');

        try {
            DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, $publisherId, &$storedImagePath, &$site) {
                Site::releaseCancelledBulkDomain($domain, $publisherId);
                $existing = $this->findSiteByDomain($domain, lock: true);
                if ($existing) {
                    throw ValidationException::withMessages([
                        'site_url' => [$this->domainAlreadyRegisteredMessage($existing)],
                    ]);
                }

                $site = new Site;

                $imagePath = null;
                if ($request->hasFile('site_image')) {
                    $upload = $request->file('site_image');
                    if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                        throw ValidationException::withMessages([
                            'site_image' => [$this->siteImageValidationMessages()['site_image.uploaded']],
                        ]);
                    }
                    $stored = $this->storeStaffSiteImage($upload);
                    if ($stored === null) {
                        throw ValidationException::withMessages([
                            'site_image' => ['Could not save the site image to storage. Check disk permissions and MEDIA_PATH.'],
                        ]);
                    }
                    $storedImagePath = $stored;
                    PublicStorageLink::ensure();
                    $imagePath = $stored;
                }

                $da = (int) $request->input('da');
                $dr = (int) $request->input('dr');
                $traffic = (int) $request->input('traffic');
                $sensitivePrices = $this->collectSensitivePrices($request);
                $homepagePrices = $this->collectHomepagePlacementPrices($request);
                $socialPromotion = $this->collectSocialPromotion($request);

                $site->applyMarketplaceListing([
                    'publisher_id' => $publisherId,
                    'assigned_by_user_id' => auth()->id(),
                    'publisher_accepted_at' => null,
                    'site_name' => $request->input('site_name'),
                    'site_url' => $request->input('site_url'),
                    'domain' => $domain,
                    'example_url' => $request->input('example_url'),
                    'da' => $da,
                    'dr' => $dr,
                    'traffic' => $traffic,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                    'country' => $countryCodes[0],
                    'countries' => $countryCodes,
                    'language' => $languageCodes[0],
                    'languages' => $languageCodes,
                    'category' => $primaryCategory,
                    'categories' => $categoriesArray,
                    'price' => $request->input('price'),
                    'turnaround_time' => $request->input('turnaround_time'),
                    'publication_time' => $request->input('publication_time'),
                    'link_type' => $request->input('link_type'),
                    'description' => $cleanDescription,
                    'verified' => false,
                    'active' => false,
                    'enrichment_status' => 'pending',
                    'onboarding_status' => null,
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                    'homepage_placement_prices' => ! empty($homepagePrices) ? $homepagePrices : null,
                    'social_promotion' => $socialPromotion,
                ]);

                // Hard-set invite + metrics so a missing column skip cannot silently drop them.
                $price = $request->input('price');
                $site->forceFill([
                    'assigned_by_user_id' => auth()->id(),
                    'publisher_accepted_at' => null,
                    'verified' => false,
                    'active' => false,
                    'da' => $da,
                    'dr' => $dr,
                    'traffic' => $traffic,
                    'price' => $price,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                ]);

                if (class_exists(SiteTag::class)) {
                    SiteTag::applyStaffDefault($site, $request->input('site_tag'));
                }

                $site->save();

                if (is_string($imagePath) && $imagePath !== '') {
                    $this->persistStaffSiteImagePath($site, $imagePath);
                }

                if ((int) $site->da !== $da || (int) $site->dr !== $dr || (int) $site->traffic !== $traffic) {
                    throw new \RuntimeException('DA/DR/traffic did not persist after save.');
                }
                if (is_numeric($price) && round((float) $site->price, 2) !== round((float) $price, 2)) {
                    throw new \RuntimeException('Staff site price did not persist after save.');
                }
                if (filled($site->publisher_accepted_at) || blank($site->assigned_by_user_id)) {
                    throw new \RuntimeException('Publisher invite state did not persist after save.');
                }
                if ((bool) $site->verified || (bool) $site->active) {
                    throw new \RuntimeException('Staff site invite flags did not persist after save.');
                }
            });
        } catch (ValidationException $e) {
            $this->deleteStoredSiteImage($storedImagePath);
            throw $e;
        } catch (\Throwable $e) {
            $this->deleteStoredSiteImage($storedImagePath);
            Log::error('Staff site-for-publisher store failed', [
                'publisher_id' => $publisherId,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            if ($this->isDomainUniqueConstraintFailure($e)) {
                return redirect()->back()
                    ->withErrors(['site_url' => 'This website domain is already registered.'])
                    ->withInput();
            }

            $errors = $this->staffSiteUpdateFailureErrors($e);
            if (str_contains($e->getMessage(), 'did not persist after save.')
                || str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'no such column')) {
                $errors = ['save' => 'We could not save invite state, DA/DR, monthly traffic, or price. Run the latest migrations on the server, clear caches, and try again.'];
            }

            return redirect()->back()
                ->withErrors($errors)
                ->withInput();
        }

        if ($site && config('site_enrichment.enabled', true)) {
            try {
                CaptureSiteScreenshotJob::dispatch($site->id, 'staff_assign');
            } catch (\Throwable $e) {
                Log::warning('Failed to queue screenshot for staff-assigned site', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $site) {
            return redirect()->back()
                ->withErrors(['save' => 'We could not save this website. Please try again.'])
                ->withInput();
        }

        try {
            app(CommunityInboxNotifier::class)->acceptWebsiteSuggestionAfterListing(
                CommunityInbox::suggestionIdFrom($request->input('suggestion_id')),
                $site->fresh() ?? $site,
                $request->user()
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to accept website suggestion after listing: '.$e->getMessage(), [
                'site_id' => $site->id,
                'suggestion_id' => $request->input('suggestion_id'),
            ]);
        }

        try {
            ActivityLogger::log(
                'site.assigned_for_acceptance',
                (auth()->user()->name ?? 'Staff').' added site "'.$site->site_name.'" for publisher acceptance',
                $site,
                [
                    'publisher_id' => $publisherId,
                    'assigned_by_user_id' => auth()->id(),
                    'domain' => $site->domain,
                ],
                $site->site_name
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log staff-assigned site: '.$e->getMessage());
        }

        $emailed = false;
        $belled = false;
        $publisher = $site->publisher;
        try {
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(new AdminAssignedSiteNotification($site, $publisher));
                $emailed = true;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email publisher about staff-assigned site: '.$e->getMessage());
        }

        try {
            if ((int) ($site->publisher_id ?? 0) > 0) {
                app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site);
                $belled = true;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to bell-notify publisher about staff-assigned site: '.$e->getMessage());
        }

        $success = 'Site added (DA '.$site->da.' / DR '.$site->dr.').';
        $success .= ($emailed || $belled)
            ? ' Publisher was notified — they must open My Sites → Invites and Accept before it appears under Pending.'
            : ' The listing was saved, but we could not notify the publisher. Ask them to open My Sites → Invites and Accept.';
        if (! $site->hasGoodMetrics()) {
            $success .= ' This listing is below the marketing Activate bar (DA ≥ '.Site::GOOD_MIN_DA.', DR ≥ '.Site::GOOD_MIN_DR.', traffic ≥ '.number_format(Site::GOOD_MIN_TRAFFIC).').';
        }

        $redirectParams = ['publisher' => $publisherId];
        if ($site?->id) {
            $redirectParams['site'] = $site->id;
        }

        return redirect()
            ->to(staff_route('sites.index', $redirectParams))
            ->with('success', $success);
    }

    // Edit page (optional)
    public function edit($id)
    {
        try {
            return $this->renderSiteEdit($id);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->to(staff_route('sites.index'))
                ->with('error', UserFacingError::message($e, 'We could not load that site editor. Please try again.'));
        }
    }

    private function renderSiteEdit($id)
    {
        $site = Site::with('publisher:id,name,email')->findOrFail($id);
        $user = auth()->user();
        $isMarketingEditor = $this->isMarketingEditor($user);
        $marketingListingLocked = $isMarketingEditor && $this->marketingListingIsLocked($site);
        $languages = Language::marketplace()->orderBy('name')->get();
        $countries = Country::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();

        $editData = compact(
            'site',
            'isMarketingEditor',
            'marketingListingLocked',
            'languages',
            'countries',
            'categories',
            'countryLanguageMap'
        );

        // Named view keeps @section / @stack working. File fallback covers a
        // stale `view:cache` manifest that reports the view missing.
        if (view()->exists('admin.site-edit')) {
            return view('admin.site-edit', $editData);
        }

        $editViewPath = resource_path('views/admin/site-edit.blade.php');
        if (is_file($editViewPath)) {
            return view()->file($editViewPath, $editData);
        }

        // Fallback: open the existing Sites UI editor for this publisher/site.
        return redirect()->to(staff_route('sites.index', [
            'publisher' => $site->publisher_id,
            'edit_site' => $site->id,
        ]));
    }

    // Upload image for site
    public function uploadImage(Request $request, $id)
    {
        $site = Site::findOrFail($id);
        if ($this->isMarketingEditor(auth()->user()) && $this->marketingListingIsLocked($site)) {
            $message = 'Marketing can only edit pending sites that are not live.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 403);
            }

            abort(403, $message);
        }

        $file = $request->file('site_image');
        if (! $file) {
            $message = 'Choose a site image to upload.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 422);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        if (! $file->isValid()) {
            $mb = $this->siteImageMaxMegabytesLabel();
            $message = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The site image is too large. Use a file under '.$mb.' MB.',
                UPLOAD_ERR_PARTIAL => 'The site image upload was interrupted. Try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a site image to upload.',
                default => 'The site image failed to upload. Use JPEG, PNG, GIF, or WebP under '.$mb.' MB.',
            };

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 422);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        $request->validate([
            // extensions = client filename. Laravel `mimes` uses finfo on
            // PHP tmp (Hostinger open_basedir often fails). Bytes are checked
            // in storeSafePublicImage().
            'site_image' => SiteImageUpload::uploadedFileRules(true),
        ], $this->siteImageValidationMessages());

        $disk = Storage::disk('public');
        try {
            $disk->makeDirectory('sites');
        } catch (\Throwable $e) {
            Log::error('Could not create sites media directory', [
                'error' => $e->getMessage(),
                'root' => config('filesystems.disks.public.root'),
            ]);
            $message = 'Could not prepare image storage. Check disk permissions and MEDIA_PATH.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 500);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        $previous = is_string($site->site_image) ? $site->site_image : null;

        // Store new image first — only delete the previous file after success.
        $path = $this->storeStaffSiteImage($file);
        if ($path === null) {
            $message = 'Could not save the site image to storage. Check disk permissions and MEDIA_PATH.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 500);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        // Heal / verify public/storage. Hostinger open_basedir often makes is_file()
        // fail even when the web server can serve the file — never roll back a good save.
        $ensure = PublicStorageLink::ensure();
        $publicLinked = PublicStorageLink::pathIsPubliclyReachable($path);
        if (! $publicLinked) {
            Log::warning('Site image stored; public/storage probe failed (kept upload)', [
                'path' => $path,
                'disk_root' => config('filesystems.disks.public.root'),
                'public_storage' => public_path('storage'),
                'media_path' => config('filesystems.media_path'),
                'ensure' => $ensure,
            ]);
        }

        try {
            $this->persistStaffSiteImagePath($site, $path);
        } catch (\Throwable $e) {
            $this->deleteStoredSiteImage($path);
            Log::error('Staff site image upload failed to persist', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $message = 'Could not save the site image. Please try again.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 500);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        if ($previous && $previous !== $path) {
            $this->deleteStoredSiteImage($previous);
        }

        ActivityLogger::tryLog(
            'site.image_uploaded',
            auth()->user()->name.' uploaded an image for site "'.$site->site_name.'"',
            $site,
            ['image_path' => $path],
            $site->site_name
        );

        $imageUrl = $this->staffPublicStorageUrl($path);
        // Cache-bust so browsers do not keep a prior broken/blank response.
        $imageUrlWithBust = $imageUrl ? ($imageUrl.'?v='.time()) : null;

        $message = 'Image uploaded successfully';
        if (! $publicLinked) {
            $message = 'Image saved. Preview uses a secure media URL; run php artisan media:ensure-link if /storage still 404s publicly.';
        }

        return response()->json([
            'success' => true,
            'image_path' => $path,
            'image_url' => $imageUrlWithBust,
            'storage_ok' => $publicLinked,
            'message' => $message,
        ]);
    }

    // UPDATE (supports partial + full updates safely)
    public function update(Request $request, $id)
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();
        } catch (\Throwable $e) {
            Log::warning('Admin site schema ensure failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $site = Site::findOrFail($id);
        $user = auth()->user();
        $isMarketingEditor = $this->isMarketingEditor($user);

        if ($isMarketingEditor && $site->isArchived()) {
            $message = 'This listing is archived. Marketing cannot change it. Ask an admin.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 403);
            }

            return redirect()
                ->to(staff_route('sites.edit', $site->id))
                ->withErrors(['save' => $message]);
        }

        $marketingDescriptionOnly = $isMarketingEditor && $this->marketingListingIsLocked($site);

        // Store old data for email comparison / activity log
        $oldData = [
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $site->price,
            'language' => $site->language,
            'country' => $site->country,
            'category' => $site->category,
            'example_url' => $site->example_url,
            'link_type' => $site->link_type,
            'publication_time' => $site->publication_time,
            'active' => $site->active,
            'verified' => $site->verified,
        ];

        $data = $marketingDescriptionOnly
            ? $this->marketingDescriptionOnlyPayload($request, $site)
            : ($isMarketingEditor
                ? $this->marketingUpdatePayload($request, $site)
                : $this->adminUpdatePayload($request, $site));

        if ($data instanceof JsonResponse || $data instanceof RedirectResponse) {
            return $data;
        }

        unset(
            $data['active'],
            $data['verified'],
            $data['verified_at'],
            $data['verify_method'],
            $data['verify_token'],
            $data['verify_token_created_at']
        );

        if (array_key_exists('link_type', $data)) {
            Site::ensureLinkTypeColumn();
        }

        $data = array_filter(
            $data,
            static fn (string $key): bool => Site::hasSitesColumn($key),
            ARRAY_FILTER_USE_KEY
        );

        $previousImage = is_string($site->site_image) ? $site->site_image : null;
        $imagePath = $data['site_image'] ?? null;
        $persistImageSeparately = is_string($imagePath) && $imagePath !== '';
        if ($persistImageSeparately) {
            unset($data['site_image']);
        }

        try {
            if ($data !== []) {
                $site->update($data);
            }
            if ($persistImageSeparately) {
                $this->persistStaffSiteImagePath($site, $imagePath);
            }
            $site->refresh();
            if (! $isMarketingEditor) {
                try {
                    $site->promoteForAdminSaveIfBriefReady();
                    $site->refresh();
                } catch (\Throwable $e) {
                    Log::warning('Could not promote site onboarding after admin save', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (ValidationException $e) {
            $storedThisRequest = $request->attributes->get('staff_stored_site_image');
            if (is_string($storedThisRequest) && $storedThisRequest !== '') {
                $this->deleteStoredSiteImage($storedThisRequest);
            }

            throw $e;
        } catch (\Throwable $e) {
            $storedThisRequest = $request->attributes->get('staff_stored_site_image');
            if (is_string($storedThisRequest) && $storedThisRequest !== '') {
                $this->deleteStoredSiteImage($storedThisRequest);
            }

            $message = $e->getMessage();
            if ($e instanceof QueryException
                && array_key_exists('link_type', $data)
                && (str_contains($message, 'link_type')
                    || str_contains($message, 'Data truncated')
                    || str_contains($message, '1265'))) {
                throw ValidationException::withMessages([
                    'link_type' => 'This link type could not be saved. Run the latest database update and try again.',
                ]);
            }

            Log::error('Staff site update failed', [
                'site_id' => $site->id,
                'error' => $message,
            ]);

            $errors = $this->staffSiteUpdateFailureErrors($e);
            $hint = (string) reset($errors);
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $hint,
                    'errors' => $errors,
                ], $this->isDomainUniqueConstraintFailure($e) ? 422 : 500);
            }

            return back()->withErrors($errors)->withInput();
        }

        $newImage = is_string($site->site_image) ? $site->site_image : null;
        if ($previousImage && $previousImage !== $newImage) {
            $this->deleteStoredSiteImage($previousImage);
        }

        $changes = [];
        foreach ($oldData as $key => $oldValue) {
            $newValue = $site->{$key} ?? null;
            if ((string) $oldValue !== (string) $newValue) {
                $changes[$key] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        try {
            if ($changes !== []) {
                ActivityLogger::log(
                    'site.updated',
                    (auth()->user()->name ?? 'Staff').' modified site "'.$site->site_name.'"',
                    $site,
                    ['changes' => $changes],
                    $site->site_name
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to log staff site update: '.$e->getMessage());
        }

        $emailSent = false;

        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email && $this->shouldNotifyPublisherOfSiteUpdate($site, $oldData, $isMarketingEditor)) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, 'update', $oldData));
                $emailSent = true;
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send update notification: '.$e->getMessage());
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site updated successfully',
                'email_sent' => $emailSent,
                'can_activate' => $this->staffCanActivateSite($site),
                'activate_block_reason' => $this->staffActivateBlockReason($site),
            ]);
        }

        $message = 'Site updated successfully.'.($emailSent ? ' Publisher notified.' : '');

        if ($isMarketingEditor) {
            return redirect()
                ->to(staff_route('sites.index', array_filter([
                    'publisher' => $site->publisher_id,
                    'site' => $site->id,
                ])))
                ->with('success', $message);
        }

        return redirect()
            ->to(staff_route('sites.edit', $site->id))
            ->with('success', $message);
    }

    /**
     * Marketing metrics/geo/niche/image saves stay internal. Publisher mail
     * only fires when listing identity (name, URL, price) changes.
     *
     * @param  array<string, mixed>  $oldData
     */
    private function shouldNotifyPublisherOfSiteUpdate(Site $site, array $oldData, bool $isMarketingEditor): bool
    {
        $keys = $isMarketingEditor
            ? ['site_name', 'site_url', 'price']
            : ['site_name', 'site_url', 'da', 'dr', 'traffic', 'price', 'language', 'country', 'active', 'verified'];

        foreach ($keys as $key) {
            $oldValue = $oldData[$key] ?? null;
            $newValue = $site->{$key} ?? null;
            if ($key === 'price') {
                if (round((float) $oldValue, 2) !== round((float) $newValue, 2)) {
                    return true;
                }

                continue;
            }
            if ($key === 'site_url') {
                $oldUrl = is_string($oldValue) ? $this->normalizeHttpUrl($oldValue) : '';
                $newUrl = is_string($newValue) ? $this->normalizeHttpUrl((string) $newValue) : '';
                if ($oldUrl !== $newUrl) {
                    return true;
                }

                continue;
            }
            if ($key === 'site_name') {
                $oldName = is_string($oldValue) ? $this->normalizeSiteName($oldValue) : '';
                $newName = is_string($newValue) ? $this->normalizeSiteName((string) $newValue) : '';
                if ($oldName !== $newName) {
                    return true;
                }

                continue;
            }
            if (in_array($key, ['country', 'language'], true)) {
                if (strtolower(trim((string) $oldValue)) !== strtolower(trim((string) $newValue))) {
                    return true;
                }

                continue;
            }
            if ((string) $oldValue !== (string) $newValue) {
                return true;
            }
        }

        return false;
    }

    private function isMarketingEditor(?User $user): bool
    {
        return (bool) ($user?->isMarketing() && ! $user?->isAdmin());
    }

    private function marketingListingIsLocked(Site $site): bool
    {
        return $site->isLockedForMarketingEdits();
    }

    /**
     * Admin listing edits. Status flags are never taken from this payload.
     *
     * @return array<string, mixed>
     */
    private function adminUpdatePayload(Request $request, Site $site): array
    {
        $this->mergeNormalizedUrlOrFail($request, 'site_url');
        $this->mergeNormalizedUrlOrFail($request, 'example_url', nullable: true);
        $metricMerge = [];
        foreach (['da', 'dr', 'traffic'] as $field) {
            if ($request->exists($field)) {
                $metricMerge[$field] = $this->normalizeMetricInt($request->input($field));
            }
        }
        if ($metricMerge !== []) {
            $request->merge($metricMerge);
        }

        $countryCodes = $request->has('country') || $request->has('countries')
            ? array_slice($this->parseCodeList($request->input('country', $request->input('countries'))), 0, 1)
            : [];
        $languageCodes = $request->has('language') || $request->has('languages')
            ? array_slice($this->parseCodeList($request->input('language', $request->input('languages'))), 0, 1)
            : [];

        if ($countryCodes !== []) {
            $request->merge(['country' => $countryCodes[0]]);
        } elseif ($request->has('country') && trim(scalar_text($request->input('country'))) === '') {
            $request->merge(['country' => null]);
        }
        if ($languageCodes !== []) {
            $request->merge(['language' => $languageCodes[0]]);
        } elseif ($request->has('language') && trim(scalar_text($request->input('language'))) === '') {
            $request->merge(['language' => null]);
        }
        if ($request->has('description') && SiteDescriptionRules::isBlankHtml($request->input('description'))) {
            $request->merge(['description' => null]);
        }
        if ($request->has('link_type') && trim(scalar_text($request->input('link_type'))) === '') {
            $request->merge(['link_type' => null]);
        }
        if ($request->exists('site_name') && is_string($request->input('site_name'))) {
            $request->merge(['site_name' => $this->normalizeSiteName($request->input('site_name'))]);
        }

        $domain = null;
        $siteUrl = trim(scalar_text($request->input('site_url', '')));
        if ($siteUrl !== '') {
            $host = parse_url($siteUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $normalized = $this->normalizeDomain($host);
                $domain = $normalized !== '' ? $normalized : null;
            }
        }

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        if ($request->exists('category') && ! is_string($request->input('category'))) {
            $request->merge(['category' => null]);
        }

        $rules = [
            'site_name' => 'sometimes|required|string|max:255',
            'site_url' => 'sometimes|required|url|max:255',
            'example_url' => 'sometimes|nullable|url|max:255',
            'da' => 'sometimes|required|integer|min:0|max:100',
            'dr' => 'sometimes|required|integer|min:0|max:100',
            'traffic' => 'sometimes|required|integer|min:0|max:4294967295',
            'country' => 'sometimes|nullable|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'sometimes|nullable|string|size:2|in:'.implode(',', $allowedLanguages),
            'price' => 'sometimes|required|numeric|min:0|max:999999.99',
            'description' => 'sometimes|nullable|string|max:20000',
            'category' => 'sometimes|nullable|string|max:255',
            'publication_time' => 'sometimes|nullable|string|max:20',
            // Dedicated editor is free text; modal may send dofollow/nofollow.
            'link_type' => 'sometimes|nullable|string|max:50',
            'sponsored' => 'sometimes|nullable|boolean',
            'partner_material' => 'sometimes|nullable|boolean',
            'as_you_prefer' => 'sometimes|nullable|boolean',
        ];

        if ($request->boolean('placement_offers_form')) {
            $rules = array_merge($rules, $this->placementOfferValidationRules());
        }

        // site_image is often a stored path string after upload-image; only
        // validate as a file when a real upload is present (handled below).

        $validator = Validator::make(
            $request->all(),
            $rules,
            array_merge($this->siteImageValidationMessages(), [
                'price.max' => 'Price must be at most €999,999.99.',
            ]),
            $this->placementOfferValidationAttributes()
        );

        $validator->after(function ($validator) use ($request, $site, $domain) {
            if (is_string($domain) && $domain !== '') {
                if (! $this->isMarketplaceHost($domain)) {
                    $validator->errors()->add('site_url', 'Invalid URL');
                } else {
                    Site::releaseCancelledBulkDomain($domain, (int) $site->publisher_id);
                    $existing = $this->findSiteByDomain($domain, exceptId: $site->id);
                    if ($existing) {
                        $validator->errors()->add('site_url', $this->domainAlreadyRegisteredMessage($existing));
                    }
                }
            }

            if ($request->filled('site_url') && ($domain === null || $domain === '')) {
                $validator->errors()->add('site_url', 'Invalid URL');
            }

            $exampleUrl = $request->input('example_url');
            if (is_string($exampleUrl) && $exampleUrl !== '') {
                $exampleHost = parse_url($exampleUrl, PHP_URL_HOST);
                $exampleDomain = is_string($exampleHost) && $exampleHost !== ''
                    ? $this->normalizeDomain($exampleHost)
                    : '';
                if ($exampleDomain === '' || ! $this->isMarketplaceHost($exampleDomain)) {
                    $validator->errors()->add('example_url', 'Invalid URL');
                }
            }

            if ($request->has('country') || $request->has('language')) {
                $country = strtolower($this->scalarString($request->input('country', $site->country)));
                $language = strtolower($this->scalarString($request->input('language', $site->language)));
                if ($country !== '' && $language !== '' && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                    $validator->errors()->add(
                        'language',
                        'That language is not allowed for the selected country. Pick country first, then a paired language.'
                    );
                }
            }

            $rawDescription = $request->input('description');
            if (is_string($rawDescription) && ! SiteDescriptionRules::isBlankHtml($rawDescription)) {
                $clean = app(SiteDescriptionSanitizer::class)->sanitize($rawDescription);
                $incomingPlain = SiteDescriptionRules::plainText($clean);
                $existingPlain = SiteDescriptionRules::plainText((string) $site->description);
                if ($incomingPlain !== $existingPlain) {
                    foreach (SiteDescriptionRules::errors($clean) as $message) {
                        $validator->errors()->add('description', $message);
                    }
                }
            }

            if ($request->filled('site_url') || $request->exists('example_url')) {
                $siteUrl = $request->filled('site_url') ? $request->input('site_url') : $site->site_url;
                $exampleUrl = $request->exists('example_url') ? $request->input('example_url') : $site->example_url;
                if ($this->exampleUrlHostDiffers($siteUrl, $exampleUrl)) {
                    if (! $request->exists('example_url') && $request->filled('site_url')) {
                        // Metrics & image modal posts a new URL but no example URL.
                        $request->merge(['example_url' => '']);
                    } else {
                        $validator->errors()->add('example_url', 'Example URL must be on the same website domain.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $request->only([
            'site_name',
            'site_url',
            'domain',
            'example_url',
            'da',
            'dr',
            'traffic',
            'country',
            'language',
            'category',
            'price',
            'publication_time',
            'link_type',
            'sponsored',
            'partner_material',
            'as_you_prefer',
            'description',
            'site_image',
        ]);

        // Domain comes only from the listing URL host. A posted domain field
        // must not retarget uniqueness (DB unique is publisher_id + domain).
        unset($data['domain']);
        if (is_string($domain) && $domain !== '') {
            $data['domain'] = $domain;
        }

        // category is a VARCHAR (often 50) and is not in $rules. An array 500s
        // the save; a long free-text value overflows the column.
        if (array_key_exists('category', $data)) {
            if (! is_string($data['category'])) {
                unset($data['category']);
            } else {
                $trimmedCategory = trim($data['category']);
                if ($trimmedCategory === '') {
                    unset($data['category']);
                } else {
                    $data['category'] = Site::fitCategoryColumn($trimmedCategory);
                }
            }
        }

        if ($metricMerge !== []) {
            $data['metrics_manual'] = true;
            $data['metrics_provider'] = 'manual';
            $data['metrics_fetched_at'] = now();
            $data['enrichment_status'] = 'ready';
        }

        if (isset($data['country']) && $data['country'] !== null && $data['country'] !== '') {
            $data['country'] = strtolower(trim((string) $data['country']));
            $data['countries'] = [$data['country']];
        }
        if (isset($data['language']) && $data['language'] !== null && $data['language'] !== '') {
            $data['language'] = strtolower(trim((string) $data['language']));
            $data['languages'] = [$data['language']];
        }

        if ($request->has('categories') || $request->filled('category')) {
            $raw = $request->has('categories')
                ? $request->input('categories')
                : $request->input('category');
            $resolved = Category::resolveNicheNames($raw);
            $incoming = array_values(array_unique(array_merge($resolved['resolved'], $resolved['unknown'])));
            $replaceAll = $request->has('categories') || count($incoming) > 1;
            $categories = $this->mergeAdminCategoryUpdate($site, $incoming, $replaceAll);
            $data['categories'] = $categories;
            $data['category'] = Site::fitCategoryColumn(
                $categories !== [] ? (string) $categories[0] : '',
                $categories !== [] ? $categories : null
            );
        } elseif ($request->has('category')) {
            // Dedicated edit always posts category; blank must not wipe niches.
            unset($data['category']);
        }

        if ($request->hasFile('site_image')) {
            $upload = $request->file('site_image');
            if ($upload && ! $upload->isValid()) {
                throw ValidationException::withMessages([
                    'site_image' => [$this->siteImageValidationMessages()['site_image.uploaded']],
                ]);
            }

            $request->validate([
                'site_image' => SiteImageUpload::uploadedFileRules(false),
            ], $this->siteImageValidationMessages());

            $disk = Storage::disk('public');
            $disk->makeDirectory('sites');

            $stored = $this->storeStaffSiteImage($upload);
            if ($stored === null) {
                throw ValidationException::withMessages([
                    'site_image' => ['Could not save the site image to storage. Check disk permissions and MEDIA_PATH.'],
                ]);
            }

            PublicStorageLink::ensure();
            if (! PublicStorageLink::pathIsPubliclyReachable($stored)) {
                Log::warning('Site image saved via update; public/storage probe failed (kept upload)', [
                    'path' => $stored,
                    'disk_root' => config('filesystems.disks.public.root'),
                ]);
            }

            $data['site_image'] = $stored;
            $request->attributes->set('staff_stored_site_image', $stored);
        } elseif ($request->has('site_image') && ! $request->hasFile('site_image')) {
            $path = $this->postedSiteImagePath($request->input('site_image'));
            $current = is_string($site->site_image) ? $this->postedSiteImagePath($site->site_image) : null;
            if ($path !== null && $current !== null && $path === $current) {
                $data['site_image'] = $path;
            } else {
                unset($data['site_image']);
            }
        } else {
            unset($data['site_image']);
        }

        $placementPatch = null;
        if ($request->boolean('placement_offers_form')) {
            $homepagePrices = $this->collectHomepagePlacementPrices($request);
            $placementPatch = [
                'homepage_placement_prices' => $homepagePrices !== [] ? $homepagePrices : null,
                'social_promotion' => $this->collectSocialPromotion($request),
            ];
        }

        $data = array_filter($data, function ($value, $key) {
            // Optional example URL must be clearable; other nulls mean "leave unchanged".
            if ($key === 'example_url') {
                return true;
            }

            return $value !== null;
        }, ARRAY_FILTER_USE_BOTH);

        // Empty optional fields are merged to null above, then stripped by
        // array_filter. Re-apply explicit clears so dedicated edit can blank
        // geo / description / example URL (NOT NULL columns get '').
        if ($request->has('country') && $countryCodes === []) {
            $data['country'] = '';
            $data['countries'] = null;
        }
        if ($request->has('language') && $languageCodes === []) {
            $data['language'] = '';
            $data['languages'] = null;
        }
        if ($request->has('example_url') && $this->isBlankStringInput($request->input('example_url'))) {
            $data['example_url'] = null;
        }
        if ($request->has('description')) {
            $postedDescription = $request->input('description');
            if (SiteDescriptionRules::isBlankHtml($postedDescription) || $this->isBlankStringInput($postedDescription)) {
                unset($data['description']);
            }
        }

        if ($placementPatch !== null) {
            $data = array_merge($data, $placementPatch);
        }

        if (isset($data['description']) && is_string($data['description'])) {
            $data['description'] = app(SiteDescriptionSanitizer::class)
                ->sanitize($data['description']);
        }

        if (! class_exists(SiteTag::class)) {
            return $data;
        }

        return SiteTag::exclusiveAttributePatch($data, $site);
    }

    /**
     * Live/verified listings: marketing may change the brief only.
     *
     * @return array<string, mixed>|JsonResponse|RedirectResponse
     */
    private function marketingDescriptionOnlyPayload(Request $request, Site $site): array|JsonResponse|RedirectResponse
    {
        if (! $site->marketingCanEditDescription()) {
            $message = 'This listing is archived. Marketing cannot change it. Ask an admin.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 403);
            }

            return redirect()
                ->to(staff_route('sites.edit', $site->id))
                ->withErrors(['save' => $message]);
        }

        $incoming = $request->input('description');
        if (! is_string($incoming) || SiteDescriptionRules::isBlankHtml($incoming)) {
            return [];
        }

        $clean = app(SiteDescriptionSanitizer::class)->sanitize(scalar_text($incoming));
        $incomingPlain = SiteDescriptionRules::plainText($clean);
        $existingPlain = SiteDescriptionRules::plainText((string) $site->description);
        if ($incomingPlain === '' || $incomingPlain === $existingPlain) {
            return [];
        }

        $errors = SiteDescriptionRules::errors($clean);
        if ($errors !== []) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $errors[0],
                    'errors' => ['description' => $errors],
                ], 422);
            }

            return back()->withErrors(['description' => $errors])->withInput();
        }

        return ['description' => $clean];
    }

    /**
     * Marketing may edit metrics/geo/niches, plus URL/price on pending listings.
     *
     * @return array<string, mixed>|JsonResponse|RedirectResponse
     */
    private function marketingUpdatePayload(Request $request, Site $site)
    {
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $canFixListing = ! $this->marketingListingIsLocked($site);

        $metrics = [];
        foreach (['da', 'dr', 'traffic'] as $metric) {
            if ($request->exists($metric)) {
                $metrics[$metric] = $this->normalizeMetricInt($request->input($metric));
            }
        }
        if ($metrics !== []) {
            $request->merge($metrics);
        }

        if ($canFixListing) {
            $this->mergeNormalizedUrlOrFail($request, 'site_url', 'siteUrl');
            $this->mergeNormalizedUrlOrFail($request, 'example_url', 'exampleUrl', nullable: true);
        }

        // Resolve exact niche names and group aliases (e.g. Technology → Technology & Gadgets).
        // Also recovers from urlencoded truncation of "Technology & Gadgets" → "Technology".
        $resolved = Category::resolveNicheNames($this->nicheNamesInput($request->input('categories', [])));
        $categories = $resolved['resolved'];
        $unknownNiches = $resolved['unknown'];
        $request->merge(['categories' => $categories]);

        $rules = [
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'language' => 'required|string|max:10',
            'country' => 'required|string|max:10',
            'categories' => 'required|array|min:1|max:7',
            'site_image' => SiteImageUpload::fieldRules($request->hasFile('site_image')),
        ];
        if ($canFixListing) {
            $rules['site_name'] = 'sometimes|required|string|max:255';
            $rules['site_url'] = 'sometimes|required|url|max:255';
            $rules['example_url'] = 'nullable|url|max:255';
            $rules['price'] = 'sometimes|required|numeric|min:0|max:999999.99';
            $rules['description'] = 'sometimes|nullable|string|max:20000';
        }

        if ($request->exists('site_name') && is_string($request->input('site_name'))) {
            $request->merge(['site_name' => $this->normalizeSiteName($request->input('site_name'))]);
        }

        $incomingDescription = null;
        $validateIncomingDescription = false;
        if ($canFixListing && $request->exists('description') && is_string($request->input('description'))) {
            $incomingDescription = app(SiteDescriptionSanitizer::class)
                ->sanitize(scalar_text($request->input('description', '')));
            $incomingPlain = SiteDescriptionRules::plainText($incomingDescription);
            $existingPlain = SiteDescriptionRules::plainText((string) $site->description);

            if ($incomingPlain === '') {
                // Quill empty HTML — keep the current brief so metric-only saves work.
                $incomingDescription = null;
            } elseif ($incomingPlain !== $existingPlain) {
                $validateIncomingDescription = true;
            }
        }

        $validator = Validator::make($request->all(), $rules, array_merge($this->siteImageValidationMessages(), [
            'price.max' => 'Price must be at most €999,999.99.',
        ]));

        // site_image is often a stored path string after upload-image; only
        // validate as a file when a real upload is present.
        if ($request->hasFile('site_image')) {
            $validator->addRules([
                'site_image' => SiteImageUpload::uploadedFileRules(false),
            ]);
        }

        $validator->after(function ($validator) use ($request, $allowedCountries, $allowedLanguages, $unknownNiches, $canFixListing, $site, $validateIncomingDescription, $incomingDescription) {
            $language = strtolower(trim(scalar_text($request->input('language', ''))));
            $country = strtolower(trim(scalar_text($request->input('country', ''))));

            if ($language !== '' && ! in_array($language, $allowedLanguages, true)) {
                $validator->errors()->add('language', 'Choose a valid marketplace language.');
            }
            if ($country !== '' && ! in_array($country, $allowedCountries, true)) {
                $validator->errors()->add('country', 'Choose a valid marketplace country.');
            }
            if ($country !== '' && $language !== '' && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                $validator->errors()->add(
                    'language',
                    'That language is not allowed for the selected country. Pick country first, then a paired language.'
                );
            }

            foreach ($unknownNiches as $cat) {
                $validator->errors()->add('categories', 'Unknown niche: '.$cat);
            }

            if ($canFixListing && $request->filled('site_url')) {
                $siteUrl = scalar_text($request->input('site_url', ''));
                $host = parse_url($siteUrl, PHP_URL_HOST);
                $domain = is_string($host) && $host !== '' ? $this->normalizeDomain($host) : '';
                if ($domain === '' || ! $this->isMarketplaceHost($domain)) {
                    $validator->errors()->add('site_url', 'Invalid URL');
                } else {
                    Site::releaseCancelledBulkDomain($domain, (int) $site->publisher_id);
                    $existing = $this->findSiteByDomain($domain, exceptId: $site->id);
                    if ($existing) {
                        $validator->errors()->add('site_url', $this->domainAlreadyRegisteredMessage($existing));
                    }
                }
            }

            if ($canFixListing && $request->filled('example_url')) {
                $exampleUrl = scalar_text($request->input('example_url', ''));
                $exampleHost = parse_url($exampleUrl, PHP_URL_HOST);
                $exampleDomain = is_string($exampleHost) && $exampleHost !== ''
                    ? $this->normalizeDomain($exampleHost)
                    : '';
                if ($exampleDomain === '' || ! $this->isMarketplaceHost($exampleDomain)) {
                    $validator->errors()->add('example_url', 'Invalid URL');
                }
            }

            if ($canFixListing && ($request->filled('site_url') || $request->exists('example_url'))) {
                $siteUrl = $request->filled('site_url') ? $request->input('site_url') : $site->site_url;
                $exampleUrl = $request->exists('example_url') ? $request->input('example_url') : $site->example_url;
                if ($this->exampleUrlHostDiffers($siteUrl, $exampleUrl)) {
                    $validator->errors()->add('example_url', 'Example URL must be on the same website domain.');
                }
            }

            if ($validateIncomingDescription && is_string($incomingDescription)) {
                foreach (SiteDescriptionRules::errors($incomingDescription) as $message) {
                    $validator->errors()->add('description', $message);
                }
            }
        });

        if ($validator->fails()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        $language = strtolower(trim(scalar_text($request->input('language'))));
        $country = strtolower(trim(scalar_text($request->input('country'))));

        $payload = [
            'da' => (int) $request->input('da'),
            'dr' => (int) $request->input('dr'),
            'traffic' => (int) $request->input('traffic'),
            'language' => $language,
            'languages' => [$language],
            'country' => $country,
            'countries' => [$country],
            'category' => Site::fitCategoryColumn(implode('|', $categories), $categories),
            'categories' => $categories,
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'enrichment_status' => 'ready',
        ];

        if ($canFixListing) {
            if ($request->exists('site_name')) {
                $payload['site_name'] = scalar_text($request->input('site_name'));
            }
            if ($request->exists('site_url')) {
                $siteUrl = scalar_text($request->input('site_url'));
                $host = parse_url($siteUrl, PHP_URL_HOST) ?: '';
                $domain = $this->normalizeDomain($host);
                $payload['site_url'] = $siteUrl;
                if ($domain !== '') {
                    $payload['domain'] = $domain;
                }
            }
            if ($request->exists('example_url')) {
                $payload['example_url'] = scalar_text($request->input('example_url'));
            }
            if ($request->exists('price')) {
                $payload['price'] = $request->input('price');
            }
            if ($incomingDescription !== null) {
                $payload['description'] = $incomingDescription;
            }
        }

        // Same image rules as admin — optional; leave empty to keep current.
        if ($request->hasFile('site_image')) {
            $upload = $request->file('site_image');
            if ($upload && ! $upload->isValid()) {
                $message = $this->siteImageValidationMessages()['site_image.uploaded'];
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'errors' => ['site_image' => [$message]],
                    ], 422);
                }

                return back()->withErrors(['site_image' => $message])->withInput();
            }

            $disk = Storage::disk('public');
            $disk->makeDirectory('sites');

            $stored = $this->storeStaffSiteImage($upload);
            if ($stored === null) {
                $message = 'Could not save the site image to storage. Check disk permissions and MEDIA_PATH.';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'errors' => ['site_image' => [$message]],
                    ], 500);
                }

                return back()->withErrors(['site_image' => $message])->withInput();
            }

            PublicStorageLink::ensure();
            if (! PublicStorageLink::pathIsPubliclyReachable($stored)) {
                Log::warning('Site image saved via marketing update; public/storage probe failed (kept upload)', [
                    'path' => $stored,
                    'disk_root' => config('filesystems.disks.public.root'),
                ]);
            }

            $payload['site_image'] = $stored;
            $request->attributes->set('staff_stored_site_image', $stored);
        } elseif ($request->filled('site_image') && ! $request->hasFile('site_image')) {
            // JSON/AJAX path: image already persisted via upload-image.
            $path = $this->postedSiteImagePath($request->input('site_image'));
            $current = is_string($site->site_image) ? $this->postedSiteImagePath($site->site_image) : null;
            if ($path !== null && $current !== null && $path === $current) {
                $payload['site_image'] = $path;
            }
        }

        return $payload;
    }

    private function domainAlreadyRegisteredMessage(Site $existing): string
    {
        return $existing->occupyingDomainMessage();
    }

    /**
     * Prefer a live listing when legacy duplicates exist so the restore copy
     * is not shown while a non-archived row already occupies the domain.
     */
    private function findSiteByDomain(string $domain, ?int $exceptId = null, bool $lock = false): ?Site
    {
        return Site::findOccupyingDomain($domain, $exceptId, $lock);
    }

    private function isDomainUniqueConstraintFailure(\Throwable $e): bool
    {
        $message = $e->getMessage();

        $isUnique = str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, '1062');

        return $isUnique && (str_contains($message, 'domain') || str_contains($message, 'publisher_id_domain'));
    }

    /**
     * Do not pin every save failure on Site URL — that made marketing think
     * a valid .fr listing URL was rejected.
     *
     * @return array<string, string>
     */
    private function staffSiteUpdateFailureErrors(\Throwable $e): array
    {
        if ($this->isDomainUniqueConstraintFailure($e)) {
            return ['site_url' => 'This website domain is already registered.'];
        }

        $message = $e->getMessage();
        if (str_contains($message, 'Unknown column') || str_contains($message, 'no such column')) {
            return ['save' => 'We could not save this website because the database is missing a recent update. Run the latest migrations and try again.'];
        }
        if (str_contains($message, 'Data too long') || str_contains($message, '1406')) {
            return ['save' => 'One of the fields is too long to save. Shorten it and try again.'];
        }

        return ['save' => 'We could not save this website. Please try again.'];
    }

    /**
     * @return array<string, string>
     */
    private function placementOfferValidationRules(): array
    {
        return [
            'price_sensitive.*' => 'nullable|numeric|min:0|max:999999.99',
            'sensitive.crypto' => 'nullable|boolean',
            'sensitive.trading' => 'nullable|boolean',
            'sensitive.CBD' => 'nullable|boolean',
            'sensitive.forex' => 'nullable|boolean',
            'price_sensitive.crypto' => 'nullable|numeric|min:0|max:999999.99',
            'price_sensitive.trading' => 'nullable|numeric|min:0|max:999999.99',
            'price_sensitive.CBD' => 'nullable|numeric|min:0|max:999999.99',
            'price_sensitive.forex' => 'nullable|numeric|min:0|max:999999.99',
            'homepage.1' => 'nullable|boolean',
            'homepage.7' => 'nullable|boolean',
            'homepage.30' => 'nullable|boolean',
            'price_homepage.1' => 'nullable|numeric|min:0|max:999999.99',
            'price_homepage.7' => 'nullable|numeric|min:0|max:999999.99',
            'price_homepage.30' => 'nullable|numeric|min:0|max:999999.99',
            'social.facebook' => 'nullable|boolean',
            'social.instagram' => 'nullable|boolean',
            'social.x' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function placementOfferValidationAttributes(): array
    {
        return [
            'price_sensitive.crypto' => 'crypto extra price',
            'price_sensitive.trading' => 'trading extra price',
            'price_sensitive.CBD' => 'CBD extra price',
            'price_sensitive.forex' => 'forex extra price',
            'price_homepage.1' => '1-day homepage fee',
            'price_homepage.7' => '7-day homepage fee',
            'price_homepage.30' => '30-day homepage fee',
        ];
    }

    /**
     * Keep only a relative public-disk cover under sites/. Arrays become "Array" if cast.
     */
    private function postedSiteImagePath(mixed $raw): ?string
    {
        return SiteImageUpload::publicCoverPath($raw);
    }

    private function deleteStoredSiteImage(?string $path): void
    {
        SiteImageUpload::deletePublicCover($path);
    }

    /**
     * Blank/null → €0. Arrays/objects/non-numeric → null (skip; never cast to 1.0).
     */
    private function optionalNonNegativeMoney(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return 0.0;
        }
        if (! is_scalar($raw) || is_bool($raw)) {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }

        $amount = round((float) $raw, 2);
        if (! is_finite($amount) || $amount < 0 || $amount > 999999.99) {
            return null;
        }

        return $amount;
    }

    /**
     * @return array<string, float>
     */
    private function collectSensitivePrices(Request $request): array
    {
        $sensitivePrices = [];
        foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
            if (! $request->boolean("sensitive.$topic")) {
                continue;
            }

            $amount = $this->optionalNonNegativeMoney($request->input("price_sensitive.$topic"));
            if ($amount === null) {
                continue;
            }
            $sensitivePrices[$topic] = $amount;
        }

        return $sensitivePrices;
    }

    /**
     * @return array<string, float>
     */
    private function collectHomepagePlacementPrices(Request $request): array
    {
        $out = [];
        foreach (config('site_placement.homepage_days', [1, 7, 30]) as $days) {
            if (! $request->boolean("homepage.$days")) {
                continue;
            }

            $price = $this->optionalNonNegativeMoney($request->input("price_homepage.$days"));
            if ($price === null) {
                continue;
            }

            $out[(string) $days] = $price;
        }

        return $out;
    }

    /**
     * @return array<string, true>|null
     */
    private function collectSocialPromotion(Request $request): ?array
    {
        $channels = [];
        foreach (config('site_placement.social_channels', ['facebook', 'instagram', 'x']) as $channel) {
            if ($request->boolean("social.$channel")) {
                $channels[$channel] = true;
            }
        }

        return $channels === [] ? null : $channels;
    }

    /**
     * Max upload size for site cover images (kilobytes).
     * App cap is 10 MB. PHP ini is not the advertised product limit.
     */
    private function siteImageMaxKilobytes(): int
    {
        return SiteImageUpload::maxKilobytes();
    }

    private function siteImageMaxMegabytesLabel(): int
    {
        return SiteImageUpload::maxMegabytesLabel();
    }

    /**
     * @return array<string, string>
     */
    private function siteImageValidationMessages(): array
    {
        $mb = $this->siteImageMaxMegabytesLabel();

        return [
            'site_image.uploaded' => 'The site image failed to upload. Use JPEG, PNG, GIF, or WebP under '.$mb.' MB (check the file is not corrupted).',
            'site_image.image' => 'The site image must be a JPEG, PNG, GIF, or WebP file.',
            'site_image.mimes' => 'The site image must be a JPEG, PNG, GIF, or WebP file.',
            'site_image.extensions' => 'The site image must be a JPEG, PNG, GIF, or WebP file.',
            'site_image.regex' => 'The site image must be a stored sites/ path or an uploaded JPEG, PNG, GIF, or WebP file.',
            'site_image.max' => 'The site image must be under '.$mb.' MB.',
            'site_image.required' => 'Choose a site image to upload.',
        ];
    }

    /**
     * Write the cover path even when a leftover saved() hook throws
     * (missing GuestPostPriceIndex after a partial Hostinger upload).
     */
    private function persistStaffSiteImagePath(Site $site, string $path): void
    {
        if (! Site::hasSitesColumn('site_image')) {
            throw new \RuntimeException('sites.site_image column is missing');
        }

        try {
            $site->update(['site_image' => $path]);

            return;
        } catch (\Throwable $e) {
            Log::warning('Staff site image model update failed; retrying without events', [
                'site_id' => $site->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        $site->withoutEvents(function () use ($site, $path) {
            $site->update(['site_image' => $path]);
        });
    }

    /**
     * Persist a staff cover as WebP when GD can convert. GIF stays GIF.
     * JPEG/PNG keep original bytes when they are a real image and WebP is unavailable.
     */
    private function storeStaffSiteImage(UploadedFile $file): ?string
    {
        try {
            $disk = Storage::disk('public');
            $disk->makeDirectory('sites');

            $stored = app(ImageOptimizationService::class)->storeSafePublicImage($file, 'sites');

            if (! is_string($stored) || $stored === '') {
                return null;
            }

            // Hostinger open_basedir can make exists() false after a good store()
            // (same class of leftover as is_file() on PHP tmp). Trust the path.
            return $stored;
        } catch (\Throwable $e) {
            Log::error('Staff site image store failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function parseCategoryList($raw): array
    {
        return Category::normalizeNicheInputs($raw);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function parseCodeList($value): array
    {
        $parts = [];
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[|,]/', scalar_text($value)) ?: [];
        }

        $codes = [];
        foreach ($parts as $part) {
            $code = strtolower(trim(scalar_text($part)));
            if ($code !== '' && preg_match('/^[a-z]{2}$/', $code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Form/JSON text. Arrays/objects must not reach (string) — PHP 8 TypeError.
     */
    private function scalarString(mixed $value): string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Empty optional text, including ConvertEmptyStringsToNull → null.
     * Arrays are not blank — those must 422, not wipe the stored value.
     */
    private function isBlankStringInput(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function firstScalarString(mixed $raw): mixed
    {
        if (is_array($raw)) {
            $raw = reset($raw);
        }

        return $raw;
    }

    private function nonStringUrlErrors(array $values): array
    {
        $errors = [];
        foreach ($values as $field => $value) {
            if (! is_string($value)) {
                $errors[$field] = 'Invalid URL';
            }
        }

        return $errors;
    }

    /**
     * Strip www, trailing dots, ports, and case so example.com:443 matches example.com.
     */
    private function normalizeDomain(string $host): string
    {
        return Site::normalizeMarketplaceDomain($host);
    }

    private function isMarketplaceHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_starts_with($host, '[')) {
            return false;
        }
        if (str_contains($host, ':') && preg_match('/^(.+):(\d+)$/', $host, $m) === 1) {
            $host = $m[1];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);
        $tld = (string) end($labels);
        if (in_array($tld, ['localhost', 'local', 'internal', 'invalid'], true)) {
            return false;
        }
        $allNumeric = true;
        foreach ($labels as $label) {
            if ($label === '') {
                return false;
            }
            if (! ctype_digit($label)) {
                $allNumeric = false;
            }
        }

        return ! $allNumeric;
    }

    private function normalizeSiteName(string $raw): string
    {
        $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $raw) ?? $raw;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    private function urlHost(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return '';
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $this->normalizeDomain($host) : '';
    }

    private function exampleUrlHostDiffers(mixed $siteUrl, mixed $exampleUrl): bool
    {
        $siteHost = $this->urlHost($siteUrl);
        $exampleHost = $this->urlHost($exampleUrl);

        return $siteHost !== '' && $exampleHost !== '' && $siteHost !== $exampleHost;
    }

    /**
     * @return string|iterable<int|string, mixed>|null
     */
    private function nicheNamesInput(mixed $raw): string|iterable|null
    {
        if (is_string($raw) || is_iterable($raw) || $raw === null) {
            return $raw;
        }

        return [];
    }

    private function mergeNormalizedUrlOrFail(
        Request $request,
        string $field,
        ?string $alt = null,
        bool $nullable = false
    ): void {
        if (! $request->exists($field) && ($alt === null || ! $request->exists($alt))) {
            return;
        }

        $raw = $request->input($field, $alt !== null ? $request->input($alt) : null);
        if ($raw === null || $raw === '') {
            if ($nullable) {
                $request->merge([$field => null]);
            }

            return;
        }

        if (! is_string($raw)) {
            throw ValidationException::withMessages([$field => ['Invalid URL']]);
        }

        $normalized = $this->normalizeHttpUrl($raw);
        if ($normalized === '') {
            throw ValidationException::withMessages([$field => ['Invalid URL']]);
        }
        $request->merge([$field => $normalized]);
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, "\0") || preg_match('/\s/u', $url) === 1) {
            return '';
        }

        // Protocol-relative //host → https://host (do not prefix as https:////host).
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (preg_match('~^(?:https?|ftps?)://~i', $url) !== 1) {
            // Reject javascript:/data:/mailto: and ftp://. Keep host:port (example.com:8080).
            if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $url, $schemeMatch) === 1) {
                $scheme = strtolower($schemeMatch[1]);
                $hasAuthority = preg_match('~^'.preg_quote($schemeMatch[1], '~').'://~i', $url) === 1;
                if ($hasAuthority || in_array($scheme, ['javascript', 'data', 'mailto', 'vbscript', 'file', 'about', 'blob'], true)) {
                    return '';
                }
                if (! str_contains($scheme, '.')) {
                    return '';
                }
            }
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = rtrim($parts['host'], '.');
        if ($host === '') {
            return '';
        }
        if (function_exists('idn_to_ascii') && ! filter_var($host, FILTER_VALIDATE_IP) && ! str_starts_with($host, '[')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
            $host = strtolower($host);
        }
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        $authority = $host;
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            if ($port < 1 || $port > 65535) {
                return '';
            }
            if (! in_array($port, [80, 443], true)) {
                $authority .= ':'.$port;
            }
        }

        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$authority.$path.$query;
    }

    /**
     * First usable positive int from a query/form value.
     * PHP casts any non-empty array to 1, which would select user 1.
     */
    private function firstPositiveInt(mixed $value): int
    {
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($item) use (&$flat) {
                if (is_scalar($item)) {
                    $flat[] = $item;
                }
            });
            $value = $flat[0] ?? 0;
        }

        if (! is_scalar($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /**
     * On/off flag from JSON or form input.
     * PHP casts any non-empty array to 1, so active:[0] would activate.
     */
    private function requestFlag(Request $request, string $key): bool
    {
        $value = $request->input($key);
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($item) use (&$flat) {
                if (is_scalar($item)) {
                    $flat[] = $item;
                }
            });
            $value = $flat[0] ?? false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $raw = strtolower(trim((string) $value));

        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Dedicated admin edit only posts the primary category. Keep extra niches
     * unless the request sent an explicit categories list (or multiple values).
     *
     * @param  list<string>  $incoming
     * @return list<string>
     */
    private function mergeAdminCategoryUpdate(Site $site, array $incoming, bool $replaceAll): array
    {
        if ($replaceAll) {
            return $incoming;
        }

        $primary = $incoming[0] ?? '';
        $oldPrimary = trim((string) ($site->category ?? ''));
        $kept = [];
        foreach ($site->categories_array ?? [] as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if ($oldPrimary !== '' && strcasecmp($name, $oldPrimary) === 0) {
                continue;
            }
            if ($primary !== '' && strcasecmp($name, $primary) === 0) {
                continue;
            }
            $kept[] = $name;
        }

        return $primary !== '' ? array_merge([$primary], $kept) : $kept;
    }

    /**
     * Normalize DA/DR/traffic from number inputs (commas, decimals, blanks).
     */
    private function normalizeMetricInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value) || is_object($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $raw = trim((string) $value);
        $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
        if ($raw === '') {
            return null;
        }

        // US thousands: 15,000 or 1,200,000.5
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $raw)) {
            $raw = str_replace(',', '', $raw);
        }
        // EU thousands: 15.000 or 1.200.000,5
        elseif (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }
        // Decimal comma only: 48,5
        elseif (preg_match('/^\d+,\d+$/', $raw)) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw);
    }

    // VERIFY / UNVERIFY (approve / reject) — admin only
    public function verify(Request $request, $id)
    {
        if (! auth()->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can verify or unverify sites.',
            ], 403);
        }

        try {
            $approving = $this->requestFlag($request, 'verified');
            $reason = $this->validatedStatusReason($request, ! $approving);

            $site = Site::findOrFail($id);

            if ($approving && $site->isPendingPublisherAcceptance()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This site is waiting for the publisher to accept it into My Sites.',
                ], 422);
            }

            // Heal complete drafts; admin approve also clears incomplete awaiting_details.
            $site->promoteFromAwaitingDetailsIfComplete();
            $site->refresh();

            $oldStatus = (int) $site->verified;
            $site->verified = $approving ? 1 : 0;
            if ($site->verified) {
                $site->verified_at = now();
                $site->verify_method = 'manual';
                $site->verify_token = null;
                $site->verify_token_created_at = null;
                // Leave the review/onboarding queue once approved.
                $site->onboarding_status = null;
            } else {
                $site->verified_at = null;
                $site->verify_method = null;
                Site::ensureStatusReasonColumns();
                $this->applyStatusReason($site, $reason);
                $this->restoreBulkOnboardingAfterStaffUndo($site);
            }

            try {
                $site->save();
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->staffSiteMutationFailure(
                    'Failed to update site verification',
                    (int) $id,
                    $e,
                    'Could not update verification.'
                );
            }

            $this->syncLinkedBulkAfterSiteRemoved($site->bulk_site_request_id);

            $action = $site->verified ? 'site.approved' : 'site.rejected';
            $label = $site->verified ? 'approved' : 'rejected';
            $verifiedChanged = $oldStatus !== (int) $site->verified;

            if ($verifiedChanged) {
                ActivityLogger::tryLog(
                    $action,
                    auth()->user()->name.' '.$label.' site "'.$site->site_name.'"',
                    $site,
                    [
                        'from' => $oldStatus,
                        'to' => (int) $site->verified,
                        'bulk_site_request_id' => $site->bulk_site_request_id,
                        'reason' => $reason,
                    ],
                    $site->site_name
                );
            }

            // After a real verify: refresh homepage screenshot.
            // Skip automated metrics when the publisher entered DA/DR/traffic manually.
            if ($verifiedChanged && $site->verified && config('site_enrichment.enabled', true)) {
                $runMetrics = ! (bool) $site->metrics_manual;
                EnrichSiteJob::dispatch($site->id, 'verify', $runMetrics, true);
            }

            // Verify / unverify is an admin decision — clear open review reminders for this site.
            try {
                app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
            } catch (\Throwable $e) {
                Log::warning('Could not complete site review notifications after verify: '.$e->getMessage());
            }

            $emailSent = false;
            $status = $site->verified ? 'verified' : 'unverified';
            $notifyReason = $approving ? null : $reason;

            if ($verifiedChanged) {
                try {
                    $publisher = $site->publisher;
                    if ($publisher && $publisher->email) {
                        Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status, null, $notifyReason));
                        $emailSent = true;
                    }
                    if ($publisher) {
                        app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status, $notifyReason);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to send verification notification: '.$e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Verification updated',
                'email_sent' => $emailSent,
                'verified' => (bool) $site->verified,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->staffSiteMutationFailure(
                'Failed to update site verification',
                (int) $id,
                $e,
                'Could not update verification.'
            );
        }
    }

    private function isMarketingActor(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return (bool) ($actor?->isMarketing() && ! $actor?->isAdmin());
    }

    private function staffSiteMutationFailure(string $logMessage, int $siteId, \Throwable $e, string $userMessage): JsonResponse
    {
        Log::error($logMessage, [
            'site_id' => $siteId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => $userMessage,
        ], 500);
    }

    private function staffCanActivateSite(Site $site): bool
    {
        return $site->staffCanGoLive($this->isMarketingActor());
    }

    private function staffActivateBlockReason(Site $site): ?string
    {
        return $site->staffGoLiveBlockReason($this->isMarketingActor());
    }

    // TOGGLE ACTIVE STATUS — admin and marketing (shared Sites Management)
    public function toggleActive(Request $request, $id)
    {
        $actor = auth()->user();
        if (! $actor?->canActivateSites()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to activate or deactivate sites.',
            ], 403);
        }

        try {
            $site = Site::findOrFail($id);
            $activating = $this->requestFlag($request, 'active');
            // Must not be swallowed by the catch below — UI expects 422 + errors.reason.
            $reason = $this->validatedStatusReason($request, ! $activating);

            $isMarketingActor = (bool) ($actor?->isMarketing() && ! $actor?->isAdmin());

            if ($activating && ! (bool) $site->active) {
                $block = $site->staffGoLiveBlockReason($isMarketingActor);
                if ($block !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $block,
                        'missing_market' => ! $site->hasMarketplaceCountry(),
                        'below_quality_bar' => $isMarketingActor && ! $site->hasGoodMetrics(),
                    ], 422);
                }
            }

            $oldStatus = (int) $site->active;
            $site->active = $activating ? 1 : 0;
            if ($activating) {
                // Activate only flips live. The Verified badge is assigned
                // separately via verify() so staff can choose who gets it.
                $site->onboarding_status = null;
            } else {
                Site::ensureStatusReasonColumns();
                $this->applyStatusReason($site, $reason);
                $this->restoreBulkOnboardingAfterStaffUndo($site);
            }
            $site->save();
            $this->syncLinkedBulkAfterSiteRemoved($site->bulk_site_request_id);

            $activeChanged = $oldStatus !== (int) $site->active;

            if ($activeChanged) {
                $action = $site->active ? 'site.activated' : 'site.deactivated';
                $label = $site->active ? 'activated' : 'deactivated';

                ActivityLogger::tryLog(
                    $action,
                    ($actor->name ?? 'Staff').' '.$label.' site "'.$site->site_name.'"',
                    $site,
                    [
                        'from' => $oldStatus,
                        'to' => (int) $site->active,
                        'bulk_site_request_id' => $site->bulk_site_request_id,
                        'by_role' => $actor->activeRole(),
                        'reason' => $reason,
                        'non_english_brief_warned' => $activating && ! $site->descriptionLooksLikeEnglish(),
                    ],
                    $site->site_name
                );
            }

            // Activate / deactivate counts as an admin decision for the open review task.
            try {
                app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
            } catch (\Throwable $e) {
                Log::warning('Could not complete site review notifications after active toggle: '.$e->getMessage());
            }

            $emailSent = false;
            $status = $site->active ? 'activated' : 'deactivated';
            $notifyReason = $activating ? null : $reason;
            $belowQualityBar = $activating && ! $site->hasGoodMetrics();
            $warning = $belowQualityBar
                ? 'Activated below the quality bar (DA ≥ 30, DR ≥ 30, traffic ≥ 10,000). Listing is live; consider updating metrics before promoting it.'
                : null;

            if ($activeChanged) {
                try {
                    $publisher = $site->publisher;
                    if ($publisher && $publisher->email) {
                        Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status, null, $notifyReason));
                        $emailSent = true;
                    }
                    if ($publisher) {
                        app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status, $notifyReason);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to send status notification: '.$e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => $activating ? 'Site activated' : 'Site deactivated',
                'email_sent' => $emailSent,
                'active' => (bool) $site->active,
                'verified' => (bool) $site->verified,
                'reason' => $notifyReason,
                'warning' => $warning,
                'missing_market' => false,
                'below_quality_bar' => $belowQualityBar,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->staffSiteMutationFailure(
                'Failed to toggle site active status',
                (int) $id,
                $e,
                'Could not update active status.'
            );
        }
    }

    /**
     * @return string|null Trimmed reason when provided; null when not required / empty optional.
     */
    private function validatedStatusReason(Request $request, bool $required): ?string
    {
        $rules = $required
            ? ['reason' => ['required', 'string', 'min:10', 'max:1000']]
            : ['reason' => ['nullable', 'string', 'max:1000']];

        $data = $request->validate($rules);
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

        return $reason !== '' ? $reason : null;
    }

    private function applyStatusReason(Site $site, ?string $reason): void
    {
        if ($reason === null) {
            return;
        }

        $site->status_reason = $reason;
        $site->status_reason_at = now();
        $site->status_reason_by = auth()->id();
    }

    // DELETE — pending never-ordered: hard delete. Live listings: archive.
    // Sites with order items cannot be removed (FK restrict + 422).
    public function destroy(Request $request, $id)
    {
        $user = auth()->user();

        $outcome = DB::transaction(function () use ($request, $id, $user) {
            $site = Site::query()->lockForUpdate()->findOrFail($id);

            $isAdmin = (bool) $user?->isAdmin();
            $isMarketingPendingDelete = (bool) $user?->isMarketing() && $site->canBeDeletedByMarketing();

            if (! $isAdmin && ! $isMarketingPendingDelete) {
                return [
                    'http' => 403,
                    'payload' => [
                        'success' => false,
                        'message' => $user?->isMarketing()
                            ? 'Marketing can only delete pending sites that are not verified or active in the portal.'
                            : 'Only admins can delete sites.',
                    ],
                ];
            }

            $orderCount = $site->orderItemsCount();
            if ($orderCount > 0) {
                return [
                    'http' => 422,
                    'payload' => [
                        'success' => false,
                        'message' => $orderCount === 1
                            ? 'This site has 1 order and cannot be deleted. Deactivate it to hide it from the catalog.'
                            : 'This site has '.$orderCount.' orders and cannot be deleted. Deactivate it to hide it from the catalog.',
                        'order_count' => $orderCount,
                    ],
                ];
            }

            if ($site->isArchived()) {
                return [
                    'http' => 422,
                    'payload' => [
                        'success' => false,
                        'message' => 'This site is already archived.',
                    ],
                ];
            }

            $rejectionReason = $this->validatedStatusReason($request, true);

            Site::ensureStatusReasonColumns();
            $this->applyStatusReason($site, $rejectionReason);

            $meta = [
                'siteName' => $site->site_name,
                'siteId' => $site->id,
                'domain' => $site->domain,
                'bulkRequestId' => $site->bulk_site_request_id,
                'onboarding' => $site->onboarding_status,
                'rejectionReason' => $rejectionReason,
                'publisher' => $site->publisher,
                'isAdmin' => $isAdmin,
                'isMarketingPendingDelete' => $isMarketingPendingDelete,
            ];

            $shouldArchive = (bool) $site->verified || (bool) $site->active;
            if ($shouldArchive) {
                if (! $site->archiveByStaff($rejectionReason)) {
                    return [
                        'http' => 503,
                        'payload' => [
                            'success' => false,
                            'message' => 'Archive is not available yet.',
                        ],
                    ];
                }

                return $meta + [
                    'action' => 'archived',
                    'site' => $site->fresh() ?? $site,
                ];
            }

            $notifySnapshot = clone $site;
            if ($rejectionReason) {
                $notifySnapshot->status_reason = $rejectionReason;
            }

            return $meta + [
                'action' => 'deleted',
                'notifySnapshot' => $notifySnapshot,
                'cover' => is_string($site->site_image) ? $site->site_image : null,
                'screenshot' => is_string($site->screenshot_path) ? $site->screenshot_path : null,
                'thumb' => is_string($site->screenshot_thumb_path) ? $site->screenshot_thumb_path : null,
                'mediaSiteId' => (int) $site->id,
                'deleted' => (bool) $site->delete(),
            ];
        });

        if (isset($outcome['http'])) {
            return response()->json($outcome['payload'], $outcome['http']);
        }

        $siteName = $outcome['siteName'];
        $siteId = $outcome['siteId'];
        $domain = $outcome['domain'];
        $bulkRequestId = $outcome['bulkRequestId'];
        $onboarding = $outcome['onboarding'];
        $rejectionReason = $outcome['rejectionReason'];
        $publisher = $outcome['publisher'];
        $isAdmin = $outcome['isAdmin'];
        $isMarketingPendingDelete = $outcome['isMarketingPendingDelete'];

        if (($outcome['action'] ?? '') === 'archived') {
            $site = $outcome['site'];
            try {
                app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
            } catch (\Throwable $e) {
                Log::warning('Could not complete site review notifications before archive: '.$e->getMessage());
            }

            $this->notifyPublisherSiteRemoved($site, $publisher, $rejectionReason, 'archived');

            ActivityLogger::tryLog(
                'site.archived',
                ($user->name ?? 'Staff').' archived site "'.$siteName.'"'.($domain ? ' ('.$domain.')' : ''),
                $site,
                [
                    'site_id' => $siteId,
                    'site_name' => $siteName,
                    'domain' => $domain,
                    'bulk_site_request_id' => $bulkRequestId,
                    'onboarding_status' => $onboarding,
                    'archived_by_role' => $user?->activeRole(),
                    'reason' => $rejectionReason,
                ],
                $siteName
            );

            $this->syncLinkedBulkAfterSiteRemoved($bulkRequestId);

            return response()->json([
                'success' => true,
                'archived' => true,
                'message' => 'Site archived and hidden from the catalog.',
            ]);
        }

        $notifySnapshot = $outcome['notifySnapshot'];
        try {
            app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($notifySnapshot);
        } catch (\Throwable $e) {
            Log::warning('Could not complete site review notifications before delete: '.$e->getMessage());
        }

        try {
            app(InAppNotificationService::class)->completePublisherSiteAssignmentNotifications($notifySnapshot);
        } catch (\Throwable $e) {
            Log::warning('Could not complete publisher invite notifications before delete: '.$e->getMessage());
        }

        SiteImageUpload::deleteListingPublicMedia(
            $outcome['cover'] ?? null,
            $outcome['screenshot'] ?? null,
            $outcome['thumb'] ?? null,
            (int) ($outcome['mediaSiteId'] ?? 0)
        );

        // Deleting a seeded draft re-pends the URL+price row (site_id nullOnDelete).
        // That is a staff correction, not a rejection — Done-reject notifies instead.
        if (! $this->bulkItemWasRepended($bulkRequestId, $domain)) {
            $this->notifyPublisherSiteRemoved($notifySnapshot, $publisher, $rejectionReason, 'removed');
        }

        ActivityLogger::tryLog(
            $isMarketingPendingDelete && ! $isAdmin ? 'site.deleted_by_marketing' : 'site.deleted',
            ($user->name ?? 'Staff').' deleted site "'.$siteName.'"'.($domain ? ' ('.$domain.')' : ''),
            null,
            [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'domain' => $domain,
                'bulk_site_request_id' => $bulkRequestId,
                'publisher_id' => $publisher?->id,
                'onboarding_status' => $onboarding,
                'deleted_by_role' => $user?->activeRole(),
                'reason' => $rejectionReason,
            ],
            $siteName
        );

        $this->syncLinkedBulkAfterSiteRemoved($bulkRequestId);

        return response()->json([
            'success' => true,
            'archived' => false,
            'message' => 'Site deleted successfully',
        ]);
    }

    private function syncLinkedBulkAfterSiteRemoved(?int $bulkRequestId): void
    {
        if (! $bulkRequestId) {
            return;
        }

        $bulk = BulkSiteRequest::query()->find($bulkRequestId);
        if (! $bulk) {
            return;
        }

        $bulk->refreshProgressStatus();
    }

    /**
     * Staff verify/activate clears onboarding. Undo must put a bulk draft
     * back in Complete details or the publisher is stuck with an empty queue.
     */
    private function restoreBulkOnboardingAfterStaffUndo(Site $site): void
    {
        if (! $site->bulk_site_request_id || $site->isArchived()) {
            return;
        }

        if ((bool) $site->verified || (bool) $site->active) {
            return;
        }

        if (! Site::hasSitesColumn('onboarding_status') || $site->onboarding_status !== null) {
            return;
        }

        $site->onboarding_status = $site->hasCompletedPublisherDetails()
            ? Site::ONBOARDING_DETAILS_COMPLETE
            : Site::ONBOARDING_AWAITING_DETAILS;
    }

    private function bulkItemWasRepended(?int $bulkRequestId, ?string $domain): bool
    {
        if (! $bulkRequestId || ! filled($domain)) {
            return false;
        }

        return BulkSiteRequestItem::query()
            ->where('bulk_site_request_id', $bulkRequestId)
            ->where('domain', $domain)
            ->whereNull('site_id')
            ->exists();
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function hydrateStaffQueueMeta($sites): void
    {
        $list = collect($sites)->filter(fn ($site) => $site instanceof Site)->values();
        if ($list->isEmpty()) {
            return;
        }

        $ids = $list->map(fn (Site $site) => (int) $site->id)->filter()->all();
        $notesBySite = [];
        if ($ids !== [] && SiteAdminNote::tableAvailable()) {
            $notes = SiteAdminNote::query()
                ->with('admin:id,name')
                ->whereIn('site_id', $ids)
                ->orderByDesc('id')
                ->get();
            foreach ($notes as $note) {
                $siteId = (int) $note->site_id;
                if (! isset($notesBySite[$siteId])) {
                    $notesBySite[$siteId] = [
                        'count' => 0,
                        'latest' => [
                            'body' => (string) $note->body,
                            'admin_name' => $note->admin?->name,
                            'created_at' => optional($note->created_at)?->toIso8601String(),
                        ],
                    ];
                }
                $notesBySite[$siteId]['count']++;
            }
        }

        $duplicates = $this->staffDuplicateMap($list);

        foreach ($list as $site) {
            $siteId = (int) $site->id;
            $noteMeta = $notesBySite[$siteId] ?? ['count' => 0, 'latest' => null];
            $site->setAttribute('staff_notes_count', (int) $noteMeta['count']);
            $site->setAttribute('staff_latest_note', $noteMeta['latest']);
            $site->setAttribute('staff_duplicate', $duplicates[$siteId] ?? null);
        }
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{id:int,domain:?string,site_name:?string,publisher_id:?int}>
     */
    private function staffDuplicateMap($sites): array
    {
        $list = collect($sites)->filter(fn ($site) => $site instanceof Site);
        $ids = $list->map(fn (Site $site) => (int) $site->id)->all();
        $candidateToIds = [];
        foreach ($list as $site) {
            $host = (string) ($site->domain ?: '');
            if ($host === '') {
                continue;
            }
            foreach (Site::domainLookupCandidates($host) as $candidate) {
                $candidateToIds[$candidate][] = (int) $site->id;
            }
        }
        if ($candidateToIds === []) {
            return [];
        }

        $others = Site::query()
            ->notArchived()
            ->whereIn('domain', array_keys($candidateToIds))
            ->whereNotIn('id', $ids)
            ->get(['id', 'domain', 'site_name', 'publisher_id']);

        $map = [];
        foreach ($others as $other) {
            $candidates = Site::domainLookupCandidates((string) $other->domain);
            $hitIds = [];
            foreach ($candidates as $candidate) {
                foreach ($candidateToIds[$candidate] ?? [] as $siteId) {
                    $hitIds[$siteId] = true;
                }
            }
            $payload = [
                'id' => (int) $other->id,
                'domain' => $other->domain,
                'site_name' => $other->site_name,
                'publisher_id' => $other->publisher_id ? (int) $other->publisher_id : null,
            ];
            foreach (array_keys($hitIds) as $siteId) {
                if (! isset($map[$siteId])) {
                    $map[$siteId] = $payload;
                }
            }
        }

        return $map;
    }

    private function staffCanNudgeSite(Site $site): bool
    {
        if ($site->isArchived()) {
            return false;
        }
        if ((bool) $site->verified || (bool) $site->active) {
            return false;
        }

        return $site->awaitsPublisherDetails()
            || $site->hasDetailsComplete()
            || $site->isPendingPublisherAcceptance();
    }

    private function staffNudgeKind(Site $site): string
    {
        return $site->isPendingPublisherAcceptance() ? 'accept' : 'details';
    }

    public function restore(Request $request, $id)
    {
        if (! auth()->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can restore archived sites.',
            ], 403);
        }

        if (! Site::hasSitesColumn('archived_at')) {
            return response()->json([
                'success' => false,
                'message' => 'Archive is not available yet.',
            ], 503);
        }

        $site = Site::query()->findOrFail($id);
        if (! $site->isArchived()) {
            return response()->json([
                'success' => false,
                'message' => 'This site is not archived.',
            ], 422);
        }

        $occupier = $site->domain ? Site::findOccupyingDomain((string) $site->domain, (int) $site->id) : null;
        if ($occupier && ! $occupier->isArchived()) {
            return response()->json([
                'success' => false,
                'message' => 'Another listing already occupies '.$occupier->domain.'. Restore is blocked until that row is archived.',
                'duplicate_id' => (int) $occupier->id,
            ], 422);
        }

        if (! $site->restoreFromArchive()) {
            return response()->json([
                'success' => false,
                'message' => 'Could not restore this site.',
            ], 422);
        }

        $site->refresh();

        ActivityLogger::tryLog(
            'site.unarchived',
            (auth()->user()?->name ?? 'Staff').' restored site "'.$site->site_name.'" from archive',
            $site,
            [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'by_role' => auth()->user()?->activeRole(),
            ],
            $site->site_name
        );

        try {
            $publisher = $site->publisher;
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, 'restored'));
            }
            if ($publisher) {
                app(InAppNotificationService::class)->notifySiteStatusChanged($site, 'restored');
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify publisher after site restore: '.$e->getMessage());
        }

        $message = 'Site restored. It remains inactive until it is active again.';
        if ($site->isCatalogVisible()) {
            $message = 'Site restored to the catalog.';
        }

        return response()->json([
            'success' => true,
            'archived' => false,
            'message' => $message,
        ]);
    }

    public function storeNote(Request $request, $id)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        if (! SiteAdminNote::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Site notes cannot be saved on this database.',
            ], 503);
        }

        $site = Site::query()->findOrFail($id);
        $note = SiteAdminNote::create([
            'site_id' => $site->id,
            'admin_id' => $request->user()?->id,
            'body' => trim($data['body']),
            'created_at' => now(),
        ]);

        ActivityLogger::tryLog(
            'site.note_added',
            ($request->user()?->name ?? 'Staff').' added an internal note on site "'.$site->site_name.'"',
            $site,
            ['note_id' => $note->id],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Note saved.',
            'note' => [
                'id' => (int) $note->id,
                'body' => (string) $note->body,
                'admin_name' => $request->user()?->name,
                'created_at' => optional($note->created_at)?->toIso8601String(),
            ],
        ]);
    }

    public function nudgePublisher(Request $request, $id)
    {
        $site = Site::query()->with('publisher:id,name,email')->findOrFail($id);
        if (! $this->staffCanNudgeSite($site)) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is not waiting on the publisher.',
            ], 422);
        }

        $publisher = $site->publisher;
        if (! $publisher?->email) {
            return response()->json([
                'success' => false,
                'message' => 'This publisher has no email address.',
            ], 422);
        }

        $kind = $this->staffNudgeKind($site);
        Mail::to($publisher->email)->send(new PublisherListingNudge($site, $kind, $publisher));
        try {
            app(InAppNotificationService::class)->notifyPublisherListingNudge($site, $kind);
        } catch (\Throwable $e) {
            Log::warning('Could not create listing nudge bell: '.$e->getMessage());
        }

        ActivityLogger::tryLog(
            'site.nudged',
            ($request->user()?->name ?? 'Staff').' nudged publisher about "'.$site->site_name.'"',
            $site,
            ['kind' => $kind],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => $kind === 'accept'
                ? 'Reminder sent to accept this listing.'
                : 'Reminder sent to finish listing details.',
            'kind' => $kind,
        ]);
    }

    public function bulk(Request $request)
    {
        if ($request->input('action') === 'verify') {
            return response()->json([
                'success' => false,
                'message' => 'Verification is a per-site admin choice. Use Manage → Verify on each listing.',
            ], 422);
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', 'in:deactivate,archive,restore,nudge'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $action = $data['action'];
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
        if (in_array($action, ['archive', 'deactivate'], true) && strlen($reason) < 10) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a reason (at least 10 characters).',
                'errors' => ['reason' => ['Please enter a reason (at least 10 characters).']],
            ], 422);
        }

        $done = [];
        $skipped = [];

        foreach ($data['ids'] as $id) {
            $id = (int) $id;

            if ($action === 'archive') {
                $candidate = Site::query()->find($id);
                if (! $candidate) {
                    $skipped[] = ['id' => $id, 'message' => 'Site not found.'];

                    continue;
                }
                if ($candidate->isArchived()) {
                    $skipped[] = ['id' => $id, 'message' => 'This site is already archived.'];

                    continue;
                }
                if (! $candidate->verified && ! $candidate->active) {
                    $skipped[] = ['id' => $id, 'message' => 'Pending listings are deleted one at a time, not archived in bulk.'];

                    continue;
                }
            }

            $inner = Request::create($request->url(), 'POST', array_filter([
                'active' => $action === 'deactivate' ? 0 : null,
                'reason' => in_array($action, ['archive', 'deactivate'], true) ? $reason : null,
            ], static fn ($value) => $value !== null));
            $inner->headers->set('Accept', 'application/json');
            $inner->headers->set('X-Requested-With', 'XMLHttpRequest');
            $inner->setUserResolver(fn () => $request->user());
            if ($request->hasSession()) {
                $inner->setLaravelSession($request->session());
            }

            try {
                $response = match ($action) {
                    'deactivate' => $this->toggleActive($inner, $id),
                    'archive' => $this->destroy($inner, $id),
                    'restore' => $this->restore($inner, $id),
                    'nudge' => $this->nudgePublisher($inner, $id),
                };
            } catch (ModelNotFoundException $e) {
                $skipped[] = ['id' => $id, 'message' => 'Site not found.'];

                continue;
            } catch (ValidationException $e) {
                $skipped[] = ['id' => $id, 'message' => collect($e->errors())->flatten()->first() ?: $e->getMessage()];

                continue;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'message' => UserFacingError::message($e, 'Could not update this site.')];

                continue;
            }

            $payload = json_decode($response->getContent(), true);
            if (! is_array($payload)) {
                $payload = [];
            }
            if ($response->getStatusCode() >= 400 || empty($payload['success'])) {
                $skipped[] = [
                    'id' => $id,
                    'message' => (string) ($payload['message'] ?? 'Could not update this site.'),
                ];

                continue;
            }

            $done[] = $id;
        }

        return response()->json([
            'success' => $done !== [],
            'message' => $done === []
                ? 'No sites were updated.'
                : count($done).' site'.(count($done) === 1 ? '' : 's').' updated.',
            'done' => $done,
            'skipped' => $skipped,
        ], $done === [] ? 422 : 200);
    }

    private function notifyPublisherSiteRemoved(
        Site $site,
        ?User $publisher,
        ?string $reason,
        string $action
    ): void {
        try {
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(
                    new SiteStatusNotification($site, $action, null, $reason)
                );
            }
            if ($publisher) {
                app(InAppNotificationService::class)
                    ->notifySiteStatusChanged($site, $action, $reason);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify publisher after site '.$action.': '.$e->getMessage());
        }
    }
}
