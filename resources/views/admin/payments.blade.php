@extends('admin.layouts.app')

@section('title', 'Payments')

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Payments Management',
        'subtitle' => 'Confirm Wise / bank / crypto, fail unpaid checkouts, or refund in-flight paid orders to the advertiser wallet',
        'actionUrl' => route('admin.orders.index', absolute: false),
        'actionLabel' => 'Orders console',
        'actionIcon' => 'fa-shopping-bag',
    ])

    <div id="paymentsSummary" class="row g-3 mb-4">
        <div class="col-md-4">
            <div id="openUnpaidQueue" class="card border-0 shadow-sm h-100 text-start w-100" role="button" tabindex="0">
                <div class="card-body">
                    <div class="small text-muted">Unpaid ops queue</div>
                    <div class="fs-4 fw-semibold" id="summaryUnpaidCount">—</div>
                    <div class="small text-muted" id="summaryUnpaidAmount">Pending confirmation</div>
                    <div class="small mt-2" id="summaryUnpaidMethods"></div>
                </div>
            </div>
        </div>
        <div class="col-md-8 d-flex align-items-end justify-content-md-end gap-2">
            <a id="exportPaymentsBtn" href="{{ route('admin.payments.export', absolute: false) }}" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-download me-1"></i> Export CSV
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="searchInput">Search</label>
                    <div class="slb-search-wrap">
                        <input type="search"
                               id="searchInput"
                               class="form-control form-control-sm"
                               placeholder="Order, company, site, Stripe, PayPal…"
                               title="Results update as you type"
                               autocomplete="off"
                               enterkeyhint="search"
                               aria-describedby="adminPaymentsSearchStatus">
                        <button type="button" id="adminPaymentsSearchClear" class="btn btn-sm btn-link slb-search-clear d-none" aria-label="Clear search">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div id="adminPaymentsSearchStatus" class="form-text slb-search-status" role="status" aria-live="polite"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="paymentStatusFilter">Payment Status</label>
                    <select id="paymentStatusFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="unpaid">Unpaid (ops queue)</option>
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="paymentMethodFilter">Payment Method</label>
                    <select id="paymentMethodFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="card">Credit/Debit Card</option>
                        <option value="paypal">PayPal</option>
                        <option value="wallet">Wallet Balance</option>
                        <option value="wise">Wise Transfer</option>
                        <option value="crypto">Cryptocurrency</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="orderStatusFilter">Order Status</label>
                    <select id="orderStatusFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="review">Review</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="dateFrom">Date range</label>
                    <div class="input-group input-group-sm mb-1">
                        <input type="date" id="dateFrom" class="form-control" placeholder="From" aria-label="From date">
                        <input type="date" id="dateTo" class="form-control" placeholder="To" aria-label="To date">
                    </div>
                    <select id="dateFieldFilter" class="form-select form-select-sm" aria-label="Date field">
                        <option value="created_at">Filter by created date</option>
                        <option value="paid_at">Filter by paid date</option>
                        <option value="completed_at">Filter by completed date</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="sortFilter">Sort</label>
                    <select id="sortFilter" class="form-select form-select-sm">
                        <option value="">Newest</option>
                        <option value="oldest">Oldest</option>
                        <option value="amount">Amount</option>
                        <option value="paid">Paid date</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="fa fa-search"></i> Filter
                    </button>
                    <button type="button" id="resetFiltersBtn" class="btn btn-secondary btn-sm px-3">
                        <i class="fa fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="card border-0 shadow-sm admin-table-fit">
        <div class="card-body p-0">
            <div id="paymentsFinanceBanner" class="alert alert-info rounded-0 border-0 mb-0 d-none">Finance GMV for this period, dated by completed date.</div>
            <div id="paymentsDateError" class="alert alert-warning rounded-0 border-0 mb-0 d-none"></div>
            <div id="paymentsExportLimit" class="alert alert-warning rounded-0 border-0 mb-0 d-none">This filter matches more than 5,000 rows. The CSV includes the first 5,000 only.</div>
            <div id="paymentsMatchLine" class="px-3 py-2 border-bottom small text-muted"></div>
            <div class="px-3 py-2 border-bottom d-flex align-items-center gap-2">
                <button type="button" id="batchPaidBtn" class="btn btn-sm btn-primary" disabled>Mark selected paid</button>
                <span class="small text-muted">Wise, bank, or crypto — one method at a time.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col"><input type="checkbox" id="batchSelectAll" aria-label="Select unpaid Wise, bank, or crypto rows"></th>
                            <th class="admin-num-col">#</th>
                            <th class="admin-id-col">Order #</th>
                            <th>User</th>
                            <th class="admin-id-col">Reference</th>
                            <th class="admin-narrow-col">Amount</th>
                            <th>Payment Method</th>
                            <th>Payment Status</th>
                            <th>Order Status</th>
                            <th>Paid At</th>
                            <th class="admin-actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <tr>
                            <td colspan="11" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading payments...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="paginationContainer" class="d-flex justify-content-between align-items-center px-3 py-3 border-top">
                <div id="paginationInfo" class="text-muted small"></div>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="paginationLinks"></ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Update Payment Status Modal -->
<div class="modal fade" id="updatePaymentModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title">
                    <i class="fa fa-credit-card me-2"></i> Update Payment Status
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="update_order_id">
                <input type="hidden" id="update_payment_method">
                <input type="hidden" id="update_order_status">
                <input type="hidden" id="update_amount">
                <input type="hidden" id="update_current_payment_status">

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="update_order_number">Order Number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fa fa-hashtag text-muted"></i>
                        </span>
                        <input type="text" id="update_order_number" class="form-control bg-light border-start-0" readonly>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="update_current_status">Current Payment Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fa fa-info-circle text-muted"></i>
                        </span>
                        <input type="text" id="update_current_status" class="form-control bg-light border-start-0" readonly>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="update_payment_status">New Payment Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fa fa-exchange-alt text-muted"></i>
                        </span>
                        <select id="update_payment_status" class="form-select border-start-0">
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="failed">Failed</option>
                            <option value="refunded">Refunded</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3" id="paymentReferenceWrap">
                    <label class="form-label fw-semibold" for="update_payment_reference">Transfer reference</label>
                    <input type="text" id="update_payment_reference" class="form-control" maxlength="120" placeholder="Wise / bank / crypto reference">
                    <small class="text-muted">Optional. Shown on the order and in CSV export.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="update_notes">Admin notes</label>
                    <textarea id="update_notes" class="form-control" rows="3" maxlength="2000" placeholder="Why this status is changing…"></textarea>
                </div>

                <div id="paymentMoneyHint" class="alert alert-warning small py-2 d-none" role="status"></div>

                <div class="mb-0">
                    <div class="form-check">
                        <input type="checkbox" id="send_notification" class="form-check-input" checked>
                        <label class="form-check-label" for="send_notification">
                            <i class="fa fa-envelope me-1"></i> Notify the customer
                        </label>
                        <small class="text-muted d-block mt-1">When checked, the advertiser gets email and in-app notices for paid, failed, and refunded. Publisher mail still goes out on mark-paid.</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fa fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="savePaymentUpdate">
                    <i class="fa fa-save"></i> Update Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>

<script>
let currentPage = 1;
let currentPerPage = 20;

// Payment rows are built as HTML strings from API data, so every dynamic
// value has to be escaped before it is concatenated in.
function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const PAYMENTS_DATA = @json(route('admin.payments.data', absolute: false));
const PAYMENTS_UPDATE = @json(route('admin.payments.updateStatus', ['id' => '__ID__'], absolute: false));
const PAYMENTS_BATCH = @json(route('admin.payments.batch-paid', absolute: false));
const PAYMENTS_EXPORT = @json(route('admin.payments.export', absolute: false));
const ORDERS_SHOW = @json(route('admin.orders.show', ['id' => '__ID__'], absolute: false));

function paymentUrl(template, id) {
    return String(template).replace('__ID__', encodeURIComponent(id));
}

const financeQuery = new URLSearchParams(window.location.search);
let financeClock = financeQuery.get('finance') === '1';
const financeDates = {
    from: financeQuery.get('date_from') || '',
    to: financeQuery.get('date_to') || '',
};

function currentFilterParams() {
    const params = {
        search: $('#searchInput').val() || '',
        payment_status: $('#paymentStatusFilter').val() || '',
        payment_method: $('#paymentMethodFilter').val() || '',
        status: $('#orderStatusFilter').val() || '',
        date_from: $('#dateFrom').val() || '',
        date_to: $('#dateTo').val() || '',
        date_field: $('#dateFieldFilter').val() || 'created_at',
        sort: $('#sortFilter').val() || '',
    };
    if (financeClock) {
        params.finance = '1';
        params.search = '';
        params.payment_status = '';
        params.payment_method = '';
        params.status = '';
        params.date_from = financeDates.from;
        params.date_to = financeDates.to;
        params.date_field = 'completed_at';
    }
    return params;
}

function syncFiltersToUrl() {
    const params = new URLSearchParams();
    const data = currentFilterParams();
    Object.keys(data).forEach(function (key) {
        if (data[key]) {
            params.set(key, data[key]);
        }
    });
    const qs = params.toString();
    history.replaceState({}, '', qs ? (window.location.pathname + '?' + qs) : window.location.pathname);
    const exportParams = new URLSearchParams(data);
    ['search', 'payment_status', 'payment_method', 'status', 'date_from', 'date_to', 'date_field', 'sort'].forEach(function (key) {
        if (!data[key]) {
            exportParams.delete(key);
        }
    });
    const exportQs = exportParams.toString();
    $('#exportPaymentsBtn').attr('href', PAYMENTS_EXPORT + (exportQs ? '?' + exportQs : ''));
}

function applyQueryFilters() {
    const params = new URLSearchParams(window.location.search);
    const hasQuery = window.location.search.length > 1;
    if (params.get('payment_status')) {
        $('#paymentStatusFilter').val(params.get('payment_status'));
    } else if (!hasQuery) {
        $('#paymentStatusFilter').val('unpaid');
    }
    if (params.get('payment_method')) {
        var method = params.get('payment_method');
        if (method === 'stripe') {
            method = 'card';
        } else if (method === 'bank_transfer') {
            method = 'bank';
        }
        $('#paymentMethodFilter').val(method);
    }
    if (params.get('status')) {
        $('#orderStatusFilter').val(params.get('status'));
    }
    if (params.get('search')) {
        $('#searchInput').val(params.get('search'));
    }
    if (params.get('date_from')) {
        $('#dateFrom').val(params.get('date_from'));
    }
    if (params.get('date_to')) {
        $('#dateTo').val(params.get('date_to'));
    }
    if (params.get('date_field')) {
        $('#dateFieldFilter').val(params.get('date_field'));
    }
    if (params.get('sort')) {
        $('#sortFilter').val(params.get('sort'));
    }
    if (financeClock) {
        $('#dateFieldFilter').val('completed_at');
    }
}

function moneyHint(status, method, current) {
    if (current && status === current) {
        return 'Saves notes and transfer reference. Payment status stays ' + status + '.';
    }
    if (status === 'refunded') {
        if (current === 'failed') {
            return 'Relabels this failed payment as refunded. Funds were already returned when it was marked failed — this does not credit the wallet again.';
        }
        if (method === 'paypal') {
            return 'Refunds the PayPal capture back to the buyer. It does not credit the advertiser wallet again. Completed placements must use a dispute clawback.';
        }
        if (method === 'card') {
            return 'Refund credits the advertiser wallet. It does not refund the Stripe charge. Completed placements must use a dispute clawback.';
        }
        return 'Refund returns funds to the advertiser wallet and cancels the order. Completed placements must use a dispute clawback.';
    }
    if (status === 'failed') {
        if (method === 'wallet') {
            return 'Failed cancels an in-flight order and releases the wallet hold. Completed orders cannot be failed here.';
        }
        if (method === 'paypal') {
            return 'Failed cancels an in-flight order and refunds the PayPal capture back to the buyer (same money move as Refunded). It does not credit the advertiser wallet. Completed orders cannot be failed here.';
        }
        return 'Failed cancels an in-flight order and credits the advertiser wallet for a settled card / bank / Wise / crypto payment (same money move as Refunded). It does not refund the Stripe charge. Completed orders cannot be failed here.';
    }
    if (status === 'paid') {
        return 'Mark paid only after the transfer is on the statement. Publishers are notified even if customer mail is off.';
    }
    return '';
}

function fillStatusOptions(allowed, current) {
    const labels = {
        pending: 'Pending',
        paid: 'Paid',
        failed: 'Failed',
        refunded: 'Refunded',
    };
    const $select = $('#update_payment_status');
    $select.empty();
    const options = (allowed && allowed.length) ? allowed : [current];
    options.forEach(function (value) {
        $select.append($('<option>', { value: value, text: labels[value] || value }));
    });
    if (options.indexOf(current) !== -1) {
        $select.val(current);
    } else {
        $select.val(options[0]);
    }
}

$(document).ready(function() {
    function openUnpaidQueue(method) {
        financeClock = false;
        $('#searchInput').val('');
        $('#paymentStatusFilter').val('unpaid');
        $('#paymentMethodFilter').val(method || '');
        $('#orderStatusFilter').val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        $('#dateFieldFilter').val('created_at');
        $('#sortFilter').val('');
        currentPage = 1;
        loadPayments();
    }

    applyQueryFilters();
    loadPayments();

    $('#openUnpaidQueue').on('click', function () {
        openUnpaidQueue('');
    });
    $(document).on('click', '.unpaid-method-chip', function (e) {
        e.stopPropagation();
        openUnpaidQueue($(this).data('method') || '');
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        financeClock = false;
        currentPage = 1;
        loadPayments();
    });

    if (typeof window.SlbLiveSearch !== 'undefined') {
        window.SlbLiveSearch.init(document.getElementById('searchInput'), {
            mode: 'event',
            statusEl: document.getElementById('adminPaymentsSearchStatus'),
            clearBtn: document.getElementById('adminPaymentsSearchClear'),
            onSearch: function () {
                financeClock = false;
                currentPage = 1;
                loadPayments();
            },
        });
    }

    $('#resetFiltersBtn').on('click', function() {
        $('#searchInput').val('');
        $('#paymentStatusFilter').val('unpaid');
        $('#sortFilter').val('');
        $('#paymentMethodFilter').val('');
        $('#orderStatusFilter').val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        $('#dateFieldFilter').val('created_at');
        financeClock = false;
        currentPage = 1;
        loadPayments();
    });

    function selectedBatchIds() {
        return $('.payment-batch-check:checked').map(function () {
            return $(this).val();
        }).get();
    }

    function refreshBatchButton() {
        $('#batchPaidBtn').prop('disabled', selectedBatchIds().length === 0);
    }

    $(document).on('change', '.payment-batch-check', refreshBatchButton);

    $('#batchSelectAll').on('change', function () {
        $('.payment-batch-check').prop('checked', $(this).is(':checked'));
        refreshBatchButton();
    });

    $('#batchPaidBtn').on('click', function () {
        var ids = selectedBatchIds();
        if (!ids.length) {
            return;
        }
        Swal.fire({
            title: 'Mark ' + ids.length + ' payment(s) paid?',
            text: 'Only unpaid Wise, bank, or crypto rows of one method are accepted. Publishers are notified.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Mark paid',
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }
            $.ajax({
                url: PAYMENTS_BATCH,
                method: 'POST',
                data: {
                    ids: ids,
                    send_notification: 1,
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    Swal.fire('Success!', response.message || 'Marked paid.', 'success');
                    loadPayments(currentPage);
                },
                error: function (xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : 'Could not mark these payments paid.';
                    Swal.fire('Error', message, 'error');
                    loadPayments(currentPage);
                }
            });
        });
    });

    $('#update_payment_status').on('change', function () {
        const hint = moneyHint($(this).val(), $('#update_payment_method').val(), $('#update_current_payment_status').val());
        const $box = $('#paymentMoneyHint');
        if (hint) {
            $box.removeClass('d-none').text(hint);
        } else {
            $box.addClass('d-none').text('');
        }
    });

    $(document).on('click', '.update-payment-btn', function() {
        var orderId = $(this).data('id');
        var orderNumber = $(this).data('order');
        var currentStatus = String($(this).data('status') || '');
        var allowed = $(this).data('allowed') || [];
        if (typeof allowed === 'string') {
            try { allowed = JSON.parse(allowed); } catch (e) { allowed = []; }
        }

        $('#update_order_id').val(orderId);
        $('#update_order_number').val(orderNumber);
        $('#update_current_payment_status').val(currentStatus);
        $('#update_current_status').val(currentStatus
            ? currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1)
            : '');
        $('#update_payment_method').val($(this).data('method') || '');
        $('#update_order_status').val($(this).data('order-status') || '');
        $('#update_amount').val($(this).data('amount') || '');
        $('#update_notes').val($(this).data('notes') || '');
        $('#update_payment_reference').val($(this).data('reference') || '');
        $('#send_notification').prop('checked', true);
        fillStatusOptions(allowed, currentStatus);
        $('#update_payment_status').trigger('change');

        new bootstrap.Modal(document.getElementById('updatePaymentModal')).show();
    });

    $('#savePaymentUpdate').on('click', function() {
        var orderId = $('#update_order_id').val();
        var newStatus = $('#update_payment_status').val();
        if (!newStatus) {
            Swal.fire('Error', 'Choose a payment status first.', 'error');
            return;
        }
        var notes = $('#update_notes').val();
        var paymentReference = $('#update_payment_reference').val();
        var sendNotification = $('#send_notification').is(':checked');
        var amount = parseFloat($('#update_amount').val() || '0') || 0;
        var method = $('#update_payment_method').val();

        var currentPaymentStatus = $('#update_current_payment_status').val();
        var confirmTitle = (newStatus === currentPaymentStatus)
            ? 'Save payment notes?'
            : ('Update payment to ' + newStatus + '?');
        var confirmText = moneyHint(newStatus, method, currentPaymentStatus) || 'This updates the order payment status.';
        var willMoveMoney = currentPaymentStatus === 'paid'
            && (newStatus === 'refunded' || newStatus === 'failed')
            && amount > 0;
        if (willMoveMoney) {
            confirmText = 'About €' + amount.toFixed(2) + ' will return to the advertiser wallet. ' + confirmText;
        }

        var $btn = $(this);

        Swal.fire({
            title: confirmTitle,
            text: confirmText,
            icon: (newStatus === 'refunded' || newStatus === 'failed') ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonText: 'Update payment',
            cancelButtonText: 'Cancel',
            customClass: (newStatus === 'refunded' || newStatus === 'failed')
                ? { confirmButton: 'slb-swal-danger' }
                : {},
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');

            $.ajax({
                url: paymentUrl(PAYMENTS_UPDATE, orderId),
                method: 'POST',
                data: {
                    payment_status: newStatus,
                    notes: notes,
                    payment_reference: paymentReference,
                    send_notification: sendNotification ? 1 : 0,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success!', response.message, 'success');
                        bootstrap.Modal.getInstance(document.getElementById('updatePaymentModal')).hide();
                        loadPayments(currentPage);
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr) {
                    var message = xhr.responseJSON?.message || 'Failed to update payment status';
                    if (xhr.status === 422 && xhr.responseJSON?.errors) {
                        var first = Object.values(xhr.responseJSON.errors)[0];
                        if (Array.isArray(first) && first[0]) {
                            message = first[0];
                        }
                    }
                    Swal.fire('Error', message, 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Update Payment');
                }
            });
        });
    });

    function loadPayments(page = 1) {
        currentPage = page;
        syncFiltersToUrl();
        $('#paymentsTableBody').html(
            '<tr>' +
                '<td colspan="11" class="text-center py-5">' +
                    '<div class="spinner-border text-primary" role="status">' +
                        '<span class="visually-hidden">Loading...</span>' +
                    '</div>' +
                    '<p class="mt-2 text-muted">Loading payments...</p>' +
                '</td>' +
            '</tr>'
        );

        $.ajax({
            url: PAYMENTS_DATA,
            method: 'GET',
            data: Object.assign({ page: page }, currentFilterParams()),
            success: function(response) {
                if (response.success) {
                    if (response.pagination && response.pagination.per_page) {
                        currentPerPage = response.pagination.per_page;
                    }
                    if (response.summary) {
                        $('#summaryUnpaidCount').text(response.summary.unpaid_count ?? 0);
                        $('#summaryUnpaidAmount').text(
                            '€' + (parseFloat(response.summary.unpaid_amount || 0).toFixed(2)) + ' waiting'
                        );
                        var methods = response.summary.methods || {};
                        var chips = ['wise', 'bank', 'crypto'].map(function (method) {
                            var label = method.charAt(0).toUpperCase() + method.slice(1);
                            return '<button type="button" class="btn btn-link btn-sm p-0 me-2 unpaid-method-chip" data-method="'
                                + method + '">' + label + ' ' + (methods[method] || 0) + '</button>';
                        }).join('');
                        $('#summaryUnpaidMethods').html(chips);
                    }
                    renderMatchLine(response.totals || {});
                    $('#paymentsFinanceBanner').toggleClass('d-none', !financeClock);
                    var dateError = response.date_error || '';
                    $('#paymentsDateError').toggleClass('d-none', !dateError).text(dateError);
                    $('#paymentsExportLimit').toggleClass('d-none', !response.export_limited);
                    renderPaymentsTable(response.data);
                    renderAdminPagination(response.pagination, {
                        links: '#paginationLinks',
                        info: '#paginationInfo',
                        label: 'payments',
                        onNavigate: loadPayments,
                    });
                } else {
                    $('#paymentsTableBody').html('<tr><td colspan="11" class="text-center text-danger py-5">' + escapeHtml(response.message || 'Failed to load payments') + '</td></tr>');
                }
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Error loading payments. Please refresh the page.';
                $('#paymentsTableBody').html('<tr><td colspan="11" class="text-center text-danger py-5">' + escapeHtml(message) + '</td></tr>');
            }
        });
    }

    function renderMatchLine(totals) {
        var count = totals.count || 0;
        var euros = parseFloat(totals.euros || 0);
        if (isNaN(euros)) {
            euros = 0;
        }
        var text = count + ' match · €' + euros.toFixed(2);
        var charges = totals.charges || {};
        Object.keys(charges).forEach(function (code) {
            text += ' · ' + code + ' ' + parseFloat(charges[code] || 0).toFixed(2);
        });
        if (totals.not_recorded) {
            text += ' · ' + totals.not_recorded + ' card/PayPal charge not recorded';
        }
        $('#paymentsMatchLine').text(text);
    }

    function renderPaymentsTable(orders) {
        $('#batchSelectAll').prop('checked', false);
        $('#batchPaidBtn').prop('disabled', true);
        if (!orders || orders.length === 0) {
            $('#paymentsTableBody').html('<tr><td colspan="11" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2">No payments found</p></td></tr>');
            return;
        }

        var html = '';
        orders.forEach(function(order, index) {
            // Payment Status Badge
            var paymentStatusBadge = '';
            switch(order.payment_status) {
                case 'paid':
                    paymentStatusBadge = '<span class="badge bg-success px-3 py-2"><i class="fa fa-check-circle me-1"></i> Paid</span>';
                    break;
                case 'pending':
                    paymentStatusBadge = '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-clock me-1"></i> Pending</span>';
                    break;
                case 'failed':
                    paymentStatusBadge = '<span class="badge bg-danger px-3 py-2"><i class="fa fa-exclamation-circle me-1"></i> Failed</span>';
                    break;
                case 'refunded':
                    paymentStatusBadge = '<span class="badge bg-info px-3 py-2"><i class="fa fa-undo me-1"></i> Refunded</span>';
                    break;
                default:
                    paymentStatusBadge = order.payment_status
                        ? '<span class="badge bg-secondary px-3 py-2">' + escapeHtml(order.payment_status) + '</span>'
                        : '<span class="badge bg-secondary px-3 py-2">Unpaid</span>';
            }

            // Order Status Badge
            var orderStatusBadge = '';
            switch(order.status) {
                case 'completed':
                    orderStatusBadge = '<span class="badge bg-success px-3 py-2"><i class="fa fa-check-circle me-1"></i> Completed</span>';
                    break;
                case 'processing':
                    orderStatusBadge = '<span class="badge bg-primary px-3 py-2"><i class="fa fa-spinner fa-spin me-1"></i> Processing</span>';
                    break;
                case 'pending':
                    orderStatusBadge = '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-hourglass-half me-1"></i> Pending</span>';
                    break;
                case 'cancelled':
                    orderStatusBadge = '<span class="badge bg-danger px-3 py-2"><i class="fa fa-ban me-1"></i> Cancelled</span>';
                    break;
                case 'review':
                    orderStatusBadge = '<span class="badge bg-info px-3 py-2"><i class="fa fa-search me-1"></i> Review</span>';
                    break;
                case 'scheduled':
                    orderStatusBadge = '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-calendar me-1"></i> Scheduled</span>';
                    break;
                default:
                    orderStatusBadge = order.status
                        ? '<span class="badge bg-secondary px-3 py-2">' + escapeHtml(order.status) + '</span>'
                        : '<span class="badge bg-secondary px-3 py-2">—</span>';
            }

            // Payment Method Badge
            var paymentMethodBadge = '';
            switch(order.payment_method_label || order.payment_method) {
                case 'card':
                    paymentMethodBadge = '<span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2"><i class="fab fa-cc-visa me-1"></i> Card</span>';
                    break;
                case 'paypal':
                    paymentMethodBadge = '<span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2"><img src="{{ asset('assets/img/payments/paypal.png') }}" alt="" width="20" height="20" style="width:20px;height:20px;vertical-align:-4px;margin-right:4px"> PayPal</span>';
                    break;
                case 'wallet':
                    paymentMethodBadge = '<span class="badge bg-success bg-opacity-10 text-success px-3 py-2"><i class="fa fa-wallet me-1"></i> Wallet</span>';
                    break;
                case 'wise':
                    paymentMethodBadge = '<span class="badge bg-info bg-opacity-10 text-info px-3 py-2"><i class="fa fa-university me-1"></i> Wise</span>';
                    break;
                case 'crypto':
                    paymentMethodBadge = '<span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2"><i class="fab fa-bitcoin me-1"></i> Crypto</span>';
                    break;
                case 'bank':
                    paymentMethodBadge = '<span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2"><i class="fa fa-building me-1"></i> Bank</span>';
                    break;
                default:
                    paymentMethodBadge = '<span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">' + escapeHtml(order.payment_method_label || order.payment_method || '—') + '</span>';
            }

            var paidAt = '-';
            if (order.paid_at) {
                var date = new Date(order.paid_at);
                paidAt = date.toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            var rowNumber = ((currentPage - 1) * currentPerPage) + (index + 1);
            var allowed = Array.isArray(order.allowed_statuses) ? order.allowed_statuses : [];
            var amount = parseFloat(order.total_amount);
            if (isNaN(amount)) {
                amount = 0;
            }

            var chargeHtml = '';
            if (order.charge_currency && order.charge_currency !== 'EUR' && order.charge_amount != null) {
                chargeHtml = '<div class="small text-muted">' + escapeHtml(order.charge_currency) + ' '
                    + parseFloat(order.charge_amount).toFixed(2) + '</div>';
            }
            var buyerName = escapeHtml(order.user ? order.user.name : 'N/A');
            if (order.user && order.user.dossier_url) {
                buyerName = '<a href="' + escapeHtml(order.user.dossier_url) + '">' + buyerName + '</a>';
            }
            var siteHtml = order.site_name
                ? '<div class="small text-muted">' + escapeHtml(order.site_name) + '</div>'
                : '';
            var checkCell = order.can_batch_pay
                ? '<input type="checkbox" class="payment-batch-check" value="' + escapeHtml(order.id) + '" aria-label="Select order">'
                : '';

            html += '<tr>';
            html += '<td class="text-center">' + checkCell + '</td>';
            html += '<td class="text-center">' + rowNumber + '</td>';
            html += '<td><strong class="admin-id-clamp" title="' + escapeHtml(order.order_number) + '">'
                + escapeHtml(order.order_number) + '</strong>' + siteHtml + '</td>';
            html += '<td>';
            html += '<div class="d-flex flex-column">';
            html += '<span class="fw-semibold">' + buyerName + '</span>';
            html += '<small class="text-muted">' + escapeHtml(order.user ? order.user.email : 'No email') + '</small>';
            html += '</div>';
            html += '</td>';
            html += '<td><code class="small admin-id-clamp" title="' + escapeHtml(order.reference_code || '') + '">'
                + escapeHtml(order.reference_code || '—') + '</code></td>';
            html += '<td class="fw-bold text-primary">€' + amount.toFixed(2) + chargeHtml + '</td>';
            html += '<td>' + paymentMethodBadge + '</td>';
            html += '<td>' + paymentStatusBadge + '</td>';
            html += '<td>' + orderStatusBadge + '</td>';
            html += '<td>' + paidAt + '</td>';
            html += '<td>';
            html += '<div class="dropdown admin-manage-dropdown">';
            html += '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Manage</button>';
            html += '<ul class="dropdown-menu dropdown-menu-end">';
            html += '<li><a class="dropdown-item" href="' + escapeHtml(paymentUrl(ORDERS_SHOW, order.id)) + '"><i class="fa fa-shopping-bag me-2"></i>Open order</a></li>';
            if (order.invoice_url) {
                html += '<li><a class="dropdown-item" href="' + escapeHtml(order.invoice_url) + '"><i class="fa fa-file-invoice-dollar me-2"></i>Open invoice</a></li>';
            }
            if (allowed.length > 0) {
                html += '<li><button type="button" class="dropdown-item update-payment-btn" ';
                html += 'data-id="' + escapeHtml(order.id) + '" ';
                html += 'data-order="' + escapeHtml(order.order_number) + '" ';
                html += 'data-status="' + escapeHtml(order.payment_status || '') + '" ';
                html += 'data-method="' + escapeHtml(order.payment_method_label || order.payment_method) + '" ';
                html += 'data-order-status="' + escapeHtml(order.status) + '" ';
                html += 'data-amount="' + escapeHtml(amount.toFixed(2)) + '" ';
                html += 'data-notes="' + escapeHtml(order.admin_notes || '') + '" ';
                html += 'data-reference="' + escapeHtml(order.payment_reference || '') + '" ';
                html += 'data-allowed="' + escapeHtml(JSON.stringify(allowed)) + '">';
                html += '<i class="fa fa-edit me-2"></i>Update payment</button></li>';
            } else if (order.payment_status === 'paid' && order.status === 'completed') {
                html += '<li><a class="dropdown-item" href="' + escapeHtml(paymentUrl(ORDERS_SHOW, order.id)) + '"><i class="fa fa-gavel me-2"></i>Use a dispute clawback</a></li>';
            } else if (order.payment_status === 'refunded') {
                html += '<li><span class="dropdown-item-text text-muted"><i class="fa fa-undo me-2"></i>Refunded</span></li>';
            }
            html += '</ul></div>';
            html += '</td>';
            html += '</tr>';
        });

        $('#paymentsTableBody').html(html);
    }
});
</script>

@endsection
