@extends(staff_layout())

@section('content')
@php
    $publisherSearch = trim((string) ($publisherSearch ?? ''));
    $publisherSearchQuery = array_filter(['q' => $publisherSearch !== '' ? $publisherSearch : null]);
    $flatQueue = $flatQueue ?? false;
    $allSitesMode = $allSitesMode ?? false;
    $staffSiteFilters = $staffSiteFilters ?? [];
    $listQuery = array_filter([
        'q' => $publisherSearch !== '' ? $publisherSearch : null,
        'tag' => ($staffSiteFilters['tag'] ?? null) !== null && ($staffSiteFilters['tag'] ?? '') !== '' ? $staffSiteFilters['tag'] : null,
        'country' => ($staffSiteFilters['country'] ?? '') !== '' ? $staffSiteFilters['country'] : null,
        'listing_active' => ($staffSiteFilters['listing_active'] ?? '') !== '' ? $staffSiteFilters['listing_active'] : null,
        'listing_verified' => ($staffSiteFilters['listing_verified'] ?? '') !== '' ? $staffSiteFilters['listing_verified'] : null,
        'below_quality' => !empty($staffSiteFilters['below_quality']) ? 1 : null,
        'ready_to_activate' => !empty($staffSiteFilters['ready_to_activate']) ? 1 : null,
        'missing_market' => !empty($staffSiteFilters['missing_market']) ? 1 : null,
        'placeholder' => !empty($staffSiteFilters['placeholder']) ? 1 : null,
        'missing_cover' => !empty($staffSiteFilters['missing_cover']) ? 1 : null,
        'bulk_request' => !empty($staffSiteFilters['bulk_request']) ? 1 : null,
        'language' => ($staffSiteFilters['language'] ?? '') !== '' ? $staffSiteFilters['language'] : null,
        'niche' => ($staffSiteFilters['niche'] ?? '') !== '' ? $staffSiteFilters['niche'] : null,
        'archived' => !empty($staffSiteFilters['archived']) ? 1 : null,
        'sort' => ($staffSiteFilters['sort'] ?? '') !== '' ? $staffSiteFilters['sort'] : null,
    ], static fn ($value) => $value !== null && $value !== '');
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-0 fw-bold">Sites Management</h4>
            @if(($openReviewCount ?? 0) > 0)
                <small class="text-muted">
                    <span class="badge text-bg-warning">{{ $openReviewCount }}</span>
                    site{{ $openReviewCount === 1 ? '' : 's' }} need{{ $openReviewCount === 1 ? 's' : '' }} review
                </small>
            @endif
            @if(($missingMarketListCount ?? 0) > 0)
                <small class="text-muted d-block mt-1">
                    <a href="{{ staff_route('sites.index', ['all' => 1, 'listing_active' => 1, 'missing_market' => 1]) }}" class="link-secondary">
                        <span class="badge text-bg-danger">{{ $missingMarketListCount }}</span>
                        active site{{ $missingMarketListCount === 1 ? '' : 's' }} missing market country
                    </a>
                </small>
            @endif
            @php
                $liveUnverifiedUrl = staff_route('sites.index', ['all' => 1, 'listing_active' => 1, 'listing_verified' => 0]);
                $healthLinks = [
                    'below_quality' => [
                        'label' => 'below quality bar',
                        'count' => (int) ($belowQualityListCount ?? 0),
                        'url' => staff_route('sites.index', ['all' => 1, 'below_quality' => 1]),
                    ],
                    'unverified' => [
                        'label' => 'unverified active',
                        'count' => (int) ($liveUnverifiedCount ?? 0),
                        'url' => $liveUnverifiedUrl,
                    ],
                    'placeholder' => [
                        'label' => 'placeholder',
                        'count' => (int) ($placeholderListCount ?? 0),
                        'url' => staff_route('sites.index', ['all' => 1, 'placeholder' => 1]),
                    ],
                    'missing_cover' => [
                        'label' => 'missing cover',
                        'count' => (int) ($missingCoverListCount ?? 0),
                        'url' => staff_route('sites.index', ['all' => 1, 'missing_cover' => 1]),
                    ],
                ];
                $healthPreview = collect($healthLinks)->filter(fn ($row) => $row['count'] > 0);
            @endphp
            @if($healthPreview->isNotEmpty())
                <small class="text-muted d-block mt-1">
                    Catalog health:
                    @foreach($healthPreview as $healthRow)
                        <a href="{{ $healthRow['url'] }}" class="link-secondary">
                            <span class="badge text-bg-warning">{{ $healthRow['count'] }}</span>
                            {{ $healthRow['label'] }}
                        </a>@if(! $loop->last), @endif
                    @endforeach
                </small>
            @endif
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if(!empty($needsReviewFilterActive))
                <a href="{{ staff_route('sites.index', $publisherSearchQuery) }}" class="btn btn-sm btn-outline-dark">
                    Show all publishers
                </a>
                @if(!empty($flatQueue))
                    <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1] + $listQuery)) }}" class="btn btn-sm btn-outline-secondary">
                        By publisher
                    </a>
                @else
                    <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1, 'flat' => 1] + $listQuery)) }}" class="btn btn-sm btn-outline-warning">
                        Site queue
                    </a>
                @endif
            @elseif(!empty($waitingOnPublisherFilterActive))
                <a href="{{ staff_route('sites.index', $publisherSearchQuery) }}" class="btn btn-sm btn-outline-dark">
                    Show all publishers
                </a>
                @php
                    $waitingLayoutQuery = array_filter([
                        'waiting_on_publisher' => 1,
                        'waiting_stage' => ($waitingStage ?? '') !== '' ? $waitingStage : null,
                    ] + $listQuery);
                @endphp
                @if(!empty($flatQueue))
                    <a href="{{ staff_route('sites.index', $waitingLayoutQuery) }}" class="btn btn-sm btn-outline-secondary">
                        By publisher
                    </a>
                @else
                    <a href="{{ staff_route('sites.index', array_filter(['flat' => 1] + $waitingLayoutQuery)) }}" class="btn btn-sm btn-outline-secondary">
                        Site queue
                    </a>
                @endif
            @else
                <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1] + $publisherSearchQuery)) }}" class="btn btn-sm btn-outline-warning">
                    <i class="fa fa-bell me-1"></i> Needs review
                    @if(($openReviewCount ?? 0) > 0)
                        <span class="badge text-bg-dark ms-1">{{ $openReviewCount }}</span>
                    @endif
                </a>
                <a href="{{ staff_route('sites.index', array_filter(['needs_review' => 1, 'flat' => 1] + $publisherSearchQuery)) }}" class="btn btn-sm btn-outline-warning">
                    Site queue
                </a>
                <a href="{{ staff_route('sites.index', array_filter(['waiting_on_publisher' => 1, 'flat' => 1] + $publisherSearchQuery)) }}" class="btn btn-sm btn-outline-secondary">
                    Waiting on publisher
                    @if(($waitingOnPublisherCount ?? 0) > 0)
                        <span class="badge text-bg-dark ms-1">{{ $waitingOnPublisherCount }}</span>
                    @endif
                </a>
                @php
                    $queueFiltersClear = $publisherSearch === ''
                        && ($staffSiteFilters['country'] ?? '') === ''
                        && ($staffSiteFilters['language'] ?? '') === ''
                        && ($staffSiteFilters['niche'] ?? '') === ''
                        && ($staffSiteFilters['tag'] ?? '') === ''
                        && empty($staffSiteFilters['below_quality'])
                        && empty($staffSiteFilters['missing_market'])
                        && empty($staffSiteFilters['placeholder'])
                        && empty($staffSiteFilters['missing_cover'])
                        && empty($staffSiteFilters['bulk_request'])
                        && empty($staffSiteFilters['archived']);
                    $onLiveUnverified = !empty($allSitesMode)
                        && ($staffSiteFilters['listing_active'] ?? '') === '1'
                        && ($staffSiteFilters['listing_verified'] ?? '') === '0'
                        && $queueFiltersClear
                        && empty($staffSiteFilters['ready_to_activate']);
                    $onReadyToActivate = !empty($allSitesMode)
                        && !empty($staffSiteFilters['ready_to_activate'])
                        && $queueFiltersClear
                        && ($staffSiteFilters['listing_active'] ?? '') === ''
                        && ($staffSiteFilters['listing_verified'] ?? '') === '';
                @endphp
                <a href="{{ staff_route('sites.index', ['all' => 1, 'ready_to_activate' => 1]) }}" class="btn btn-sm {{ $onReadyToActivate ? 'btn-success' : 'btn-outline-success' }}">
                    Ready to activate
                    @if(($readyToActivateCount ?? 0) > 0)
                        <span class="badge text-bg-dark ms-1">{{ $readyToActivateCount }}</span>
                    @endif
                </a>
                <a href="{{ $liveUnverifiedUrl }}" class="btn btn-sm {{ $onLiveUnverified ? 'btn-secondary' : 'btn-outline-secondary' }}">
                    Live unverified
                    @if(($liveUnverifiedCount ?? 0) > 0)
                        <span class="badge text-bg-dark ms-1">{{ $liveUnverifiedCount }}</span>
                    @endif
                </a>
                @if(!empty($allSitesMode))
                    <a href="{{ staff_route('sites.index', $listQuery) }}" class="btn btn-sm btn-outline-dark">Publishers</a>
                @else
                    <a href="{{ staff_route('sites.index', array_filter(['all' => 1] + $listQuery)) }}" class="btn btn-sm btn-outline-dark">All sites</a>
                @endif
            @endif
            @if(auth()->user()?->isAdmin())
                <a href="{{ route('admin.sites.records', array_filter(['missing_market' => ($missingMarketCount ?? 0) > 0 ? 1 : null])) }}"
                   class="btn btn-sm {{ ($missingMarketCount ?? 0) > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' }}">
                    <i class="fa fa-table me-1"></i> Websites records sheet
                    @if(($missingMarketCount ?? 0) > 0)
                        <span class="badge text-bg-danger ms-1">{{ $missingMarketCount }} missing</span>
                    @endif
                </a>
                <a href="{{ staff_route('site-enrichment.index') }}" class="btn btn-sm btn-outline-primary">
                    Enrichment &amp; scan failures
                </a>
            @endif
            <a href="{{ staff_route('sites.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> Add site for publisher
            </a>
            @if(auth()->user()?->isAdmin())
                <a href="{{ route('admin.sites.on-demand.index') }}" class="btn btn-sm btn-outline-primary">
                    On-demand
                </a>
            @endif
        </div>
    </div>

    @if(!empty($waitingOnPublisherFilterActive))
        <div class="alert alert-secondary border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Waiting on publisher</strong>
                <span class="ms-1">
                    @if(!empty($flatQueue))
                        Listings still with the publisher (details or accept). Not staff work yet.
                    @else
                        Publishers with listings still filling details or waiting to accept.
                    @endif
                </span>
                @php
                    $stageCounts = $waitingStageCounts ?? ['filling' => 0, 'reviewing' => 0, 'accept' => 0];
                    $stageLinks = [
                        '' => ['All', (int) ($waitingOnPublisherCount ?? 0)],
                        'filling' => ['Filling', (int) ($stageCounts['filling'] ?? 0)],
                        'reviewing' => ['Reviewing', (int) ($stageCounts['reviewing'] ?? 0)],
                        'accept' => ['Accepting', (int) ($stageCounts['accept'] ?? 0)],
                    ];
                @endphp
                <span class="d-flex flex-wrap gap-2 ms-2">
                    @foreach($stageLinks as $stageKey => $stageRow)
                        @php
                            $stageTotal = (int) $stageRow[1];
                            $stageUnit = empty($flatQueue)
                                ? ($stageTotal === 1 ? ' site' : ' sites')
                                : '';
                        @endphp
                        <a href="{{ staff_route('sites.index', array_filter(['waiting_on_publisher' => 1, 'flat' => !empty($flatQueue) ? 1 : null, 'waiting_stage' => $stageKey !== '' ? $stageKey : null] + $listQuery)) }}"
                           class="small {{ ($waitingStage ?? '') === $stageKey ? 'fw-semibold' : '' }}">
                            {{ $stageRow[0] }} (<span data-waiting-stage-total="{{ $stageKey !== '' ? $stageKey : 'all' }}">{{ number_format($stageTotal) }}</span>{{ $stageUnit }})
                        </a>
                    @endforeach
                </span>
            </div>
            <a href="{{ staff_route('sites.index', $publisherSearchQuery) }}" class="btn btn-sm btn-outline-dark">Show all publishers</a>
        </div>
    @endif

    @if(!empty($needsReviewFilterActive) || !empty($unverifiedFilter))
        <div class="alert alert-warning border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Needs review queue</strong>
                <span class="ms-1">
                    @if(!empty($flatQueue))
                        @if(auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin())
                            Flat list of sites waiting for Activate or delete (pending only). Admin verifies.
                        @else
                            Flat list of sites waiting for Verify, Activate, Reject, or Delete.
                        @endif
                    @elseif(auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin())
                        Publishers with new/ready sites waiting for Activate or delete (pending only). Admin verifies.
                    @else
                        Publishers with new/ready sites waiting for Verify, Activate, Reject, or Delete. Reminders stay until you decide.
                    @endif
                </span>
            </div>
            <a href="{{ staff_route('sites.index', $publisherSearchQuery) }}" class="btn btn-sm btn-outline-dark">Show all publishers</a>
        </div>
    @endif

    <div id="staffIndexSearchWrap">
        <form method="GET" action="{{ staff_route('sites.index') }}" class="mb-2" style="max-width: 320px;" role="search">
            @if(!empty($needsReviewFilterActive) || !empty($unverifiedFilter))
                <input type="hidden" name="needs_review" value="1">
            @endif
            @if(!empty($waitingOnPublisherFilterActive))
                <input type="hidden" name="waiting_on_publisher" value="1">
            @endif
            @if(!empty($flatQueue))
                <input type="hidden" name="flat" value="1">
            @endif
            @if(($waitingStage ?? '') !== '')
                <input type="hidden" name="waiting_stage" value="{{ $waitingStage }}">
            @endif
            @if(!empty($allSitesMode))
                <input type="hidden" name="all" value="1">
            @endif
            @foreach($listQuery as $filterKey => $filterValue)
                @if($filterKey !== 'q')
                    <input type="hidden" name="{{ $filterKey }}" value="{{ $filterValue }}">
                @endif
            @endforeach
            <x-slb-search-field
                name="q"
                id="userSearch"
                :value="$publisherSearch"
                placeholder="Search publishers or sites…"
                label="Search publishers or sites"
                label-class="visually-hidden"
            />
        </form>
    </div>

    @if(!empty($flatQueue) && $flatQueueSites)
    <div class="card shadow-sm border-0 mb-3 admin-table-fit" data-flat-queue="1">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>{{ !empty($waitingOnPublisherFilterActive) ? 'Waiting on publisher' : 'Sites needing review' }}</span>
            <span class="small text-muted" data-flat-queue-count>{{ $flatQueueSites->total() }} in queue</span>
        </div>
        @include('admin.sites.partials.list-filters', ['mode' => 'flat'])
        <div class="px-3 py-2 border-bottom d-flex flex-wrap gap-2 align-items-center" data-staff-bulk-bar="flat">
            @if(auth()->user()?->isAdmin())
                <button type="button" class="btn btn-sm btn-outline-success" data-staff-bulk="verify">Verify</button>
            @endif
            @if(auth()->user()?->canActivateSites())
                <button type="button" class="btn btn-sm btn-outline-primary" data-staff-bulk="activate">Activate</button>
            @endif
            <button type="button" class="btn btn-sm btn-outline-danger" data-staff-bulk="reject">Reject</button>
            @if(auth()->user()?->canActivateSites())
                <button type="button" class="btn btn-sm btn-outline-secondary" data-staff-bulk="deactivate">Deactivate</button>
            @endif
            @if(auth()->user()?->isAdmin())
                <button type="button" class="btn btn-sm btn-outline-dark" data-staff-bulk="archive">Archive</button>
            @endif
            <span class="small text-muted" data-staff-bulk-count>0 selected</span>
            <label class="form-check small mb-0">
                <input class="form-check-input" type="checkbox" data-staff-bulk-all-matching>
                Apply to all <span data-staff-bulk-match-total>{{ $flatQueueSites->total() }}</span> filtered sites
            </label>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="admin-num-col"><input type="checkbox" data-staff-bulk-all="flat" aria-label="Select all sites on this page"></th>
                        <th class="admin-num-col">#</th>
                        <th>Site</th>
                        <th>Publisher</th>
                        <th class="admin-narrow-col">DA / DR</th>
                        <th>Markets</th>
                        <th class="admin-narrow-col">Tag</th>
                        <th class="admin-narrow-col">Traffic</th>
                        <th class="admin-narrow-col">Buyer price</th>
                        <th class="admin-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($flatQueueSites as $index => $site)
                    @php
                        $openUrl = staff_route('sites.index', array_filter([
                            'publisher' => $site->publisher_id,
                            'site' => $site->id,
                        ]));
                        $isMarketingEditor = (bool) (auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin());
                        $hasOrders = $site->orderItemsCount() > 0;
                        $canDeleteFlat = ! $site->isArchived()
                            && ! $hasOrders
                            && ! $site->verified
                            && ! $site->active
                            && (auth()->user()?->isAdmin() || $isMarketingEditor);
                        $canArchiveFlat = (bool) auth()->user()?->isAdmin()
                            && ! $site->isArchived()
                            && ! $hasOrders
                            && ($site->verified || $site->active);
                    @endphp
                    <tr data-flat-site-row="{{ $site->id }}">
                        <td><input type="checkbox" data-staff-bulk-id="{{ $site->id }}" data-verified="{{ $site->verified ? '1' : '0' }}" data-active="{{ $site->active ? '1' : '0' }}" data-can-activate="{{ $site->staffGoLiveBlockReason((bool) (auth()->user()?->isMarketing() && ! auth()->user()?->isAdmin())) === null ? '1' : '0' }}" aria-label="Select {{ $site->site_name ?: $site->domain }}"></td>
                        <td>{{ $flatQueueSites->firstItem() + $index }}</td>
                        <td>
                            <div class="fw-semibold">{{ $site->site_name ?: '—' }}</div>
                            <div class="small text-muted text-break">{{ $site->site_url }}</div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @if($site->verified)
                                    <span class="badge rounded-pill bg-success">Verified</span>
                                @else
                                    <span class="badge rounded-pill bg-secondary">Unverified</span>
                                @endif
                                @if(! $site->hasMarketplaceCountry())
                                    <span class="badge text-bg-danger">Missing market</span>
                                @endif
                                @if(! $site->hasGoodMetrics())
                                    <span class="badge text-bg-warning text-dark">{{ $site->qualityBarBadgeText() }}</span>
                                @endif
                                @if(! $site->hasCatalogCover())
                                    <span class="badge text-bg-warning text-dark">No cover</span>
                                @endif
                                @if($site->awaitsPublisherDetails())
                                    <span class="badge text-bg-secondary">Awaiting publisher</span>
                                @endif
                                @if($site->hasDetailsComplete())
                                    <span class="badge text-bg-secondary">Publisher reviewing</span>
                                @endif
                                @if($site->isPendingPublisherAcceptance())
                                    <span class="badge text-bg-info">Awaiting accept</span>
                                @endif
                                @if($site->wasAddedFromBulkRequest())
                                    <span class="badge text-bg-light border">Bulk request</span>
                                @endif
                            </div>
                        </td>
                        <td class="small">
                            <div>{{ $site->publisher?->name ?? 'Unknown' }}</div>
                            <div class="text-muted">{{ $site->publisher?->email }}</div>
                            @if($site->publisher?->inCatalogHideMode())
                                <span class="badge text-bg-dark">Copy-strike hide</span>
                            @endif
                        </td>
                        <td class="small">{{ $site->da ?? '—' }} / {{ $site->dr ?? '—' }}</td>
                        <td class="small">@include('admin.sites.partials.row-markets')</td>
                        <td class="small">
                            @if($site->tagValue() === null)
                                <span class="badge text-bg-warning text-dark">No tags</span>
                            @else
                                {{ $site->tagLabel() }}
                            @endif
                        </td>
                        <td>{{ number_format((int) $site->traffic) }}</td>
                        <td>@include('admin.sites.partials.row-price')</td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="{{ $openUrl }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                <a href="{{ staff_route('sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary">{{ $isMarketingEditor && $site->isLockedForMarketingEdits() && ! $site->marketingCanEditDescription() ? 'View' : 'Edit' }}</a>
                                @if(empty($waitingOnPublisherFilterActive))
                                    @if(auth()->user()?->isAdmin() && ! $site->verified)
                                        <button type="button"
                                        class="btn btn-sm btn-outline-success toggle-verify"
                                        data-id="{{ $site->id }}"
                                        data-status="1"
                                        data-name="{{ $site->site_name }}"
                                        @if($site->hasDetailsComplete()) data-publisher-reviewing="1" @endif>Verify</button>
                                    @endif
                                    @include('partials.staff-site-activate-button', ['site' => $site])
                                    @if($canDeleteFlat)
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger delete-site"
                                                data-id="{{ $site->id }}"
                                                data-name="{{ $site->site_name }}">Reject</button>
                                    @elseif($canArchiveFlat)
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger delete-site"
                                                data-id="{{ $site->id }}"
                                                data-name="{{ $site->site_name }}"
                                                data-archive="1">Archive</button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">{{ !empty($waitingOnPublisherFilterActive) ? 'No listings waiting on a publisher.' : 'No sites in the review queue.' }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-2">
            {{ $flatQueueSites->links() }}
        </div>
    </div>
    @endif

    @include('admin.sites.partials.all-sites-table')

    <!-- ================= USERS TABLE ================= -->
    <div id="usersSection" class="{{ (!empty($flatQueue) || !empty($allSitesMode)) ? 'd-none' : '' }}">

        <div class="card shadow-sm border-0 mb-3 admin-table-fit">
            <div class="card-header bg-white fw-semibold">
                {{ !empty($waitingOnPublisherFilterActive)
                    ? 'Publishers with listings waiting on the publisher'
                    : (!empty($needsReviewFilterActive) || !empty($unverifiedFilter) ? 'Publishers with sites needing review' : 'Publishers') }}
            </div>
            @include('admin.sites.partials.list-filters', ['mode' => 'publishers'])

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col">#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th class="admin-sites-count-col">Sites</th>
                            <th class="admin-actions-col">Action</th>
                        </tr>
                    </thead>

                    <tbody id="usersTable">
                    @forelse($users as $index => $user)
                        <tr class="user-row" data-id="{{ $user->id }}" style="height:60px;">
                            <td>{{ $users->firstItem() + $index }}</td>
                            <td class="fw-semibold" data-publisher-name="{{ $user->name }}">
                                {{ $user->name }}
                                @if($user->inCatalogHideMode())
                                    <span class="badge text-bg-dark ms-1">Copy-strike hide</span>
                                @endif
                            </td>
                            <td class="slb-text-break">{{ $user->email }}</td>
                            <td class="admin-sites-count-col">
                                @php
                                    $needsReviewCount = (int) ($user->needs_review_sites_count
                                        ?? $user->unverified_sites_count
                                        ?? 0);
                                    $waitingFilling = (int) ($user->waiting_filling_sites_count ?? 0);
                                    $waitingReviewing = (int) ($user->waiting_reviewing_sites_count ?? 0);
                                    $waitingAccept = (int) ($user->waiting_accept_sites_count ?? 0);
                                    $totalSitesCount = (int) ($user->sites_count ?? 0);
                                    $filtersNarrow = ($staffSiteFilters['tag'] ?? '') !== ''
                                        || ($staffSiteFilters['country'] ?? '') !== ''
                                        || ($staffSiteFilters['language'] ?? '') !== ''
                                        || ($staffSiteFilters['niche'] ?? '') !== ''
                                        || ($staffSiteFilters['listing_active'] ?? '') !== ''
                                        || ($staffSiteFilters['listing_verified'] ?? '') !== ''
                                        || !empty($staffSiteFilters['below_quality'])
                                        || !empty($staffSiteFilters['missing_market'])
                                        || !empty($staffSiteFilters['placeholder'])
                                        || !empty($staffSiteFilters['missing_cover'])
                                        || !empty($staffSiteFilters['bulk_request']);
                                    $matchedCount = (int) ($user->matched_sites_count ?? 0);
                                @endphp
                                <div class="admin-sites-count-badges">
                                    @if($needsReviewCount > 0)
                                        <span class="badge rounded-pill text-bg-warning" data-review-count="1" title="Sites waiting for admin decision">
                                            {{ number_format($needsReviewCount) }} new
                                        </span>
                                    @endif
                                    @if($waitingFilling > 0)
                                        <span class="badge rounded-pill text-bg-secondary" data-waiting-stage="filling" title="Publisher is still filling details">
                                            {{ number_format($waitingFilling) }} filling
                                        </span>
                                    @endif
                                    @if($waitingReviewing > 0)
                                        <span class="badge rounded-pill text-bg-secondary" data-waiting-stage="reviewing" title="Publisher is reviewing before submit">
                                            {{ number_format($waitingReviewing) }} reviewing
                                        </span>
                                    @endif
                                    @if($waitingAccept > 0)
                                        <span class="badge rounded-pill text-bg-secondary" data-waiting-stage="accept" title="Old accept invite">
                                            {{ number_format($waitingAccept) }} accepting
                                        </span>
                                    @endif
                                    <span class="badge rounded-pill bg-secondary" title="Total sites: {{ number_format($totalSitesCount) }}">
                                        {{ number_format($totalSitesCount) }} total
                                    </span>
                                    @if(($publisherSearch !== '' || $filtersNarrow) && ($publisherSearch === '' || $matchedCount > 0 || $filtersNarrow))
                                        <button type="button"
                                                class="badge rounded-pill text-bg-primary border-0 select-user"
                                                data-id="{{ $user->id }}"
                                                data-site-q="{{ $publisherSearch }}"
                                                title="Open this publisher with the site search filled">
                                            {{ number_format($matchedCount) }} matched
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary select-user"
                                        data-id="{{ $user->id }}">
                                    View Sites
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">No publishers found</td>
                        </tr>
                    @endforelse
                    </tbody>

                </table>
            </div>

            <div class="p-2">
                {{ $users->links() }}
            </div>

        </div>
    </div>

    <!-- ================= SITES FULL VIEW ================= -->
    <div id="sitesSection" class="d-none">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0 fw-bold" id="siteUserName"></h5>
                <span id="siteUserCopyStrike" class="badge text-bg-dark ms-2 d-none">Copy-strike hide</span>
                <small class="text-muted" id="siteUserEmail"></small>
                <small class="text-muted d-block" id="siteUserSummary"></small>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="#" class="btn btn-sm btn-primary d-none" id="addSiteForPublisherBtn">
                    <i class="fa fa-plus me-1"></i> Add site
                </a>
                <button class="btn btn-sm btn-outline-secondary" id="backBtn">
                    ← Back
                </button>
            </div>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div style="max-width: 250px;">
                <x-slb-search-field name="site_search" id="siteSearch" placeholder="Search sites…" mode="" />
            </div>
            <div class="form-check form-check-inline m-0">
                {{-- Default OFF: needs_review=1 filters the publishers list only.
                     Pre-checking this hid activated/verified sites after Approve/Activate
                     (and again on refresh / sidebar re-entry). Staff can still toggle it. --}}
                <input class="form-check-input" type="checkbox" id="sitesNeedsReviewOnly">
                <label class="form-check-label small" for="sitesNeedsReviewOnly">Needs review only</label>
            </div>
            @include('admin.sites.partials.list-filters', ['mode' => 'publisher'])
        </div>

        <div class="card shadow-sm border-0 admin-table-fit">
            <div class="px-3 py-2 border-bottom d-flex flex-wrap gap-2 align-items-center" data-staff-bulk-bar="publisher">
                @if(auth()->user()?->isAdmin())
                    <button type="button" class="btn btn-sm btn-outline-success" data-staff-bulk="verify">Verify</button>
                @endif
                @if(auth()->user()?->canActivateSites())
                    <button type="button" class="btn btn-sm btn-outline-primary" data-staff-bulk="activate">Activate</button>
                @endif
                <button type="button" class="btn btn-sm btn-outline-danger" data-staff-bulk="reject">Reject</button>
                @if(auth()->user()?->canActivateSites())
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-staff-bulk="deactivate">Deactivate</button>
                @endif
                @if(auth()->user()?->isAdmin())
                    <button type="button" class="btn btn-sm btn-outline-dark" data-staff-bulk="archive">Archive</button>
                @endif
                <span class="small text-muted" data-staff-bulk-count>0 selected</span>
                <label class="form-check small mb-0">
                    <input class="form-check-input" type="checkbox" data-staff-bulk-all-matching>
                    Apply to all <span data-staff-bulk-match-total>0</span> filtered sites
                </label>
            </div>

            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">

                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col"><input type="checkbox" data-staff-bulk-all="publisher" aria-label="Select all sites on this page"></th>
                            <th class="admin-num-col">#</th>
                            <th>Site Information</th>
                            <th class="admin-narrow-col">Traffic</th>
                            <th class="admin-narrow-col">Buyer price</th>
                            <th class="admin-status-col">Status</th>
                            <th class="admin-actions-col">Actions</th>
                        </tr>
                    </thead>

                    <tbody id="sitesTable"></tbody>

                 </table>
            </div>
            <div class="p-2 d-flex flex-wrap justify-content-between align-items-center gap-2" id="sitesPager"></div>

        </div>

    </div>

</div>



<script src="{{ same_origin_asset('assets/js/site-image-upload.js') }}?v={{ @filemtime(public_path('assets/js/site-image-upload.js')) ?: '1' }}"></script>
<script>
const STAFF_BASE = @json(staff_base_path());
const SITE_IMAGE_MAX_KB = {{ (int) \App\Support\SiteImageUpload::maxKilobytes() }};
const SITE_IMAGE_PHP_MAX_KB = {{ (int) \App\Support\SiteImageUpload::phpUploadMaxKilobytes() }};
const CSRF_TOKEN = @json(csrf_token());
const CAN_DELETE_ANY_SITE = @json(auth()->user()->isAdmin());
const CAN_DELETE_PENDING_SITES = @json(auth()->user()->isAdmin() || auth()->user()->isMarketing());
const CAN_VERIFY_SITES = @json(auth()->user()->isAdmin());
const CAN_TOGGLE_ACTIVE = @json(auth()->user()->canActivateSites());
const IS_MARKETING_EDITOR = @json(auth()->user()->isMarketing() && ! auth()->user()->isAdmin());
const FLAT_QUEUE = @json(! empty($flatQueue));
const ALL_SITES = @json(! empty($allSitesMode));
const QUALITY_MIN_DA = {{ (int) \App\Models\Site::GOOD_MIN_DA }};
const QUALITY_MIN_DR = {{ (int) \App\Models\Site::GOOD_MIN_DR }};
const QUALITY_MIN_TRAFFIC = {{ (int) \App\Models\Site::GOOD_MIN_TRAFFIC }};
let allSites = [];
let pendingHighlightSiteId = null;

function siteIsVerified(site) {
    return Number(site?.verified) === 1 || site?.verified === true;
}

function siteIsActive(site) {
    return Number(site?.active) === 1 || site?.active === true;
}

function siteHasOrders(site) {
    return (Number(site?.orders_count) || 0) > 0;
}

function canDeleteSiteRow(site) {
    if (site?.archived) return false;
    if (siteHasOrders(site)) return false;
    if (siteIsVerified(site) || siteIsActive(site)) return false;
    if (CAN_DELETE_ANY_SITE) return true;
    if (!CAN_DELETE_PENDING_SITES) return false;
    return true;
}

function canArchiveSiteRow(site) {
    if (!CAN_DELETE_ANY_SITE) return false;
    if (site?.archived) return false;
    if (siteHasOrders(site)) return false;
    return siteIsVerified(site) || siteIsActive(site);
}

/* ================= TOAST ================= */
function toast(msg, icon = 'success') {
    // Prefer the shared app toast — a SweetAlert toast right after the Edit Site
    // dialog closes can leave a brief black backdrop / "error" flash.
    const type = (icon === 'error' || icon === 'danger')
        ? 'error'
        : (icon === 'info' ? 'info' : (icon === 'warning' ? 'warning' : 'success'));

    showAppToast(String(msg || ''), type);
}

function releaseSwalBodyLock() {
    document.body.classList.remove('swal2-shown', 'swal2-height-auto');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    // Drop a leftover non-toast container that can leave a dark overlay flash.
    document.querySelectorAll('body > .swal2-container').forEach((el) => {
        if (el.querySelector('.swal2-toast')) return;
        if (el.classList.contains('swal2-backdrop-show') || !el.querySelector('.swal2-popup')) {
            el.remove();
        }
    });
}

/* ================= LOAD SITES ================= */
let sitesFetchSeq = 0;

function resetPublisherSiteFilters() {
    document.querySelectorAll('#staffPublisherFilters [data-staff-filter]').forEach(function (el) {
        if (el.type === 'checkbox') {
            el.checked = false;
            return;
        }
        el.value = '';
    });
}

function fetchUserSites(id, page){
    const userRow = document.querySelector(`.user-row[data-id="${id}"]`);
    const addBtn = document.getElementById('addSiteForPublisherBtn');

    document.getElementById('usersSection').classList.add('d-none');
    document.getElementById('sitesSection').classList.remove('d-none');
    document.getElementById('staffIndexSearchWrap')?.classList.add('d-none');

    if (userRow) {
        const nameCell = userRow.children[1];
        const publisherName = (nameCell?.getAttribute('data-publisher-name') || '').trim() || 'Publisher';
        document.getElementById('siteUserName').innerText = publisherName + " websites";
        document.getElementById('siteUserEmail').innerText =
            userRow.children[2].innerText;
        document.getElementById('siteUserCopyStrike')?.classList.toggle('d-none', !nameCell?.querySelector('.badge'));
    } else {
        document.getElementById('siteUserName').innerText = 'Publisher websites';
        document.getElementById('siteUserEmail').innerText = '';
        document.getElementById('siteUserCopyStrike')?.classList.add('d-none');
    }
    const summaryEl = document.getElementById('siteUserSummary');
    if (summaryEl) summaryEl.textContent = '';

    if (addBtn) {
        addBtn.href = `${STAFF_BASE}/sites/create?publisher=${encodeURIComponent(id)}`;
        addBtn.classList.remove('d-none');
    }

    document.getElementById('sitesTable').innerHTML =
        `<tr><td colspan="7">Loading...</td></tr>`;

    const pageNum = Number(page) > 1 ? Number(page) : 1;
    const params = new URLSearchParams();
    params.set('_', String(Date.now()));
    if (pageNum > 1) {
        params.set('page', String(pageNum));
    }
    const siteQ = (document.getElementById('siteSearch')?.value || '').trim();
    if (siteQ !== '') {
        params.set('q', siteQ);
    }
    if (document.getElementById('sitesNeedsReviewOnly')?.checked) {
        params.set('needs_review', '1');
    }
    const pageQuery = new URLSearchParams(window.location.search);
    const focusSite = pageQuery.get('site') || '';
    const indexQ = (pageQuery.get('q') || '').trim();
    // Keep the opened site on the list while the box still has that search.
    // A new search must not drag the old site back in.
    if (/^[1-9]\d*$/.test(focusSite) && (siteQ === '' || siteQ === indexQ)) {
        params.set('site', focusSite);
    }
    document.querySelectorAll('#staffPublisherFilters [data-staff-filter]').forEach(function (el) {
        if (el.type === 'checkbox') {
            if (el.checked) params.set(el.getAttribute('data-staff-filter'), '1');
            return;
        }
        const value = (el.value || '').trim();
        if (value !== '') params.set(el.getAttribute('data-staff-filter'), value);
    });
    const sitesUrl = `${STAFF_BASE}/users/${id}/sites?${params.toString()}`;
    const fetchSeq = ++sitesFetchSeq;

    return fetch(sitesUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
        .then(async (res) => {
            const contentType = res.headers.get('content-type') || '';
            const isJson = contentType.includes('application/json');

            // Stale sessionStorage publisher ids (or deleted users) 404 here and
            // used to toast on every Sites Management visit. Clear and go back.
            if (res.status === 404) {
                if (fetchSeq !== sitesFetchSeq) {
                    throw new Error('Publisher not found');
                }
                sessionStorage.removeItem('selected_user');
                document.getElementById('sitesSection').classList.add('d-none');
                document.getElementById('usersSection').classList.remove('d-none');
                document.getElementById('staffIndexSearchWrap')?.classList.remove('d-none');
                document.getElementById('sitesTable').innerHTML = '';
                throw new Error('Publisher not found');
            }

            if (!res.ok) {
                let message = 'Failed to load sites';
                if (isJson) {
                    try {
                        const errBody = await res.json();
                        if (errBody?.message) message = errBody.message;
                    } catch (e) { /* keep default */ }
                }
                throw new Error(message);
            }

            if (!isJson) {
                throw new Error('Failed to load sites');
            }

            return res.json();
        })
        .then(data => {
            if (fetchSeq !== sitesFetchSeq) {
                return allSites;
            }
            // Support legacy bare-array responses and the publisher+sites payload.
            const sites = Array.isArray(data) ? data : (data?.sites || []);
            const publisher = Array.isArray(data) ? null : (data?.publisher || null);

            if (publisher) {
                document.getElementById('siteUserName').innerText =
                    (publisher.name || 'Publisher') + ' websites';
                document.getElementById('siteUserEmail').innerText =
                    publisher.email || '';
                document.getElementById('siteUserCopyStrike')?.classList.toggle('d-none', !publisher.copy_strike);
            }
            const summaryEl = document.getElementById('siteUserSummary');
            const summary = Array.isArray(data) ? null : (data?.summary || null);
            if (summaryEl) {
                if (summary && summary.total != null) {
                    const total = Number(summary.total) || 0;
                    const ready = Number(summary.ready_to_activate) || 0;
                    const below = Number(summary.below_quality) || 0;
                    summaryEl.textContent = total + (total === 1 ? ' site' : ' sites')
                        + ' · ' + ready + ' ready to activate · ' + below + ' below quality bar';
                } else {
                    summaryEl.textContent = '';
                }
            }

            const meta = Array.isArray(data) ? null : (data?.meta || null);
            const page = meta && Number(meta.current_page) > 1 ? Number(meta.current_page) : 1;
            if (page > 1) {
                const seen = new Set(allSites.map((s) => s.id));
                sites.forEach((s) => { if (!seen.has(s.id)) allSites.push(s); });
            } else {
                allSites = sites;
            }
            window.sitesListMeta = meta;
            const matchTotal = document.querySelector('[data-staff-bulk-bar="publisher"] [data-staff-bulk-match-total]');
            if (matchTotal && meta && meta.total != null) {
                matchTotal.textContent = String(meta.total);
            }
            applySiteFilters();
            renderSitesPager(id, meta, allSites.length);
            return allSites;
        })
        .catch((err) => {
            if (fetchSeq !== sitesFetchSeq) {
                return allSites;
            }
            const msg = (err && err.message) ? String(err.message) : 'Failed to load sites';
            // Quietly recover from stale deep links; keep a toast for real failures.
            if (msg !== 'Publisher not found') {
                toast(msg, 'error');
            }
            return [];
        });
}

function renderSitesPager(publisherId, meta, loadedCount) {
    const pager = document.getElementById('sitesPager');
    if (!pager) return;
    if (!meta || Number(meta.last_page) <= 1) {
        pager.innerHTML = '';
        return;
    }
    const loaded = Number(loadedCount) || 0;
    const total = Number(meta.total) || loaded;
    const nextPage = (Number(meta.current_page) || 1) + 1;
    const hasMore = nextPage <= Number(meta.last_page);
    pager.innerHTML = `<span class="small text-muted">Showing ${loaded} of ${total}</span>`
        + (hasMore
            ? `<button type="button" class="btn btn-sm btn-outline-primary" id="sitesLoadMore" data-id="${publisherId}" data-page="${nextPage}">Load more</button>`
            : '');
}

document.addEventListener('click', function (e) {
    const more = e.target.closest('#sitesLoadMore');
    if (!more) return;
    e.preventDefault();
    more.disabled = true;
    fetchUserSites(more.dataset.id, more.dataset.page).finally(() => {
        more.disabled = false;
    });
});

function refreshSidebarQueueBadges() {
    if (typeof window.refreshAdminQueueBadges === 'function') {
        window.refreshAdminQueueBadges();
    }
}

function formatSitesCount(n) {
    const value = Number(n) || 0;
    try {
        return value.toLocaleString('en-US');
    } catch (e) {
        return String(value);
    }
}

function adjustPublisherReviewBadge(publisherId, delta) {
    if (!publisherId || !delta) return;
    const badge = document.querySelector(`.user-row[data-id="${publisherId}"] [data-review-count]`);
    if (!badge) return;
    const current = parseInt(String(badge.textContent || '').replace(/[^\d]/g, ''), 10);
    if (!Number.isFinite(current)) return;
    const next = current + delta;
    if (next <= 0) {
        badge.remove();
        return;
    }
    badge.textContent = formatSitesCount(next) + ' new';
}

function decrementLabeledCount(el, formatted) {
    if (!el) return;
    const current = parseInt(String(el.textContent || '').replace(/[^\d]/g, ''), 10);
    if (!Number.isFinite(current) || current < 1) return;
    const next = current - 1;
    el.textContent = formatted ? formatSitesCount(next) : String(next);
}

function revealAllPublisherSites() {
    const needsOnlyEl = document.getElementById('sitesNeedsReviewOnly');
    if (needsOnlyEl && needsOnlyEl.checked) {
        needsOnlyEl.checked = false;
    }
}

function dropNeedsReviewQueryParam() {
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('needs_review') && url.searchParams.get('verified') !== '0') {
            return;
        }
        url.searchParams.delete('needs_review');
        if (url.searchParams.get('verified') === '0') {
            url.searchParams.delete('verified');
        }
        const next = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '');
        window.history.replaceState({}, '', next);
    } catch (e) {
        // ignore
    }
}

function removeSiteFromTable(id) {
    const nid = Number(id);
    allSites = allSites.filter((s) => Number(s.id) !== nid);
    document.getElementById('details-' + id)?.remove();
    document.querySelector(`[data-site-row="${id}"]`)?.remove();
    applySiteFilters();
}

function afterSiteDecision(removedId) {
    if (FLAT_QUEUE) {
        if (removedId != null && removedId !== '') {
            document.querySelector(`[data-flat-site-row="${removedId}"]`)?.remove();
            const countEl = document.querySelector('[data-flat-queue-count]');
            if (countEl) {
                const current = parseInt(String(countEl.textContent || '').replace(/[^\d]/g, ''), 10);
                if (Number.isFinite(current) && current > 0) {
                    const next = current - 1;
                    countEl.textContent = next + ' in queue';
                }
            }
            decrementLabeledCount(document.querySelector('[data-staff-bulk-bar="flat"] [data-staff-bulk-match-total]'), false);
            const waitingStage = new URLSearchParams(window.location.search).get('waiting_stage') || 'all';
            decrementLabeledCount(document.querySelector(`[data-waiting-stage-total="${waitingStage}"]`), true);
            if (waitingStage !== 'all') {
                decrementLabeledCount(document.querySelector('[data-waiting-stage-total="all"]'), true);
            }
        }
        refreshSidebarQueueBadges();
        if (!document.querySelector('[data-flat-site-row]')) {
            window.location.reload();
        }
        return;
    }
    // Verify/Activate removes needs_review — keep the row visible with updated status.
    if (removedId != null && removedId !== '') {
        const leaving = (allSites || []).find((site) => Number(site.id) === Number(removedId));
        if (leaving && leaving.needs_review) {
            adjustPublisherReviewBadge(sessionStorage.getItem('selected_user'), -1);
        }
        removeSiteFromTable(removedId);
    }
    revealAllPublisherSites();
    dropNeedsReviewQueryParam();
    const userId = sessionStorage.getItem('selected_user');
    if (userId) {
        fetchUserSites(userId);
    } else {
        applySiteFilters();
    }
    refreshSidebarQueueBadges();
}

function applySiteFilters() {
    renderSites(allSites);
}

function refetchOpenPublisherSites() {
    const userId = sessionStorage.getItem('selected_user');
    if (userId) {
        fetchUserSites(userId, 1);
        return;
    }
    applySiteFilters();
}

/* ================= EDIT WITH FILE UPLOAD ================= */
function editSiteWithImage(siteId) {
    let site = allSites.find(s => s.id == siteId);
    if (!site) return;

    Swal.fire({
        title: 'Edit Site',
        width: 960,
        showCancelButton: true,
        confirmButtonText: 'Update',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        html: `
            <div style="text-align: left;">
                <label style="font-weight:600; margin-bottom:5px; display:block;">Site Name</label>
                <input id="swal-site_name" class="swal2-input" value="${escapeHtml(site.site_name ?? '')}" placeholder="Site Name">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Site URL</label>
                <input id="swal-site_url" class="swal2-input" value="${escapeHtml(site.site_url ?? '')}" placeholder="Site URL">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">DA (Domain Authority)</label>
                <input id="swal-da" class="swal2-input" type="number" value="${site.da ?? ''}" placeholder="0-100" min="0" max="100" step="1">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">DR (Domain Rating)</label>
                <input id="swal-dr" class="swal2-input" type="number" value="${site.dr ?? ''}" placeholder="0-100" min="0" max="100" step="1">
                
                <label style="font-weight:600; margin-bottom:5px; margin-top:10px; display:block;">Traffic (monthly visitors)</label>
                <input id="swal-traffic" class="swal2-input" type="number" value="${site.traffic ?? ''}" placeholder="e.g. 1500000" min="0" max="4294967295" step="1" inputmode="numeric">

                <label style="font-weight:600; margin-bottom:5px; margin-top:14px; display:block;" for="swal-description">Description</label>
                <textarea id="swal-description" class="swal2-textarea" rows="6" placeholder="Advertiser-facing brief (min 50 characters)">${escapeHtml(site.description_textarea ?? site.description ?? '')}</textarea>
                <small class="text-muted" style="display:block; margin-top:0; margin-bottom:12px;">Shown on the listing. Min 50 characters. Leave empty to keep the current brief.</small>

                <label style="font-weight:600; margin-bottom:5px; margin-top:14px; display:block;">Site Image (Upload)</label>
                <input type="file" id="swal-site_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" data-max-kb="${SITE_IMAGE_MAX_KB}" data-php-max-kb="${SITE_IMAGE_PHP_MAX_KB}">
                <div id="imagePreviewContainer" class="site-image-desktop-preview ${(site.image_url || site.preview_full_url || site.site_image) ? '' : 'is-empty'}">
                    ${(site.image_url || site.preview_full_url || siteStorageUrl(site.site_image))
                        ? `<img id="imagePreview" src="${escapeHtml(site.image_url || site.preview_full_url || siteStorageUrl(site.site_image))}" alt="Current site image" data-media-fallback="${escapeHtml(siteMediaUrl(site.site_image) || '')}" onerror="if(!this.dataset.triedMedia&&this.dataset.mediaFallback){this.dataset.triedMedia='1';this.src=this.dataset.mediaFallback;}else{this.parentElement.classList.add('is-empty');this.remove();}">`
                        : '<span>No image uploaded — pick a desktop screenshot (16:10, JPEG/PNG/WebP)</span>'}
                </div>
                <small class="text-muted" style="display:block; margin-top:5px; margin-bottom:12px;">Desktop-size preview (16:10). Hover to zoom. JPEG/PNG/GIF/WebP up to ${Math.floor(SITE_IMAGE_MAX_KB / 1024)} MB. Leave empty to keep the current image.</small>
            </div>
        `,
        didOpen: () => {
            const fileInput = document.getElementById('swal-site_image');
            const previewContainer = document.getElementById('imagePreviewContainer');
            const existingSrc = site.image_url || site.preview_full_url || siteStorageUrl(site.site_image);
            const emptyHtml = '<span>No image uploaded — pick a desktop screenshot (16:10, JPEG/PNG/WebP)</span>';

            if (window.SiteImageUpload) {
                window.SiteImageUpload.bindSiteImageInput({
                    input: fileInput,
                    preview: previewContainer,
                    maxKb: SITE_IMAGE_MAX_KB,
                    phpMaxKb: SITE_IMAGE_PHP_MAX_KB,
                    existingSrc: existingSrc || '',
                    emptyHtml: emptyHtml,
                    onError: function (msg) {
                        Swal.showValidationMessage(msg);
                    },
                    onReady: function (file) {
                        if (file && typeof Swal.resetValidationMessage === 'function') {
                            Swal.resetValidationMessage();
                        }
                    },
                });
                return;
            }

            if (fileInput && previewContainer) {
                fileInput.addEventListener('change', function() {
                    const file = this.files[0];
                    if (file) {
                        if (file.size > SITE_IMAGE_MAX_KB * 1024) {
                            Swal.showValidationMessage('Site image must be under ' + Math.floor(SITE_IMAGE_MAX_KB / 1024) + ' MB');
                            this.value = '';
                            return;
                        }
                        if (typeof Swal.resetValidationMessage === 'function') {
                            Swal.resetValidationMessage();
                        }
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewContainer.classList.remove('is-empty');
                            previewContainer.innerHTML = `<img src="${e.target.result}" alt="Selected site image">`;
                        };
                        reader.readAsDataURL(file);
                    } else if (existingSrc) {
                        previewContainer.classList.remove('is-empty');
                        previewContainer.innerHTML = `<img src="${existingSrc}" alt="Current site image">`;
                    } else {
                        previewContainer.classList.add('is-empty');
                        previewContainer.innerHTML = emptyHtml;
                    }
                });
            }
        },
        didClose: () => {
            // Run after close animation so body lock / backdrop cannot flash black.
            releaseSwalBodyLock();
        },
        preConfirm: async () => {
            if (typeof Swal.resetValidationMessage === 'function') {
                Swal.resetValidationMessage();
            }

            let site_url = document.getElementById('swal-site_url').value.trim();
            let domain = '';

            try {
                domain = new URL(site_url).hostname.replace('www.', '');
            } catch {
                domain = site_url.replace(/^(https?:\/\/)?(www\.)?/, '').split('/')[0];
            }

            const fileInput = document.getElementById('swal-site_image');
            let file = fileInput?.files?.[0];
            let imagePath = null;
            let imageUrl = null;

            // Upload first when a new file is chosen (persists even before metrics update).
            if (file) {
                if (window.SiteImageUpload) {
                    const prepared = await window.SiteImageUpload.prepareSiteImage(file, SITE_IMAGE_PHP_MAX_KB);
                    if (prepared.error) {
                        Swal.showValidationMessage(prepared.error);
                        return false;
                    }
                    if (prepared.file) {
                        file = prepared.file;
                        window.SiteImageUpload.assignInputFile(fileInput, file);
                    }
                } else if (file.size > SITE_IMAGE_MAX_KB * 1024) {
                    Swal.showValidationMessage('Site image must be under ' + Math.floor(SITE_IMAGE_MAX_KB / 1024) + ' MB');
                    return false;
                }

                const uploadFormData = new FormData();
                uploadFormData.append('site_image', file);
                uploadFormData.append('_token', CSRF_TOKEN);

                try {
                    const uploadResponse = await fetch(`${STAFF_BASE}/sites/${siteId}/upload-image`, {
                        method: 'POST',
                        body: uploadFormData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': CSRF_TOKEN,
                        },
                        credentials: 'same-origin',
                    });

                    let uploadResult = {};
                    try {
                        uploadResult = await uploadResponse.json();
                    } catch (_) {
                        Swal.showValidationMessage('Image upload failed — server returned an unexpected response.');
                        return false;
                    }

                    if (!uploadResponse.ok) {
                        const fieldError = uploadResult?.errors?.site_image?.[0];
                        Swal.showValidationMessage(fieldError || uploadResult.message || 'Image upload failed');
                        return false;
                    }

                    imagePath = uploadResult.image_path || null;
                    imageUrl = uploadResult.image_url || null;
                    if (imagePath || imageUrl) {
                        site.site_image = imagePath || site.site_image;
                        if (imageUrl) {
                            site.image_url = imageUrl;
                            // Keep list/hover in sync until fetchUserSites refreshes rows.
                            site.preview_thumb_url = imageUrl;
                            site.preview_full_url = imageUrl;
                            const prior = Array.isArray(site.preview_fallback_urls)
                                ? site.preview_fallback_urls
                                : [];
                            site.preview_fallback_urls = [imageUrl].concat(
                                prior.filter((u) => u && u !== imageUrl)
                            );
                        }
                    }
                } catch (error) {
                    Swal.showValidationMessage('Error uploading image: ' + error.message);
                    return false;
                }
            }

            const nextDescription = document.getElementById('swal-description')?.value ?? '';
            const originalDescription = String(site.description_textarea ?? site.description ?? '');
            const payload = {
                site_name: document.getElementById('swal-site_name').value,
                site_url: site_url,
                domain: domain,
                site_image: imagePath, // null = leave existing image unchanged on update
                da: document.getElementById('swal-da').value,
                dr: document.getElementById('swal-dr').value,
                traffic: document.getElementById('swal-traffic').value,
                _imageUploaded: !!imagePath,
            };
            // Skip an unchanged brief so a metrics-only save cannot 422 on a stale description.
            if (nextDescription.trim() !== originalDescription.trim()) {
                payload.description = nextDescription;
            }
            return payload;
        }
    }).then(async (result) => {
        if (!result.isConfirmed || !result.value) return;
        // Let the dialog + dark backdrop finish closing before feedback/reload.
        await new Promise((resolve) => setTimeout(resolve, 80));
        await submitSiteUpdate(siteId, result.value);
    });
}

function firstStaffValidationError(data) {
    const errors = data && data.errors && typeof data.errors === 'object' ? data.errors : {};
    for (const key of Object.keys(errors)) {
        const val = errors[key];
        if (Array.isArray(val) && val[0]) {
            return String(val[0]);
        }
        if (typeof val === 'string' && val !== '') {
            return val;
        }
    }
    return '';
}

async function submitSiteUpdate(siteId, updateData) {
    const imageAlreadySaved = !!(updateData && updateData._imageUploaded);
    const payload = { ...(updateData || {}) };
    delete payload._imageUploaded;
    // Path already persisted by upload-image — omit so a partial PUT cannot clobber it.
    if (imageAlreadySaved) {
        delete payload.site_image;
    }

    try {
        const response = await fetch(`${STAFF_BASE}/sites/${siteId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-HTTP-Method-Override': 'PUT',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
        });

        let data = {};
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            try {
                data = await response.json();
            } catch (_) {
                data = {};
            }
        }

        if (response.ok) {
            toast('Updated successfully');
            if (data.email_sent) {
                toast('Email notification sent to publisher', 'info');
            }
            const userId = sessionStorage.getItem('selected_user');
            if (userId) {
                fetchUserSites(userId);
            }
            return;
        }

        // Image was already persisted via upload-image — don't look like a hard failure.
        if (imageAlreadySaved) {
            toast('Image saved. Other fields could not be updated — try again.', 'warning');
            const userId = sessionStorage.getItem('selected_user');
            if (userId) {
                fetchUserSites(userId);
            }
            return;
        }

        toast(firstStaffValidationError(data) || data.message || 'Update failed', 'error');
    } catch (error) {
        if (imageAlreadySaved) {
            toast('Image saved. Other fields could not be updated — try again.', 'warning');
            const userId = sessionStorage.getItem('selected_user');
            if (userId) {
                fetchUserSites(userId);
            }
            return;
        }
        toast('Update failed: ' + error.message, 'error');
    } finally {
        releaseSwalBodyLock();
    }
}

/* ================= EVENTS ================= */
document.addEventListener('click', function(e){

    const btn = e.target.closest('.select-user');
    if(btn){
        let id = btn.dataset.id;
        const siteQ = btn.dataset.siteQ || '';
        const siteSearch = document.getElementById('siteSearch');
        if (siteSearch) {
            siteSearch.value = (siteQ !== '' && !siteQ.includes('@')) ? siteQ : '';
        }
        sessionStorage.setItem('selected_user', id);
        // Publishers list may be queue-filtered; always show every site for this publisher.
        revealAllPublisherSites();
        fetchUserSites(id);
        return;
    }

    /* DETAILS expand / collapse */
    if(e.target.closest('.toggle-site-details')){
        const id = e.target.closest('[data-id]').dataset.id;
        const row = document.getElementById('details-' + id);
        if(!row) return;
        setSiteDetailsOpen(id, !row.classList.contains('is-open'));
        return;
    }

    /* EDIT - Using new file upload method */
    if(e.target.closest('.edit-site')){
        let id = e.target.closest('button').dataset.id;
        editSiteWithImage(id);
    }

    /* DELETE / ARCHIVE */
    if(e.target.closest('.delete-site')){
        const btn = e.target.closest('.delete-site');
        let id = btn.dataset.id;
        let site = allSites.find(s => s.id == id);
        const isArchive = canArchiveSiteRow(site) || btn.dataset.archive === '1';
        const name = site?.site_name || btn.dataset.name || 'this site';
        const title = isArchive
            ? 'Archive this site?'
            : 'Reject this site?';
        const text = isArchive
            ? `"${name}" will be hidden from the catalog. Explain why — the publisher will see this reason. The listing is kept so order history stays intact.`
            : `Explain why "${name}" is being rejected. The publisher will see this reason.`;

        Swal.fire({
            title,
            text,
            icon:'warning',
            input: 'textarea',
            inputLabel: 'Reason for the publisher',
            inputPlaceholder: 'Reason (min. 10 characters)',
            inputAttributes: { 'aria-label': isArchive ? 'Archive reason' : 'Rejection reason', maxlength: '1000' },
            showCancelButton:true,
            confirmButtonText: isArchive ? 'Archive' : 'Reject',
            customClass: { confirmButton: 'slb-swal-danger' },
            preConfirm: (value) => {
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                if (reason.length > 1000) {
                    Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                    return false;
                }
                return reason;
            },
        }).then(result => {
            if(!result.isConfirmed) return;

            const reason = String(result.value || '').trim();
            fetch(`${STAFF_BASE}/sites/${id}`, {
                method:'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ reason }),
            })
            .then(async (res) => {
                let data = {};
                try {
                    data = await res.json();
                } catch (_) {
                    throw new Error(`Failed to delete site (${res.status})`);
                }

                if (!res.ok || !data.success) {
                    const reasonErr = data.errors && data.errors.reason
                        ? (Array.isArray(data.errors.reason) ? data.errors.reason[0] : data.errors.reason)
                        : null;
                    throw new Error(reasonErr || data.message || (isArchive ? 'Could not archive site' : 'Failed to delete site'));
                }

                toast(data.message || (data.archived ? 'Site archived' : 'Deleted successfully'));
                afterSiteDecision(id);
            })
            .catch((error) => {
                toast(error.message || (isArchive ? 'Could not archive site' : 'Failed to delete site'), 'error');
            });
        });
    }

    /* TOGGLE ACTIVE */
    if(e.target.closest('.toggle-active')){
        let btn = e.target.closest('button');
        let id = btn.dataset.id;
        let status = btn.dataset.status;
        let activating = Number(status) === 1;
        let newStatus = activating ? 'activate' : 'deactivate';
        let needsReason = !activating;

        const postActive = (payload) => {
            fetch(`${STAFF_BASE}/sites/${id}/active`, {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'Accept':'application/json',
                    'X-Requested-With':'XMLHttpRequest',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(async (res) => {
                let data = {};
                try {
                    data = await res.json();
                } catch (_) {
                    throw new Error(`Failed to ${newStatus} site (${res.status})`);
                }

                if(!res.ok || !data.success) {
                    const reasonErr = data.errors && data.errors.reason
                        ? (Array.isArray(data.errors.reason) ? data.errors.reason[0] : data.errors.reason)
                        : null;
                    const msg = reasonErr || data.message || `Failed to ${newStatus} site`;
                    throw new Error(msg);
                }

                toast(data.message || (activating ? 'Site activated successfully' : 'Site deactivated successfully'));
                if (data.warning) {
                    toast(data.warning, 'warning');
                }
                if(data.email_sent) {
                    toast('Email notification sent to publisher', 'info');
                }
                afterSiteDecision();
            })
            .catch((error) => {
                toast(error.message || `Failed to ${newStatus} site`, 'error');
            });
        };

        if (activating) {
            const site = allSites.find((s) => String(s.id) === String(id)) || {};
            const activateOpts = {
                looksEnglish: site.description_looks_english,
                excerpt: site.description_excerpt || '',
                name: site.site_name || '',
                editUrl: `${STAFF_BASE}/sites/${id}/edit#description`,
            };
            const fallbackActivateText = activateOpts.name
                ? 'Make "' + activateOpts.name + '" live in the catalog?'
                : 'Are you sure you want to activate this site?';
            const confirmActivate = (typeof window.slbConfirmActivate === 'function')
                ? window.slbConfirmActivate(activateOpts)
                : (typeof window.slbConfirm === 'function')
                    ? window.slbConfirm({
                        title: 'Activate Site?',
                        text: fallbackActivateText,
                        icon: 'question',
                        confirmText: 'Yes, activate',
                    })
                    : Swal.fire({
                        title: 'Activate Site?',
                        text: fallbackActivateText,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, activate',
                    }).then((result) => !!(result && result.isConfirmed));
            confirmActivate.then((ok) => {
                if (!ok) return;
                postActive({ active: 1 });
            });
            return;
        }

        Swal.fire({
            title: 'Deactivate Site?',
            text: 'Explain why this listing is being deactivated. The publisher will see this reason in email and notifications.',
            icon: 'question',
            input: needsReason ? 'textarea' : undefined,
            inputLabel: needsReason ? 'Reason for the publisher' : undefined,
            inputPlaceholder: needsReason ? 'Reason (min. 10 characters)' : undefined,
            inputAttributes: needsReason ? { 'aria-label': 'Deactivation reason', maxlength: '1000' } : undefined,
            showCancelButton: true,
            confirmButtonText: 'Yes, deactivate',
            preConfirm: (value) => {
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                if (reason.length > 1000) {
                    Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                    return false;
                }
                return reason;
            },
        }).then(result => {
            if(!result.isConfirmed) return;
            const reason = String(result.value || '').trim();
            if (reason.length < 10) {
                toast('A deactivation reason is required (min. 10 characters).', 'error');
                return;
            }
            postActive({ active: 0, reason: reason });
        });
    }

    /* TOGGLE VERIFY */
    if(e.target.closest('.toggle-verify')){
        let btn = e.target.closest('button');
        let id = btn.dataset.id;
        let status = btn.dataset.status;
        let newStatus = status == 1 ? 'verify' : 'unverify';
        let needsReason = newStatus === 'unverify';

        Swal.fire({
            title: `${newStatus === 'verify' ? 'Verify' : 'Unverify'} Site?`,
            text: needsReason
                ? 'Explain why verification is being removed. The publisher will see this reason.'
                : (btn.dataset.publisherReviewing === '1'
                    ? 'The publisher has not submitted this site yet. Verifying approves it before they submit.'
                    : `Are you sure you want to ${newStatus} this site?`),
            icon: 'question',
            input: needsReason ? 'textarea' : undefined,
            inputPlaceholder: needsReason ? 'Reason (min. 10 characters)' : undefined,
            inputAttributes: needsReason ? { 'aria-label': 'Unverify reason' } : undefined,
            showCancelButton: true,
            confirmButtonText: `Yes, ${newStatus}`,
            preConfirm: (value) => {
                if (!needsReason) return null;
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                if (reason.length > 1000) {
                    Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                    return false;
                }
                return reason;
            },
        }).then(result => {
            if(!result.isConfirmed) return;

            const payload = { verified: Number(status) === 1 ? 1 : 0 };
            if (needsReason && result.value) {
                payload.reason = result.value;
            }

            fetch(`${STAFF_BASE}/sites/${id}/verify`, {
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'Accept':'application/json',
                    'X-CSRF-TOKEN':'{{ csrf_token() }}'
                },
                body: JSON.stringify(payload)
            })
            .then(async (res) => {
                let data = {};
                try {
                    data = await res.json();
                } catch (_) {
                    throw new Error(`Failed to ${newStatus} site (${res.status})`);
                }

                if(!res.ok || !data.success) {
                    const msg = data.message
                        || (data.errors && data.errors.reason && data.errors.reason[0])
                        || `Failed to ${newStatus} site`;
                    throw new Error(msg);
                }

                toast(`Site ${newStatus}d successfully`);
                if(data.email_sent) {
                    toast(`Email notification sent to publisher`, 'info');
                }
                if (!FLAT_QUEUE && String(status) === '1') {
                    const reviewed = (allSites || []).find((site) => Number(site.id) === Number(id));
                    if (reviewed && reviewed.needs_review) {
                        adjustPublisherReviewBadge(sessionStorage.getItem('selected_user'), -1);
                    }
                }
                afterSiteDecision(FLAT_QUEUE ? id : undefined);
            })
            .catch((error) => {
                toast(error.message || `Failed to ${newStatus} site`, 'error');
            });
        });
    }
});

/* ================= ENRICHMENT ================= */
document.addEventListener('click', async function(e){
    const enrichBtn = e.target.closest('.enrich-site');
    const shotBtn = e.target.closest('.refresh-screenshot');
    const unlockBtn = e.target.closest('.allow-api-overwrite');
    if(!enrichBtn && !shotBtn && !unlockBtn) return;

    const btn = enrichBtn || shotBtn || unlockBtn;
    const id = btn.dataset.id;
    const url = unlockBtn
        ? `${STAFF_BASE}/sites/${id}/allow-api-metrics`
        : (enrichBtn ? `${STAFF_BASE}/sites/${id}/enrich` : `${STAFF_BASE}/sites/${id}/refresh-screenshot`);
    btn.disabled = true;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            // Queue jobs — sync capture blocks the UI for tens of seconds.
            body: JSON.stringify(unlockBtn ? {} : { sync: false }),
        });
        const data = await res.json();
        const okLabel = unlockBtn ? 'API overwrite allowed' : (enrichBtn ? 'Enrichment queued' : 'Screenshot queued');
        toast(
            data.message || (data.success ? okLabel : 'Failed'),
            data.success ? 'success' : 'error'
        );
        // Do not reload the whole publisher list after queueing — keep the UI snappy.
    } catch (err) {
        toast(unlockBtn ? 'Could not unlock API overwrite' : 'Enrichment request failed', 'error');
    } finally {
        btn.disabled = false;
    }
});

/* ================= HELPER ================= */
function escapeHtml(str) {
    if(!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ================= RENDER ================= */
function siteStorageUrl(path) {
    if (!path) return null;
    const raw = String(path);
    if (/^https?:\/\//i.test(raw) || raw.startsWith('/storage/') || raw.startsWith('/media/') || raw.includes('/sites/media/')) {
        return raw;
    }
    return `/storage/${raw.replace(/^\/+/, '')}`;
}

function siteMediaUrl(path) {
    if (!path) return null;
    const raw = String(path);
    if (/^https?:\/\//i.test(raw) || raw.includes('/sites/media/')) {
        return raw;
    }
    if (raw.startsWith('/storage/')) {
        return `${STAFF_BASE}/sites/media/${raw.slice('/storage/'.length)}`;
    }
    if (raw.startsWith('/media/')) {
        return `${STAFF_BASE}/sites/media/${raw.slice('/media/'.length)}`;
    }
    return `${STAFF_BASE}/sites/media/${raw.replace(/^\/+/, '')}`;
}

function sitePreviewPaths(site) {
    // Prefer API-built disk-aware URLs. Avoid inventing /storage/ paths that 404.
    const chain = [];
    const push = (url) => {
        if (!url) return;
        const u = String(url);
        if (u && !chain.includes(u)) chain.push(u);
    };

    const uploaded = site.image_url || siteMediaUrl(site.site_image) || siteStorageUrl(site.site_image);
    // Prefer staff media stream first (Hostinger /storage often 404s).
    push(siteMediaUrl(site.site_image));
    push(uploaded);
    push(siteStorageUrl(site.site_image));

    const apiFallbacks = Array.isArray(site.preview_fallback_urls)
        ? site.preview_fallback_urls
        : null;

    if (apiFallbacks !== null) {
        apiFallbacks.forEach(push);
        push(site.preview_thumb_url);
        push(site.preview_full_url);
        push(site.screenshot_thumb_url);
        push(site.screenshot_url);
        push(site.image_url);
        push(siteMediaUrl(site.site_image));
        push(siteStorageUrl(site.site_image));
    } else {
        // Legacy payload without disk checks.
        push(site.preview_thumb_url);
        push(site.preview_full_url);
        push(site.screenshot_thumb_url);
        push(site.screenshot_url);
        push(site.image_url);
        push(siteMediaUrl(site.screenshot_thumb_path));
        push(siteStorageUrl(site.screenshot_thumb_path));
        push(siteMediaUrl(site.screenshot_path));
        push(siteStorageUrl(site.screenshot_path));
        push(siteMediaUrl(site.site_image));
        push(siteStorageUrl(site.site_image));
    }

    const thumb = siteMediaUrl(site.site_image) || uploaded || site.preview_thumb_url || site.screenshot_thumb_url || chain[0] || null;
    const full = site.preview_full_url || site.screenshot_url || siteMediaUrl(site.site_image) || uploaded || thumb || null;

    if (thumb) push(thumb);
    if (full) push(full);

    return { thumb, full, chain };
}

function markSitePreviewBroken(img) {
    const parent = img && img.parentElement;
    if (!parent) return;
    parent.classList.add('is-empty');
    parent.removeAttribute('data-zoom-src');
    parent.removeAttribute('tabindex');
    parent.innerHTML = '<i class="fa fa-image" aria-hidden="true"></i>';
}

function sitePreviewImgOnError(img) {
    let chain = [];
    try {
        chain = JSON.parse(img.getAttribute('data-preview-chain') || '[]');
    } catch (e) {
        chain = [];
    }
    const next = Number(img.getAttribute('data-preview-i') || '0') + 1;
    if (next < chain.length) {
        img.setAttribute('data-preview-i', String(next));
        img.src = chain[next];
        return;
    }
    img.onerror = null;
    markSitePreviewBroken(img);
}

function sitePreviewHtml(site) {
    const paths = sitePreviewPaths(site);
    if (!paths.thumb) {
        return `<span class="site-row-preview is-empty" aria-label="No preview"><i class="fa fa-image" aria-hidden="true"></i></span>`;
    }

    const name = escapeHtml(site.site_name || 'Site');
    // Zoom uses full only on hover (loaded then) — keep list src on the light thumb.
    const zoomAttr = paths.full ? ` data-zoom-src="${escapeHtml(paths.full)}" tabindex="0"` : '';
    // Prefer thumb → upload → full so a missing thumb recovers without fetching the desktop shot first.
    const chain = [];
    [paths.thumb, siteMediaUrl(site.site_image), site.image_url || siteStorageUrl(site.site_image), paths.full]
        .concat(paths.chain || [])
        .forEach(function (url) {
            if (url && !chain.includes(url)) chain.push(url);
        });
    const chainJson = escapeHtml(JSON.stringify(chain));

    return `
        <span class="site-row-preview"
              role="img"
              aria-label="${name} preview"${zoomAttr}>
            <img src="${escapeHtml(chain[0] || paths.thumb)}"
                 alt="${name} preview"
                 loading="lazy"
                 decoding="async"
                 data-preview-chain="${chainJson}"
                 data-preview-i="0"
                 onerror="sitePreviewImgOnError(this)">
        </span>
    `;
}

function syncSiteDetailsLabel(id, opening) {
    const label = document.querySelector(`#sitesTable .toggle-site-details[data-id="${id}"]`);
    if (!label) return;
    label.innerHTML = opening
        ? '<i class="fa fa-chevron-up me-2"></i>Hide details'
        : '<i class="fa fa-chevron-down me-2"></i>Details';
}

function setSiteDetailsOpen(id, opening) {
    const row = document.getElementById('details-' + id);
    if (!row) return false;
    if (opening) {
        document.querySelectorAll('#sitesTable .admin-expand-row.is-open').forEach(function (openRow) {
            if (openRow === row) return;
            openRow.classList.remove('is-open');
            const otherId = String(openRow.id || '').replace(/^details-/, '');
            if (otherId) {
                syncSiteDetailsLabel(otherId, false);
            }
        });
        row.classList.add('is-open');
        hydrateSiteDetailImages(row);
    } else {
        row.classList.remove('is-open');
    }
    syncSiteDetailsLabel(id, opening);
    return true;
}

function hydrateSiteDetailImages(scope) {
    (scope || document).querySelectorAll('img[data-detail-src]').forEach(function (img) {
        const src = img.getAttribute('data-detail-src');
        if (!src || img.getAttribute('src')) return;
        img.setAttribute('src', src);
        img.removeAttribute('data-detail-src');
        // If staff/storage URL 404s, retry via the other known public paths.
        if (!img.getAttribute('onerror')) {
            img.onerror = function () {
                const src = String(this.src || '');
                if (!this.dataset.triedStaff && src.includes('/storage/')) {
                    this.dataset.triedStaff = '1';
                    this.src = src.replace('/storage/', (typeof STAFF_BASE !== 'undefined' ? STAFF_BASE : '/admin') + '/sites/media/');
                    return;
                }
                if (!this.dataset.triedPublicMedia && src.includes('/sites/media/')) {
                    this.dataset.triedPublicMedia = '1';
                    this.src = '/media/' + src.split('/sites/media/').pop();
                    return;
                }
                if (!this.dataset.triedStorage && src.includes('/media/') && !src.includes('/sites/media/')) {
                    this.dataset.triedStorage = '1';
                    this.src = src.replace('/media/', '/storage/');
                    return;
                }
                if (this.parentElement) {
                    this.parentElement.style.display = 'none';
                }
            };
        }
    });
}

function initSitePreviewZoom(root) {
    const scope = root || document;
    if (window.SiteImageUpload && !window.SiteImageUpload.canHoverZoom()) return;
    if (!window.SiteImageUpload && window.matchMedia && !window.matchMedia('(any-hover: hover)').matches && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    let pop = document.getElementById('sitePreviewZoomPop');
    if (!pop) {
        pop = document.createElement('div');
        pop.id = 'sitePreviewZoomPop';
        pop.className = 'site-preview-zoom-pop';
        pop.setAttribute('aria-hidden', 'true');
        pop.innerHTML = '<img alt="" decoding="async">';
        document.body.appendChild(pop);
    }
    const img = pop.querySelector('img');
    let hideTimer = null;

    function place(trigger) {
        const rect = trigger.getBoundingClientRect();
        const pad = 12;
        const popW = pop.offsetWidth || 720;
        const popH = pop.offsetHeight || 450;
        let left = rect.right + 12;
        let top = rect.top + (rect.height / 2) - (popH / 2);
        if (left + popW > window.innerWidth - pad) {
            left = rect.left - popW - 12;
        }
        if (left < pad) left = pad;
        if (top < pad) top = pad;
        if (top + popH > window.innerHeight - pad) {
            top = Math.max(pad, window.innerHeight - popH - pad);
        }
        pop.style.left = Math.round(left) + 'px';
        pop.style.top = Math.round(top) + 'px';
    }

    function show(trigger) {
        const src = trigger.getAttribute('data-zoom-src');
        if (!src || trigger.classList.contains('is-empty')) return;
        clearTimeout(hideTimer);
        if (img.getAttribute('src') !== src) {
            img.setAttribute('src', src);
        }
        img.setAttribute('alt', trigger.getAttribute('aria-label') || 'Site preview');
        pop.classList.add('is-visible');
        place(trigger);
        requestAnimationFrame(function () { place(trigger); });
    }

    function hide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            pop.classList.remove('is-visible');
        }, 80);
    }

    scope.querySelectorAll('.site-row-preview[data-zoom-src]').forEach(function (el) {
        if (el.getAttribute('data-zoom-ready') === '1') return;
        el.setAttribute('data-zoom-ready', '1');
        el.addEventListener('mouseenter', function () { show(el); });
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', function () { show(el); });
        el.addEventListener('blur', hide);
    });
}

function formatJoined(values, upper, limit) {
    const list = (Array.isArray(values) ? values : [])
        .map(function (value) { return String(value || '').trim(); })
        .filter(Boolean)
        .map(function (value) { return upper ? value.toUpperCase() : value; });
    if (!list.length) return '—';
    const cap = limit === 0 ? list.length : 3;
    const shown = list.slice(0, cap);
    const extra = list.length - shown.length;
    return escapeHtml(shown.join(', ')) + (extra > 0 ? ' <span class="text-muted">+' + extra + ' more</span>' : '');
}

function renderSites(data){

    data = [...(data || [])];

    let html = '';

    if(!data.length){
        html = `<tr><td colspan="7" class="text-center text-muted">No sites found</td></tr>`;
    } else {

        data.forEach((site,i) => {
            const paths = sitePreviewPaths(site);

            const needsReview = !!site.needs_review;
            const reviewBadge = needsReview
                ? `<span class="badge text-bg-warning badge-needs-review ms-1">NEW · Needs review</span>`
                : '';
            const awaitingBadge = site.awaits_publisher_details
                ? `<span class="badge text-bg-secondary badge-needs-review ms-1">Awaiting publisher</span>`
                : '';
            const reviewingBadge = site.details_complete
                ? `<span class="badge text-bg-secondary badge-needs-review ms-1">Publisher reviewing</span>`
                : '';
            const inviteBadge = site.pending_publisher_acceptance
                ? `<span class="badge text-bg-info badge-needs-review ms-1">Awaiting accept</span>`
                : '';
            const bulkOriginBadge = site.added_from_bulk_request
                ? `<span class="badge text-bg-light border badge-needs-review ms-1">Bulk request</span>`
                : '';
            const csvMetricsBadge = site.csv_metrics_spot_check
                ? `<span class="badge text-bg-light border badge-needs-review ms-1" title="Publisher-supplied DA/DR/traffic from agency CSV — spot-check before activate">CSV metrics — spot-check</span>`
                : '';
            const missingMarketBadge = site.missing_market
                ? `<span class="badge text-bg-danger badge-needs-review ms-1" title="Set a marketplace country before marketing can activate">Missing market</span>`
                : '';
            const qualityFailures = (Array.isArray(site.quality_failures) ? site.quality_failures : [])
                .map(function (item) { return String(item || '').trim(); })
                .filter(Boolean);
            const belowQualityBadge = site.below_quality_bar
                ? `<span class="badge text-bg-warning text-dark badge-needs-review ms-1" title="DA ≥ ${QUALITY_MIN_DA}, DR ≥ ${QUALITY_MIN_DR}, traffic ≥ ${QUALITY_MIN_TRAFFIC.toLocaleString('en-US')}">Below quality bar${qualityFailures.length ? ' — ' + escapeHtml(qualityFailures.join(', ')) : ''}</span>`
                : '';
            const missingCoverBadge = site.missing_cover
                ? `<span class="badge text-bg-warning text-dark badge-needs-review ms-1">No cover</span>`
                : '';
            const missingTagsBadge = site.missing_tags
                ? `<span class="badge text-bg-warning text-dark badge-needs-review ms-1">No tags</span>`
                : '';
            const scanBadge = site.enrichment_failed
                ? `<span class="badge text-bg-danger badge-needs-review ms-1">Scan failed</span>`
                : '';
            const copyStrikeBadge = site.publisher_copy_strike
                ? `<span class="badge text-bg-dark badge-needs-review ms-1">Copy-strike hide</span>`
                : '';
            const ordersCount = Number(site.orders_count) || 0;
            const ordersLabel = ordersCount + (ordersCount === 1 ? ' order' : ' orders');
            const ordersHtml = site.orders_url
                ? `<a href="${escapeHtml(site.orders_url)}">${ordersLabel}</a>`
                : ordersLabel;
            const metricsSource = site.metrics_manual ? 'Manual' : (site.metrics_fetched_label ? 'Scan' : '');
            const metricsHtml = metricsSource
                ? ` · ${metricsSource}${site.metrics_fetched_label ? ' ' + escapeHtml(site.metrics_fetched_label) : ''}`
                : '';
            const tagMeta = site.missing_tags ? '' : (site.listing_tag_label ? ` · ${escapeHtml(site.listing_tag_label)}` : '');
            const saleHtml = site.sale_price != null
                ? `<div class="small text-muted">Sale €${Number(site.sale_price).toFixed(2)}</div>`
                : '';
            const offerBadges = (site.featured ? `<span class="badge text-bg-primary">Featured</span>` : '')
                + (site.bulk_discount ? `<span class="badge text-bg-info">Bulk</span>` : '');

            // Publisher-style 16:10 preview + site identity
            let siteInfoHtml = `
                <div class="site-info-cell admin-site-info-stack">
                    ${sitePreviewHtml(site)}
                    <div class="site-details">
                        <div class="site-name">
                            ${escapeHtml(site.site_name ?? '-')}
                            ${reviewBadge}
                            ${awaitingBadge}
                            ${reviewingBadge}
                            ${inviteBadge}
                            ${bulkOriginBadge}
                            ${csvMetricsBadge}
                            ${missingMarketBadge}
                            ${belowQualityBadge}
                            ${missingCoverBadge}
                            ${missingTagsBadge}
                            ${scanBadge}
                            ${copyStrikeBadge}
                        </div>
                        <a href="${escapeHtml(site.site_url ?? '#')}" target="_blank" class="site-url" title="${escapeHtml(site.site_url ?? '')}">
                            ${escapeHtml(site.site_url ?? '-')}
                        </a>
                        <div class="small text-muted">DA ${site.da ?? '—'} · DR ${site.dr ?? '—'} · ${formatJoined((site.countries_list && site.countries_list.length) ? site.countries_list : [site.country], true)} · ${formatJoined((site.languages_list && site.languages_list.length) ? site.languages_list : [site.language], true)} · ${formatJoined((site.categories_list && site.categories_list.length) ? site.categories_list : [site.category], false)}${tagMeta}${site.link_type_label ? ' · ' + escapeHtml(site.link_type_label) : ''}${site.sponsored ? ' · Sponsored' : ''}${metricsHtml}</div>
                        <div class="small">${ordersHtml}</div>
                    </div>
                </div>
            `;

            const isActive = Number(site.active) === 1 || site.active === true;
            const isVerified = Number(site.verified) === 1 || site.verified === true;

            const statusHtml = `
                <div class="admin-status-stack">
                    <span title="${isActive ? 'Active' : 'Inactive'}">${isActive
                        ? '<span class="pulse-dot pulse-green"></span>For sale'
                        : '<span class="pulse-dot pulse-red"></span>Not for sale'}</span>
                    <span class="badge rounded-pill ${isVerified ? 'bg-success' : 'bg-secondary'}" title="${isVerified ? 'Verified' : 'Unverified'}">
                        ${isVerified ? 'Checked' : 'Not checked'}
                    </span>
                </div>
            `;

            const listingLocked = IS_MARKETING_EDITOR && (
                isVerified
                || isActive
                || !!site.listing_locked
            );
            const editLabel = (IS_MARKETING_EDITOR && !!site.archived) ? 'View' : 'Edit';
            const editItem = `<li><a class="dropdown-item" href="${STAFF_BASE}/sites/${site.id}/edit"><i class="fa fa-edit me-2"></i>${editLabel}</a></li>`
                + (IS_MARKETING_EDITOR
                    ? ''
                    : `<li><button type="button" class="dropdown-item edit-site" data-id="${site.id}"><i class="fa fa-image me-2"></i>Metrics &amp; image</button></li>`);
            const enrichItems = (IS_MARKETING_EDITOR && listingLocked)
                ? ''
                : `<li><button type="button" class="dropdown-item enrich-site" data-id="${site.id}"><i class="fa fa-sync me-2"></i>Enrich</button></li>
                        <li><button type="button" class="dropdown-item refresh-screenshot" data-id="${site.id}"><i class="fa fa-camera me-2"></i>Shot</button></li>`
                    + (site.metrics_manual
                        ? `<li><button type="button" class="dropdown-item allow-api-overwrite" data-id="${site.id}"><i class="fa fa-unlock me-2"></i>Allow API overwrite</button></li>`
                        : '');

            const deleteItem = canDeleteSiteRow(site)
                ? `<li><button type="button" class="dropdown-item text-danger delete-site" data-id="${site.id}"><i class="fa fa-trash me-2"></i>Reject</button></li>`
                : (canArchiveSiteRow(site)
                    ? `<li><button type="button" class="dropdown-item text-danger delete-site" data-id="${site.id}" data-archive="1"><i class="fa fa-archive me-2"></i>Archive</button></li>`
                    : (CAN_DELETE_ANY_SITE && siteHasOrders(site) && !site.archived
                        ? `<li><button type="button" class="dropdown-item disabled" disabled title="This listing has orders. Deactivate it to hide it from the catalog."><i class="fa fa-ban me-2"></i>Has orders — deactivate instead</button></li>`
                        : ''));

            // Always offer Deactivate after Activate. Hide Activate when the
            // listing cannot go live (server also 422s the same rules).
            const marketingActivateBlocked = IS_MARKETING_EDITOR && (
                !!site.details_complete
                || !!site.below_quality_bar
            );
            const activateBlocked = site.can_activate === false || marketingActivateBlocked;
            const activateBlockReason = site.activate_block_reason || 'Cannot activate this listing yet.';
            let primaryAction = '';
            if (!isActive && site.below_quality_bar) {
                primaryAction = IS_MARKETING_EDITOR
                    ? `<a class="btn btn-sm btn-outline-warning" href="${STAFF_BASE}/sites/${site.id}/edit">Fix metrics</a>`
                    : `<button type="button" class="btn btn-sm btn-outline-warning edit-site" data-id="${site.id}">Fix metrics</button>`;
            } else if (!isActive && !activateBlocked && CAN_TOGGLE_ACTIVE) {
                primaryAction = `<button type="button" class="btn btn-sm btn-outline-primary toggle-active" data-id="${site.id}" data-status="1">Activate</button>`;
            }
            const activeItem = CAN_TOGGLE_ACTIVE
                ? (isActive
                    ? `<li><button type="button" class="dropdown-item toggle-active" data-id="${site.id}" data-status="0"><i class="fa fa-pause me-2"></i>Deactivate</button></li>`
                    : (activateBlocked
                        ? `<li><button type="button" class="dropdown-item disabled" disabled title="${escapeHtml(activateBlockReason)}"><i class="fa fa-ban me-2"></i>Cannot activate</button></li>`
                        : `<li><button type="button" class="dropdown-item toggle-active" data-id="${site.id}" data-status="1"><i class="fa fa-play me-2"></i>Activate</button></li>`))
                : '';

            const verifyItem = CAN_VERIFY_SITES
                ? (isVerified
                    ? `<li><button type="button" class="dropdown-item toggle-verify" data-id="${site.id}" data-status="0"><i class="fa fa-times me-2"></i>Unverify</button></li>`
                    : `<li><button type="button" class="dropdown-item toggle-verify" data-id="${site.id}" data-status="1"${site.details_complete ? ' data-publisher-reviewing="1"' : ''}><i class="fa fa-check me-2"></i>Verify</button></li>`)
                : '';

            const managePopperConfig = JSON.stringify({
                strategy: 'fixed',
                modifiers: [
                    { name: 'preventOverflow', options: { boundary: 'viewport', padding: 8 } },
                    { name: 'flip', options: { fallbackPlacements: ['top-end', 'bottom-end', 'top', 'bottom'] } },
                ],
            });

            const manageHtml = `
                <div class="dropdown admin-manage-dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                            data-bs-toggle="dropdown"
                            data-bs-auto-close="true"
                            data-bs-popper-config='${managePopperConfig}'
                            aria-expanded="false"
                            aria-haspopup="true">
                        Manage
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end admin-manage-menu">
                        ${editItem}
                        ${deleteItem}
                        ${(activeItem || verifyItem) ? '<li><hr class="dropdown-divider"></li>' : ''}
                        ${activeItem}
                        ${verifyItem}
                        ${enrichItems ? '<li><hr class="dropdown-divider"></li>' + enrichItems : ''}
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" class="dropdown-item toggle-site-details" data-id="${site.id}"><i class="fa fa-chevron-down me-2"></i>Details</button></li>
                    </ul>
                </div>
            `;

            html += `
                <tr class="${needsReview ? 'site-needs-review-row' : ''}" data-site-row="${site.id}">
                    <td><input type="checkbox" data-staff-bulk-id="${site.id}" data-verified="${isVerified ? '1' : '0'}" data-active="${isActive ? '1' : '0'}" data-can-activate="${(!isActive && !activateBlocked && CAN_TOGGLE_ACTIVE) ? '1' : '0'}" aria-label="Select site"></td>
                    <td>${i+1}</td>
                    <td>${siteInfoHtml}</td>
                    <td>${site.traffic ?? '-'}</td>
                    <td><div>€${site.price ?? '-'}</div>${saleHtml}${offerBadges}</td>
                    <td>${statusHtml}</td>
                    <td><div class="d-flex flex-wrap gap-1 align-items-center">${primaryAction}${manageHtml}</div></td>
                </tr>

                <tr id="details-${site.id}" class="admin-expand-row">
                    <td colspan="7">
                        <div class="admin-expand-box">
                            <div class="border rounded bg-white shadow-sm p-3">
                                <div class="row g-3">
                                    <div class="col-md-4"><strong>Domain</strong><div class="slb-text-break">${escapeHtml(site.domain ?? '-')}</div></div>
                                    <div class="col-md-4"><strong>DA/DR</strong><div>${site.da ?? '-'} / ${site.dr ?? '-'}</div></div>
                                    <div class="col-md-4"><strong>Metrics</strong><div>${metricsSource || '—'}${site.metrics_fetched_label ? ' · ' + escapeHtml(site.metrics_fetched_label) : ''}</div></div>
                                    <div class="col-md-4"><strong>Screenshot</strong><div>${(paths.full || paths.thumb) ? `<div class="site-preview-detail"><img data-detail-src="${escapeHtml(paths.full || paths.thumb)}" alt="Site preview" loading="lazy" decoding="async" onerror="this.parentElement.style.display='none'"></div>` : '—'}</div></div>
                                    ${site.enrichment_error ? `<div class="col-12"><strong>Last scan error</strong><div class="text-danger small slb-text-break">${escapeHtml(site.enrichment_error)}</div></div>` : ''}
                                    <div class="col-md-4"><strong>Countries</strong><div>${formatJoined(site.countries_list, true, 0)}</div></div>
                                    <div class="col-md-4"><strong>Languages</strong><div>${formatJoined(site.languages_list, true, 0)}</div></div>
                                    <div class="col-md-4"><strong>Categories</strong><div>${formatJoined(site.categories_list, false, 0)}</div></div>
                                    <div class="col-md-4"><strong>Link Type</strong><div>${escapeHtml(site.link_type_label || site.link_type || '-')}</div></div>
                                    <div class="col-md-4"><strong>Sponsored</strong><div>${site.sponsored ? 'Yes':'No'}</div></div>
                                    <div class="col-md-4"><strong>Buyer price</strong><div>€${site.price ?? '-'}</div></div>
                                    <div class="col-12"><strong>Description</strong><div class="slb-text-break">${escapeHtml(site.description_textarea || site.description_excerpt || site.description || '-')}</div><a class="small" href="${STAFF_BASE}/sites/${site.id}/edit#description">Edit description</a></div>
                                    ${(site.image_url || siteMediaUrl(site.site_image) || siteStorageUrl(site.site_image)) ? `<div class="col-12"><strong>Site Image</strong><div class="site-preview-detail"><img data-detail-src="${escapeHtml(site.image_url || siteMediaUrl(site.site_image) || siteStorageUrl(site.site_image))}" alt="Site image" loading="lazy" decoding="async"></div></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        });
    }

    document.getElementById('sitesTable').innerHTML = html;
    initSitePreviewZoom(document.getElementById('sitesTable'));
    syncBulkCounts();

    if (pendingHighlightSiteId) {
        const highlightId = String(pendingHighlightSiteId);
        pendingHighlightSiteId = null;
        const row = document.querySelector(`[data-site-row="${highlightId}"]`);
        if (row) {
            row.classList.add('site-highlight-row');
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            // CSS reveals the panel via .is-open, not .d-none.
            setSiteDetailsOpen(highlightId, true);
        }
    }
}

/* ================= BACK ================= */
document.getElementById('backBtn').addEventListener('click', function(){
    try {
        const url = new URL(window.location.href);
        if (url.searchParams.get('all') === '1') {
            ['publisher', 'site', 'edit_site'].forEach((key) => url.searchParams.delete(key));
            window.location = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '');
            return;
        }
    } catch (e) {}
    document.getElementById('sitesSection').classList.add('d-none');
    const usersSection = document.getElementById('usersSection');
    if (usersSection) {
        usersSection.classList.remove('d-none');
    }
    document.getElementById('staffIndexSearchWrap')?.classList.remove('d-none');
    sessionStorage.removeItem('selected_user');
    // Drop deep-link params so refresh stays on the publisher list (not stuck on sites).
    try {
        const url = new URL(window.location.href);
        ['publisher', 'site', 'edit_site'].forEach((key) => url.searchParams.delete(key));
        const next = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '');
        window.history.replaceState({}, '', next);
    } catch (e) {}
    // Clear any leftover SweetAlert body lock after image edit/save.
    releaseSwalBodyLock();
});

/* ================= SEARCH (Catalog-parity live search) ================= */
/* Publisher search is server-side (?q=) via data-slb-live-search="form". */
/* Site-row search is server-side against this publisher (?q= on /users/{id}/sites). */
function queryLooksLikeSiteSearch(q) {
    const s = String(q || '').trim();
    if (!s) return false;
    if (/^\d+$/.test(s)) return true;
    if (s.includes('@')) return false;
    return s.includes('.') || s.includes('://');
}

(function initStaffSitesLiveSearch() {
    function boot() {
        if (typeof window.SlbLiveSearch !== 'undefined') {
            window.SlbLiveSearch.init(document.getElementById('siteSearch'), {
                mode: 'event',
                statusEl: document.getElementById('siteSearchStatus'),
                clearBtn: document.getElementById('siteSearchClear'),
                onSearch: function () { refetchOpenPublisherSites(); },
            });
            return;
        }
        document.getElementById('siteSearch')?.addEventListener('keyup', function(){
            refetchOpenPublisherSites();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

document.getElementById('sitesNeedsReviewOnly')?.addEventListener('change', function(){
    refetchOpenPublisherSites();
});

document.getElementById('staffPublisherFilters')?.addEventListener('change', function () {
    const params = new URLSearchParams(window.location.search);
    document.querySelectorAll('#staffPublisherFilters [data-staff-filter]').forEach(function (el) {
        const key = el.getAttribute('data-staff-filter');
        if (!key) return;
        if (el.type === 'checkbox') {
            if (el.checked) params.set(key, el.value || '1');
            else params.delete(key);
            return;
        }
        const value = String(el.value || '').trim();
        if (value === '') params.delete(key);
        else params.set(key, value);
    });
    params.delete('page');
    window.location = STAFF_BASE + '/sites' + (params.toString() ? '?' + params.toString() : '');
});

function bulkRoot(scope) {
    if (scope === 'flat') return document.querySelector('[data-flat-queue]');
    if (scope === 'all') return document.querySelector('[data-all-sites]');
    return document.getElementById('sitesSection');
}

function selectedBulkIds(scope) {
    const root = bulkRoot(scope);
    if (!root) return [];
    return Array.from(root.querySelectorAll('[data-staff-bulk-id]:checked'))
        .map((el) => Number(el.getAttribute('data-staff-bulk-id')))
        .filter((id) => id > 0);
}

function selectedBulkBoxes(scope) {
    const root = bulkRoot(scope);
    if (!root) return [];
    return Array.from(root.querySelectorAll('[data-staff-bulk-id]:checked'));
}

function syncBulkCounts() {
    document.querySelectorAll('[data-staff-bulk-bar]').forEach(function (bar) {
        const scope = bar.getAttribute('data-staff-bulk-bar');
        const boxes = selectedBulkBoxes(scope);
        const countEl = bar.querySelector('[data-staff-bulk-count]');
        if (countEl) countEl.textContent = boxes.length + ' selected';
        const verifyBtn = bar.querySelector('[data-staff-bulk="verify"]');
        const deactivateBtn = bar.querySelector('[data-staff-bulk="deactivate"]');
        const activateBtn = bar.querySelector('[data-staff-bulk="activate"]');
        const matchAll = !!bar.querySelector('[data-staff-bulk-all-matching]')?.checked;
        const reset = function (btn) {
            if (!btn) return;
            btn.classList.remove('d-none');
            btn.disabled = false;
            btn.removeAttribute('title');
        };
        if (matchAll || !boxes.length) {
            reset(verifyBtn);
            reset(deactivateBtn);
            reset(activateBtn);
            return;
        }
        const allVerified = boxes.every(function (el) { return el.getAttribute('data-verified') === '1'; });
        const allInactive = boxes.every(function (el) { return el.getAttribute('data-active') !== '1'; });
        const anyCanActivate = boxes.some(function (el) { return el.getAttribute('data-can-activate') === '1'; });
        if (verifyBtn) verifyBtn.classList.toggle('d-none', allVerified);
        if (deactivateBtn) deactivateBtn.classList.toggle('d-none', allInactive);
        if (activateBtn) {
            activateBtn.disabled = !anyCanActivate;
            if (anyCanActivate) activateBtn.removeAttribute('title');
            else activateBtn.title = 'None of the selected sites can be activated.';
        }
    });
}

document.addEventListener('change', function (e) {
    const all = e.target.closest('[data-staff-bulk-all]');
    if (all) {
        const scope = all.getAttribute('data-staff-bulk-all');
        const root = scope === 'publisher'
            ? document.getElementById('sitesTable')
            : bulkRoot(scope);
        root?.querySelectorAll('[data-staff-bulk-id]').forEach(function (box) {
            box.checked = all.checked;
        });
    }
    if (e.target.matches('[data-staff-bulk-id], [data-staff-bulk-all], [data-staff-bulk-all-matching]')) {
        syncBulkCounts();
    }
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-staff-bulk]');
    if (!btn) return;
    e.preventDefault();
    const bar = btn.closest('[data-staff-bulk-bar]');
    const scope = bar?.getAttribute('data-staff-bulk-bar') || 'publisher';
    const matchBox = bar?.querySelector('[data-staff-bulk-all-matching]');
    const matchAll = !!(matchBox && matchBox.checked);
    const matchTotal = Number(bar?.querySelector('[data-staff-bulk-match-total]')?.textContent || 0);
    const ids = selectedBulkIds(scope);
    const action = btn.getAttribute('data-staff-bulk');
    if (!matchAll && !ids.length) {
        toast('Select at least one site.', 'warning');
        return;
    }
    if (matchAll && matchTotal < 1) {
        toast('No matching sites to update.', 'warning');
        return;
    }
    const run = function (reason) {
        const body = matchAll ? { action: action, match_all: true, scope: scope } : { action: action, ids: ids };
        if (matchAll) {
            const pageQuery = new URLSearchParams(window.location.search);
            if (scope === 'publisher') {
                body.publisher_id = Number(sessionStorage.getItem('selected_user') || 0);
                const siteQ = (document.getElementById('siteSearch')?.value || '').trim();
                if (siteQ !== '') body.q = siteQ;
                const focusSite = pageQuery.get('site') || '';
                const indexQ = (pageQuery.get('q') || '').trim();
                if (/^[1-9]\d*$/.test(focusSite) && (siteQ === '' || siteQ === indexQ)) {
                    body.site = focusSite;
                }
                if (document.getElementById('sitesNeedsReviewOnly')?.checked) body.needs_review = 1;
                document.querySelectorAll('#staffPublisherFilters [data-staff-filter]').forEach(function (el) {
                    const key = el.getAttribute('data-staff-filter');
                    if (!key) return;
                    if (el.type === 'checkbox') {
                        if (el.checked) body[key] = 1;
                        return;
                    }
                    const value = (el.value || '').trim();
                    if (value !== '') body[key] = value;
                });
            } else {
                pageQuery.forEach(function (value, key) {
                    if (key === 'page' || key === 'action' || key === 'match_all' || key === 'scope' || key === 'ids' || key === 'reason') {
                        return;
                    }
                    body[key] = value;
                });
            }
        }
        if (reason) body.reason = reason;
        fetch(`${STAFF_BASE}/sites/bulk-action`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        }).then(async function (res) {
            const data = await res.json().catch(function () { return {}; });
            toast(data.message || (res.ok ? 'Updated' : 'Could not update the selection.'), res.ok ? 'success' : 'warning');
            if (!res.ok) return;
            if (scope === 'flat' || scope === 'all') {
                window.location.reload();
                return;
            }
            const userId = sessionStorage.getItem('selected_user');
            if (userId) fetchUserSites(userId);
        }).catch(function () {
            toast('Could not update the selection.', 'error');
        });
    };
    const actionLabels = {
        verify: 'Verify',
        activate: 'Activate',
        reject: 'Reject',
        deactivate: 'Deactivate',
        archive: 'Archive',
    };
    const actionLabel = actionLabels[action] || 'Update';
    const matchTitle = matchAll
        ? (matchTotal > 500
            ? (actionLabel + ' the first 500 of ' + matchTotal + ' matching sites?')
            : (actionLabel + ' all ' + matchTotal + ' matching sites?'))
        : null;
    const reasonPrompts = {
        reject: [matchTitle || 'Reject selected sites?', 'Reject'],
        deactivate: [matchTitle || 'Deactivate selected sites?', 'Deactivate'],
        archive: [matchTitle || 'Archive selected sites?', 'Archive'],
    };
    if (reasonPrompts[action]) {
        Swal.fire({
            title: reasonPrompts[action][0],
            text: action === 'archive'
                ? 'Only listings that can be archived one at a time are archived. The publisher will see this reason.'
                : 'The publisher will see this reason.',
            input: 'textarea',
            inputPlaceholder: 'Reason (min. 10 characters)',
            showCancelButton: true,
            confirmButtonText: reasonPrompts[action][1],
            customClass: { confirmButton: 'slb-swal-danger' },
            preConfirm: function (value) {
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                return reason;
            },
        }).then(function (result) {
            if (result.isConfirmed) run(result.value);
        });
        return;
    }
    if (matchAll && window.Swal) {
        window.Swal.fire({
            title: matchTitle,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: actionLabel,
        }).then(function (result) {
            if (result.isConfirmed) run(null);
        });
        return;
    }
    run(null);
});

/* ================= RESTORE / DEEP-LINK ================= */
window.addEventListener('DOMContentLoaded',()=>{
    const params = new URLSearchParams(window.location.search);
    const editSiteId = params.get('edit_site');
    const siteId = params.get('site');

    // Asking for the review queue is an explicit request for the list. The last
    // publisher opened is remembered so a refresh returns you to them, but that
    // memory was also restored here — so clicking "Needs review" fetched the
    // queue, then immediately covered it with whichever publisher you happened
    // to open last, and the button looked dead.
    const wantsReviewQueue = params.has('needs_review') || params.get('verified') === '0' || params.has('waiting_on_publisher');
    if ((wantsReviewQueue || ALL_SITES) && !params.get('publisher') && !siteId) {
        sessionStorage.removeItem('selected_user');
    }

    const publisherId = params.get('publisher') || sessionStorage.getItem('selected_user');

    if (siteId) {
        pendingHighlightSiteId = siteId;
    }

    // Opening a publisher detail (deep link, notification, or session restore) must
    // show activated/verified sites — never re-apply the queue-only client filter.
    if (publisherId || siteId) {
        revealAllPublisherSites();
    }

    if (publisherId) {
        sessionStorage.setItem('selected_user', publisherId);
        const indexQ = params.get('q') || '';
        const siteSearch = document.getElementById('siteSearch');
        if (siteSearch && queryLooksLikeSiteSearch(indexQ) && !siteSearch.value) {
            siteSearch.value = indexQ;
        }
        fetchUserSites(publisherId).then(() => {
            if (editSiteId) {
                window.location.href = `${STAFF_BASE}/sites/${editSiteId}/edit`;
                return;
            }
        });
        return;
    }

    let id = sessionStorage.getItem('selected_user');
    if(id && !FLAT_QUEUE && !ALL_SITES) {
        revealAllPublisherSites();
        fetchUserSites(id);
    }
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-mkt-activate');
    if (!btn) return;
    e.preventDefault();
    const id = btn.dataset.id;
    const name = btn.dataset.name || 'this site';
    const go = (typeof window.slbConfirmActivate === 'function')
        ? window.slbConfirmActivate({
            looksEnglish: btn.dataset.descriptionEnglish !== '0',
            excerpt: btn.dataset.descriptionExcerpt || '',
            name: name,
            confirmText: 'Activate',
            editUrl: `${STAFF_BASE}/sites/${id}/edit#description`,
        })
        : (typeof window.slbConfirm === 'function')
            ? window.slbConfirm({
                title: 'Activate Site?',
                text: 'Make "' + name + '" live in the catalog?',
                icon: 'question',
                confirmText: 'Activate',
            })
            : (typeof Swal !== 'undefined' && Swal.fire)
                ? Swal.fire({
                    title: 'Activate Site?',
                    text: 'Make "' + name + '" live in the catalog?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Activate',
                }).then((r) => !!(r && r.isConfirmed))
                : Promise.resolve(false);
    go.then((ok) => {
        if (!ok) return;
        fetch(`${STAFF_BASE}/sites/${id}/active`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF_TOKEN,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ active: 1 }),
        })
        .then((res) => res.json().then((data) => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if (ok && data && data.success) {
                toast((data && data.message) || 'Site activated successfully');
                afterSiteDecision(id);
                return;
            }
            toast((data && data.message) || 'Could not activate site', 'error');
        })
        .catch(() => toast('Could not activate site', 'error'));
    });
});
</script>

@endsection