@extends('advertiser.layouts.app')

@section('title', 'My Orders')

@push('page-styles')
<link href="{{ asset('assets/css/single-select.css') }}?v={{ @filemtime(public_path('assets/css/single-select.css')) ?: '1' }}" rel="stylesheet">
<link rel="stylesheet" href="{{ same_origin_asset('assets/css/advertiser-orders.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-orders.css')) ?: '1' }}">
@endpush

@section('content')
<div class="container-fluid orders-page">
    
    <!-- HEADER -->
    <div class="orders-page-header">
        <h1 class="orders-page-title">My Orders</h1>
        <p class="orders-page-sub">
            Track each order from payment to live publication.
        </p>
    </div>

    <!-- Funnel KPIs -->
    <div class="row mb-4 g-3" id="ordersFunnelKpis">
        <div class="col-6 col-lg-3">
            <button type="button" class="wallet-kpi" data-orders-kpi="review" aria-label="Filter needs review">
                <span class="kpi-icon kpi-icon--review" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
                <span>
                    <span class="kpi-label">Needs review</span>
                    <span class="kpi-value" id="ordNeedsReview">0</span>
                    <span class="kpi-desc">Live URL ready</span>
                </span>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="wallet-kpi" data-orders-kpi="in_progress" aria-label="Filter in progress">
                <span class="kpi-icon kpi-icon--progress" aria-hidden="true"><i class="fa-solid fa-spinner"></i></span>
                <span>
                    <span class="kpi-label">In progress</span>
                    <span class="kpi-value" id="ordInProgress">0</span>
                    <span class="kpi-desc">Publisher working</span>
                </span>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="wallet-kpi" data-orders-kpi="completed" aria-label="Filter completed">
                <span class="kpi-icon kpi-icon--completed" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <span>
                    <span class="kpi-label">Completed</span>
                    <span class="kpi-value" id="ordCompleted">0</span>
                    <span class="kpi-desc">Marked complete</span>
                </span>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="wallet-kpi" data-orders-kpi="awaiting_payment" aria-label="Filter awaiting payment">
                <span class="kpi-icon kpi-icon--awaiting" aria-hidden="true"><i class="fa-solid fa-credit-card"></i></span>
                <span>
                    <span class="kpi-label">Awaiting payment</span>
                    <span class="kpi-value" id="ordAwaitingPayment">0</span>
                    <span class="kpi-desc">Pay to start</span>
                </span>
            </button>
        </div>
    </div>

    <div id="needsActionBanner" class="ui-callout ui-callout--attention ui-callout--banner d-none mb-4" role="status">
        <div class="ui-callout__main">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
            <div class="ui-callout__body">
                <strong>Needs your attention</strong>
                <span class="ms-1" id="needsActionText"></span>
            </div>
        </div>
        <div class="ui-callout__actions">
            <button type="button" class="btn btn-sm btn-primary" id="showNeedsReviewBtn">Show orders needing attention</button>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('advertiser.orders') }}" id="filterForm" class="orders-filter-bar">
                <div class="orders-filter-bar__row">
                    <div class="orders-filter-bar__search">
                        <label class="form-label fw-semibold small text-muted mb-1" for="searchInput">Search</label>
                        <div class="position-relative orders-search-wrap slb-search-wrap">
                            <input type="search"
                                   name="search"
                                   id="searchInput"
                                   class="form-control form-control-sm"
                                   placeholder="Order #, reference, site name or URL…"
                                   title="Live search: order number, reference, site name, site URL, or live URL. Multi-word matches require every word."
                                   autocomplete="off"
                                   enterkeyhint="search"
                                   aria-describedby="ordersSearchHint ordersSearchStatus"
                                   data-orders-live-search="1"
                                   value="{{ search_text(request('search')) }}">
                            <button type="button"
                                    id="ordersSearchClear"
                                    class="btn btn-sm btn-link orders-search-clear slb-search-clear d-none"
                                    aria-label="Clear search">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="orders-filter-bar__select">
                        <label class="form-label fw-semibold small text-muted mb-1" for="statusFilter">Order Status</label>
                        @include('advertiser.partials.library-theme-select', [
                            'selectId' => 'statusFilter',
                            'name' => 'status',
                            'label' => 'Order Status',
                            'current' => search_text(request('status')),
                            'options' => [
                                ['value' => '', 'label' => 'All Status'],
                                ['value' => 'awaiting_payment', 'label' => 'Awaiting payment'],
                                ['value' => 'awaiting_publisher', 'label' => 'Awaiting publisher'],
                                ['value' => 'in_progress', 'label' => 'In progress'],
                                ['value' => 'needs_action', 'label' => 'Needs your attention'],
                                ['value' => 'processing', 'label' => 'Publisher working'],
                                ['value' => 'review', 'label' => 'Needs your review'],
                                ['value' => 'completed', 'label' => 'Completed'],
                                ['value' => 'cancelled', 'label' => 'Cancelled'],
                            ],
                        ])
                    </div>

                    <div class="orders-filter-bar__select">
                        <label class="form-label fw-semibold small text-muted mb-1" for="paymentMethodFilter">Payment Method</label>
                        @include('advertiser.partials.library-theme-select', [
                            'selectId' => 'paymentMethodFilter',
                            'name' => 'payment_method',
                            'label' => 'Payment Method',
                            'current' => search_text(request('payment_method')),
                            'options' => [
                                ['value' => '', 'label' => 'All Methods'],
                                ['value' => 'wallet', 'label' => 'Wallet Balance'],
                                ['value' => 'wise', 'label' => 'Wise Transfer'],
                                ['value' => 'crypto', 'label' => 'Cryptocurrency'],
                                ['value' => 'bank', 'label' => 'Bank Transfer'],
                                ['value' => 'card', 'label' => 'Card Payment'],
                                ['value' => 'paypal', 'label' => 'PayPal'],
                            ],
                        ])
                    </div>

                    <div class="orders-filter-bar__select">
                        <label class="form-label fw-semibold small text-muted mb-1" for="paymentStatusFilter">Payment Status</label>
                        @include('advertiser.partials.library-theme-select', [
                            'selectId' => 'paymentStatusFilter',
                            'name' => 'payment_status',
                            'label' => 'Payment Status',
                            'current' => search_text(request('payment_status')),
                            'options' => [
                                ['value' => '', 'label' => 'All Status'],
                                ['value' => 'paid', 'label' => 'Paid'],
                                ['value' => 'pending', 'label' => 'Pending'],
                                ['value' => 'failed', 'label' => 'Failed'],
                                ['value' => 'refunded', 'label' => 'Refunded'],
                            ],
                        ])
                    </div>

                    <div class="orders-filter-bar__dates">
                        <span class="form-label fw-semibold small text-muted mb-1">Date Range</span>
                        <div class="d-flex gap-2 orders-date-range">
                            <label class="visually-hidden" for="dateFrom">From</label>
                            <input type="date"
                                   name="date_from"
                                   id="dateFrom"
                                   class="form-control form-control-sm"
                                   autocomplete="off"
                                   value="{{ search_text(request('date_from')) }}">
                            <label class="visually-hidden" for="dateTo">To</label>
                            <input type="date"
                                   name="date_to"
                                   id="dateTo"
                                   class="form-control form-control-sm"
                                   autocomplete="off"
                                   value="{{ search_text(request('date_to')) }}">
                        </div>
                    </div>

                    <div class="orders-filter-bar__actions">
                        <button type="submit" class="btn btn-sm btn-cta-secondary px-3" id="ordersApplyFiltersBtn">
                            <i class="fa-solid fa-filter" aria-hidden="true"></i> Apply
                        </button>
                        <button type="button" id="resetFilters" class="btn btn-sm btn-cta-secondary px-3">
                            <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reset
                        </button>
                    </div>
                </div>
                @php
                    $filterProject = $filterProject ?? null;
                    $filterProjectStage = (string) ($filterProjectStage ?? '');
                    $projectFilterValue = $filterProject?->id ?? search_text(request('project'));
                    $projectStageLabel = \App\Models\Project::stageLabel($filterProjectStage);
                    $showProjectChip = $filterProject !== null;
                @endphp
                <input type="hidden" name="project" id="projectFilter" value="{{ $projectFilterValue }}">
                <input type="hidden" name="project_stage" id="projectStageFilter" value="{{ $filterProjectStage }}">
                <div id="ordersProjectChip"
                     class="orders-project-chip{{ $showProjectChip ? '' : ' d-none' }}"
                     data-project-id="{{ $filterProject?->id }}"
                     data-project-name="{{ $filterProject?->project_name }}">
                    <span id="ordersProjectChipLabel">
                        @if($filterProject)
                            Project: {{ $filterProject->project_name }}@if($projectStageLabel !== '' && $filterProjectStage !== '') · {{ $projectStageLabel }}@endif
                        @else
                            Project
                        @endif
                    </span>
                    <button type="button" id="ordersProjectChipClear" class="orders-project-chip__clear" aria-label="Clear project filter">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div id="ordersSearchHint" class="form-text orders-search-hint">Results update as you type.</div>
                <div id="ordersSearchStatus" class="form-text orders-search-status" role="status" aria-live="polite"></div>
            </form>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card border-0 shadow-sm" id="ordersResultsCard">
        <div class="card-header bg-white orders-history-head">
            <div class="orders-history-head__title">
                <span class="fw-semibold"><i class="fa fa-shopping-bag me-2" aria-hidden="true"></i>Order History</span>
                <span id="ordersAttentionChip" class="badge rounded-pill text-bg-light border orders-attention-chip{{ in_array(search_text(request('sort')), ['date_desc', 'date_asc', 'total_desc'], true) ? ' d-none' : '' }}">Needs attention first</span>
                <span id="ordersSearchBusy" class="orders-search-busy d-none text-muted small" aria-hidden="true">
                    <i class="fa fa-spinner fa-spin me-1"></i>Searching…
                </span>
            </div>
            <div class="orders-history-head__meta">
                <label class="small text-muted mb-0" for="ordersSort">Sort</label>
                <div class="orders-sort-select">
                    @include('advertiser.partials.library-theme-select', [
                        'selectId' => 'ordersSort',
                        'name' => 'sort',
                        'label' => 'Sort orders',
                        'current' => in_array(search_text(request('sort')), ['date_desc', 'date_asc', 'total_desc'], true)
                            ? search_text(request('sort'))
                            : 'attention',
                        'options' => [
                            ['value' => 'attention', 'label' => 'Needs attention first'],
                            ['value' => 'date_desc', 'label' => 'Newest first'],
                            ['value' => 'date_asc', 'label' => 'Oldest first'],
                            ['value' => 'total_desc', 'label' => 'Highest total'],
                        ],
                    ])
                </div>
                <small class="text-muted" id="resultsCount"></small>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 data-table orders-history-table">
                    <thead class="table-light">
                        <tr>
                            <th class="orders-col-id">Order #</th>
                            <th class="orders-col-site">Site</th>
                            <th class="orders-col-date">Date</th>
                            <th class="orders-col-total">Total</th>
                            <th class="orders-col-payment">Payment</th>
                            <th class="orders-col-status">Status</th>
                            <th class="orders-col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="text-muted">Loading orders...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination — catalog chrome is painted by renderPagination() -->
    <div id="paginationNav"></div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl order-details-dialog">
        <div class="modal-content order-details-content">
            <div class="modal-header py-2">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body order-details-body">
                <div id="orderDetailsContent">
                    <!-- Dynamic content will be inserted here -->
                </div>
            </div>
            <div class="modal-footer py-2 flex-wrap gap-2" id="orderDetailsFooter">
                <div id="orderDetailsActions" class="d-flex flex-wrap gap-2 me-auto"></div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Request changes Modal -->
<div class="modal fade" id="modificationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request changes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modificationOrderId">
                <div class="mb-3">
                    <label for="modificationReason" class="form-label">What needs to change? <span class="text-danger">*</span></label>
                    <textarea id="modificationReason" class="form-control" rows="4" placeholder="Explain the fixes needed on the live post…" minlength="10"></textarea>
                    <small class="text-muted mt-2 d-block">At least 10 characters. The publisher will see this reason, update the post, and resubmit the live URL. Auto-approve pauses until they resubmit.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmModification">Send change request</button>
            </div>
        </div>
    </div>
</div>

@include('partials.order-chat-modal')
@endsection

@push('scripts')
<script>
window.AdvertiserOrdersConfig = {
    csrfToken: @json(csrf_token()),
    projectName: @json($filterProject?->project_name),
    projectStageLabels: @json(\App\Models\Project::STAGE_LABELS),
    routes: {
        // Relative paths avoid APP_URL host mismatches (Hostinger) breaking live search fetch.
        list: @json(route('advertiser.orders.list', absolute: false)),
        statistics: @json(route('advertiser.orders.statistics', absolute: false)),
        ordersBase: @json(parse_url(url('advertiser/orders'), PHP_URL_PATH) ?: '/advertiser/orders'),
        ratingsBatch: @json(route('advertiser.ratings.batch', absolute: false)),
        orderTimelineBase: @json(parse_url(url('/notifications/order'), PHP_URL_PATH) ?: '/notifications/order'),
        catalog: @json(route('advertiser.catalog', absolute: false)),
        contentLibrary: @json(route('advertiser.content-library', absolute: false)),
        refundPolicy: @json(route('refund-policy', absolute: false)),
    },
};
</script>
<script src="{{ asset('assets/js/single-select.js') }}?v={{ @filemtime(public_path('assets/js/single-select.js')) ?: '1' }}" defer></script>
<script src="{{ same_origin_asset('assets/js/advertiser-orders.js') }}?v={{ @filemtime(public_path('assets/js/advertiser-orders.js')) ?: '1' }}" defer></script>
@endpush
