@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">My Orders</h2>
            <p class="text-muted mb-0">
                Track each order from payment to live publication.
            </p>
        </div>
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
                    <span class="kpi-desc">Approved &amp; live</span>
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
            <form method="GET" action="{{ route('advertiser.orders') }}" id="filterForm">
                <div class="row g-2 g-md-3 align-items-end">
                    <!-- Search -->
                    <div class="col-12 col-sm-6 col-xl-3">
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
                                   value="{{ scalar_text(request('search')) }}">
                            <button type="button"
                                    id="ordersSearchClear"
                                    class="btn btn-sm btn-link orders-search-clear slb-search-clear d-none"
                                    aria-label="Clear search">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div id="ordersSearchHint" class="form-text orders-search-hint">Results update as you type.</div>
                        <div id="ordersSearchStatus" class="form-text orders-search-status" role="status" aria-live="polite"></div>
                    </div>

                    <!-- Status Filter -->
                    <div class="col-6 col-sm-6 col-xl-2">
                        <label class="form-label fw-semibold small text-muted mb-1">Order Status</label>
                        <select name="status" id="statusFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="awaiting_payment" {{ request('status') == 'awaiting_payment' ? 'selected' : '' }}>Awaiting payment</option>
                            <option value="awaiting_publisher" {{ request('status') == 'awaiting_publisher' ? 'selected' : '' }}>Awaiting publisher</option>
                            <option value="in_progress" {{ request('status') == 'in_progress' ? 'selected' : '' }}>In progress</option>
                            <option value="needs_action" {{ request('status') == 'needs_action' ? 'selected' : '' }}>Needs your attention</option>
                            <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Publisher working</option>
                            <option value="review" {{ request('status') == 'review' ? 'selected' : '' }}>Needs your review</option>
                            <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                            <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </div>

                    <!-- Payment Method & Status Filter (Combined) -->
                    <div class="col-6 col-sm-6 col-xl-2">
                        <label class="form-label fw-semibold small text-muted mb-1">Payment Method</label>
                        <select name="payment_method" id="paymentMethodFilter" class="form-select form-select-sm">
                            <option value="">All Methods</option>
                            <option value="wallet" {{ request('payment_method') == 'wallet' ? 'selected' : '' }}>Wallet Balance</option>
                            <option value="wise" {{ request('payment_method') == 'wise' ? 'selected' : '' }}>Wise Transfer</option>
                            <option value="crypto" {{ request('payment_method') == 'crypto' ? 'selected' : '' }}>Cryptocurrency</option>
                            <option value="bank" {{ request('payment_method') == 'bank' ? 'selected' : '' }}>Bank Transfer</option>
                            <option value="card" {{ request('payment_method') == 'card' ? 'selected' : '' }}>Card Payment</option>
                        </select>
                    </div>

                    <div class="col-6 col-sm-6 col-xl-2">
                        <label class="form-label fw-semibold small text-muted mb-1">Payment Status</label>
                        <select name="payment_status" id="paymentStatusFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="paid" {{ request('payment_status') == 'paid' ? 'selected' : '' }}>Paid</option>
                            <option value="pending" {{ request('payment_status') == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="failed" {{ request('payment_status') == 'failed' ? 'selected' : '' }}>Failed</option>
                            <option value="refunded" {{ request('payment_status') == 'refunded' ? 'selected' : '' }}>Refunded</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="col-12 col-sm-8 col-xl-3">
                        <label class="form-label fw-semibold small text-muted mb-1">Date Range</label>
                        <div class="d-flex gap-2 orders-date-range">
                            <input type="date" 
                                   name="date_from" 
                                   id="dateFrom"
                                   class="form-control form-control-sm" 
                                   placeholder="From"
                                   value="{{ scalar_text(request('date_from')) }}">
                            <input type="date" 
                                   name="date_to" 
                                   id="dateTo"
                                   class="form-control form-control-sm" 
                                   placeholder="To"
                                   value="{{ scalar_text(request('date_to')) }}">
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-sm-4 col-xl-2">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-sm btn-primary px-3">
                                <i class="fa-solid fa-filter me-1"></i> Filter
                            </button>
                            <button type="button" id="resetFilters" class="btn btn-sm btn-cta-secondary px-3">
                                <i class="fa-solid fa-rotate-right me-1"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card border-0 shadow-sm" id="ordersResultsCard">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span><i class="fa fa-shopping-bag me-2"></i> Order History</span>
                <span id="ordersSearchBusy" class="orders-search-busy d-none text-muted small" aria-hidden="true">
                    <i class="fa fa-spinner fa-spin me-1"></i>Searching…
                </span>
            </div>
            <div>
                <small class="text-muted" id="resultsCount"></small>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 data-table">
                    <thead class="table-light">
                        <tr>
                            <th>Order ID</th>
                            <th>Site</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th width="180">Actions</th>
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

<link rel="stylesheet" href="{{ asset('assets/css/advertiser-orders.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-orders.css')) ?: '1' }}">
@endsection

@push('scripts')
<script>
window.AdvertiserOrdersConfig = {
    csrfToken: @json(csrf_token()),
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
<script src="{{ asset('assets/js/advertiser-orders.js') }}?v={{ @filemtime(public_path('assets/js/advertiser-orders.js')) ?: '1' }}" defer></script>
@endpush
