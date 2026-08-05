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
                <i class="fa fa-file-csv me-1"></i> Export CSV
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-3" id="statsRow">
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Pending</div>
                    <div class="fs-4 fw-bold text-warning" id="statPending">—</div>
                    <div class="small text-muted" id="statPendingAmount">€—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Processing</div>
                    <div class="fs-4 fw-bold text-info" id="statProcessing">—</div>
                    <div class="small text-muted" id="statProcessingAmount">€—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Total to pay</div>
                    <div class="fs-4 fw-bold text-danger" id="statToPay">€—</div>
                    <div class="small text-muted">Open queue net</div>
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
                    <div class="text-muted small mb-1">Open by method</div>
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
                    <label class="form-label fw-semibold small text-muted">Queue</label>
                    <select id="queueFilter" class="form-select form-select-sm">
                        <option value="open" selected>Open (pay these)</option>
                        <option value="history">History</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Status</label>
                    <select id="statusFilter" class="form-select form-select-sm">
                        <option value="">Any in queue</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed / Paid</option>
                        <option value="cancelled">Cancelled / Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Payment Method</label>
                    <select id="paymentMethodFilter" class="form-select form-select-sm">
                        <option value="">All Methods</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="paypal">PayPal</option>
                        <option value="wise">Wise</option>
                        <option value="crypto">Cryptocurrency</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Date Range</label>
                    <div class="d-flex gap-2">
                        <input type="date" id="dateFrom" class="form-control form-control-sm">
                        <input type="date" id="dateTo" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Search</label>
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Name, email, or #ID">
                </div>
            </div>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <button id="filterBtn" class="btn btn-primary btn-sm px-3">
                    <i class="fa fa-search"></i> Filter
                </button>
                <button id="resetFiltersBtn" class="btn btn-secondary btn-sm px-3">
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
        </div>

        <div class="table-responsive admin-table-fit">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="admin-num-col">
                            <input type="checkbox" class="form-check-input" id="selectAll" title="Select all on page">
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

        <div class="p-2">
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
                <button type="button" class="btn btn-outline-primary btn-sm" id="copyDetailsBtn">
                    <i class="fa fa-copy me-1"></i> Copy payout details
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}
.status-pending { background-color: #fef3c7; color: #d97706; }
.status-processing { background-color: #dbeafe; color: #2563eb; }
.status-completed { background-color: #dcfce7; color: #16a34a; }
.status-cancelled { background-color: #fee2e2; color: #dc2626; }
.waiting-urgent { color: #dc2626; font-weight: 600; }
.dest-cell { max-width: 100%; overflow-wrap: anywhere; word-break: break-word; }
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let currentPage = 1;
let selectedIds = new Set();
let lastDetailsCopyText = '';

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
    || '{{ csrf_token() }}';

function toast(msg, icon = 'success') {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: icon,
        title: msg,
        showConfirmButton: false,
        timer: 2200
    });
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

function loadStatistics() {
    $.getJSON('/admin/withdrawals/statistics', function(response) {
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
    const params = filterParams();
    params.page = page;

    $.ajax({
        url: '/admin/withdrawals/data',
        method: 'GET',
        data: params,
        success: function(response) {
            if (response.success) {
                renderWithdrawals(response.data);
                renderPagination(response.pagination);
            } else {
                $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-danger py-5">' + escapeHtml(response.message || 'Failed to load') + '</td></tr>');
            }
        },
        error: function() {
            $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-danger py-5">Error loading withdrawals</td></tr>');
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
        const checked = selectedIds.has(w.id) ? 'checked' : '';
        const copyEncoded = encodeURIComponent(w.destination_copy_text || '');

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
                        <span class="fw-semibold">${escapeHtml(w.user?.name || 'N/A')}</span>
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
                                data-method="${escapeHtml(w.payment_method)}"><i class="fa fa-check me-2"></i>Mark paid</button></li>
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

function renderPagination(pagination) {
    if (!pagination || pagination.last_page <= 1) {
        $('#paginationLinks').html('');
        return;
    }

    let paginationHtml = '<nav><ul class="pagination justify-content-center mb-0">';

    if (pagination.current_page > 1) {
        paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page - 1}">Previous</button></li>`;
    } else {
        paginationHtml += `<li class="page-item disabled"><span class="page-link">Previous</span></li>`;
    }

    for (let i = 1; i <= pagination.last_page; i++) {
        if (i === pagination.current_page) {
            paginationHtml += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
        } else if (i >= pagination.current_page - 2 && i <= pagination.current_page + 2) {
            paginationHtml += `<li class="page-item"><button class="page-link" data-page="${i}">${i}</button></li>`;
        }
    }

    if (pagination.current_page < pagination.last_page) {
        paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page + 1}">Next</button></li>`;
    } else {
        paginationHtml += `<li class="page-item disabled"><span class="page-link">Next</span></li>`;
    }

    paginationHtml += '</ul></nav>';
    $('#paginationLinks').html(paginationHtml);

    $('.page-link').off('click').on('click', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        if (page) loadWithdrawals(page);
    });
}

function updateBatchBar() {
    const n = selectedIds.size;
    if (n > 0) {
        $('#batchBar').removeClass('d-none');
        $('#batchCount').text(n + ' selected');
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
    postAction(`/admin/withdrawals/${id}/processing`, { notes })
        .done(function(res) {
            toast(res.message || 'Updated');
            selectedIds.delete(id);
            refreshAll();
        })
        .fail(function(xhr) {
            toast(xhr.responseJSON?.message || 'Failed', 'error');
        });
});

$(document).on('click', '.act-paid', async function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const net = $(this).data('net');
    const method = $(this).data('method');
    const notes = await confirmNotes(
        'Mark paid?',
        `Pay <strong>€${escapeHtml(String(net))}</strong> net to <strong>${escapeHtml(name)}</strong> via <strong>${escapeHtml(method)}</strong>?<br><span class="text-muted small">Only confirm after you sent the money outside the app.</span>`,
        'Yes, mark paid',
        ''
    );
    if (notes === null) return;
    postAction(`/admin/withdrawals/${id}/paid`, { notes })
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
    postAction(`/admin/withdrawals/${id}/reject`, { notes })
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

async function runBatch(action, title, confirmText, confirmClass) {
    if (selectedIds.size === 0) return;
    const notes = await confirmNotes(
        title,
        `Apply to <strong>${selectedIds.size}</strong> selected withdrawal(s).`,
        confirmText,
        confirmClass
    );
    if (notes === null) return;

    postAction('/admin/withdrawals/batch', {
        ids: Array.from(selectedIds),
        action,
        notes,
    }).done(function(res) {
        toast(res.message + (res.payout_run_id ? ' · ' + res.payout_run_id : ''));
        selectedIds.clear();
        refreshAll();
    }).fail(function(xhr) {
        toast(xhr.responseJSON?.message || 'Batch failed', 'error');
        refreshAll();
    });
}

$('#batchProcessingBtn').on('click', () => runBatch('processing', 'Mark selected processing?', 'Mark processing', ''));
$('#batchPaidBtn').on('click', () => runBatch('completed', 'Mark selected paid?', 'Mark paid', ''));
$('#batchRejectBtn').on('click', () => runBatch('cancelled', 'Reject selected & refund?', 'Reject & refund', 'slb-swal-danger'));

function buildExportUrl(extra = {}) {
    const params = new URLSearchParams();
    const status = $('#statusFilter').val();
    const method = $('#paymentMethodFilter').val();
    if (status) params.set('status', status);
    if (method) params.set('payment_method', method);
    Object.keys(extra).forEach(k => {
        if (Array.isArray(extra[k])) {
            extra[k].forEach(v => params.append(k + '[]', v));
        } else if (extra[k] != null) {
            params.set(k, extra[k]);
        }
    });
    return '/admin/withdrawals/export?' + params.toString();
}

$('#exportCsvBtn').on('click', function() {
    window.location = buildExportUrl();
});

$('#batchExportBtn').on('click', function() {
    if (selectedIds.size === 0) return;
    window.location = buildExportUrl({ ids: Array.from(selectedIds) });
});

// Details modal
$(document).on('click', '.view-details', function() {
    const id = $(this).data('id');
    $.getJSON(`/admin/withdrawals/${id}`, function(response) {
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

    const userId = withdrawal.user?.id;
    if (userId) {
        $('#openPublisherLink')
            .removeClass('d-none')
            .attr('href', `/admin/finance/users/${userId}`);
    } else {
        $('#openPublisherLink').addClass('d-none');
    }

    $('#detailsContent').html(`
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

$('#filterBtn').on('click', () => loadWithdrawals(1));
$('#resetFiltersBtn').on('click', function() {
    $('#queueFilter').val('open');
    $('#statusFilter').val('');
    $('#paymentMethodFilter').val('');
    $('#dateFrom').val('');
    $('#dateTo').val('');
    $('#searchInput').val('');
    selectedIds.clear();
    loadWithdrawals(1);
});

$('#queueFilter').on('change', function() {
    if ($(this).val() === 'open') $('#statusFilter').val('');
    loadWithdrawals(1);
});

$('#searchInput').on('keypress', function(e) {
    if (e.which === 13) loadWithdrawals(1);
});

// Deep-link query support (?status=completed&queue=history)
(function initFromQuery() {
    const q = new URLSearchParams(window.location.search);
    if (q.get('queue')) $('#queueFilter').val(q.get('queue'));
    if (q.get('status')) $('#statusFilter').val(q.get('status'));
    if (q.get('payment_method')) $('#paymentMethodFilter').val(q.get('payment_method'));
})();

loadStatistics();
loadWithdrawals(1);
</script>
@endsection
