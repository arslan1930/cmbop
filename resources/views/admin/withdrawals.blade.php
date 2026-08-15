@extends('admin.layouts.app')

@section('content')
@php
    $platformChargePercent = (float) config('billing.withdrawal_fee_percent', 0);
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Payout queue</h4>
            <p class="text-muted mb-0 small">Pay publishers outside the app, then mark them paid here. Oldest requests first.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.finance') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chart-pie me-1"></i> Finance overview
            </a>
            <button type="button" id="exportCsvBtn" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-file-csv me-1"></i> <span id="exportCsvLabel">Export CSV</span>
            </button>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="small text-muted">Stats</span>
        <div class="btn-group btn-group-sm" role="group" aria-label="Stats scope">
            <button type="button" class="btn btn-outline-secondary active" id="statsScopeAll" data-scope="all">All open</button>
            <button type="button" class="btn btn-outline-secondary" id="statsScopeView" data-scope="view">This view</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-3" id="statsRow">
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small" id="statPendingLabel">All open · Pending</div>
                    <div class="fs-4 fw-bold text-warning" id="statPending">—</div>
                    <div class="small text-muted" id="statPendingAmount">€—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small" id="statProcessingLabel">All open · Processing</div>
                    <div class="fs-4 fw-bold text-info" id="statProcessing">—</div>
                    <div class="small text-muted" id="statProcessingAmount">€—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small" id="statToPayLabel">All open to pay</div>
                    <div class="fs-4 fw-bold text-danger" id="statToPay">€—</div>
                    <div class="small text-muted" id="statToPayHint">All open net</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Paid this week</div>
                    <div class="fs-4 fw-bold text-success" id="statWeek">—</div>
                    <div class="small text-muted" id="statWeekAmount">€—</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-1" id="statByMethodLabel">All open by method</div>
                    <div id="statByMethod" class="small text-muted">Loading…</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="queueFilter">Queue</label>
                    <select id="queueFilter" class="form-select form-select-sm">
                        <option value="open" selected>Open (pay these)</option>
                        <option value="history">History</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="statusFilter">Status</label>
                    <select id="statusFilter" class="form-select form-select-sm">
                        <option value="">Any in queue</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed / Paid</option>
                        <option value="cancelled">Cancelled / Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="paymentMethodFilter">Payment Method</label>
                    <select id="paymentMethodFilter" class="form-select form-select-sm">
                        <option value="">All Methods</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="paypal">PayPal</option>
                        <option value="wise">Wise</option>
                        <option value="crypto">Cryptocurrency</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="dateFrom">Date Range</label>
                    <div class="d-flex gap-2">
                        <input type="date" id="dateFrom" class="form-control form-control-sm" aria-label="Requested from date">
                        <label class="visually-hidden" for="dateTo">Requested to date</label>
                        <input type="date" id="dateTo" class="form-control form-control-sm" aria-label="Requested to date">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="searchInput">Search</label>
                    <div class="slb-search-wrap">
                        <input type="search"
                               id="searchInput"
                               class="form-control form-control-sm"
                               placeholder="Name, email, or #ID"
                               title="Results update as you type"
                               autocomplete="off"
                               enterkeyhint="search"
                               aria-describedby="adminWithdrawalsSearchStatus">
                        <button type="button" id="adminWithdrawalsSearchClear" class="btn btn-sm btn-link slb-search-clear d-none" aria-label="Clear search">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div id="adminWithdrawalsSearchStatus" class="form-text slb-search-status" role="status" aria-live="polite"></div>
                </div>
            </div>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="button" id="filterBtn" class="btn btn-primary btn-sm px-3">
                    <i class="fa fa-search"></i> Filter
                </button>
                <button type="button" id="resetFiltersBtn" class="btn btn-secondary btn-sm px-3">
                    <i class="fa fa-undo"></i> Reset
                </button>
            </div>
        </div>
    </div>

    <!-- Batch bar -->
    <div id="batchBar" class="card border-0 shadow-sm mb-3 d-none">
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
            <span class="small fw-semibold" id="batchCount">0 selected</span>
            <button type="button" class="btn btn-sm btn-outline-info" id="batchProcessingBtn">
                <i class="fa fa-spinner me-1"></i> Mark processing
            </button>
            <button type="button" class="btn btn-sm btn-success" id="batchPaidBtn">
                <i class="fa fa-check me-1"></i> Mark paid
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="batchRejectBtn">
                <i class="fa fa-times me-1"></i> Reject &amp; refund
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" id="batchExportBtn">
                <i class="fa fa-file-csv me-1"></i> Export selected
            </button>
            <button type="button" class="btn btn-sm btn-link text-muted ms-auto" id="clearSelectionBtn">Clear</button>
        </div>
    </div>

    <!-- Table -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span>Withdrawal requests
                @if($platformChargePercent > 0)
                    <span class="text-muted fw-normal small">(fee {{ rtrim(rtrim(number_format($platformChargePercent, 2, '.', ''), '0'), '.') }}%)</span>
                @endif
            </span>
            <button type="button" id="selectMatchingBtn" class="btn btn-sm btn-outline-secondary" title="Select up to 100 matching open withdrawals">
                Select all matching
            </button>
        </div>

        <div class="table-responsive admin-table-fit">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="admin-num-col">
                            <label class="visually-hidden" for="selectAll">Select all on this page</label>
                            <input type="checkbox" class="form-check-input" id="selectAll" title="Select all on this page">
                        </th>
                        <th class="admin-num-col">#</th>
                        <th>Publisher</th>
                        <th class="admin-narrow-col">Waiting</th>
                        <th class="admin-narrow-col">Net pay</th>
                        <th class="admin-narrow-col">Method</th>
                        <th>Destination</th>
                        <th class="admin-status-col">Status</th>
                        <th class="admin-narrow-col">Requested</th>
                        <th class="admin-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody id="withdrawalsTable">
                    <tr>
                        <td colspan="10" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="p-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div id="paginationInfo" class="text-muted small"></div>
            <div id="paginationLinks"></div>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fa fa-info-circle me-2"></i>Withdrawal Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="detailsContent"></div>
            <div class="modal-footer flex-wrap gap-2">
                <a href="#" id="openPublisherLink" class="btn btn-outline-secondary btn-sm me-auto d-none" target="_blank">
                    <i class="fa fa-user me-1"></i> Open publisher / edit payout
                </a>
                <a href="#" id="openShowPageLink" class="btn btn-outline-secondary btn-sm">
                    <i class="fa fa-external-link-alt me-1"></i> Open page
                </a>
                <a href="#" id="openInvoiceLink" class="btn btn-outline-secondary btn-sm d-none">
                    <i class="fa fa-file-invoice-dollar me-1"></i> Open invoice
                </a>
                <button type="button" class="btn btn-outline-primary btn-sm" id="copyDetailsBtn">
                    <i class="fa fa-copy me-1"></i> Copy payout details
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let currentPage = 1;
let selectedIds = new Set();
let lastDetailsCopyText = '';
let statsScope = 'all';
let appliedFilters = {};
const withdrawalFlags = new Map();
const duplicateLookbackDays = {{ max(1, (int) config('billing.withdrawal_mark_paid_duplicate_lookback_days', 30)) }};

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
    || '{{ csrf_token() }}';

const WD_DATA = @json(route('admin.withdrawals.data'));
const WD_STATS = @json(route('admin.withdrawals.statistics'));
const WD_IDS = @json(route('admin.withdrawals.ids'));
const WD_EXPORT = @json(route('admin.withdrawals.export'));
const WD_SHOW = @json(route('admin.withdrawals.show', ['id' => '__ID__']));
const WD_PAID = @json(route('admin.withdrawals.paid', ['id' => '__ID__']));
const WD_PROCESSING = @json(route('admin.withdrawals.processing', ['id' => '__ID__']));
const WD_REJECT = @json(route('admin.withdrawals.reject', ['id' => '__ID__']));
const WD_BATCH = @json(route('admin.withdrawals.batch'));
const FINANCE_USER = @json(route('admin.finance.user', ['user' => '__ID__']));

function withdrawalUrl(template, id) {
    return String(template).replace('__ID__', encodeURIComponent(id));
}

function applySelectIfAllowed(selector, value) {
    if (!value) return;
    const $el = $(selector);
    if ($el.find('option').filter(function () { return this.value === value; }).length) {
        $el.val(value);
    }
}

function applyDateIfValid(selector, value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return;
    $(selector).val(value);
}

function resetSelection() {
    selectedIds.clear();
    $('.row-select').prop('checked', false);
    $('#selectAll').prop('checked', false);
    updateBatchBar();
}

function snapshotFilters() {
    appliedFilters = Object.assign({}, filterParams());
}

function viewFilterParams() {
    return Object.keys(appliedFilters).length ? Object.assign({}, appliedFilters) : filterParams();
}

function syncFiltersToUrl() {
    const params = new URLSearchParams();
    const data = viewFilterParams();
    Object.keys(data).forEach(function (key) {
        if (key === 'page') return;
        const value = data[key];
        if (value == null || String(value).trim() === '') return;
        if (key === 'queue' && value === 'open') return;
        params.set(key, value);
    });
    const qs = params.toString();
    const next = qs ? (window.location.pathname + '?' + qs) : window.location.pathname;
    if (window.location.pathname + window.location.search !== next) {
        history.replaceState({}, '', next);
    }
}

function toast(msg, icon = 'success') {
    showAppToast(msg, icon);
}

function escapeHtml(str) {
    if (str == null || str === '') return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function getStatusClass(status) {
    return ({
        pending: 'status-pending',
        processing: 'status-processing',
        completed: 'status-completed',
        cancelled: 'status-cancelled'
    })[status] || 'status-pending';
}

function getPaymentMethodBadge(method) {
    const badges = {
        bank: '<span class="badge bg-secondary bg-opacity-10 text-secondary px-2 py-1"><i class="fa fa-university me-1"></i>Bank</span>',
        paypal: '<span class="badge bg-primary bg-opacity-10 text-primary px-2 py-1"><i class="fab fa-paypal me-1"></i>PayPal</span>',
        wise: '<span class="badge bg-info bg-opacity-10 text-info px-2 py-1"><i class="fa fa-exchange-alt me-1"></i>Wise</span>',
        crypto: '<span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1"><i class="fab fa-bitcoin me-1"></i>Crypto</span>'
    };
    return badges[method] || '<span class="badge bg-secondary">' + escapeHtml(method) + '</span>';
}

function adminStatusLabel(status) {
    return ({
        pending: 'Pending',
        processing: 'Processing',
        completed: 'Paid',
        cancelled: 'Rejected'
    })[status] || capitalize(status);
}

function waitingLabel(days) {
    if (days == null) return '<span class="text-muted">—</span>';
    const n = parseInt(days, 10);
    if (n <= 0) return '<span class="text-muted">Today</span>';
    const cls = n >= 3 ? 'waiting-urgent' : 'text-muted';
    return `<span class="${cls}">${n}d</span>`;
}

function filterParams() {
    const params = {
        page: currentPage,
        search: $('#searchInput').val(),
        payment_method: $('#paymentMethodFilter').val(),
        date_from: $('#dateFrom').val(),
        date_to: $('#dateTo').val(),
    };
    const status = $('#statusFilter').val();
    const queue = $('#queueFilter').val();
    if (status) {
        params.status = status;
    } else if (queue === 'all') {
        params.queue = 'all';
    } else {
        params.queue = queue || 'open';
    }
    return params;
}

function statsPrefix() {
    return statsScope === 'view' ? 'This view' : 'All open';
}

function applyStatsLabels() {
    const prefix = statsPrefix();
    $('#statPendingLabel').text(prefix + ' · Pending');
    $('#statProcessingLabel').text(prefix + ' · Processing');
    $('#statToPayLabel').text(prefix + ' to pay');
    $('#statToPayHint').text(prefix + ' net');
    $('#statByMethodLabel').text(prefix + ' by method');
}

function isHistoryExport() {
    const applied = viewFilterParams();
    const status = applied.status || '';
    const queue = applied.queue || 'open';
    return status === 'completed' || status === 'cancelled' || (!status && queue === 'history');
}

function updateExportButtonLabel() {
    $('#exportCsvLabel').text(isHistoryExport() ? 'Export history CSV' : 'Export CSV');
}

function loadStatistics() {
    const params = statsScope === 'view' ? Object.assign({ scope: 'view' }, viewFilterParams()) : {};
    delete params.page;
    applyStatsLabels();
    $.getJSON(WD_STATS, params, function(response) {
        if (!response.success) return;
        const s = response.data;
        $('#statPending').text(s.pending);
        $('#statPendingAmount').text('€' + Number(s.pending_amount || 0).toFixed(2));
        $('#statProcessing').text(s.processing);
        $('#statProcessingAmount').text('€' + Number(s.processing_amount || 0).toFixed(2));
        $('#statToPay').text('€' + Number(s.total_to_pay || 0).toFixed(2));
        $('#statWeek').text(s.completed_this_week || 0);
        $('#statWeekAmount').text('€' + Number(s.completed_this_week_amount || 0).toFixed(2));

        const by = s.by_method || {};
        const labels = { bank: 'Bank', paypal: 'PayPal', wise: 'Wise', crypto: 'Crypto' };
        const parts = Object.keys(by).map(function(method) {
            const row = by[method];
            return `<span class="d-inline-block me-2 mb-1"><strong>${row.count}</strong> ${labels[method] || method} · €${Number(row.net_total).toFixed(0)}</span>`;
        });
        $('#statByMethod').html(parts.length ? parts.join('') : '<span class="text-muted">No open payouts</span>');
    });
}

function loadWithdrawals(page = 1) {
    currentPage = page;
    const params = Object.assign({}, viewFilterParams(), { page: page });
    appliedFilters.page = page;

    syncFiltersToUrl();
    updateExportButtonLabel();

    $.ajax({
        url: WD_DATA,
        method: 'GET',
        data: params,
        success: function(response) {
            if (response.success) {
                renderWithdrawals(response.data);
                renderAdminPagination(response.pagination, {
                    links: '#paginationLinks',
                    info: '#paginationInfo',
                    label: 'withdrawals',
                    onNavigate: loadWithdrawals,
                });
            } else {
                $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-danger py-5">' + escapeHtml(response.message || 'Failed to load') + '</td></tr>');
                $('#paginationInfo').text('');
                $('#paginationLinks').empty();
            }
        },
        error: function() {
            $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-danger py-5">Error loading withdrawals</td></tr>');
            $('#paginationInfo').text('');
            $('#paginationLinks').empty();
        }
    });
}

function renderWithdrawals(withdrawals) {
    if (!withdrawals || withdrawals.length === 0) {
        $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-muted py-5">No withdrawal requests in this view</td></tr>');
        updateBatchBar();
        return;
    }

    let html = '';
    withdrawals.forEach(function(w) {
        const actionable = w.status === 'pending' || w.status === 'processing';
        const checked = selectedIds.has(Number(w.id)) ? 'checked' : '';
        const copyEncoded = encodeURIComponent(w.destination_copy_text || '');
        const matchIds = Array.isArray(w.duplicate_match_ids) ? w.duplicate_match_ids : [];
        withdrawalFlags.set(Number(w.id), {
            possible_duplicate: !!w.possible_duplicate,
            duplicate_match_ids: matchIds,
            status: w.status,
        });

        html += `
            <tr data-id="${w.id}">
                <td>
                    ${actionable
                        ? `<input type="checkbox" class="form-check-input row-select" value="${w.id}" ${checked}>`
                        : ''}
                </td>
                <td class="text-muted small">WD-${w.id}</td>
                <td>
                    <div class="d-flex flex-column">
                        ${w.user?.id
                            ? `<a href="${escapeHtml(withdrawalUrl(FINANCE_USER, w.user.id))}" class="fw-semibold">${escapeHtml(w.user?.name || 'N/A')}</a>`
                            : `<span class="fw-semibold">${escapeHtml(w.user?.name || 'N/A')}</span>`}
                        <small class="text-muted">${escapeHtml(w.user?.email || '')}</small>
                    </div>
                </td>
                <td>${waitingLabel(w.waiting_days)}</td>
                <td>
                    <div class="fw-bold text-success">€${parseFloat(w.net_amount).toFixed(2)}</div>
                    <small class="text-muted">gross €${parseFloat(w.amount).toFixed(2)}</small>
                </td>
                <td>${getPaymentMethodBadge(w.payment_method)}</td>
                <td class="dest-cell">
                    <div class="small slb-text-break" title="${escapeHtml(w.destination_snippet || '')}">${escapeHtml(w.destination_snippet || '—')}</div>
                    <button type="button" class="btn btn-link btn-sm p-0 copy-dest" data-copy="${copyEncoded}">
                        <i class="fa fa-copy me-1"></i>Copy
                    </button>
                </td>
                <td><span class="status-badge ${getStatusClass(w.status)}">${adminStatusLabel(w.status)}</span></td>
                <td class="small">${formatDate(w.created_at)}</td>
                <td>
                    <div class="dropdown admin-manage-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Manage</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button type="button" class="dropdown-item view-details" data-id="${w.id}"><i class="fa fa-eye me-2"></i>View</button></li>
                            <li><a class="dropdown-item" href="${escapeHtml(withdrawalUrl(WD_SHOW, w.id))}"><i class="fa fa-external-link-alt me-2"></i>Open page</a></li>
                            ${w.invoice_url ? `<li><a class="dropdown-item" href="${escapeHtml(w.invoice_url)}"><i class="fa fa-file-invoice-dollar me-2"></i>Open invoice</a></li>` : ''}
                            ${w.status === 'pending' ? `
                            <li><button type="button" class="dropdown-item act-processing" data-id="${w.id}"
                                data-name="${escapeHtml(w.user?.name || '')}"
                                data-net="${parseFloat(w.net_amount).toFixed(2)}"
                                data-method="${escapeHtml(w.payment_method)}"><i class="fa fa-play me-2"></i>Start</button></li>` : ''}
                            ${actionable ? `
                            <li><hr class="dropdown-divider"></li>
                            <li><button type="button" class="dropdown-item act-paid" data-id="${w.id}"
                                data-name="${escapeHtml(w.user?.name || '')}"
                                data-net="${parseFloat(w.net_amount).toFixed(2)}"
                                data-method="${escapeHtml(w.payment_method)}"
                                data-status="${escapeHtml(w.status)}"
                                data-duplicate="${w.possible_duplicate ? '1' : '0'}"
                                data-duplicate-ids="${escapeHtml(matchIds.map(function (id) { return 'WD-' + id; }).join(', '))}"><i class="fa fa-check me-2"></i>Mark paid</button></li>
                            <li><button type="button" class="dropdown-item text-danger act-reject" data-id="${w.id}"
                                data-name="${escapeHtml(w.user?.name || '')}"
                                data-amount="${parseFloat(w.amount).toFixed(2)}"><i class="fa fa-times me-2"></i>Reject</button></li>` : ''}
                        </ul>
                    </div>
                </td>
            </tr>
        `;
    });

    $('#withdrawalsTable').html(html);
    updateBatchBar();
}

function updateBatchBar() {
    const n = selectedIds.size;
    const pageIds = new Set();
    $('.row-select').each(function () {
        pageIds.add(parseInt($(this).val(), 10));
    });
    let offPage = 0;
    selectedIds.forEach(function (id) {
        if (!pageIds.has(id)) offPage++;
    });
    if (n > 0) {
        $('#batchBar').removeClass('d-none');
        $('#batchCount').text(offPage > 0
            ? n + ' selected (including other pages)'
            : n + ' selected');
    } else {
        $('#batchBar').addClass('d-none');
    }
    const pageBoxes = $('.row-select');
    const allChecked = pageBoxes.length > 0 && pageBoxes.filter(':checked').length === pageBoxes.length;
    $('#selectAll').prop('checked', allChecked);
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast('Copied to clipboard');
    } catch (e) {
        toast('Could not copy', 'error');
    }
}

function postAction(url, body = {}) {
    return $.ajax({
        url: url,
        method: 'POST',
        contentType: 'application/json',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        data: JSON.stringify(body),
    });
}

function refreshAll() {
    loadStatistics();
    loadWithdrawals(currentPage);
}

async function confirmNotes(title, html, confirmText, confirmClass) {
    const result = await Swal.fire({
        title,
        html,
        input: 'textarea',
        inputLabel: 'Notes / payment reference (optional)',
        inputPlaceholder: 'e.g. Wise transfer #12345',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        customClass: { confirmButton: confirmClass || '' },
    });
    if (!result.isConfirmed) return null;
    return result.value || '';
}

// Row actions
$(document).on('click', '.act-processing', async function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const notes = await confirmNotes(
        'Start processing?',
        `Move <strong>${escapeHtml(name)}</strong> to processing.`,
        'Start processing',
        ''
    );
    if (notes === null) return;
    postAction(withdrawalUrl(WD_PROCESSING, id), { notes })
        .done(function(res) {
            toast(res.message || 'Updated');
            selectedIds.delete(id);
            refreshAll();
        })
        .fail(function(xhr) {
            toast(xhr.responseJSON?.message || 'Failed', 'error');
        });
});

function duplicateWarningHtml(matchRefs) {
    if (!matchRefs) return '';
    return `<br><span class="text-warning small">Same publisher was paid this net amount in the last ${duplicateLookbackDays} days (${escapeHtml(matchRefs)}). Confirm you are not paying twice.</span>`;
}

async function confirmPendingPayIfNeeded(ids) {
    const pendingCount = ids.filter(function (id) {
        const flag = withdrawalFlags.get(Number(id));
        if (flag && flag.status) {
            return flag.status === 'pending';
        }
        return $('.act-paid[data-id="' + id + '"]').attr('data-status') === 'pending';
    }).length;
    if (pendingCount === 0) return true;
    const result = await Swal.fire({
        title: 'Pay without processing?',
        html: pendingCount === 1
            ? 'You have not marked this processing. Pay anyway?'
            : 'You have not marked ' + pendingCount + ' of these processing. Pay anyway?',
        showCancelButton: true,
        confirmButtonText: 'Pay anyway',
        cancelButtonText: 'Cancel',
    });
    return result.isConfirmed;
}

$(document).on('click', '.act-paid', async function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const net = $(this).data('net');
    const method = $(this).data('method');
    const isDuplicate = $(this).attr('data-duplicate') === '1';
    const matchRefs = $(this).attr('data-duplicate-ids') || '';
    const notes = await confirmNotes(
        'Mark paid?',
        `Pay <strong>€${escapeHtml(String(net))}</strong> net to <strong>${escapeHtml(name)}</strong> via <strong>${escapeHtml(method)}</strong>?<br><span class="text-muted small">Only confirm after you sent the money outside the app.</span>${isDuplicate ? duplicateWarningHtml(matchRefs) : ''}`,
        'Yes, mark paid',
        ''
    );
    if (notes === null) return;
    if (!await confirmPendingPayIfNeeded([id])) return;
    postAction(withdrawalUrl(WD_PAID, id), { notes })
        .done(function(res) {
            toast(res.message || 'Marked paid');
            selectedIds.delete(id);
            refreshAll();
        })
        .fail(function(xhr) {
            toast(xhr.responseJSON?.message || 'Failed', 'error');
        });
});

$(document).on('click', '.act-reject', async function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const amount = $(this).data('amount');
    const notes = await confirmNotes(
        'Reject & refund?',
        `Reject withdrawal for <strong>${escapeHtml(name)}</strong> and refund <strong>€${escapeHtml(String(amount))}</strong> to their wallet.`,
        'Reject & refund',
        'slb-swal-danger'
    );
    if (notes === null) return;
    postAction(withdrawalUrl(WD_REJECT, id), { notes })
        .done(function(res) {
            toast(res.message || 'Rejected');
            selectedIds.delete(id);
            refreshAll();
        })
        .fail(function(xhr) {
            toast(xhr.responseJSON?.message || 'Failed', 'error');
        });
});

$(document).on('click', '.copy-dest', function() {
    const encoded = $(this).attr('data-copy') || '';
    try {
        copyText(decodeURIComponent(encoded));
    } catch (e) {
        toast('Could not copy', 'error');
    }
});

$(document).on('change', '.row-select', function() {
    const id = parseInt($(this).val(), 10);
    if ($(this).is(':checked')) selectedIds.add(id);
    else selectedIds.delete(id);
    updateBatchBar();
});

$('#selectAll').on('change', function() {
    const checked = $(this).is(':checked');
    $('.row-select').each(function() {
        const id = parseInt($(this).val(), 10);
        $(this).prop('checked', checked);
        if (checked) selectedIds.add(id);
        else selectedIds.delete(id);
    });
    updateBatchBar();
});

$('#clearSelectionBtn').on('click', function() {
    selectedIds.clear();
    $('.row-select').prop('checked', false);
    updateBatchBar();
});

function selectedDuplicateRefs() {
    const refs = [];
    selectedIds.forEach(function (id) {
        const flag = withdrawalFlags.get(Number(id));
        if (flag && flag.possible_duplicate) {
            refs.push('WD-' + id);
        }
    });
    return refs;
}

async function runBatch(action, title, confirmText, confirmClass, options) {
    if (selectedIds.size === 0) return;
    options = options || {};
    const confirmDuplicates = !!options.confirmDuplicates;
    const dupRefs = action === 'completed'
        ? (options.duplicateRefs && options.duplicateRefs.length ? options.duplicateRefs : selectedDuplicateRefs())
        : [];
    const warn = (action === 'completed' && (confirmDuplicates || dupRefs.length))
        ? duplicateWarningHtml(dupRefs.join(', ') || 'selected rows')
        : '';
    const notes = await confirmNotes(
        title,
        `Apply to <strong>${selectedIds.size}</strong> selected withdrawal(s).${warn}`,
        confirmText,
        confirmClass
    );
    if (notes === null) return;
    if (action === 'completed' && !options.skipPendingConfirm && !await confirmPendingPayIfNeeded(Array.from(selectedIds))) return;

    const payload = {
        ids: Array.from(selectedIds),
        action,
        notes,
    };
    if (action === 'completed' && (confirmDuplicates || dupRefs.length)) {
        payload.confirm_duplicates = 1;
    }

    postAction(WD_BATCH, payload).done(function(res) {
        toast(res.message + (res.payout_run_id ? ' · ' + res.payout_run_id : ''));
        selectedIds.clear();
        refreshAll();
    }).fail(function(xhr) {
        const body = xhr.responseJSON || {};
        if (action === 'completed' && xhr.status === 422 && body.needs_duplicate_confirm && !confirmDuplicates) {
            runBatch(action, 'Possible duplicate payout', confirmText, confirmClass, {
                confirmDuplicates: true,
                skipPendingConfirm: true,
                duplicateRefs: (body.duplicate_ids || []).map(function (id) { return 'WD-' + id; }),
            });
            return;
        }
        toast(body.message || 'Batch failed', 'error');
        refreshAll();
    });
}

$('#batchProcessingBtn').on('click', () => runBatch('processing', 'Mark selected processing?', 'Mark processing', ''));
$('#batchPaidBtn').on('click', () => runBatch('completed', 'Mark selected paid?', 'Mark paid', ''));
$('#batchRejectBtn').on('click', () => runBatch('cancelled', 'Reject selected & refund?', 'Reject & refund', 'slb-swal-danger'));

function buildExportUrl(extra = {}) {
    const params = new URLSearchParams();
    const filters = viewFilterParams();
    Object.keys(filters).forEach(function (key) {
        if (key === 'page') return;
        const value = filters[key];
        if (value != null && String(value).trim() !== '') {
            params.set(key, value);
        }
    });
    Object.keys(extra).forEach(k => {
        if (Array.isArray(extra[k])) {
            extra[k].forEach(v => params.append(k + '[]', v));
        } else if (extra[k] != null) {
            params.set(k, extra[k]);
        }
    });
    const qs = params.toString();
    return WD_EXPORT + (qs ? '?' + qs : '');
}

$('#exportCsvBtn').on('click', async function() {
    if (isHistoryExport()) {
        const result = await Swal.fire({
            title: 'Export history CSV?',
            text: 'This exports completed and cancelled withdrawals, not the open payout queue.',
            showCancelButton: true,
            confirmButtonText: 'Export',
            cancelButtonText: 'Cancel',
        });
        if (!result.isConfirmed) return;
    }
    window.location = buildExportUrl();
});

$('#selectMatchingBtn').on('click', function() {
    const params = viewFilterParams();
    delete params.page;
    $.getJSON(WD_IDS, params, function(res) {
        if (!res.success) {
            toast(res.message || 'Could not load matching ids', 'error');
            return;
        }
        selectedIds.clear();
        const pendingSet = new Set((res.pending_ids || []).map(Number));
        (res.ids || []).forEach(function (id) {
            selectedIds.add(Number(id));
            const existing = withdrawalFlags.get(Number(id)) || {};
            existing.status = pendingSet.has(Number(id)) ? 'pending' : (existing.status || 'processing');
            withdrawalFlags.set(Number(id), existing);
        });
        $('.row-select').each(function() {
            const id = parseInt($(this).val(), 10);
            $(this).prop('checked', selectedIds.has(id));
        });
        updateBatchBar();
        if (res.capped) {
            toast('Selected first ' + res.limit + ' of ' + res.total + ' matching (cap ' + res.limit + ')', 'info');
        } else {
            toast((res.ids || []).length + ' matching selected');
        }
    }).fail(function() {
        toast('Could not load matching ids', 'error');
    });
});

$('#statsScopeAll, #statsScopeView').on('click', function() {
    statsScope = $(this).attr('data-scope') === 'view' ? 'view' : 'all';
    $('#statsScopeAll, #statsScopeView').removeClass('active');
    $(this).addClass('active');
    loadStatistics();
});

$('#batchExportBtn').on('click', function() {
    if (selectedIds.size === 0) return;
    window.location = buildExportUrl({ ids: Array.from(selectedIds) });
});

// Details modal
$(document).on('click', '.view-details', function() {
    const id = $(this).data('id');
    $.getJSON(withdrawalUrl(WD_SHOW, id), function(response) {
        if (!response.success) {
            toast('Failed to load details', 'error');
            return;
        }
        renderDetails(response.data);
        $('#detailsModal').modal('show');
    }).fail(function() {
        toast('Failed to load details', 'error');
    });
});

function renderDetails(withdrawal) {
    const paymentDetails = withdrawal.payment_details || {};
    let paymentDetailsHtml = '';

    switch (withdrawal.payment_method) {
        case 'bank':
            paymentDetailsHtml = `
                <p class="mb-1"><strong>Bank Name:</strong> ${escapeHtml(paymentDetails.bank_name || 'N/A')}</p>
                <p class="mb-1"><strong>Account Holder:</strong> ${escapeHtml(paymentDetails.account_holder || 'N/A')}</p>
                <p class="mb-1"><strong>Account Number:</strong> ${escapeHtml(paymentDetails.account_number || 'N/A')}</p>
                <p class="mb-1"><strong>SWIFT Code:</strong> ${escapeHtml(paymentDetails.swift_code || 'N/A')}</p>
            `;
            break;
        case 'paypal':
        case 'wise':
            paymentDetailsHtml = `<p class="mb-1"><strong>Email:</strong> ${escapeHtml(paymentDetails.email || 'N/A')}</p>`;
            break;
        case 'crypto':
            paymentDetailsHtml = `
                <p class="mb-1"><strong>Cryptocurrency:</strong> ${escapeHtml(paymentDetails.crypto_type || 'N/A')}</p>
                <p class="mb-1"><strong>Wallet Address:</strong> ${escapeHtml(paymentDetails.wallet_address || 'N/A')}</p>
            `;
            break;
    }

    lastDetailsCopyText = withdrawal.destination_copy_text || '';
    const matchRefs = (Array.isArray(withdrawal.duplicate_match_ids) ? withdrawal.duplicate_match_ids : [])
        .map(function (id) { return 'WD-' + id; })
        .join(', ');
    const duplicateAlert = withdrawal.possible_duplicate
        ? `<div class="alert alert-warning" role="alert">${duplicateWarningHtml(matchRefs).replace(/^<br>/, '')}</div>`
        : '';

    const userId = withdrawal.user?.id;
    if (userId) {
        $('#openPublisherLink')
            .removeClass('d-none')
            .attr('href', withdrawalUrl(FINANCE_USER, userId));
    } else {
        $('#openPublisherLink').addClass('d-none');
    }

    if (withdrawal.invoice_url) {
        $('#openInvoiceLink')
            .removeClass('d-none')
            .attr('href', withdrawal.invoice_url);
    } else {
        $('#openInvoiceLink').addClass('d-none').attr('href', '#');
    }

    $('#openShowPageLink').attr('href', withdrawalUrl(WD_SHOW, withdrawal.id));

    $('#detailsContent').html(`
        ${duplicateAlert}
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Publisher</h6>
                    <p class="mb-1"><strong>Name:</strong> ${escapeHtml(withdrawal.user?.name || 'N/A')}</p>
                    <p class="mb-1"><strong>Email:</strong> ${escapeHtml(withdrawal.user?.email || 'N/A')}</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Request</h6>
                    <p class="mb-1"><strong>Reference:</strong> WD-${withdrawal.id}</p>
                    <p class="mb-1"><strong>Date:</strong> ${formatDate(withdrawal.created_at)}</p>
                    <p class="mb-1"><strong>Status:</strong> <span class="status-badge ${getStatusClass(withdrawal.status)}">${adminStatusLabel(withdrawal.status)}</span></p>
                    ${withdrawal.waiting_days != null ? `<p class="mb-1"><strong>Waiting:</strong> ${withdrawal.waiting_days}d</p>` : ''}
                </div>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Amounts</h6>
                    <p class="mb-1"><strong>Gross:</strong> €${parseFloat(withdrawal.amount).toFixed(2)}</p>
                    <p class="mb-1"><strong>Fee:</strong> €${parseFloat(withdrawal.fee).toFixed(2)}</p>
                    <p class="mb-1"><strong>Net to pay:</strong> <span class="text-success fw-bold">€${parseFloat(withdrawal.net_amount).toFixed(2)}</span></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Payout destination (${escapeHtml(withdrawal.payment_method)})</h6>
                    ${paymentDetailsHtml}
                </div>
            </div>
        </div>
        ${withdrawal.admin_notes ? `<div class="alert alert-secondary mb-0"><strong>Admin notes:</strong> ${escapeHtml(withdrawal.admin_notes)}</div>` : ''}
    `);
}

$('#copyDetailsBtn').on('click', function() {
    if (lastDetailsCopyText) copyText(lastDetailsCopyText);
});

function reloadFilteredView() {
    currentPage = 1;
    snapshotFilters();
    loadStatistics();
    loadWithdrawals(1);
}

$('#filterBtn').on('click', function () {
    resetSelection();
    reloadFilteredView();
});
$('#resetFiltersBtn').on('click', function() {
    $('#queueFilter').val('open');
    $('#statusFilter').val('');
    $('#paymentMethodFilter').val('');
    $('#dateFrom').val('');
    $('#dateTo').val('');
    $('#searchInput').val('');
    withdrawalFlags.clear();
    resetSelection();
    reloadFilteredView();
});

$('#queueFilter').on('change', function() {
    if ($(this).val() === 'open') $('#statusFilter').val('');
    resetSelection();
    reloadFilteredView();
});

document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.SlbLiveSearch !== 'undefined') {
        window.SlbLiveSearch.init(document.getElementById('searchInput'), {
            mode: 'event',
            statusEl: document.getElementById('adminWithdrawalsSearchStatus'),
            clearBtn: document.getElementById('adminWithdrawalsSearchClear'),
            onSearch: function () { resetSelection(); reloadFilteredView(); },
        });
        return;
    }
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) { resetSelection(); reloadFilteredView(); }
    });
});

// Deep-link query support (?status=completed&queue=history)
(function initFromQuery() {
    const q = new URLSearchParams(window.location.search);
    applySelectIfAllowed('#queueFilter', q.get('queue'));
    applySelectIfAllowed('#statusFilter', q.get('status'));
    applySelectIfAllowed('#paymentMethodFilter', q.get('payment_method'));
    if (q.get('search')) $('#searchInput').val(q.get('search'));
    applyDateIfValid('#dateFrom', q.get('date_from'));
    applyDateIfValid('#dateTo', q.get('date_to'));
})();

snapshotFilters();
loadStatistics();
loadWithdrawals(1);
</script>
@endsection
