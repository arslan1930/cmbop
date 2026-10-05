<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Jobs\EnrichSiteJob;
use App\Mail\AdminAssignedSiteNotification;
use App\Mail\AdminAssignedSitesBatchNotification;
use App\Mail\SiteStatusNotification;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Services\ActivityLogger;
use App\Services\CheckoutSchemaService;
use App\Services\CommunityInboxNotifier;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\SiteDescriptionSanitizer;
use App\Services\SiteEnrichment\ImageOptimizationService;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use App\Services\SiteEnrichment\SiteMetricsAggregator;
use App\Support\AdminSites;
use App\Support\CatalogHealthQueue;
use App\Support\CatalogProblemReport;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    private const EXPORT_LIMIT = 5000;

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
                'missingMarketListCount' => 0,
                'scanFailedListCount' => 0,
                'belowQualityListCount' => 0,
                'liveUnverifiedCount' => 0,
                'placeholderListCount' => 0,
                'missingCoverListCount' => 0,
                'readyToActivateCount' => 0,
                'healthCounts' => CatalogHealthQueue::emptyCounts(),
                'waitingOnPublisherFilterActive' => false,
                'waitingOnPublisherCount' => 0,
                'publisherSearch' => trim(scalar_text($request->query('q', ''))),
                'flatQueue' => $this->requestFlag($request, 'flat'),
                'flatQueueSites' => null,
                'allSitesMode' => false,
                'allSites' => null,
                'sitesExportLimited' => false,
                'sitesExportUrl' => staff_route('sites.export'),
                'staffSiteFilters' => $this->staffSitesListFilterState($request),
                'listingTagOptions' => SiteTag::catalogFilterOptions(),
                'marketplaceCountries' => collect(),
                'marketplaceLanguages' => collect(),
                'nicheOptions' => [],
                'waitingStageCounts' => ['filling' => 0, 'reviewing' => 0, 'accept' => 0],
                'waitingStage' => '',
                'sitesReturnQuery' => [],
                'archivedListCount' => 0,
            ]);
        }
    }

    private function renderSitesIndex(Request $request)
    {
        $needsReviewFilter = $this->requestNeedsReview($request);

        $waitingOnPublisherFilter = $this->requestFlag($request, 'waiting_on_publisher');
        if ($waitingOnPublisherFilter) {
            $needsReviewFilter = false;
        }

        $publisherSearch = trim(scalar_text($request->query('q', '')));
        $flatQueue = $this->requestFlag($request, 'flat');
        $staffSiteFilters = $this->staffSitesListFilterState($request);
        $publishersDirectory = $this->requestFlag($request, 'publishers');
        $allSitesMode = $this->requestFlag($request, 'all')
            && ! $needsReviewFilter
            && ! $waitingOnPublisherFilter
            && ! $publishersDirectory
            && ! $request->filled('publisher')
            && ! $request->filled('site');
        $listingTagOptions = SiteTag::catalogFilterOptions();
        $marketplaceCountries = $this->staffMarketplaceCountries();
        $marketplaceLanguages = $this->staffMarketplaceLanguages();
        $nicheOptions = $this->staffNicheOptions();

        if ($publisherSearch !== ''
            && ! $request->filled('publisher')
            && ! $request->filled('site')
            && $this->staffListPage($request) === 1
            && ! $this->staffSitesListNarrows($staffSiteFilters)
        ) {
            $exactSite = $this->uniqueStaffSiteForExactSearch($publisherSearch, $staffSiteFilters);
            if ($exactSite) {
                return redirect()->to(staff_route('sites.index', $this->staffSitesIndexRedirectQuery(
                    $publisherSearch,
                    $exactSite,
                    $needsReviewFilter,
                    $waitingOnPublisherFilter,
                    $staffSiteFilters,
                    $allSitesMode
                )));
            }
        }

        $reviewQueue = function ($q) {
            $q->needsAdminReview()->notArchived();
        };

        $unverifiedFilter = $needsReviewFilter;
        $needsReviewFilterActive = $needsReviewFilter;
        $waitingOnPublisherFilterActive = $waitingOnPublisherFilter;
        $openReviewCount = MarketingOpsQueues::sitesReadyForStaffCount();
        $waitingListNarrows = $waitingOnPublisherFilter
            && ($publisherSearch !== '' || $this->staffSitesListNarrows($staffSiteFilters));
        if ($waitingOnPublisherFilter) {
            if ($waitingListNarrows) {
                $countWaitingStage = function (?string $stage) use ($publisherSearch, $staffSiteFilters): int {
                    $stageQuery = MarketingOpsQueues::sitesWaitingOnPublisher($stage);
                    $this->applyStaffIndexSiteOrPublisherSearch($stageQuery, $publisherSearch);
                    $this->applyStaffSitesListFilters($stageQuery, $staffSiteFilters);

                    return (int) $stageQuery->count();
                };
                $waitingOnPublisherCount = $countWaitingStage(null);
                $waitingStageCounts = [
                    'filling' => $countWaitingStage('filling'),
                    'reviewing' => $countWaitingStage('reviewing'),
                    'accept' => $countWaitingStage('accept'),
                ];
            } else {
                $waitingOnPublisherCount = MarketingOpsQueues::sitesWaitingOnPublisherCount();
                $waitingStageCounts = MarketingOpsQueues::sitesWaitingStageCounts();
            }
        } else {
            $waitingOnPublisherCount = MarketingOpsQueues::sitesWaitingOnPublisherCount();
            $waitingStageCounts = ['filling' => 0, 'reviewing' => 0, 'accept' => 0];
        }
        $waitingStage = $waitingOnPublisherFilter
            ? (string) ($staffSiteFilters['waiting_stage'] ?? '')
            : '';
        $liveUnverifiedCount = $this->staffSitesFilterTotal([
            'listing_active' => '1',
            'listing_verified' => '0',
        ]);
        $belowQualityListCount = $this->staffSitesFilterTotal(['below_quality' => true]);
        $readyToActivateCount = 0;
        $placeholderListCount = $this->staffSitesFilterTotal(['placeholder' => true]);
        $missingCoverListCount = $this->staffSitesFilterTotal(['missing_cover' => true]);
        $missingMarketListCount = $this->staffSitesFilterTotal([
            'listing_active' => '1',
            'missing_market' => true,
        ]);
        $scanFailedListCount = $this->staffSitesFilterTotal(['scan_failed' => true]);
        $archivedListCount = $this->staffSitesFilterTotal(['archived' => true]);
        $healthCounts = CatalogHealthQueue::emptyCounts();
        $missingMarketCount = 0;
        if ($request->user()?->isAdmin()) {
            $healthCounts = CatalogHealthQueue::counts();
            $missingMarketCount = (int) ($healthCounts[CatalogHealthQueue::MISSING_MARKET] ?? 0);
        }
        $flatQueueSites = null;
        $allSites = null;
        $sitesExportLimited = false;

        if ($flatQueue && $waitingOnPublisherFilter) {
            $listPerPage = $this->staffListPerPage($request, 20);
            $listQuery = $this->staffSitesPagerAppendQuery(
                $request,
                $needsReviewFilter,
                $waitingOnPublisherFilter,
                $flatQueue,
                $allSitesMode,
                $publishersDirectory
            );
            $users = new LengthAwarePaginator([], 0, $listPerPage, 1, [
                'path' => $request->url(),
                'query' => $listQuery,
            ]);
            $flatQueueSites = MarketingOpsQueues::sitesWaitingOnPublisher($waitingStage !== '' ? $waitingStage : null)
                ->with($this->staffSiteRowWith())
                ->when(Schema::hasTable('order_items'), fn ($q) => $q->withCount('orderItems'));
            $this->applyStaffIndexSiteOrPublisherSearch($flatQueueSites, $publisherSearch);
            $this->applyStaffSitesListFilters($flatQueueSites, $staffSiteFilters);
            $this->applyStaffSitesListSort($flatQueueSites, $staffSiteFilters['sort'], 'oldest');
            $flatQueueSites = $flatQueueSites
                ->paginate($listPerPage, ['*'], 'page', $this->staffListPage($request))
                ->appends($listQuery);
        } elseif ($flatQueue && $needsReviewFilter) {
            $listPerPage = $this->staffListPerPage($request, 20);
            $listQuery = $this->staffSitesPagerAppendQuery(
                $request,
                $needsReviewFilter,
                $waitingOnPublisherFilter,
                $flatQueue,
                $allSitesMode,
                $publishersDirectory
            );
            $users = new LengthAwarePaginator([], 0, $listPerPage, 1, [
                'path' => $request->url(),
                'query' => $listQuery,
            ]);
            $flatQueueSites = MarketingOpsQueues::sitesReadyForStaff()
                ->with($this->staffSiteRowWith())
                ->when(Schema::hasTable('order_items'), fn ($q) => $q->withCount('orderItems'));
            $this->applyStaffIndexSiteOrPublisherSearch($flatQueueSites, $publisherSearch);
            $this->applyStaffSitesListFilters($flatQueueSites, $staffSiteFilters);
            $this->applyStaffSitesListSort($flatQueueSites, $staffSiteFilters['sort'], 'oldest');
            $flatQueueSites = $flatQueueSites
                ->paginate($listPerPage, ['*'], 'page', $this->staffListPage($request))
                ->appends($listQuery);
        } elseif ($allSitesMode) {
            $listPerPage = $this->staffListPerPage($request, 20);
            $listQuery = $this->staffSitesPagerAppendQuery(
                $request,
                $needsReviewFilter,
                $waitingOnPublisherFilter,
                $flatQueue,
                $allSitesMode,
                $publishersDirectory
            );
            $users = new LengthAwarePaginator([], 0, $listPerPage, 1, [
                'path' => $request->url(),
                'query' => $listQuery,
            ]);
            $allSites = $this->staffAllSitesQuery($request, $publisherSearch, $staffSiteFilters)
                ->paginate($listPerPage, ['*'], 'page', $this->staffListPage($request))
                ->appends($listQuery);
            $sitesExportLimited = $allSites->total() > self::EXPORT_LIMIT;
        } else {
            // Counts only — do not eager-load every site row for the publisher list.
            $reviewListNarrows = $needsReviewFilter
                && ($publisherSearch !== '' || $this->staffSitesListNarrows($staffSiteFilters));
            $narrowQueueSites = function ($q) use ($publisherSearch, $staffSiteFilters) {
                if ($publisherSearch !== '') {
                    $this->applyStaffIndexSiteOrPublisherSearch($q, $publisherSearch);
                }
                $this->applyStaffSitesListFilters($q, $staffSiteFilters);
            };
            $waitingStageForList = $waitingStage !== '' ? $waitingStage : null;

            $query = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
                ->withCount(['sites' => function ($q) use ($staffSiteFilters) {
                    $this->applyStaffSitesArchiveScope($q, $staffSiteFilters);
                }])
                ->withCount(['sites as needs_review_sites_count' => function ($q) use ($reviewQueue, $reviewListNarrows, $narrowQueueSites) {
                    $reviewQueue($q);
                    if ($reviewListNarrows) {
                        $narrowQueueSites($q);
                    }
                }])
                ->withCount(['sites as waiting_filling_sites_count' => function ($q) use ($waitingListNarrows, $narrowQueueSites) {
                    MarketingOpsQueues::constrainSitesWaitingOnPublisher($q, 'filling');
                    if ($waitingListNarrows) {
                        $narrowQueueSites($q);
                    }
                }])
                ->withCount(['sites as waiting_reviewing_sites_count' => function ($q) use ($waitingListNarrows, $narrowQueueSites) {
                    MarketingOpsQueues::constrainSitesWaitingOnPublisher($q, 'reviewing');
                    if ($waitingListNarrows) {
                        $narrowQueueSites($q);
                    }
                }])
                ->withCount(['sites as waiting_accept_sites_count' => function ($q) use ($waitingListNarrows, $narrowQueueSites) {
                    MarketingOpsQueues::constrainSitesWaitingOnPublisher($q, 'accept');
                    if ($waitingListNarrows) {
                        $narrowQueueSites($q);
                    }
                }]);

            if ($waitingOnPublisherFilter) {
                $query->withCount(['sites as waiting_on_publisher_sites_count' => function ($q) use ($waitingListNarrows, $narrowQueueSites) {
                    MarketingOpsQueues::constrainSitesWaitingOnPublisher($q);
                    if ($waitingListNarrows) {
                        $narrowQueueSites($q);
                    }
                }]);
            }

            if ($publisherSearch !== '' || $this->staffSitesListNarrows($staffSiteFilters)) {
                $query->withCount(['sites as matched_sites_count' => function ($q) use ($publisherSearch, $staffSiteFilters, $needsReviewFilter, $waitingOnPublisherFilter, $waitingStageForList, $reviewQueue, $narrowQueueSites) {
                    if ($waitingOnPublisherFilter) {
                        MarketingOpsQueues::constrainSitesWaitingOnPublisher($q, $waitingStageForList);
                        $narrowQueueSites($q);
                    } elseif ($needsReviewFilter) {
                        $reviewQueue($q);
                        $narrowQueueSites($q);
                    } else {
                        $this->applyStaffSitesArchiveScope($q, $staffSiteFilters);
                        if ($publisherSearch !== '') {
                            $this->constrainStaffSiteSearch($q, $publisherSearch);
                        }
                        $this->applyStaffSitesListFilters($q, $staffSiteFilters);
                    }
                }]);
            }
            if ($publisherSearch !== '' && ! $needsReviewFilter && ! $waitingOnPublisherFilter) {
                $this->applyStaffPublisherSearch($query, $publisherSearch, $staffSiteFilters);
            }

            if ($this->staffSitesListNarrows($staffSiteFilters) && ! $needsReviewFilter && ! $waitingOnPublisherFilter) {
                $query->whereHas('sites', function ($sites) use ($staffSiteFilters) {
                    $this->applyStaffSitesArchiveScope($sites, $staffSiteFilters);
                    $this->applyStaffSitesListFilters($sites, $staffSiteFilters);
                });
            }

            // Ops queue: publishers with sites ready for admin decision (not unfinished drafts)
            if ($needsReviewFilter) {
                $query->whereHas('sites', function ($q) use ($reviewQueue, $reviewListNarrows, $narrowQueueSites) {
                    $reviewQueue($q);
                    if ($reviewListNarrows) {
                        $narrowQueueSites($q);
                    }
                })->withCount(['sites as unverified_sites_count' => function ($q) use ($reviewQueue, $reviewListNarrows, $narrowQueueSites) {
                    $reviewQueue($q);
                    if ($reviewListNarrows) {
                        $narrowQueueSites($q);
                    }
                }]);
            }

            if ($waitingOnPublisherFilter) {
                $query->whereHas('sites', function ($q) use ($waitingStageForList, $waitingListNarrows, $narrowQueueSites) {
                    MarketingOpsQueues::constrainSitesWaitingOnPublisher($q, $waitingStageForList);
                    if ($waitingListNarrows) {
                        $narrowQueueSites($q);
                    }
                });
            }

            $waitingSort = match ($waitingStageForList) {
                'filling' => 'waiting_filling_sites_count',
                'reviewing' => 'waiting_reviewing_sites_count',
                'accept' => 'waiting_accept_sites_count',
                default => 'waiting_on_publisher_sites_count',
            };

            $users = $query;
            if ($needsReviewFilter || $waitingOnPublisherFilter) {
                $users = $users
                    ->orderByDesc($waitingOnPublisherFilter ? $waitingSort : 'needs_review_sites_count')
                    ->orderByDesc('sites_count')
                    ->orderBy('name');
            } else {
                $users = $users->orderByDesc('id');
            }
            $users = $users
                ->paginate($this->staffListPerPage($request, 20), ['*'], 'page', $this->staffListPage($request))
                ->appends($this->staffSitesPagerAppendQuery(
                    $request,
                    $needsReviewFilter,
                    $waitingOnPublisherFilter,
                    $flatQueue,
                    $allSitesMode,
                    $publishersDirectory
                ));
        }

        if ($flatQueueSites && $flatQueueSites->total() > self::EXPORT_LIMIT) {
            $sitesExportLimited = true;
        }

        $sitesExportUrl = staff_route('sites.export', $this->staffSitesPagerAppendQuery(
            $request,
            $needsReviewFilter,
            $waitingOnPublisherFilter,
            $flatQueue,
            $allSitesMode,
            $publishersDirectory
        ));
        $sitesReturnQuery = AdminSites::rememberReturnQuery($request);

        return view('admin.sites', compact(
            'users',
            'unverifiedFilter',
            'needsReviewFilterActive',
            'waitingOnPublisherFilterActive',
            'openReviewCount',
            'waitingOnPublisherCount',
            'waitingStageCounts',
            'waitingStage',
            'liveUnverifiedCount',
            'belowQualityListCount',
            'readyToActivateCount',
            'placeholderListCount',
            'missingCoverListCount',
            'missingMarketListCount',
            'scanFailedListCount',
            'missingMarketCount',
            'healthCounts',
            'publisherSearch',
            'flatQueue',
            'flatQueueSites',
            'allSitesMode',
            'publishersDirectory',
            'allSites',
            'sitesExportLimited',
            'sitesExportUrl',
            'archivedListCount',
            'staffSiteFilters',
            'listingTagOptions',
            'marketplaceCountries',
            'marketplaceLanguages',
            'nicheOptions',
            'sitesReturnQuery'
        ));
    }

    /**
     * @param  array{tag: ?string, country: string, listing_active: string, listing_verified: string, below_quality: bool, missing_market: bool, archived: bool, sort: string}  $filters
     * @return array<string, mixed>
     */
    private function staffSitesIndexRedirectQuery(
        string $search,
        Site $exact,
        bool $needsReview,
        bool $waiting,
        array $filters,
        bool $allSitesMode
    ): array {
        return array_filter([
            'q' => $search,
            'publisher' => $exact->publisher_id,
            'site' => $exact->id,
            'needs_review' => $needsReview ? 1 : null,
            'waiting_on_publisher' => $waiting ? 1 : null,
            'all' => $allSitesMode ? 1 : null,
            'tag' => ($filters['tag'] ?? null) !== null && ($filters['tag'] ?? '') !== '' ? $filters['tag'] : null,
            'country' => ($filters['country'] ?? '') !== '' ? $filters['country'] : null,
            'listing_active' => ($filters['listing_active'] ?? '') !== '' ? $filters['listing_active'] : null,
            'listing_verified' => ($filters['listing_verified'] ?? '') !== '' ? $filters['listing_verified'] : null,
            'below_quality' => ! empty($filters['below_quality']) ? 1 : null,
            'ready_to_activate' => ! empty($filters['ready_to_activate']) ? 1 : null,
            'missing_market' => ! empty($filters['missing_market']) ? 1 : null,
            'placeholder' => ! empty($filters['placeholder']) ? 1 : null,
            'missing_cover' => ! empty($filters['missing_cover']) ? 1 : null,
            'bulk_request' => ! empty($filters['bulk_request']) ? 1 : null,
            'scan_failed' => ! empty($filters['scan_failed']) ? 1 : null,
            'copy_strike' => ! empty($filters['copy_strike']) ? 1 : null,
            'has_orders' => ! empty($filters['has_orders']) ? 1 : null,
            'featured' => ! empty($filters['featured']) ? 1 : null,
            'bulk_discount' => ! empty($filters['bulk_discount']) ? 1 : null,
            'csv_metrics' => ! empty($filters['csv_metrics']) ? 1 : null,
            'price_min' => $filters['price_min'] ?? null,
            'price_max' => $filters['price_max'] ?? null,
            'traffic_min' => $filters['traffic_min'] ?? null,
            'da_min' => $filters['da_min'] ?? null,
            'metrics_age' => ($filters['metrics_age'] ?? '') !== '' ? $filters['metrics_age'] : null,
            'language' => ($filters['language'] ?? '') !== '' ? $filters['language'] : null,
            'niche' => ($filters['niche'] ?? '') !== '' ? $filters['niche'] : null,
            'archived' => ! empty($filters['archived']) ? 1 : null,
            'waiting_stage' => $waiting && ($filters['waiting_stage'] ?? '') !== '' ? $filters['waiting_stage'] : null,
            'sort' => ($filters['sort'] ?? '') !== '' ? $filters['sort'] : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    private function staffSiteRowWith(): array
    {
        $with = [$this->staffPublisherWith()];
        if (Site::hasSitesColumn('status_reason_by')) {
            $with[] = 'statusReasonAuthor:id,name';
        }

        return $with;
    }

    /**
     * CSV follows the queue on screen: review, waiting on publisher, or all sites.
     *
     * @return Builder<Site>
     */
    private function staffExportSitesQuery(Request $request): Builder
    {
        $filters = $this->staffSitesListFilterState($request);
        $search = trim(scalar_text($request->query('q', '')));
        $queue = null;
        $defaultSort = 'newest';

        if ($this->requestFlag($request, 'waiting_on_publisher')) {
            $stage = ($filters['waiting_stage'] ?? '') !== '' ? $filters['waiting_stage'] : null;
            $queue = MarketingOpsQueues::sitesWaitingOnPublisher($stage);
            $defaultSort = 'oldest';
        } elseif ($this->requestNeedsReview($request)) {
            $queue = MarketingOpsQueues::sitesReadyForStaff();
            $defaultSort = 'oldest';
        }

        if ($queue === null) {
            return $this->staffAllSitesQuery($request, $search, $filters);
        }

        $queue->with($this->staffSiteRowWith());
        if (Schema::hasTable('order_items')) {
            $queue->withCount('orderItems');
        }
        $this->applyStaffIndexSiteOrPublisherSearch($queue, $search);
        $this->applyStaffSitesListFilters($queue, $filters);
        $this->applyStaffSitesListSort($queue, (string) ($filters['sort'] ?? ''), $defaultSort);

        return $queue;
    }

    private function staffPublisherWith(): string
    {
        $columns = ['id', 'name', 'email'];
        try {
            if (Schema::hasColumn('users', 'catalog_hide_until')) {
                $columns[] = 'catalog_hide_until';
            }
        } catch (\Throwable) {
            // Hide chip stays off when the column cannot be read.
        }

        return 'publisher:'.implode(',', $columns);
    }

    /**
     * @param  array{tag: ?string, country: string, listing_active: string, listing_verified: string, below_quality: bool, missing_market: bool, archived: bool, sort: string}  $filters
     * @return Builder<Site>
     */
    private function staffAllSitesQuery(Request $request, ?string $search = null, ?array $filters = null): Builder
    {
        $filters ??= $this->staffSitesListFilterState($request);
        $search ??= trim(scalar_text($request->query('q', '')));

        $query = Site::query()->with($this->staffSiteRowWith());
        if (Schema::hasTable('order_items')) {
            $query->withCount('orderItems');
        }
        $this->applyStaffSitesArchiveScope($query, $filters);
        $this->applyStaffIndexSiteOrPublisherSearch($query, $search);
        $this->applyStaffSitesListFilters($query, $filters);
        $this->applyStaffSitesListSort($query, $filters['sort'], 'newest');

        return $query;
    }

    public function export(Request $request): StreamedResponse
    {
        $matchCount = 0;
        try {
            $query = $this->staffExportSitesQuery($request);
            $matchCount = (clone $query)->count();
            $rows = $query->limit(self::EXPORT_LIMIT)->get();
        } catch (\Throwable $e) {
            Log::warning('Admin sites export query failed', ['error' => $e->getMessage()]);
            $rows = collect();
            $matchCount = 0;
        }

        ActivityLogger::tryLog(
            'sites.exported',
            ($request->user()?->name ?? 'Staff').' exported sites ('.$rows->count().' row(s)).',
            null,
            [
                'rows_exported' => $rows->count(),
                'truncated' => $matchCount > self::EXPORT_LIMIT,
            ]
        );

        $filename = 'sites-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'site_name',
                'domain',
                'site_url',
                'publisher',
                'publisher_email',
                'countries',
                'languages',
                'categories',
                'link_type',
                'sponsored',
                'tag',
                'da',
                'dr',
                'traffic',
                'price_eur',
                'sale_eur',
                'featured',
                'bulk',
                'bulk_request',
                'orders',
                'active',
                'verified',
                'enrichment_status',
                'metrics_fetched_at',
                'copy_strike_hide',
            ]);
            foreach ($rows as $site) {
                $facts = $this->staffListingFacts($site);
                fputcsv($out, [
                    $this->csvCell($site->id),
                    $this->csvCell($site->site_name),
                    $this->csvCell($site->domain),
                    $this->csvCell($site->site_url),
                    $this->csvCell($site->publisher?->name),
                    $this->csvCell($site->publisher?->email),
                    $this->csvCell(implode('|', $facts['countries_list'])),
                    $this->csvCell(implode('|', $facts['languages_list'])),
                    $this->csvCell(implode('|', $facts['categories_list'])),
                    $this->csvCell($facts['link_type_label']),
                    $this->csvCell($site->sponsored ? 'yes' : 'no'),
                    $this->csvCell($site->tagLabel('')),
                    $this->csvCell($site->da),
                    $this->csvCell($site->dr),
                    $this->csvCell($site->traffic),
                    $this->csvCell($site->price),
                    $this->csvCell($facts['sale_price']),
                    $this->csvCell($facts['featured'] ? 'yes' : 'no'),
                    $this->csvCell($facts['bulk_discount'] ? 'yes' : 'no'),
                    $this->csvCell($site->wasAddedFromBulkRequest() ? 'yes' : 'no'),
                    $this->csvCell($site->orderItemsCount()),
                    $this->csvCell($site->active ? 'yes' : 'no'),
                    $this->csvCell($site->verified ? 'yes' : 'no'),
                    $this->csvCell($site->enrichment_status),
                    $this->csvCell($facts['metrics_fetched_label']),
                    $this->csvCell($facts['publisher_copy_strike'] ? 'yes' : 'no'),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * Admin records sheet: all websites with URL, countries, categories only.
     * Always reads live from the sites table.
     * Optional ?country=de (or other ISO code) filters to that market.
     * Optional ?live=1 keeps catalog-visible listings (active, not archived,
     * not cancelled-bulk leftover). Country still applies; health queues win.
     * ?partial=1 or Accept: application/json returns table HTML for live filter swaps.
     */
    public function records(Request $request)
    {
        $filter = $this->recordsFilterState($request);
        $countryFilter = $filter['country'];
        $healthFilter = $filter['health'];
        $missingMarket = $filter['missing_market'];
        $liveFilter = $filter['live'];

        try {
            $wantsPartial = $this->requestFlag($request, 'partial')
                || $request->expectsJson()
                || str_contains(strtolower(scalar_text($request->header('Accept', ''))), 'application/json');
        } catch (\Throwable $e) {
            report($e);
            $wantsPartial = $request->expectsJson();
        }

        $sites = null;
        try {
            $query = $this->recordsBaseQuery();
            $this->applyRecordsFilters($query, $filter);

            $page = $this->recordsPage($request);
            $sites = $query
                ->paginate(100, ['*'], 'page', $page)
                ->appends($filter['query_params'])
                ->through(fn (Site $site) => $this->leftoverSafeSiteRecordRow($site));
        } catch (\Throwable $e) {
            report($e);

            if ($wantsPartial) {
                return response()->json([
                    'success' => false,
                    'message' => UserFacingError::message($e, 'We could not filter records. Please try again.'),
                ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }

            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load site records. Please refresh and try again.')
            );

            $sites = new LengthAwarePaginator([], 0, 100, $this->recordsPage($request), [
                'path' => $request->url(),
                'query' => $filter['query_params'],
            ]);
        }

        // Country combobox / health chips must not empty a sheet that already loaded.
        $countryCounts = $this->recordsCountryCounts();
        $totalSites = $this->recordsTotalCount($sites);
        $healthCounts = CatalogHealthQueue::counts();
        $missingMarketCount = (int) ($healthCounts[CatalogHealthQueue::MISSING_MARKET] ?? 0);
        $liveCount = $this->recordsLiveCount();
        $countries = $this->recordsCountryOptions($countryCounts);
        $selectedCountry = $countryFilter;
        $exportUrl = $this->recordsExportUrl($filter['query_params']);

        if ($wantsPartial) {
            try {
                $tableHtml = view('admin.sites.partials.records-table', [
                    'sites' => $sites,
                    'selectedCountry' => $selectedCountry,
                    'missingMarket' => $missingMarket,
                    'healthFilter' => $healthFilter,
                    'liveFilter' => $liveFilter,
                ])->render();

                return response()->json([
                    'success' => true,
                    'selected_country' => $selectedCountry,
                    'missing_market' => $missingMarket,
                    'missing_market_count' => $missingMarketCount,
                    'health' => $healthFilter,
                    'health_counts' => $healthCounts,
                    'live' => $liveFilter,
                    'live_count' => $liveCount,
                    'total' => is_object($sites) && method_exists($sites, 'total')
                        ? (int) $sites->total()
                        : 0,
                    'export_url' => $exportUrl,
                    'table_html' => $tableHtml,
                ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'success' => false,
                    'message' => UserFacingError::message($e, 'We could not filter records. Please try again.'),
                ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        }

        try {
            return view('admin.sites.records', compact(
                'sites',
                'countries',
                'selectedCountry',
                'totalSites',
                'exportUrl',
                'missingMarket',
                'missingMarketCount',
                'healthFilter',
                'healthCounts',
                'liveFilter',
                'liveCount'
            ));
        } catch (\Throwable $e) {
            report($e);

            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load site records. Please refresh and try again.')
            );

            return response(
                'We could not load site records. Please refresh and try again.',
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }
    }

    /**
     * CSV download of the same live records sheet (honours country / live / health filter).
     */
    public function exportRecords(Request $request): StreamedResponse|RedirectResponse
    {
        $filter = $this->recordsFilterState($request);
        $countryFilter = $filter['country'];
        $healthFilter = $filter['health'];
        $missingMarket = $filter['missing_market'];
        $liveFilter = $filter['live'];

        $suffix = $healthFilter !== null
            ? '-'.$healthFilter
            : (($liveFilter ? '-live' : '').($countryFilter !== '' ? '-'.$countryFilter : ''));
        $suffix = preg_replace('/[^a-z0-9_-]+/i', '', (string) $suffix) ?? '';
        $filename = 'websites-records'.$suffix.'-'.now()->format('Y-m-d').'.csv';

        try {
            $query = $this->recordsBaseQuery();
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
                'live' => $liveFilter,
                'rows_exported' => $matchCount,
            ]
        );

        return response()->streamDownload(function () use ($query) {
            try {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }
                fputcsv($out, ['url', 'countries', 'categories', 'active', 'health', 'listing_state']);

                foreach ($query->cursor() as $site) {
                    $row = $this->leftoverSafeSiteRecordRow($site);
                    fputcsv($out, [
                        scalar_text($row['url'] ?? ''),
                        scalar_text($row['countries'] ?? ''),
                        scalar_text($row['categories'] ?? ''),
                        ! empty($row['active']) ? '1' : '0',
                        scalar_text($row['health'] ?? ''),
                        scalar_text($row['listing_state'] ?? ''),
                    ]);
                }

                fclose($out);
            } catch (\Throwable $e) {
                report($e);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{
     *     country: string,
     *     health: ?string,
     *     missing_market: bool,
     *     live: bool,
     *     query_params: array<string, int|string>
     * }
     */
    private function recordsFilterState(Request $request): array
    {
        try {
            $countryFilter = strtolower(trim($this->leftoverSafeUtf8(scalar_text($request->query('country', '')))));
            if ($countryFilter === 'all') {
                $countryFilter = '';
            }

            $health = CatalogHealthQueue::fromRequest($request);
            if ($health !== null) {
                $countryFilter = '';
            }

            // Leftover ?live[]=1 TypeErrors $request->boolean(); flatten first.
            $live = $health === null && $this->requestFlag($request, 'live');

            return [
                'country' => $countryFilter,
                'health' => $health,
                'missing_market' => $health === CatalogHealthQueue::MISSING_MARKET,
                'live' => $live,
                'query_params' => array_filter([
                    'country' => $countryFilter !== '' ? $countryFilter : null,
                    'health' => ($health !== null && $health !== CatalogHealthQueue::MISSING_MARKET)
                        ? $health
                        : null,
                    'missing_market' => $health === CatalogHealthQueue::MISSING_MARKET ? 1 : null,
                    'live' => $live ? 1 : null,
                ]),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'country' => '',
                'health' => null,
                'missing_market' => false,
                'live' => false,
                'query_params' => [],
            ];
        }
    }

    /**
     * @param  Builder<Site>  $query
     * @param  array{country: string, health: ?string, live?: bool}  $filter
     */
    private function applyRecordsFilters($query, array $filter): void
    {
        try {
            if (is_string($filter['health'] ?? null) && $filter['health'] !== '') {
                CatalogHealthQueue::apply($query, $filter['health']);

                return;
            }

            if (! empty($filter['live'])) {
                $this->constrainRecordsLive($query);
            }

            $this->applyRecordsCountryFilter($query, scalar_text($filter['country'] ?? ''));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return Builder<Site>
     */
    private function recordsBaseQuery()
    {
        $query = Site::query();
        try {
            if (Site::hasSitesColumn('domain')) {
                $query->orderBy('domain');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            if (Site::hasSitesColumn('id')) {
                $query->orderBy('id');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $query;
    }

    /**
     * Leftover ?page[]=2 TypeErrors Laravel's paginator filter_var().
     * Arrays are junk — stay on page 1 instead of flattening to an empty page.
     */
    private function recordsPage(Request $request): int
    {
        try {
            $raw = $request->query('page', 1);
            if (is_array($raw) || is_object($raw)) {
                return 1;
            }

            $page = (int) scalar_text($raw);
            if ($page < 1) {
                return 1;
            }

            return min($page, 10000);
        } catch (\Throwable $e) {
            report($e);

            return 1;
        }
    }

    /**
     * Live-on-portal = catalogVisible(). Leftover Hostinger can drop
     * bulk_site_requests while sites.bulk_site_request_id remains, and
     * catalogVisible() then 500s on orWhereHas.
     *
     * @param  Builder<Site>  $query
     */
    private function constrainRecordsLive($query): void
    {
        try {
            if (! Site::hasSitesColumn('active')) {
                return;
            }
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        try {
            if (Schema::hasTable('bulk_site_requests')) {
                $query->catalogVisible();

                return;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $query->active()->notArchived();
        } catch (\Throwable $e) {
            report($e);
            try {
                $query->where('active', 1);
            } catch (\Throwable $inner) {
                report($inner);
            }
        }
    }

    private function recordsLiveCount(): int
    {
        try {
            $query = Site::query();
            $this->constrainRecordsLive($query);

            return (int) $query->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
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
        $select = [];
        try {
            if (Site::hasSitesColumn('country')) {
                $select[] = 'country';
            }
            if (Site::hasSitesColumn('countries')) {
                $select[] = 'countries';
            }
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
        if ($select === []) {
            return [];
        }

        try {
            foreach (Site::query()->select($select)->cursor() as $site) {
                try {
                    foreach ($site->countryCodes() as $code) {
                        $code = strtolower(trim(scalar_text($code)));
                        if ($code === '') {
                            continue;
                        }
                        $counts[$code] = ($counts[$code] ?? 0) + 1;
                    }
                } catch (\Throwable $rowError) {
                    report($rowError);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Admin sites records country counts failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $countryCounts
     * @return Collection<int, array{code: string, name: string, count: int}>
     */
    private function recordsCountryOptions(array $countryCounts)
    {
        try {
            $table = (new Country)->getTable();
            $hasCode = Schema::hasColumn($table, 'code');
            $hasName = Schema::hasColumn($table, 'name');
            if (! $hasCode && ! $hasName) {
                return collect();
            }

            $select = array_values(array_filter([
                $hasCode ? 'code' : null,
                $hasName ? 'name' : null,
            ]));

            $query = Country::query();
            try {
                $allowedCodes = config('markets.allowed_country_codes', []);
                if ($hasCode && is_array($allowedCodes) && $allowedCodes !== []) {
                    $query->whereIn('code', $allowedCodes);
                } elseif (Schema::hasColumn($table, 'region')) {
                    $query = Country::marketplace();
                }
            } catch (\Throwable $e) {
                report($e);
                $query = Country::query();
            }

            try {
                if ($hasName) {
                    $query->orderBy('name');
                } elseif ($hasCode) {
                    $query->orderBy('code');
                }
            } catch (\Throwable $e) {
                report($e);
            }

            return $query
                ->get($select)
                ->map(function (Country $country) use ($countryCounts, $hasCode, $hasName) {
                    $code = strtolower(trim($this->leftoverSafeUtf8(scalar_text(
                        $hasCode ? ($country->code ?? '') : ($country->name ?? '')
                    ))));
                    $name = $hasName
                        ? $this->leftoverSafeUtf8(scalar_text($country->name ?? ''))
                        : strtoupper($code);

                    return [
                        'code' => $code,
                        'name' => $name,
                        'count' => (int) ($countryCounts[$code] ?? 0),
                    ];
                })
                ->filter(fn ($row) => $row['code'] !== '')
                ->values();
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    private function recordsTotalCount($sites): int
    {
        try {
            return (int) Site::query()->count();
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            return is_object($sites) && method_exists($sites, 'total')
                ? (int) $sites->total()
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, int|string>  $queryParams
     */
    private function recordsExportUrl(array $queryParams): string
    {
        try {
            return route('admin.sites.records.export', $queryParams);
        } catch (\Throwable $e) {
            report($e);

            return route('admin.sites.records.export');
        }
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function applyRecordsCountryFilter($query, string $countryCode): void
    {
        $code = strtolower(trim(scalar_text($countryCode)));
        if ($code === '') {
            return;
        }

        try {
            $hasCountry = Site::hasSitesColumn('country');
            $hasCountriesJson = Site::hasSitesColumn('countries');
        } catch (\Throwable $e) {
            report($e);

            return;
        }
        if (! $hasCountry && ! $hasCountriesJson) {
            return;
        }

        // LIKE on CAST text — leftover Hostinger stores junk TEXT, not JSON, and
        // whereJsonContains() 500s. Quoted needle still matches ["de","at"].
        $query->where(function ($q) use ($code, $hasCountry, $hasCountriesJson) {
            if ($hasCountry) {
                $q->whereRaw('LOWER(country) = ?', [$code]);
            }
            if ($hasCountriesJson) {
                $like = like_contains('"'.$code.'"');
                // CAST AS CHAR is CHAR(1) on MariaDB. SUBSTRING keeps the whole
                // list on MariaDB, MySQL, and SQLite.
                $jsonClause = function ($inner) use ($code, $like) {
                    $inner->whereRaw('LOWER(SUBSTRING(countries, 1, 8000)) LIKE ? ESCAPE ?', [$like, '\\'])
                        ->orWhereRaw('LOWER(SUBSTRING(countries, 1, 8000)) = ?', [$code]);
                };
                if ($hasCountry) {
                    $q->orWhere($jsonClause);
                } else {
                    $q->where($jsonClause);
                }
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
     * Advertiser offer, every market, and scan/hide facts for a staff row.
     *
     * @return array<string, mixed>
     */
    private function staffListingFacts(Site $site): array
    {
        $sale = null;
        try {
            $prices = $site->catalogPricesForViewer(null);
            if (array_key_exists('sale', $prices) && $prices['sale'] !== null) {
                $sale = round((float) $prices['sale'], 2);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $countries = [];
        $languages = [];
        $categories = [];
        try {
            $countries = array_values($site->countryCodesForDisplay());
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $languages = array_values($site->languageCodes());
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $categories = array_values(array_filter(array_map(
                static fn ($value) => trim(scalar_text($value)),
                (array) $site->categories_array
            ), static fn ($value) => $value !== ''));
        } catch (\Throwable $e) {
            report($e);
        }

        $metricsLabel = null;
        try {
            $fetched = $site->metrics_fetched_at;
            if ($fetched instanceof \DateTimeInterface) {
                $metricsLabel = Carbon::parse($fetched)
                    ->timezone((string) config('app.timezone'))
                    ->format('M j, Y');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $ordersSearch = trim(scalar_text($site->domain ?: ($site->site_name ?: '')));
        $ordersUrl = null;
        if ($ordersSearch !== '' && auth()->user()?->isAdmin()) {
            try {
                $ordersUrl = route('admin.orders.index', ['search' => $ordersSearch]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $featured = false;
        $bulk = false;
        $linkLabel = null;
        try {
            $featured = $site->isFeatured();
            $bulk = $site->joinsBulkDiscount();
            $linkLabel = $site->linkTypeLabel();
        } catch (\Throwable $e) {
            report($e);
        }

        $copyStrike = false;
        try {
            $copyStrike = (bool) $site->publisher?->inCatalogHideMode();
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'sale_price' => $sale,
            'featured' => $featured,
            'bulk_discount' => $bulk,
            'link_type_label' => $linkLabel,
            'countries_list' => $countries,
            'languages_list' => $languages,
            'categories_list' => $categories,
            'orders_url' => $ordersUrl,
            'enrichment_failed' => (string) ($site->enrichment_status ?? '') === 'failed',
            'metrics_fetched_label' => $metricsLabel,
            'metrics_source' => (Site::hasSitesColumn('metrics_manual') && (bool) $site->metrics_manual)
                ? 'Manual'
                : ($metricsLabel ? 'Scan' : null),
            'publisher_copy_strike' => $copyStrike,
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
        $listingTag = $site->tagValue();

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
            'listing_tag' => $listingTag,
            'listing_tag_label' => SiteTag::label($listingTag) ?? SiteTag::NONE_LABEL,
            'added_from_bulk_request' => $site->wasAddedFromBulkRequest(),
            'bulk_site_request_id' => $site->bulk_site_request_id ? (int) $site->bulk_site_request_id : null,
            'assigned_by_user_id' => $site->assigned_by_user_id ? (int) $site->assigned_by_user_id : null,
            'staff_assigned_batch' => filled($site->assigned_by_user_id) && $site->wasAddedFromBulkRequest(),
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
            'quality_failures' => $site->qualityBarFailures(),
            'missing_cover' => ! $site->hasCatalogCover(),
            'missing_tags' => $listingTag === null,
            'ready_to_activate' => $site->isReadyToActivate(),
            'listing_locked' => $site->isLockedForMarketingEdits(),
            'awaits_publisher_details' => $site->awaitsPublisherDetails(),
            'details_complete' => $site->hasDetailsComplete(),
            'pending_publisher_acceptance' => $site->isPendingPublisherAcceptance(),
            'agency_site_import_id' => Site::hasSitesColumn('agency_site_import_id')
                ? ($site->agency_site_import_id ? (int) $site->agency_site_import_id : null)
                : null,
            'csv_metrics_spot_check' => $site->isFromAgencyCsvImport() && (bool) $site->metrics_manual,
            'archived' => $site->isArchived(),
            'publisher_added' => $site->wasAddedByPublisher(),
            'bulk_request_draft' => $site->isBulkRequestDraft(),
            'can_activate' => $this->staffCanActivateSite($site),
            'activate_block_reason' => $this->staffActivateBlockReason($site),
            'orders_count' => $site->orderItemsCount(),
            'preview_thumb_url' => $preview['thumb'],
            'preview_full_url' => $preview['full'],
            'preview_fallback_urls' => $preview['fallbacks'],
            'screenshot_url' => $preview['full'],
            'screenshot_thumb_url' => $preview['thumb'],
            'image_url' => $imageUrl,
            ...$this->staffListingFacts($site),
            ...$this->staffListedDatePayload($site->created_at, $site->updated_at),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     url: string,
     *     href: string,
     *     admin_url: string,
     *     countries: string,
     *     categories: string,
     *     missing_market: bool,
     *     active: bool,
     *     listing_state: string,
     *     health_flags: list<string>,
     *     health: string
     * }
     */
    private function leftoverSafeSiteRecordRow(Site $site): array
    {
        try {
            return $this->siteRecordRow($site);
        } catch (\Throwable $e) {
            report($e);

            return [
                'id' => (int) ($site->id ?? 0),
                'url' => '',
                'href' => '',
                'admin_url' => '',
                'countries' => '',
                'categories' => '',
                'missing_market' => false,
                'active' => false,
                'listing_state' => 'not_live',
                'health_flags' => [],
                'health' => '',
            ];
        }
    }

    private function siteRecordRow(Site $site): array
    {
        $rawUrl = trim(scalar_text($site->site_url ?? ''));
        if ($rawUrl === '') {
            $domain = trim(scalar_text($site->domain ?? ''));
            $rawUrl = $domain !== '' ? 'https://'.$domain : '';
        }
        $href = $this->leftoverSafeRecordsUrl($rawUrl);
        $url = $href !== '' ? $href : '';

        $countries = '';
        try {
            $countries = collect($site->countryCodesForDisplay())
                ->filter()
                ->map(fn ($code) => strtolower(trim(scalar_text($code))))
                ->filter()
                ->unique()
                ->values()
                ->implode('|');
        } catch (\Throwable $e) {
            report($e);
        }

        $categories = '';
        try {
            $categories = collect($site->categories_array)
                ->filter()
                ->map(fn ($cat) => trim(scalar_text($cat)))
                ->filter()
                ->unique()
                ->values()
                ->implode('|');
        } catch (\Throwable $e) {
            report($e);
        }

        $healthFlags = [];
        try {
            $healthFlags = scalar_list(CatalogHealthQueue::flags($site));
        } catch (\Throwable $e) {
            report($e);
        }

        $listingState = 'not_live';
        try {
            $listingState = $site->isCatalogVisible() ? 'live' : 'not_live';
        } catch (\Throwable $e) {
            report($e);
        }

        $adminUrl = '';
        try {
            $adminUrl = route('admin.sites.edit', $site->id);
        } catch (\Throwable $e) {
            report($e);
        }

        $missingMarket = false;
        try {
            $missingMarket = ! $site->hasMarketplaceCountry();
        } catch (\Throwable $e) {
            report($e);
        }

        $active = false;
        try {
            $active = (bool) $site->active;
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'id' => (int) ($site->id ?? 0),
            'url' => $this->leftoverSafeUtf8($url),
            'href' => $this->leftoverSafeUtf8($href),
            'admin_url' => $this->leftoverSafeUtf8($adminUrl),
            'countries' => $this->leftoverSafeUtf8($countries),
            'categories' => $this->leftoverSafeUtf8($categories),
            'missing_market' => $missingMarket,
            'active' => $active,
            'listing_state' => $this->leftoverSafeUtf8($listingState),
            'health_flags' => array_map(fn ($flag) => $this->leftoverSafeUtf8(scalar_text($flag)), $healthFlags),
            'health' => $this->leftoverSafeUtf8(implode('|', $healthFlags)),
        ];
    }

    private function leftoverSafeUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            if (function_exists('mb_scrub')) {
                return mb_scrub($value, 'UTF-8');
            }

            $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

            return is_string($converted) ? $converted : '';
        } catch (\Throwable $e) {
            report($e);

            return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value) ?? '';
        }
    }

    private function leftoverSafeRecordsUrl(mixed $url): string
    {
        try {
            $raw = trim(scalar_text($url));
            if ($raw === '') {
                return '';
            }

            return function_exists('safe_external_url') && safe_external_url($raw) !== '#'
                ? $raw
                : '';
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
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

    /**
     * Unfiltered portfolio counts for the open publisher. Catalog-health
     * badges on the index stay global; this line is only this publisher.
     *
     * @return array{total: int, ready_to_activate: int, below_quality: int}
     */
    private function publisherSitesSummary(int $publisherId): array
    {
        $empty = ['total' => 0, 'ready_to_activate' => 0, 'below_quality' => 0];
        if ($publisherId < 1) {
            return $empty;
        }

        try {
            $base = Site::query()->where('publisher_id', $publisherId)->notArchived();
            $below = clone $base;
            $this->constrainStaffBelowQuality($below);

            return [
                'total' => (int) (clone $base)->count(),
                'ready_to_activate' => (int) (clone $base)->readyToActivate()->count(),
                'below_quality' => (int) $below->count(),
            ];
        } catch (\Throwable $e) {
            report($e);

            return $empty;
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
            'partner_material',
            'as_you_prefer',
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
            'bulk_site_request_id',
            'metrics_manual',
            'featured_until',
            'custom_discount_percent',
            'custom_discount_starts_at',
            'custom_discount_ends_at',
            'bulk_discount_enabled',
            'bulk_discount_percent',
            'original_price',
            'added_from_bulk_request',
            'status_reason',
            'status_reason_at',
            'status_reason_by',
            'created_at',
            'updated_at',
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

        $perPage = $this->staffListPerPage($request, 50);
        $siteSearch = trim(scalar_text($request->query('q', '')));
        $needsReviewOnly = $this->requestFlag($request, 'needs_review');
        $filters = $this->staffSitesListFilterState($request);
        // Deep link: keep this row on page 1 even when the list filters would hide it.
        // Later pages must not pin it, or paging keeps that site and opens its details.
        $listPage = $this->staffListPage($request);
        $focusSiteId = $this->canonicalStaffId(trim(scalar_text($request->query('site', ''))));
        $pinSiteId = $listPage === 1 ? $focusSiteId : null;

        $sitesQuery = Site::query()
            ->where('publisher_id', $user->id)
            ->where(function ($outer) use ($filters, $siteSearch, $needsReviewOnly, $pinSiteId) {
                $outer->where(function ($matched) use ($filters, $siteSearch, $needsReviewOnly) {
                    // Always start with a predicate so an empty group cannot compile to "()".
                    $matched->whereRaw('1 = 1');
                    $this->applyStaffSitesArchiveScope($matched, $filters);
                    if ($siteSearch !== '') {
                        $this->constrainStaffSiteSearch($matched, $siteSearch);
                    }
                    if ($needsReviewOnly) {
                        $matched->needsAdminReview();
                    }
                    $this->applyStaffSitesListFilters($matched, $filters);
                });
                if ($pinSiteId !== null) {
                    $outer->orWhere($outer->getModel()->getTable().'.id', $pinSiteId);
                }
            });
        $this->applyStaffSitesListSort($sitesQuery, $filters['sort'], 'newest', $pinSiteId);

        if (Schema::hasTable('order_items')) {
            $sitesQuery->withCount('orderItems');
        }

        $paginator = $sitesQuery->paginate($perPage, $select, 'page', $listPage);

        $sites = $paginator->getCollection()
            ->map(function (Site $site) use ($user) {
                $site->setRelation('publisher', $user);

                return $this->staffSiteListRow($site);
            })
            ->values();

        // Include publisher meta so the detail view still loads when the publisher
        // is absent from a filtered "needs review" users table (e.g. after activate).
        return response()->json([
            'publisher' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'copy_strike' => $user->inCatalogHideMode(),
            ],
            'summary' => $this->publisherSitesSummary((int) $user->id),
            'sites' => $sites,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'q' => $siteSearch,
                'needs_review' => $needsReviewOnly,
                'tag' => $filters['tag'],
                'country' => $filters['country'],
                'listing_active' => $filters['listing_active'],
                'listing_verified' => $filters['listing_verified'],
                'below_quality' => $filters['below_quality'],
                'ready_to_activate' => $filters['ready_to_activate'],
                'missing_market' => $filters['missing_market'],
                'archived' => $filters['archived'],
                'sort' => $filters['sort'],
            ],
        ]);
    }

    /**
     * Page size for the staff sites lists. Anything outside 20, 50, and 100
     * falls back to the list's own default.
     */
    private function staffListPerPage(Request $request, int $default): int
    {
        $value = (int) (filter_number($request->query('per_page')) ?? $default);

        return in_array($value, [20, 50, 100], true) ? $value : $default;
    }

    private function staffListPage(Request $request): int
    {
        return max(1, (int) (filter_number($request->input('page')) ?? 1));
    }

    private function requestNeedsReview(Request $request): bool
    {
        if ($this->requestFlag($request, 'needs_review')) {
            return true;
        }

        $verified = $request->query('verified');

        return scalar_text($verified) === '0' || $verified === 0;
    }

    /**
     * Publishers whose name, email, or company match, or who own a matching site.
     * Archived sites stay out unless the Archived filter is on.
     *
     * @param  array{archived?: bool, tag?: ?string, country?: string, listing_active?: string, listing_verified?: string, below_quality?: bool, missing_market?: bool}  $filters
     */
    private function applyStaffPublisherSearch($query, string $search, array $filters = []): void
    {
        if ($search === '') {
            return;
        }

        $like = like_contains($search);
        $query->where(function ($q) use ($search, $like, $filters) {
            $q->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
            if ($this->usersHaveColumn('company_name')) {
                $q->orWhereRaw('company_name LIKE ? ESCAPE ?', [$like, '\\']);
            }
            $publisherId = $this->canonicalStaffId($search);
            if ($publisherId !== null) {
                $q->orWhere('users.id', $publisherId);
            }
            $q->orWhereHas('sites', function ($sites) use ($search, $filters) {
                if ($filters === []) {
                    $sites->notArchived();
                } else {
                    $this->applyStaffSitesArchiveScope($sites, $filters);
                    $this->applyStaffSitesListFilters($sites, $filters);
                }
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
                if ($this->usersHaveColumn('company_name')) {
                    $publisher->orWhereRaw('company_name LIKE ? ESCAPE ?', [$like, '\\']);
                }
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
            foreach (['category', 'language', 'description', 'example_url'] as $column) {
                if (Site::hasSitesColumn($column)) {
                    $q->orWhereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                }
            }
            // CAST AS CHAR is CHAR(1) on MariaDB. SUBSTRING keeps the stored text.
            foreach (['categories', 'languages'] as $column) {
                if (Site::hasSitesColumn($column)) {
                    $q->orWhereRaw('SUBSTRING('.$column.', 1, 8000) LIKE ? ESCAPE ?', [$like, '\\']);
                }
            }
            $siteId = $this->canonicalStaffId($search);
            if ($siteId !== null) {
                $q->orWhere('id', $siteId);
            }
            if ($candidates !== []) {
                $q->orWhereIn('domain', $candidates);
            }
        });
    }

    /**
     * Keep the current Sites mode on page and CSV links.
     *
     * @return array<string, mixed>
     */
    private function staffSitesPagerAppendQuery(
        Request $request,
        bool $needsReview,
        bool $waiting,
        bool $flatQueue,
        bool $allSitesMode,
        bool $publishersDirectory = false
    ): array {
        $query = $request->except(['page', 'publisher', 'site', 'sites_page']);
        foreach (['needs_review', 'waiting_on_publisher', 'flat', 'all', 'publishers'] as $key) {
            unset($query[$key]);
        }

        return array_filter(array_merge($query, [
            'needs_review' => $needsReview ? 1 : null,
            'waiting_on_publisher' => $waiting ? 1 : null,
            'flat' => $flatQueue ? 1 : null,
            'publishers' => $publishersDirectory ? 1 : null,
            'all' => $allSitesMode && $request->query('all') !== null ? 1 : null,
        ]), static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array{
     *     created_at: ?string,
     *     updated_at: ?string,
     *     listed_days: ?int,
     *     listed_label: ?string,
     *     listed_date: ?string
     * }
     */
    private function staffListedDatePayload(mixed $createdAt, mixed $updatedAt = null): array
    {
        $timezone = (string) config('app.timezone');
        $listed = $createdAt instanceof \DateTimeInterface
            ? Carbon::parse($createdAt)->timezone($timezone)
            : null;
        $updated = $updatedAt instanceof \DateTimeInterface
            ? Carbon::parse($updatedAt)->timezone($timezone)
            : null;
        $days = $listed !== null ? (int) $listed->diffInDays(now()) : null;

        return [
            'created_at' => $listed?->toIso8601String(),
            'updated_at' => $updated?->toIso8601String(),
            'listed_days' => $days,
            'listed_label' => $days === null ? null : ($days === 0 ? 'Today' : $days.'d'),
            'listed_date' => $listed?->format('M j, Y'),
        ];
    }

    /**
     * A bare id matches only when the digits are the canonical integer.
     * 000042 must not also match site or publisher 42.
     */
    private function canonicalStaffId(string $search): ?int
    {
        if ($search === '' || ! ctype_digit($search)) {
            return null;
        }

        $id = (int) $search;
        if ((string) $id !== $search) {
            return null;
        }

        return $id > 0 ? $id : null;
    }

    private function usersHaveColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('users', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Exact site-id or canonical domain hit — used to deep-link a unique result.
     */
    /**
     * @param  array{archived?: bool}  $filters
     */
    private function uniqueStaffSiteForExactSearch(string $search, array $filters = []): ?Site
    {
        $query = Site::query();
        $this->applyStaffSitesArchiveScope($query, $filters);

        $siteId = $this->canonicalStaffId($search);
        if ($siteId !== null) {
            $matches = $query->where('id', $siteId)->limit(2)->get();
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

    /**
     * @return array{
     *     tag: ?string,
     *     country: string,
     *     listing_active: string,
     *     listing_verified: string,
     *     below_quality: bool,
     *     missing_market: bool,
     *     archived: bool,
     *     sort: string
     * }
     */
    private function staffSitesListFilterState(Request $request): array
    {
        $tag = SiteTag::catalogFilterFromRequest($request);
        $country = strtolower(trim(scalar_text($request->query('country', $request->input('country', '')))));
        if ($country === 'all') {
            $country = '';
        }

        $active = trim(scalar_text($request->query('listing_active', $request->input('listing_active', ''))));
        $verified = trim(scalar_text($request->query('listing_verified', $request->input('listing_verified', ''))));

        $language = strtolower(trim(scalar_text($request->query('language', $request->input('language', '')))));
        if ($language === 'all') {
            $language = '';
        }
        $niche = trim(scalar_text($request->query('niche', $request->input('niche', ''))));
        $metricsAge = trim(scalar_text($request->query('metrics_age', $request->input('metrics_age', ''))));
        if (! in_array($metricsAge, ['30', '90', 'never'], true)) {
            $metricsAge = '';
        }

        return [
            'tag' => $tag,
            'country' => $country,
            'language' => $language,
            'niche' => $niche,
            'listing_active' => in_array($active, ['0', '1'], true) ? $active : '',
            'listing_verified' => in_array($verified, ['0', '1'], true) ? $verified : '',
            'below_quality' => $this->requestFlag($request, 'below_quality'),
            'ready_to_activate' => $this->requestFlag($request, 'ready_to_activate'),
            'missing_market' => $this->requestFlag($request, 'missing_market'),
            'placeholder' => $this->requestFlag($request, 'placeholder'),
            'missing_cover' => $this->requestFlag($request, 'missing_cover'),
            'bulk_request' => $this->requestFlag($request, 'bulk_request'),
            'scan_failed' => $this->requestFlag($request, 'scan_failed'),
            'copy_strike' => $this->requestFlag($request, 'copy_strike'),
            'has_orders' => $this->requestFlag($request, 'has_orders'),
            'featured' => $this->requestFlag($request, 'featured'),
            'bulk_discount' => $this->requestFlag($request, 'bulk_discount'),
            'csv_metrics' => $this->requestFlag($request, 'csv_metrics'),
            'price_min' => $this->staffOptionalNumber($request->query('price_min', $request->input('price_min'))),
            'price_max' => $this->staffOptionalNumber($request->query('price_max', $request->input('price_max'))),
            'traffic_min' => $this->staffOptionalInt($request->query('traffic_min', $request->input('traffic_min'))),
            'da_min' => $this->staffOptionalInt($request->query('da_min', $request->input('da_min'))),
            'metrics_age' => $metricsAge,
            'archived' => $this->requestFlag($request, 'archived'),
            'waiting_stage' => MarketingOpsQueues::normalizeWaitingStage($request->query('waiting_stage', $request->input('waiting_stage'))) ?? '',
            'sort' => $this->staffSitesListSortKey($request->query('sort', $request->input('sort'))),
        ];
    }

    private function staffOptionalNumber(mixed $value): ?float
    {
        $text = trim(scalar_text($value));
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        return round((float) $text, 2);
    }

    private function staffOptionalInt(mixed $value): ?int
    {
        $text = trim(scalar_text($value));
        if ($text === '' || ! ctype_digit($text)) {
            return null;
        }

        return (int) $text;
    }

    /**
     * @param  array{tag?: ?string, country?: string, listing_active?: string, listing_verified?: string, below_quality?: bool, missing_market?: bool}  $filter
     */
    private function staffSitesListNarrows(array $filter): bool
    {
        return (($filter['tag'] ?? null) !== null && ($filter['tag'] ?? '') !== '')
            || ($filter['country'] ?? '') !== ''
            || ($filter['language'] ?? '') !== ''
            || ($filter['niche'] ?? '') !== ''
            || ($filter['listing_active'] ?? '') !== ''
            || ($filter['listing_verified'] ?? '') !== ''
            || ! empty($filter['below_quality'])
            || ! empty($filter['ready_to_activate'])
            || ! empty($filter['missing_market'])
            || ! empty($filter['placeholder'])
            || ! empty($filter['missing_cover'])
            || ! empty($filter['bulk_request'])
            || ! empty($filter['scan_failed'])
            || ! empty($filter['copy_strike'])
            || ! empty($filter['has_orders'])
            || ! empty($filter['featured'])
            || ! empty($filter['bulk_discount'])
            || ! empty($filter['csv_metrics'])
            || ($filter['price_min'] ?? null) !== null
            || ($filter['price_max'] ?? null) !== null
            || ($filter['traffic_min'] ?? null) !== null
            || ($filter['da_min'] ?? null) !== null
            || ($filter['metrics_age'] ?? '') !== ''
            || ! empty($filter['archived']);
    }

    private function staffSitesListSortKey(mixed $sort): string
    {
        $value = is_string($sort) ? trim($sort) : '';

        return in_array($value, ['newest', 'oldest', 'price', 'traffic', 'da'], true)
            ? $value
            : '';
    }

    /**
     * @param  Builder<Site>  $query
     * @param  array{archived?: bool}  $filter
     */
    private function applyStaffSitesArchiveScope($query, array $filter): void
    {
        if (! empty($filter['archived'])) {
            $query->archived();

            return;
        }

        $query->notArchived();
    }

    /**
     * @param  Builder<Site>  $query
     * @param  array{tag: ?string, country: string, listing_active: string, listing_verified: string, below_quality: bool, missing_market: bool}  $filter
     */
    private function applyStaffSitesListFilters($query, array $filter): void
    {
        try {
            SiteTag::constrainQuery($query, $filter['tag'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->applyRecordsCountryFilter($query, scalar_text($filter['country'] ?? ''));
        $this->applyStaffLanguageFilter($query, scalar_text($filter['language'] ?? ''));
        $this->applyStaffNicheFilter($query, scalar_text($filter['niche'] ?? ''));

        if (! empty($filter['bulk_request'])) {
            if (Site::hasSitesColumn('added_from_bulk_request')) {
                $query->where('added_from_bulk_request', 1);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (($filter['listing_active'] ?? '') === '1') {
            $query->where('active', 1);
        } elseif (($filter['listing_active'] ?? '') === '0') {
            $query->where(function ($q) {
                $q->where('active', 0)->orWhereNull('active');
            });
        }

        if (($filter['listing_verified'] ?? '') === '1') {
            $query->where('verified', 1);
        } elseif (($filter['listing_verified'] ?? '') === '0') {
            $query->where(function ($q) {
                $q->where('verified', 0)->orWhereNull('verified');
            });
        }

        if (! empty($filter['below_quality'])) {
            $this->constrainStaffBelowQuality($query);
        }

        if (! empty($filter['ready_to_activate'])) {
            $query->readyToActivate();
        }

        if (! empty($filter['missing_market'])) {
            $query->missingMarketplaceCountry();
        }

        if (! empty($filter['placeholder'])) {
            CatalogHealthQueue::apply($query, CatalogHealthQueue::PLACEHOLDER);
        }

        if (! empty($filter['missing_cover'])) {
            CatalogHealthQueue::apply($query, CatalogHealthQueue::MISSING_COVER);
        }

        if (! empty($filter['scan_failed'])) {
            if (Site::hasSitesColumn('enrichment_status')) {
                $query->where('enrichment_status', 'failed');
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filter['copy_strike'])) {
            $query->whereHas('publisher', function ($publisher) {
                if (Schema::hasColumn('users', 'catalog_hide_until')) {
                    $publisher->where('catalog_hide_until', '>', now());
                } else {
                    $publisher->whereRaw('1 = 0');
                }
            });
        }

        if (! empty($filter['has_orders'])) {
            if (Schema::hasTable('order_items')) {
                $query->whereHas('orderItems');
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filter['featured']) && Site::hasSitesColumn('featured_until')) {
            $query->where('featured_until', '>', now());
        } elseif (! empty($filter['featured'])) {
            $query->whereRaw('1 = 0');
        }

        if (! empty($filter['bulk_discount'])) {
            if (Site::hasSitesColumn('bulk_discount_enabled') && Site::hasSitesColumn('bulk_discount_percent')) {
                $query->where('bulk_discount_enabled', 1)->where('bulk_discount_percent', '>', 0);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filter['csv_metrics'])) {
            if (Site::hasSitesColumn('agency_site_import_id') && Site::hasSitesColumn('metrics_manual')) {
                $query->where('agency_site_import_id', '>', 0)->where('metrics_manual', 1);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (($filter['price_min'] ?? null) !== null && Site::hasSitesColumn('price')) {
            $query->where('price', '>=', $filter['price_min']);
        }
        if (($filter['price_max'] ?? null) !== null && Site::hasSitesColumn('price')) {
            $query->where('price', '<=', $filter['price_max']);
        }
        if (($filter['traffic_min'] ?? null) !== null && Site::hasSitesColumn('traffic')) {
            $query->where('traffic', '>=', $filter['traffic_min']);
        }
        if (($filter['da_min'] ?? null) !== null && Site::hasSitesColumn('da')) {
            $query->where('da', '>=', $filter['da_min']);
        }

        $metricsAge = (string) ($filter['metrics_age'] ?? '');
        if ($metricsAge !== '' && Site::hasSitesColumn('metrics_fetched_at')) {
            if ($metricsAge === 'never') {
                $query->whereNull('metrics_fetched_at');
            } elseif (in_array($metricsAge, ['30', '90'], true)) {
                $cutoff = now()->subDays((int) $metricsAge);
                $query->where(function ($q) use ($cutoff) {
                    $q->whereNull('metrics_fetched_at')->orWhere('metrics_fetched_at', '<', $cutoff);
                });
            }
        }
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function constrainStaffBelowQuality($query): void
    {
        $checks = [];
        foreach (['da', 'dr', 'traffic'] as $column) {
            if (Site::hasSitesColumn($column)) {
                $checks[] = $column;
            }
        }
        if ($checks === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $mins = [
            'da' => Site::GOOD_MIN_DA,
            'dr' => Site::GOOD_MIN_DR,
            'traffic' => Site::GOOD_MIN_TRAFFIC,
        ];

        $query->where(function ($q) use ($checks, $mins) {
            $first = array_shift($checks);
            $q->where($first, '<', $mins[$first])->orWhereNull($first);
            foreach ($checks as $column) {
                $q->orWhere($column, '<', $mins[$column])->orWhereNull($column);
            }
        });
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function applyStaffSitesListSort($query, string $sort, string $default, ?int $pinId = null): void
    {
        $key = $sort !== '' ? $sort : $default;
        if (method_exists($query, 'reorder')) {
            $query->reorder();
        }

        $table = $query->getModel()->getTable();
        if ($pinId !== null && $pinId > 0) {
            $query->orderByRaw('CASE WHEN '.$table.'.id = ? THEN 0 ELSE 1 END', [$pinId]);
        }

        $nullsLast = function (string $column) use ($query, $table): void {
            if (! Site::hasSitesColumn($column)) {
                $query->orderByDesc($table.'.id');

                return;
            }

            $query->orderByRaw('CASE WHEN '.$table.'.'.$column.' IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc($table.'.'.$column)
                ->orderByDesc($table.'.id');
        };

        match ($key) {
            'oldest' => $query->orderBy($table.'.created_at')->orderBy($table.'.id'),
            'price' => $nullsLast('price'),
            'traffic' => $nullsLast('traffic'),
            'da' => $nullsLast('da'),
            default => $query->orderByDesc($table.'.id'),
        };
    }

    /**
     * @return Collection<int, Country>
     */
    private function staffMarketplaceCountries()
    {
        try {
            return Country::marketplace()->orderBy('name')->get(['code', 'name']);
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * @return Collection<int, Language>
     */
    private function staffMarketplaceLanguages()
    {
        try {
            return Language::marketplace()->orderBy('name')->get(['code', 'name']);
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * @return list<string>
     */
    private function staffNicheOptions(): array
    {
        try {
            return Category::catalogPickerNames();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Row count for one Sites list filter, with the same archive and filter
     * rules the All sites page uses when that filter is the only one on.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function staffSitesFilterTotal(array $overrides): int
    {
        $filter = array_merge([
            'tag' => null,
            'country' => '',
            'language' => '',
            'niche' => '',
            'listing_active' => '',
            'listing_verified' => '',
            'below_quality' => false,
            'ready_to_activate' => false,
            'missing_market' => false,
            'placeholder' => false,
            'missing_cover' => false,
            'bulk_request' => false,
            'scan_failed' => false,
            'copy_strike' => false,
            'has_orders' => false,
            'featured' => false,
            'bulk_discount' => false,
            'csv_metrics' => false,
            'price_min' => null,
            'price_max' => null,
            'traffic_min' => null,
            'da_min' => null,
            'metrics_age' => '',
            'archived' => false,
        ], $overrides);

        try {
            $query = Site::query();
            $this->applyStaffSitesArchiveScope($query, $filter);
            $this->applyStaffSitesListFilters($query, $filter);

            return (int) $query->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function applyStaffLanguageFilter($query, string $languageCode): void
    {
        $code = strtolower(trim(scalar_text($languageCode)));
        if ($code === '') {
            return;
        }

        try {
            $hasLanguage = Site::hasSitesColumn('language');
            $hasLanguagesJson = Site::hasSitesColumn('languages');
        } catch (\Throwable $e) {
            report($e);
            $query->whereRaw('1 = 0');

            return;
        }
        if (! $hasLanguage && ! $hasLanguagesJson) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($q) use ($code, $hasLanguage, $hasLanguagesJson) {
            if ($hasLanguage) {
                $q->whereRaw('LOWER(language) = ?', [$code]);
            }
            if ($hasLanguagesJson) {
                $like = like_contains('"'.$code.'"');
                $jsonClause = function ($inner) use ($code, $like) {
                    $inner->whereRaw('LOWER(SUBSTRING(languages, 1, 8000)) LIKE ? ESCAPE ?', [$like, '\\'])
                        ->orWhereRaw('LOWER(SUBSTRING(languages, 1, 8000)) = ?', [$code]);
                };
                if ($hasLanguage) {
                    $q->orWhere($jsonClause);
                } else {
                    $q->where($jsonClause);
                }
            }
        });
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function applyStaffNicheFilter($query, string $niche): void
    {
        $name = trim(scalar_text($niche));
        if ($name === '') {
            return;
        }

        try {
            $hasCategory = Site::hasSitesColumn('category');
            $hasCategories = Site::hasSitesColumn('categories');
        } catch (\Throwable $e) {
            report($e);
            $query->whereRaw('1 = 0');

            return;
        }
        if (! $hasCategory && ! $hasCategories) {
            $query->whereRaw('1 = 0');

            return;
        }

        $lower = mb_strtolower($name);
        $query->where(function ($q) use ($name, $lower, $hasCategory, $hasCategories) {
            if ($hasCategory) {
                $q->whereRaw('LOWER(category) = ?', [$lower]);
            }
            if ($hasCategories && ! str_contains($name, '"') && ! str_contains($name, '\\')) {
                $like = mb_strtolower(like_contains('"'.$name.'"'));
                $json = function ($inner) use ($like) {
                    $inner->whereRaw('LOWER(SUBSTRING(categories, 1, 8000)) LIKE ? ESCAPE ?', [$like, '\\']);
                };
                if ($hasCategory) {
                    $q->orWhere($json);
                } else {
                    $q->where($json);
                }
            } elseif (! $hasCategory) {
                $q->whereRaw('1 = 0');
            }
        });
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

        $prefillSiteName = CommunityInbox::plainLine($request->query('site_name'));
        $prefillSiteUrl = CommunityInbox::safeHttpUrl($request->query('site_url')) ?? '';
        $prefillExampleUrl = CommunityInbox::safeHttpUrl($request->query('example_url')) ?? '';
        $prefillCountry = strtolower(search_text($request->query('country')));
        if (strlen($prefillCountry) !== 2) {
            $prefillCountry = '';
        }
        $prefillLanguage = strtolower(search_text($request->query('language')));
        if (strlen($prefillLanguage) !== 2) {
            $prefillLanguage = '';
        }
        $suggestionId = CommunityInbox::suggestionIdFrom($request->query('suggestion_id'));
        $prefillSuggestionNotes = '';
        $occupyingListingUrl = null;

        if ($suggestionId > 0) {
            $suggestion = WebsiteSuggestion::query()->find($suggestionId);
            if ($suggestion) {
                $prefillSuggestionNotes = CommunityInbox::plainLine($suggestion->notes);
                if ($prefillExampleUrl === '') {
                    $suggestionUrl = CommunityInbox::safeHttpUrl($suggestion->website_url);
                    if ($suggestionUrl) {
                        $path = parse_url($suggestionUrl, PHP_URL_PATH);
                        if (is_string($path) && $path !== '' && $path !== '/') {
                            $prefillExampleUrl = $suggestionUrl;
                        }
                    }
                }
                $domain = CommunityInbox::suggestionLookupDomain($suggestion);
                if ($domain !== '') {
                    $occupying = Site::findOccupyingDomain($domain);
                    if ($occupying) {
                        $occupyingListingUrl = CatalogProblemReport::staffListingUrl($occupying, false);
                        if ($selectedPublisherId <= 0) {
                            $selectedPublisherId = (int) $occupying->publisher_id;
                        }
                    }
                }
            }
        }

        $publishers = $this->selectedPublishersForStaffAssign($selectedPublisherId);

        $selectedPublisherUnverified = $selectedPublisherId > 0
            && $publishers->contains(
                fn (User $publisher) => (int) $publisher->id === $selectedPublisherId
                    && ! $publisher->hasVerifiedEmail()
            );

        $languages = Language::marketplace()->orderBy('name')->get();
        $countries = Country::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();
        $isMarketingEditor = $this->isMarketingEditor(auth()->user());
        $sitesBackUrl = $this->staffSitesBackUrl($request, $selectedPublisherId);

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
            'prefillExampleUrl',
            'prefillCountry',
            'prefillLanguage',
            'prefillSuggestionNotes',
            'occupyingListingUrl',
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
            'request_source' => 'nullable|string|max:120',
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
                ->when(User::hasUsersColumn('suspended_at'), fn ($q) => $q->whereNull('suspended_at'))
                ->first();

            if (! $publisher) {
                $validator->errors()->add('publisher_id', 'Choose a valid publisher account.');
            } elseif (! $publisher->hasVerifiedEmail()) {
                $validator->errors()->add(
                    'publisher_id',
                    'This publisher has not verified their email. They cannot Accept until they verify.'
                );
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

            $this->rejectBlankCheckedPlacementFees($validator, $request);
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = app(SiteDescriptionSanitizer::class)
            ->sanitize(scalar_text($request->input('description')));

        $site = null;
        $bulk = null;
        $storedImagePath = null;
        $publisherId = (int) $request->input('publisher_id');

        try {
            DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, $publisherId, &$storedImagePath, &$site, &$bulk) {
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

                $bulk = BulkSiteRequest::openForStaffInvites($publisherId, (int) auth()->id(), [$site]);
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
                    'written_request' => true,
                    'bulk_site_request_id' => $bulk?->id,
                    ...array_filter([
                        'request_source' => CommunityInbox::plainLine($request->input('request_source')),
                    ]),
                ],
                $site->site_name
            );
            if ($bulk) {
                ActivityLogger::log(
                    'bulk_request.staff_assigned',
                    (auth()->user()->name ?? 'Staff').' opened staff batch #'.$bulk->id.' for publisher acceptance',
                    $bulk,
                    [
                        'bulk_site_request_id' => $bulk->id,
                        'publisher_id' => $publisherId,
                        'site_ids' => [$site->id],
                        'site_count' => 1,
                    ],
                    'Bulk request #'.$bulk->id
                );
            }
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
                $belled = app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site) !== null;
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

        $successActions = [
            [
                'url' => staff_route('sites.create', ['publisher' => $publisherId], false),
                'label' => 'Add another for this publisher',
            ],
        ];
        if ($site?->id) {
            $successActions[] = [
                'url' => staff_route('sites.edit', $site->id, false),
                'label' => 'Edit listing',
            ];
        }
        if ($bulk?->id) {
            $successActions[] = [
                'url' => staff_route('bulk-site-requests.show', $bulk->id, false),
                'label' => 'Open batch',
            ];
        }

        return redirect()
            ->to(staff_route('sites.index', $redirectParams, false))
            ->with('success', $success)
            ->with('success_actions', $successActions)
            ->with('success_action', $successActions[0]);
    }

    public function domainCheck(Request $request): JsonResponse
    {
        $url = $this->postedHttpUrl($request->query('site_url', ''));
        $domain = $this->domainFromUrl($url);
        if ($domain === null) {
            return response()->json([
                'available' => false,
                'message' => 'Enter a full website URL to check it.',
            ]);
        }

        $existing = $this->findSiteByDomain($domain);

        return response()->json([
            'available' => $existing === null,
            'domain' => $domain,
            'listing_url' => $existing ? CatalogProblemReport::staffListingUrl($existing, false) : null,
            'message' => $existing
                ? $this->domainAlreadyRegisteredMessage($existing)
                : $domain.' is not registered yet.',
        ]);
    }

    public function publisherDomains(Request $request): JsonResponse
    {
        $publisherId = (int) $request->query('publisher', 0);
        $publisher = User::query()
            ->whereKey($publisherId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
            ->first();
        if (! $publisher) {
            return response()->json(['domains' => [], 'total' => 0]);
        }

        $query = Site::query()->where('publisher_id', $publisher->id)->orderBy('domain');
        $total = (clone $query)->count();
        $sites = $query->limit(12)->get(['id', 'domain', 'publisher_id']);
        $domains = $sites->map(function (Site $site) {
            return [
                'domain' => (string) $site->domain,
                'listing_url' => CatalogProblemReport::staffListingUrl($site, false),
            ];
        })->values()->all();

        $userUrl = null;
        try {
            if (auth()->user()?->isAdmin()) {
                $userUrl = route('admin.users.show', $publisher->id, false);
            }
        } catch (\Throwable) {
            $userUrl = null;
        }

        return response()->json([
            'domains' => $domains,
            'total' => $total,
            'publisher' => [
                'id' => $publisher->id,
                'name' => $publisher->name,
                'email' => $publisher->email,
                'verified' => $publisher->hasVerifiedEmail(),
                'sites_count' => $total,
            ],
            'sites_url' => staff_route('sites.index', ['publisher' => $publisher->id], false),
            'user_url' => $userUrl,
        ]);
    }

    public function searchPublishers(Request $request): JsonResponse
    {
        $q = search_text($request->query('q'));
        $selectedId = (int) $request->query('selected', $request->query('publisher', 0));

        $query = $this->staffAssignPublisherBaseQuery()
            ->whereEmailVerified()
            ->withCount('sites');

        if ($q !== '') {
            $like = like_contains($q);
            $query->where(function ($inner) use ($like) {
                $inner->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereHas('sites', function ($sites) use ($like) {
                        $sites->whereRaw('domain LIKE ? ESCAPE ?', [$like, '\\']);
                    });
            })->orderBy('name')->limit(20);
        } else {
            $query->orderByDesc('id')->limit(8);
        }

        $rows = $query->get();

        if ($selectedId > 0 && ! $rows->contains(fn (User $user) => (int) $user->id === $selectedId)) {
            $selected = $this->staffAssignPublisherBaseQuery()
                ->whereKey($selectedId)
                ->withCount('sites')
                ->first();
            if ($selected) {
                $rows = $rows->prepend($selected);
            }
        }

        $options = $rows->map(function (User $publisher) {
            $count = (int) ($publisher->sites_count ?? 0);
            $label = $publisher->name.' · '.$publisher->email;
            if ($count > 0) {
                $label .= ' ('.$count.' '.Str::plural('site', $count).')';
            }
            if (! $publisher->hasVerifiedEmail()) {
                $label .= ' · unverified';
            }

            return [
                'value' => (string) $publisher->id,
                'label' => $label,
                'data' => [
                    'verified' => $publisher->hasVerifiedEmail() ? '1' : '0',
                ],
            ];
        })->values()->all();

        return response()->json(['options' => $options]);
    }

    public function lookupMetrics(Request $request, SiteMetricsAggregator $metrics): JsonResponse
    {
        if (! SiteEnrichmentService::enabled() || ! $metrics->anyApiProviderConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Metrics have to be typed. No lookup provider is configured.',
            ]);
        }

        $url = $this->postedHttpUrl($request->input('site_url', ''));
        $domain = $this->domainFromUrl($url);
        if ($domain === null) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a valid site URL before looking up metrics.',
            ], 422);
        }

        $probe = new Site;
        $probe->site_url = $url;
        $probe->domain = $domain;
        $probe->metrics_manual = false;

        try {
            $result = $metrics->fetch($probe);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Metrics have to be typed. The lookup did not return numbers.',
            ]);
        }

        $snapshot = $result['snapshot'];
        if ($snapshot->domainAuthority === null && $snapshot->domainRating === null && $snapshot->monthlyOrganicTraffic === null) {
            return response()->json([
                'success' => false,
                'message' => 'Metrics have to be typed. The lookup did not return numbers.',
            ]);
        }

        return response()->json([
            'success' => true,
            'da' => $snapshot->domainAuthority,
            'dr' => $snapshot->domainRating,
            'traffic' => $snapshot->monthlyOrganicTraffic,
            'message' => 'Metrics filled. You can still edit them.',
        ]);
    }

    public function createBulkForPublisher(Request $request): View
    {
        $rawSelectedPublisher = old('publisher_id', $request->query('publisher', 0));
        if (is_array($rawSelectedPublisher)) {
            $rawSelectedPublisher = reset($rawSelectedPublisher);
        }
        $selectedPublisherId = (int) $rawSelectedPublisher;
        $publishers = $this->publishersForStaffAssign($selectedPublisherId);
        $sitesBackUrl = $this->staffSitesBackUrl($request, $selectedPublisherId);

        return view('admin.site-bulk-create', compact('publishers', 'selectedPublisherId', 'sitesBackUrl'));
    }

    public function storeBulkForPublisher(Request $request): RedirectResponse
    {
        if (! Site::hasSitesColumn('publisher_accepted_at') || ! Site::hasSitesColumn('assigned_by_user_id')) {
            return back()->withErrors([
                'save' => 'Database is missing the publisher-acceptance columns. Run migrations, then try again.',
            ])->withInput();
        }

        $validator = Validator::make($request->all(), [
            'publisher_id' => 'required|integer|exists:users,id',
            'rows' => 'nullable|string|max:500000',
            'csv_file' => 'nullable|file|max:5120',
            'written_request' => 'accepted',
        ], [
            'written_request.accepted' => 'Confirm you have a written request from this publisher’s account email.',
        ]);

        $publisherId = (int) $request->input('publisher_id');
        $publisher = User::query()
            ->whereKey($publisherId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
            ->when(User::hasUsersColumn('suspended_at'), fn ($q) => $q->whereNull('suspended_at'))
            ->first();

        $validator->after(function ($validator) use ($request, $publisher) {
            if (! $publisher) {
                $validator->errors()->add('publisher_id', 'Choose a valid publisher account.');
            }
            $hasFile = $request->hasFile('csv_file');
            $hasRows = trim((string) $request->input('rows', '')) !== '';
            if ($hasFile) {
                $upload = $request->file('csv_file');
                $ext = strtolower((string) ($upload instanceof UploadedFile ? $upload->getClientOriginalExtension() : ''));
                if (! $upload instanceof UploadedFile || $upload->getRealPath() === false) {
                    $validator->errors()->add('csv_file', 'We could not read that file.');
                } elseif (! in_array($ext, ['csv', 'txt'], true)) {
                    $validator->errors()->add('csv_file', 'Upload a .csv or .txt file.');
                }
            }
            if (! $hasFile && ! $hasRows) {
                $validator->errors()->add('rows', 'Paste the sites or upload a CSV.');
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $upload = $request->file('csv_file');
        $rawRows = $upload instanceof UploadedFile
            ? $this->bulkInviteRowsFromCsv($upload)
            : $this->bulkInviteRowsFromPaste((string) $request->input('rows', ''));

        if (count($rawRows) > BulkSiteRequest::MAX_SITES_PER_REQUEST) {
            return back()->withErrors([
                'rows' => 'Add at most '.BulkSiteRequest::MAX_SITES_PER_REQUEST.' sites at once.',
            ])->withInput();
        }
        if ($rawRows === []) {
            return back()->withErrors(['rows' => 'No site rows were found.'])->withInput();
        }

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $seen = [];
        $ready = [];
        $failures = [];
        foreach ($rawRows as $index => $fields) {
            $line = (int) ($fields['_line'] ?? ($index + 1));
            unset($fields['_line']);
            $checked = $this->validateBulkInviteRow($fields, $line, $allowedCountries, $allowedLanguages, $seen);
            if ($checked['errors'] !== []) {
                $failures[] = $checked;
            } else {
                $ready[] = $checked['row'];
            }
        }

        if ($failures !== []) {
            return back()
                ->withErrors(['rows' => 'Fix the rows below. Nothing was saved.'])
                ->with('bulk_row_errors', $failures)
                ->withInput();
        }

        $sites = [];
        $bulk = null;
        try {
            DB::transaction(function () use ($ready, $publisherId, &$sites, &$bulk) {
                foreach ($ready as $row) {
                    $sites[] = $this->persistStaffInvite($row, $publisherId);
                }
                $bulk = BulkSiteRequest::openForStaffInvites($publisherId, (int) auth()->id(), $sites);
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'save' => UserFacingError::message($e, 'We could not save these websites. Nothing was added.'),
            ])->withInput();
        }

        foreach ($sites as $site) {
            if (config('site_enrichment.enabled', true)) {
                try {
                    CaptureSiteScreenshotJob::dispatch($site->id, 'staff_assign');
                } catch (\Throwable $e) {
                    Log::warning('Failed to queue screenshot for staff bulk site', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $below = 0;
        foreach ($sites as $site) {
            if (! $site->hasGoodMetrics()) {
                $below++;
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
                        'written_request' => true,
                        'bulk' => true,
                        'bulk_site_request_id' => $bulk?->id,
                    ],
                    $site->site_name
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to log staff bulk site: '.$e->getMessage());
            }
        }

        if ($bulk) {
            try {
                ActivityLogger::log(
                    'bulk_request.staff_assigned',
                    (auth()->user()->name ?? 'Staff').' opened staff batch #'.$bulk->id.' for publisher acceptance',
                    $bulk,
                    [
                        'bulk_site_request_id' => $bulk->id,
                        'publisher_id' => $publisherId,
                        'site_ids' => collect($sites)->pluck('id')->all(),
                        'site_count' => count($sites),
                    ],
                    'Bulk request #'.$bulk->id
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to log staff bulk batch: '.$e->getMessage());
            }
        }

        $emailed = false;
        $belled = false;
        try {
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(new AdminAssignedSitesBatchNotification($publisher, $sites));
                $emailed = true;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email publisher about staff bulk sites: '.$e->getMessage());
        }

        try {
            $names = collect($sites)->pluck('domain')->filter()->take(12)->implode(', ');
            $extra = count($sites) > 12 ? ' and '.(count($sites) - 12).' more' : '';
            $count = count($sites);
            $belled = app(InAppNotificationService::class)->notify(
                $publisherId,
                InAppNotificationService::TYPE_SITE_STATUS,
                $count === 1
                    ? 'Please accept a website we added for you'
                    : 'Please accept websites we added for you',
                $count === 1
                    ? 'Our team added 1 website. Accept it in My Sites → Invites. '.$names
                    : 'Our team added '.$count.' websites. Accept them in My Sites → Invites. '.$names.$extra,
                [
                    'category' => InAppNotificationService::CATEGORY_ACCOUNT,
                    'icon' => 'check-circle',
                    'priority' => InAppNotification::PRIORITY_HIGH,
                    'related' => $sites[0] ?? null,
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                    'action_label' => 'Review & accept',
                    'action_url' => route('publisher.websites', ['status' => 'invites'], false),
                ]
            ) !== null;
        } catch (\Throwable $e) {
            Log::warning('Failed to bell-notify publisher about staff bulk sites: '.$e->getMessage());
        }

        $count = count($sites);
        $success = $count.' '.($count === 1 ? 'site' : 'sites').' added as batch'.($bulk?->id ? ' #'.$bulk->id : '').' for acceptance.';
        $success .= ($emailed || $belled)
            ? ($count === 1
                ? ' Publisher was notified — they must open My Sites → Invites and Accept.'
                : ' Publisher was notified once — they must open My Sites → Invites and Accept each one.')
            : ' The listings were saved, but we could not notify the publisher. Ask them to open My Sites → Invites and Accept.';
        if ($below > 0) {
            $success .= ' '.$below.' listing(s) are below the marketing Activate bar (DA ≥ '.Site::GOOD_MIN_DA.', DR ≥ '.Site::GOOD_MIN_DR.', traffic ≥ '.number_format(Site::GOOD_MIN_TRAFFIC).').';
        }

        $redirectParams = ['publisher' => $publisherId];
        if (($sites[0] ?? null)?->id) {
            $redirectParams['site'] = $sites[0]->id;
        }

        $successActions = [
            [
                'url' => staff_route('sites.bulk-create', ['publisher' => $publisherId], false),
                'label' => 'Add more in bulk',
            ],
        ];
        if ($bulk?->id) {
            $successActions[] = [
                'url' => staff_route('bulk-site-requests.show', $bulk->id, false),
                'label' => 'Open batch',
            ];
        }

        return redirect()
            ->to(staff_route('sites.index', $redirectParams, false))
            ->with('success', $success)
            ->with('success_actions', $successActions)
            ->with('success_action', $successActions[0]);
    }

    public function resendInvite(Request $request, int $id): JsonResponse
    {
        $site = Site::with('publisher:id,name,email')->findOrFail($id);
        if (! $site->isPendingPublisherAcceptance()) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is not waiting for the publisher to accept it.',
            ], 422);
        }

        $publisher = $site->publisher;
        $emailed = false;
        $belled = false;
        try {
            if ($publisher?->email) {
                $mail = new AdminAssignedSiteNotification($site, $publisher);
                $mail->dedupeKey = 'admin-assigned-site-'.$site->id.'-resend-'.Str::uuid();
                Mail::to($publisher->email)->send($mail);
                $emailed = true;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to resend staff-assigned site email: '.$e->getMessage());
        }

        try {
            $belled = app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site) !== null;
        } catch (\Throwable $e) {
            Log::warning('Failed to resend staff-assigned site bell: '.$e->getMessage());
        }

        if (! $emailed && ! $belled) {
            return response()->json([
                'success' => false,
                'message' => 'The listing is still waiting, but we could not notify the publisher.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Invite resent. The publisher still has to accept it.',
        ]);
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
        $sitesBackUrl = $this->staffSitesBackUrl(request(), (int) $site->publisher_id, (int) $site->id);

        $editData = compact(
            'site',
            'isMarketingEditor',
            'marketingListingLocked',
            'languages',
            'countries',
            'categories',
            'countryLanguageMap',
            'sitesBackUrl'
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
                ->to($this->staffSitesBackUrl($request, (int) $site->publisher_id, (int) $site->id))
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
            'site_tag' => 'sometimes|nullable|in:sponsored,partner_material,as_you_prefer,none',
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

            if ($request->boolean('placement_offers_form')) {
                $this->rejectBlankCheckedPlacementFees($validator, $request);
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

        $placementPatch = $this->placementOffersPatch($request);

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

        return SiteTag::exclusiveAttributePatch($this->mergePostedSiteTag($data, $request), $site);
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
            'site_tag' => 'sometimes|nullable|in:sponsored,partner_material,as_you_prefer,none',
        ];
        if ($canFixListing) {
            $rules['site_name'] = 'sometimes|required|string|max:255';
            $rules['site_url'] = 'sometimes|required|url|max:255';
            $rules['example_url'] = 'nullable|url|max:255';
            $rules['price'] = 'sometimes|required|numeric|min:0|max:999999.99';
            $rules['description'] = 'sometimes|nullable|string|max:20000';
        }
        if ($canFixListing && $request->boolean('placement_offers_form')) {
            $rules = array_merge($rules, $this->placementOfferValidationRules());
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

        $validator = Validator::make(
            $request->all(),
            $rules,
            array_merge($this->siteImageValidationMessages(), [
                'price.max' => 'Price must be at most €999,999.99.',
            ]),
            $this->placementOfferValidationAttributes()
        );

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

            if ($canFixListing && $request->boolean('placement_offers_form')) {
                $this->rejectBlankCheckedPlacementFees($validator, $request);
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

            $placementPatch = $this->placementOffersPatch($request);
            if ($placementPatch !== null) {
                $payload = array_merge($payload, $placementPatch);
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

        return $this->mergePostedSiteTag($payload, $request);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergePostedSiteTag(array $data, Request $request): array
    {
        if (! $request->exists('site_tag') || ! class_exists(SiteTag::class)) {
            return $data;
        }

        $raw = $request->input('site_tag');
        $tag = is_string($raw) ? strtolower(trim($raw)) : '';
        if ($tag === '' || $tag === 'none') {
            $tag = null;
        }

        return array_merge($data, SiteTag::flags($tag));
    }

    /**
     * @return Collection<int, User>
     */
    private function publishersForStaffAssign(int $selectedPublisherId)
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
            ->when(User::hasUsersColumn('suspended_at'), fn ($q) => $q->whereNull('suspended_at'))
            ->where(function ($q) use ($selectedPublisherId) {
                $q->whereEmailVerified();
                if ($selectedPublisherId > 0) {
                    $q->orWhere('id', $selectedPublisherId);
                }
            })
            ->withCount('sites')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'email_verified_at']);
    }

    /**
     * Same-origin Sites list URL. Query-string return filters only — never PUT body.
     */
    private function staffSitesBackUrl(Request $request, int $publisherId = 0, ?int $siteId = null): string
    {
        $returnQuery = AdminSites::storedReturnQuery($request);
        if ($returnQuery !== []) {
            return staff_route('sites.index', $returnQuery, false);
        }

        return staff_route('sites.index', array_filter([
            'publisher' => $publisherId > 0 ? $publisherId : null,
            'site' => ($siteId ?? 0) > 0 ? $siteId : null,
        ]), false);
    }

    /**
     * Create form only: selected publisher (if any). Typeahead loads the rest.
     *
     * @return Collection<int, User>
     */
    private function selectedPublishersForStaffAssign(int $selectedPublisherId)
    {
        if ($selectedPublisherId <= 0) {
            return collect();
        }

        return $this->staffAssignPublisherBaseQuery()
            ->whereKey($selectedPublisherId)
            ->withCount('sites')
            ->get();
    }

    /**
     * @return Builder<User>
     */
    private function staffAssignPublisherBaseQuery()
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
            ->when(User::hasUsersColumn('suspended_at'), fn ($q) => $q->whereNull('suspended_at'));
    }

    private function domainFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $domain = $this->normalizeDomain($host);
        if ($domain === '' || ! $this->isMarketplaceHost($domain)) {
            return null;
        }

        return $domain;
    }

    private function rejectBlankCheckedPlacementFees($validator, Request $request): void
    {
        foreach (config('site_placement.homepage_days', [1, 7, 30]) as $days) {
            if (! $request->boolean('homepage.'.$days)) {
                continue;
            }
            $raw = $request->input('price_homepage.'.$days);
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $validator->errors()->add(
                    'price_homepage.'.$days,
                    'Enter a fee for the '.$days.'-day homepage offer, or leave it unchecked. Use 0 for free.'
                );
            }
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function bulkInviteRowsFromPaste(string $raw): array
    {
        $rows = [];
        $lineNo = 0;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $lineNo++;
            $trimmed = trim($this->stripBulkHeaderBom($line));
            if ($trimmed === '') {
                continue;
            }
            $delimiter = $this->bulkPasteDelimiter($trimmed);
            $parts = str_getcsv($trimmed, $delimiter, '"', '');
            if ($this->isBulkHeaderRow($parts)) {
                continue;
            }
            $rows[] = $this->bulkInviteFieldsFromColumns($parts, $delimiter, $lineNo);
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function bulkInviteRowsFromCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return [];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        $start = ftell($handle);
        $sample = '';
        $sampleLine = fgets($handle);
        if (is_string($sampleLine)) {
            $sample = $this->stripBulkHeaderBom($sampleLine);
        }
        if ($start !== false) {
            fseek($handle, $start);
        }
        $delimiter = $this->bulkPasteDelimiter($sample);
        $rows = [];
        $lineNo = 0;
        while (($cols = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $lineNo++;
            if ($cols === [null]) {
                continue;
            }
            $cols[0] = $this->stripBulkHeaderBom((string) ($cols[0] ?? ''));
            if ($this->isBulkHeaderRow($cols)) {
                continue;
            }
            if (implode('', array_map(fn ($value) => trim((string) $value), $cols)) === '') {
                continue;
            }
            $rows[] = $this->bulkInviteFieldsFromColumns($cols, $delimiter, $lineNo);
        }
        fclose($handle);

        return $rows;
    }

    private function stripBulkHeaderBom(string $value): string
    {
        return ltrim($value, "\xEF\xBB\xBF");
    }

    /**
     * @param  list<mixed>  $cols
     */
    private function isBulkHeaderRow(array $cols): bool
    {
        return strtolower(trim($this->stripBulkHeaderBom((string) ($cols[0] ?? '')))) === 'url';
    }

    private function bulkPasteDelimiter(string $line): string
    {
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t"] as $delimiter) {
            if ($delimiter !== ',' && substr_count($line, $delimiter) === 0) {
                continue;
            }
            $parts = str_getcsv($line, $delimiter, '"', '');
            $url = strtolower(trim((string) ($parts[0] ?? '')));
            $score = min(count($parts), 15) + min(substr_count($line, $delimiter), 20);
            if (str_starts_with($url, 'http') || $url === 'url') {
                $score += 4;
            }
            if (is_numeric(trim((string) ($parts[1] ?? '')))) {
                $score += 4;
            }
            if (is_numeric(trim((string) ($parts[2] ?? '')))) {
                $score += 2;
            }
            if (preg_match('/^[a-z]{2}$/', strtolower(trim((string) ($parts[5] ?? ''))))) {
                $score += 2;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * @param  list<mixed>  $cols
     * @return array<string, string>
     */
    private function bulkInviteFieldsFromColumns(array $cols, string $delimiter, int $line): array
    {
        $raw = array_map(fn ($value) => (string) $value, $cols);
        $cols = array_map('trim', $raw);
        $niches = $cols[13] ?? '';
        $description = $cols[14] ?? '';
        if (count($raw) > 15) {
            [$niches, $description] = $this->repairBulkNicheAndDescription(array_slice($raw, 13), $delimiter);
        }

        return [
            '_line' => (string) $line,
            'site_url' => $cols[0] ?? '',
            'price' => $cols[1] ?? '',
            'da' => $cols[2] ?? '',
            'dr' => $cols[3] ?? '',
            'traffic' => $cols[4] ?? '',
            'country' => $cols[5] ?? '',
            'language' => $cols[6] ?? '',
            'site_name' => $cols[7] ?? '',
            'example_url' => $cols[8] ?? '',
            'turnaround_time' => $cols[9] ?? '',
            'publication_time' => $cols[10] ?? '',
            'link_type' => $cols[11] ?? '',
            'site_tag' => $cols[12] ?? '',
            'categories' => $niches,
            'description' => $description,
        ];
    }

    /**
     * Niche names such as "Marketing, PR & Advertising" contain commas.
     * Join the tail until both the niche list and the description validate.
     *
     * @param  list<string>  $tail
     * @return array{0: string, 1: string}
     */
    private function repairBulkNicheAndDescription(array $tail, string $delimiter): array
    {
        $tail = array_values($tail);
        $count = count($tail);
        for ($take = 1; $take <= $count; $take++) {
            $niche = trim(implode($delimiter, array_slice($tail, 0, $take)));
            $description = trim(implode($delimiter, array_slice($tail, $take)));
            $resolved = Category::resolveNicheNames($this->nicheNamesInput($niche));
            if ($resolved['unknown'] !== [] || $resolved['resolved'] === [] || count($resolved['resolved']) > 7) {
                continue;
            }
            if (SiteDescriptionRules::errors($description) !== []) {
                continue;
            }

            return [$niche, $description];
        }

        return [
            trim(implode($delimiter, array_slice($tail, 0, 1))),
            trim(implode($delimiter, array_slice($tail, 1))),
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @param  list<string>  $allowedCountries
     * @param  list<string>  $allowedLanguages
     * @param  array<string, true>  $seenDomains
     * @return array{line: int, errors: list<string>, row: array<string, mixed>}
     */
    private function validateBulkInviteRow(array $fields, int $line, array $allowedCountries, array $allowedLanguages, array &$seenDomains): array
    {
        $errors = [];
        $siteUrl = $this->normalizeHttpUrl((string) ($fields['site_url'] ?? ''));
        $exampleUrl = $this->normalizeHttpUrl((string) ($fields['example_url'] ?? ''));
        $domain = $this->domainFromUrl($siteUrl);
        $exampleDomain = $this->domainFromUrl($exampleUrl);
        if ($domain === null) {
            $errors[] = 'Invalid URL';
        }
        if ($exampleDomain === null || ($domain !== null && $this->exampleUrlHostDiffers($siteUrl, $exampleUrl))) {
            $errors[] = 'Example URL must be on the same website domain.';
        }
        if ($domain !== null) {
            if (isset($seenDomains[$domain])) {
                $errors[] = 'This domain is repeated in the batch.';
            }
            $seenDomains[$domain] = true;
            $existing = $this->findSiteByDomain($domain);
            if ($existing) {
                $errors[] = $this->domainAlreadyRegisteredMessage($existing);
            }
        }

        $siteName = $this->normalizeSiteName((string) ($fields['site_name'] ?? ''));
        if ($siteName === '' || strlen($siteName) > 255) {
            $errors[] = 'Site name is required.';
        }
        $price = is_numeric($fields['price'] ?? null) ? round((float) $fields['price'], 2) : null;
        if ($price === null || $price < 0 || $price > 999999.99) {
            $errors[] = 'Price must be from 0 to 999999.99.';
        }
        $da = is_numeric($fields['da'] ?? null) ? (int) $fields['da'] : null;
        $dr = is_numeric($fields['dr'] ?? null) ? (int) $fields['dr'] : null;
        $traffic = is_numeric($fields['traffic'] ?? null) ? (int) $fields['traffic'] : null;
        if ($da === null || $da < 0 || $da > 100) {
            $errors[] = 'DA must be from 0 to 100.';
        }
        if ($dr === null || $dr < 0 || $dr > 100) {
            $errors[] = 'DR must be from 0 to 100.';
        }
        if ($traffic === null || $traffic < 0 || $traffic > 4294967295) {
            $errors[] = 'Traffic must be a whole number.';
        }

        $country = strtolower(trim((string) ($fields['country'] ?? '')));
        $language = strtolower(trim((string) ($fields['language'] ?? '')));
        if (! in_array($country, $allowedCountries, true)) {
            $errors[] = 'Country is not a marketplace country.';
        }
        if (! in_array($language, $allowedLanguages, true)) {
            $errors[] = 'Language is not a marketplace language.';
        }
        if ($country !== '' && $language !== '' && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
            $errors[] = 'That language is not allowed for the selected country.';
        }

        $resolved = Category::resolveNicheNames($this->nicheNamesInput($fields['categories'] ?? ''));
        if ($resolved['unknown'] !== []) {
            $errors[] = 'Unknown niche: '.implode(', ', $resolved['unknown']);
        }
        if ($resolved['resolved'] === [] || count($resolved['resolved']) > 7) {
            $errors[] = 'Choose 1 to 7 niches.';
        }

        $turnaround = strtolower(trim((string) ($fields['turnaround_time'] ?? '')));
        $publication = strtolower(trim((string) ($fields['publication_time'] ?? '')));
        $linkType = strtolower(trim((string) ($fields['link_type'] ?? '')));
        if (! in_array($turnaround, ['24h', '48h', '3days', '5days', '7days'], true)) {
            $errors[] = 'Turnaround must be 24h, 48h, 3days, 5days, or 7days.';
        }
        if (! in_array($publication, ['6months', '1year', 'permanent'], true)) {
            $errors[] = 'Publication must be 6months, 1year, or permanent.';
        }
        if (! in_array($linkType, ['dofollow', 'nofollow'], true)) {
            $errors[] = 'Link type must be dofollow or nofollow.';
        }
        $tag = strtolower(trim((string) ($fields['site_tag'] ?? '')));
        if ($tag !== '' && ! in_array($tag, ['sponsored', 'partner_material', 'as_you_prefer', 'none'], true)) {
            $errors[] = 'Tag must be sponsored, partner_material, as_you_prefer, none, or blank.';
        }

        foreach (SiteDescriptionRules::errors((string) ($fields['description'] ?? '')) as $message) {
            $errors[] = $message;
        }

        return [
            'line' => $line,
            'errors' => $errors,
            'row' => [
                'site_name' => $siteName,
                'site_url' => $siteUrl,
                'example_url' => $exampleUrl,
                'domain' => $domain,
                'da' => $da,
                'dr' => $dr,
                'traffic' => $traffic,
                'country' => $country,
                'language' => $language,
                'categories' => $resolved['resolved'],
                'price' => $price,
                'turnaround_time' => $turnaround,
                'publication_time' => $publication,
                'link_type' => $linkType,
                'site_tag' => $tag === '' ? null : $tag,
                'description' => (string) ($fields['description'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persistStaffInvite(array $row, int $publisherId): Site
    {
        $domain = (string) $row['domain'];
        Site::releaseCancelledBulkDomain($domain, $publisherId);
        $existing = $this->findSiteByDomain($domain, lock: true);
        if ($existing) {
            throw ValidationException::withMessages([
                'rows' => [$this->domainAlreadyRegisteredMessage($existing)],
            ]);
        }

        $categories = $row['categories'];
        $cleanDescription = app(SiteDescriptionSanitizer::class)->sanitize((string) $row['description']);
        $site = new Site;
        $site->applyMarketplaceListing([
            'publisher_id' => $publisherId,
            'assigned_by_user_id' => auth()->id(),
            'publisher_accepted_at' => null,
            'site_name' => $row['site_name'],
            'site_url' => $row['site_url'],
            'domain' => $domain,
            'example_url' => $row['example_url'],
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
            'category' => implode('|', $categories),
            'categories' => $categories,
            'price' => $row['price'],
            'turnaround_time' => $row['turnaround_time'],
            'publication_time' => $row['publication_time'],
            'link_type' => $row['link_type'],
            'description' => $cleanDescription,
            'verified' => false,
            'active' => false,
            'enrichment_status' => 'pending',
            'onboarding_status' => null,
        ]);
        $site->forceFill([
            'assigned_by_user_id' => auth()->id(),
            'publisher_accepted_at' => null,
            'verified' => false,
            'active' => false,
            'da' => $row['da'],
            'dr' => $row['dr'],
            'traffic' => $row['traffic'],
            'price' => $row['price'],
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
        ]);
        SiteTag::applyStaffDefault($site, $row['site_tag']);
        $site->save();

        if ((int) $site->da !== (int) $row['da'] || (int) $site->dr !== (int) $row['dr'] || (int) $site->traffic !== (int) $row['traffic']) {
            throw new \RuntimeException('DA/DR/traffic did not persist after save.');
        }
        if (is_numeric($row['price']) && round((float) $site->price, 2) !== round((float) $row['price'], 2)) {
            throw new \RuntimeException('Staff site price did not persist after save.');
        }
        if (filled($site->publisher_accepted_at) || blank($site->assigned_by_user_id) || (bool) $site->verified || (bool) $site->active) {
            throw new \RuntimeException('Publisher invite state did not persist after save.');
        }

        return $site;
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
     * Homepage / social / sensitive extras posted from staff create or edit.
     *
     * @return array<string, mixed>|null
     */
    private function placementOffersPatch(Request $request): ?array
    {
        if (! $request->boolean('placement_offers_form')) {
            return null;
        }

        $homepagePrices = $this->collectHomepagePlacementPrices($request);
        $sensitivePrices = $this->collectSensitivePrices($request);

        return [
            'homepage_placement_prices' => $homepagePrices !== [] ? $homepagePrices : null,
            'social_promotion' => $this->collectSocialPromotion($request),
            'sensitive_prices' => $sensitivePrices !== [] ? $sensitivePrices : null,
        ];
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

    private function postedHttpUrl(mixed $raw): string
    {
        $value = $this->firstScalarString($raw);

        return $this->normalizeHttpUrl(is_string($value) ? $value : '');
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

        $raw = strtolower(trim(scalar_text($value)));

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

    private function staffSiteQualifiesForBulkArchive(Site $site): bool
    {
        if ($site->isArchived() || $site->orderItemsCount() > 0) {
            return false;
        }

        return (bool) $site->verified
            || (bool) $site->active
            || $site->wasAddedByPublisher()
            || $site->isBulkRequestDraft();
    }

    // VERIFY / UNVERIFY (approve / reject) — admin only
    public function bulkAction(Request $request): JsonResponse
    {
        $matchAll = $request->boolean('match_all');
        $rules = [
            'action' => ['required', 'in:verify,activate,reject,deactivate,archive'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'match_all' => ['nullable', 'boolean'],
            'scope' => ['nullable', 'in:publisher,flat,all'],
            'publisher_id' => ['nullable', 'integer'],
        ];
        if (! $matchAll) {
            $rules['ids'] = ['required', 'array', 'min:1', 'max:50'];
            $rules['ids.*'] = ['integer', 'distinct'];
        }
        $data = $request->validate($rules);
        $matchedTotal = null;
        if ($matchAll) {
            $matched = $this->staffBulkMatchIds($request);
            $data['ids'] = $matched['ids'];
            $matchedTotal = $matched['total'];
            if ($data['ids'] === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matching sites to update.',
                ], 422);
            }
        }

        $actor = $request->user();
        if ($data['action'] === 'verify' && ! $actor?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can verify sites.',
            ], 403);
        }
        if ($data['action'] === 'activate' && ! $actor?->canActivateSites()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to activate sites.',
            ], 403);
        }
        if ($data['action'] === 'reject' && ! $actor?->isAdmin() && ! $actor?->isMarketing()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to reject sites.',
            ], 403);
        }
        if ($data['action'] === 'deactivate' && ! $actor?->canActivateSites()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to deactivate sites.',
            ], 403);
        }
        if ($data['action'] === 'archive' && ! $actor?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can archive sites.',
            ], 403);
        }
        if (in_array($data['action'], ['reject', 'deactivate'], true)) {
            $request->validate([
                'reason' => ['required', 'string', 'min:10', 'max:1000'],
            ]);
            $data['reason'] = trim((string) $request->input('reason'));
        }
        if ($data['action'] === 'archive') {
            $needsReason = false;
            foreach (array_values(array_unique(array_map('intval', $data['ids'] ?? []))) as $archiveId) {
                if ($archiveId < 1) {
                    continue;
                }
                $candidate = Site::query()->find($archiveId);
                if ($candidate
                    && $this->staffSiteQualifiesForBulkArchive($candidate)
                    && ! $candidate->canQuietStaffArchive()) {
                    $needsReason = true;
                    break;
                }
            }
            if ($needsReason) {
                $request->validate([
                    'reason' => ['required', 'string', 'min:10', 'max:1000'],
                ]);
            }
            $data['reason'] = trim((string) $request->input('reason', ''));
        }

        $updated = [];
        $skipped = [];
        $warnings = [];

        foreach (array_values(array_unique(array_map('intval', $data['ids']))) as $id) {
            if ($id < 1) {
                continue;
            }

            if ($data['action'] === 'archive') {
                $candidate = Site::query()->find($id);
                if (! $candidate || ! $this->staffSiteQualifiesForBulkArchive($candidate)) {
                    $skipped[] = [
                        'id' => $id,
                        'message' => 'This site cannot be archived from the bulk bar.',
                    ];

                    continue;
                }
            }

            $payload = match ($data['action']) {
                'verify' => ['verified' => 1],
                'activate' => ['active' => 1],
                'reject', 'archive' => ['reason' => $data['reason'] ?? ''],
                'deactivate' => ['active' => 0, 'reason' => $data['reason']],
            };
            $sub = Request::create($request->url(), 'POST', $payload);
            $sub->headers->set('Accept', 'application/json');
            $sub->setUserResolver(static fn () => $actor);

            try {
                $response = match ($data['action']) {
                    'verify' => $this->verify($sub, $id),
                    'activate', 'deactivate' => $this->toggleActive($sub, $id),
                    'reject' => $this->destroy($sub, $id),
                    'archive' => $this->archive($sub, $id),
                };
            } catch (ValidationException $e) {
                $skipped[] = [
                    'id' => $id,
                    'message' => collect($e->errors())->flatten()->first() ?: 'Invalid request.',
                ];

                continue;
            } catch (ModelNotFoundException $e) {
                $skipped[] = ['id' => $id, 'message' => 'Site not found.'];

                continue;
            }

            $status = $response->getStatusCode();
            $body = $response->getData(true);
            if ($status >= 200 && $status < 300 && ! empty($body['success'])) {
                $updated[] = $id;
                $warning = trim((string) ($body['warning'] ?? ''));
                if ($warning !== '' && ! in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
            } else {
                $skipped[] = [
                    'id' => $id,
                    'message' => (string) ($body['message'] ?? 'Skipped'),
                ];
            }
        }

        $message = count($updated).' updated';
        if ($skipped !== []) {
            $reason = trim((string) ($skipped[0]['message'] ?? ''));
            $message .= ', '.count($skipped).' skipped';
            if ($reason !== '') {
                $message .= ': '.$reason;
                if (count($skipped) > 1) {
                    $message .= ' (+'.(count($skipped) - 1).' more)';
                }
            }
        }
        if ($warnings !== []) {
            $message = rtrim($message, ". \t").'. '.$warnings[0];
        }
        if ($matchedTotal !== null && $matchedTotal > count($data['ids'])) {
            $message .= ' First '.count($data['ids']).' of '.$matchedTotal.'.';
        }

        $skipIds = array_values(array_filter(array_map(
            static fn ($row) => (int) ($row['id'] ?? 0),
            $skipped
        )));
        $names = $skipIds === []
            ? collect()
            : Site::query()->whereIn('id', $skipIds)->pluck('site_name', 'id');
        foreach ($skipped as $index => $row) {
            $id = (int) ($row['id'] ?? 0);
            $skipped[$index]['name'] = (string) ($names[$id] ?? ('Site #'.$id));
        }

        return response()->json([
            'success' => $updated !== [],
            'updated' => $updated,
            'skipped' => $skipped,
            'matched_total' => $matchedTotal,
            'processed' => count($data['ids'] ?? []),
            'message' => $message,
        ], $updated !== [] ? 200 : 422);
    }

    /**
     * @return array{ids: list<int>, total: int}
     */
    private function staffBulkMatchIds(Request $request): array
    {
        $limit = 500;
        $scope = trim(scalar_text($request->input('scope')));
        $filters = $this->staffSitesListFilterState($request);
        $search = trim(scalar_text($request->input('q', '')));

        try {
            if ($scope === 'publisher') {
                $publisherId = (int) $request->input('publisher_id');
                if ($publisherId < 1) {
                    return ['ids' => [], 'total' => 0];
                }
                $needsReviewOnly = $this->requestFlag($request, 'needs_review');
                $focusSiteId = $this->canonicalStaffId(trim(scalar_text($request->input('site', ''))));
                $query = Site::query()
                    ->where('publisher_id', $publisherId)
                    ->where(function ($outer) use ($filters, $search, $needsReviewOnly, $focusSiteId) {
                        $outer->where(function ($matched) use ($filters, $search, $needsReviewOnly) {
                            $matched->whereRaw('1 = 1');
                            $this->applyStaffSitesArchiveScope($matched, $filters);
                            if ($search !== '') {
                                $this->constrainStaffSiteSearch($matched, $search);
                            }
                            if ($needsReviewOnly) {
                                $matched->needsAdminReview();
                            }
                            $this->applyStaffSitesListFilters($matched, $filters);
                        });
                        if ($focusSiteId !== null) {
                            $outer->orWhere($outer->getModel()->getTable().'.id', $focusSiteId);
                        }
                    });
                $this->applyStaffSitesListSort($query, (string) ($filters['sort'] ?? ''), 'newest', $focusSiteId);
            } elseif ($this->requestFlag($request, 'waiting_on_publisher')) {
                $stage = ($filters['waiting_stage'] ?? '') !== '' ? $filters['waiting_stage'] : null;
                $query = MarketingOpsQueues::sitesWaitingOnPublisher($stage);
                $this->applyStaffIndexSiteOrPublisherSearch($query, $search);
                $this->applyStaffSitesListFilters($query, $filters);
                $this->applyStaffSitesListSort($query, (string) ($filters['sort'] ?? ''), 'oldest');
            } elseif ($this->requestFlag($request, 'needs_review')) {
                $query = MarketingOpsQueues::sitesReadyForStaff();
                $this->applyStaffIndexSiteOrPublisherSearch($query, $search);
                $this->applyStaffSitesListFilters($query, $filters);
                $this->applyStaffSitesListSort($query, (string) ($filters['sort'] ?? ''), 'oldest');
            } else {
                $query = $this->staffAllSitesQuery($request, $search, $filters);
            }

            $total = (int) (clone $query)->count();
            $ids = $query->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all();

            return ['ids' => $ids, 'total' => $total];
        } catch (\Throwable $e) {
            report($e);

            return ['ids' => [], 'total' => 0];
        }
    }

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

    /**
     * @return array<string, mixed>
     */
    private function staffArchiveLockedOutcome(Request $request, Site $site, $user): array
    {
        $orderCount = $site->orderItemsCount();
        if ($orderCount > 0) {
            return [
                'http' => 422,
                'payload' => [
                    'success' => false,
                    'message' => $orderCount === 1
                        ? 'This site has 1 order and cannot be archived. Deactivate it to hide it from the catalog.'
                        : 'This site has '.$orderCount.' orders and cannot be archived. Deactivate it to hide it from the catalog.',
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

        $quietArchive = $site->canQuietStaffArchive();
        $rejectionReason = $this->validatedStatusReason($request, ! $quietArchive);

        Site::ensureStatusReasonColumns();
        $this->applyStatusReason($site, $rejectionReason);

        if (! $site->archiveByStaff($rejectionReason)) {
            return [
                'http' => 503,
                'payload' => [
                    'success' => false,
                    'message' => 'Archive is not available yet.',
                ],
            ];
        }

        return [
            'action' => 'archived',
            'site' => $site->fresh() ?? $site,
            'siteName' => $site->site_name,
            'siteId' => $site->id,
            'domain' => $site->domain,
            'bulkRequestId' => $site->bulk_site_request_id,
            'wasStaffInvite' => filled($site->assigned_by_user_id),
            'onboarding' => $site->onboarding_status,
            'rejectionReason' => $rejectionReason,
            'quietArchive' => $quietArchive,
            'publisher' => $site->publisher,
        ];
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function finishStaffArchive(array $outcome, $user)
    {
        $site = $outcome['site'];
        $siteName = $outcome['siteName'];
        $siteId = $outcome['siteId'];
        $domain = $outcome['domain'];
        $bulkRequestId = $outcome['bulkRequestId'];
        $onboarding = $outcome['onboarding'];
        $rejectionReason = $outcome['rejectionReason'];
        $publisher = $outcome['publisher'];

        try {
            app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
        } catch (\Throwable $e) {
            Log::warning('Could not complete site review notifications before archive: '.$e->getMessage());
        }

        if (empty($outcome['quietArchive'])) {
            $this->notifyPublisherSiteRemoved($site, $publisher, $rejectionReason, 'archived');
        }

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
                'quiet' => ! empty($outcome['quietArchive']),
            ],
            $siteName
        );

        $this->syncLinkedBulkAfterSiteRemoved($bulkRequestId, (bool) ($outcome['wasStaffInvite'] ?? false));

        return response()->json([
            'success' => true,
            'archived' => true,
            'quiet' => ! empty($outcome['quietArchive']),
            'message' => ! empty($outcome['quietArchive'])
                ? 'Site archived and hidden from the catalog. The publisher was not notified.'
                : 'Site archived and hidden from the catalog.',
        ]);
    }

    // Admin archive — quiet for publisher-added / bulk drafts; reason + mail for staff-assigned.
    public function archive(Request $request, $id)
    {
        $user = auth()->user();
        if (! $user?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can archive sites.',
            ], 403);
        }

        $outcome = DB::transaction(function () use ($request, $id, $user) {
            $site = Site::query()->lockForUpdate()->findOrFail($id);

            return $this->staffArchiveLockedOutcome($request, $site, $user);
        });

        if (isset($outcome['http'])) {
            return response()->json($outcome['payload'], $outcome['http']);
        }

        return $this->finishStaffArchive($outcome, $user);
    }

    public function unarchive(Request $request, $id)
    {
        $user = auth()->user();
        if (! $user?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can restore archived sites.',
            ], 403);
        }

        if (! Site::hasSitesColumn('archived_at')) {
            return response()->json(['success' => false, 'message' => 'Archive is not available yet.'], 503);
        }

        $site = Site::query()->findOrFail($id);

        if (! $site->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Site is not archived.'], 422);
        }

        try {
            if (! $site->unarchiveByStaff()) {
                return response()->json(['success' => false, 'message' => 'Archive is not available yet.'], 503);
            }
            $site->refresh();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not restore this site. Please try again.'),
            ], 500);
        }

        ActivityLogger::tryLog(
            'site.unarchived',
            ($user->name ?? 'Staff').' restored "'.$site->site_name.'" from archive',
            $site,
            [
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'domain' => $site->domain,
                'by' => 'admin',
            ],
            $site->site_name
        );

        $message = 'Site restored. It remains inactive until it is activated again.';
        if ($site->isCatalogVisible()) {
            $message = 'Site restored to the catalog.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    // DELETE — pending never-ordered: hard delete + reason + notify.
    // Live listings (verified or active) still archive if DELETE is used.
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

            $shouldArchive = $isAdmin && ((bool) $site->verified || (bool) $site->active);

            if ($shouldArchive) {
                return $this->staffArchiveLockedOutcome($request, $site, $user) + [
                    'isAdmin' => $isAdmin,
                    'isMarketingPendingDelete' => $isMarketingPendingDelete,
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
                'wasStaffInvite' => filled($site->assigned_by_user_id),
                'onboarding' => $site->onboarding_status,
                'rejectionReason' => $rejectionReason,
                'publisher' => $site->publisher,
                'isAdmin' => $isAdmin,
                'isMarketingPendingDelete' => $isMarketingPendingDelete,
            ];

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
            return $this->finishStaffArchive($outcome, $user);
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
        // Staff-assigned invites are real removals: notify even though the item unlinks.
        if (($outcome['wasStaffInvite'] ?? false) || ! $this->bulkItemWasRepended($bulkRequestId, $domain)) {
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

        $this->syncLinkedBulkAfterSiteRemoved($bulkRequestId, (bool) ($outcome['wasStaffInvite'] ?? false));

        return response()->json([
            'success' => true,
            'archived' => false,
            'message' => 'Site deleted successfully',
        ]);
    }

    private function syncLinkedBulkAfterSiteRemoved(?int $bulkRequestId, bool $wasStaffInvite = false): void
    {
        if (! $bulkRequestId) {
            return;
        }

        $bulk = BulkSiteRequest::query()->find($bulkRequestId);
        if (! $bulk) {
            return;
        }

        if ($wasStaffInvite) {
            $bulk->forgetUnlinkedStaffInviteItems();
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
