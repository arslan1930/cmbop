@extends('publisher.layouts.app')

@section('title', 'Reports')

@section('content')
<div class="publisher-reports-container"
     data-stats-url="{{ route('publisher.reports.statistics', absolute: false) }}"
     data-orders-url="{{ route('publisher.reports.orders', absolute: false) }}"
     data-order-details-template="{{ route('publisher.reports.order.details', ['orderItemId' => '__ID__'], absolute: false) }}"
     data-withdrawals-url="{{ route('publisher.reports.withdrawals', absolute: false) }}"
     data-withdraw-url="{{ route('publisher.withdraw', absolute: false) }}"
     data-tasks-url="{{ route('publisher.tasks', absolute: false) }}">

    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Financial Reports</h2>
            <p class="text-muted mb-0">
                Earnings from completed placements and your withdrawal history.
            </p>
        </div>
    </div>

    <div class="row mb-4" id="reportsStatCards">
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Earned</h6>
                        <h3 class="mb-0" id="totalEarned" style="color: #10b981;">€0.00</h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-euro-sign fa-2x text-success"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Completed Orders</h6>
                        <h3 class="mb-0" id="completedOrders">0</h3>
                        <div class="text-muted small mt-1">
                            Open placements: <span id="openOrders">0</span>
                            ·
                            <a href="{{ route('publisher.tasks') }}">Tasks</a>
                        </div>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-check-circle fa-2x text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Withdrawn</h6>
                        <h3 class="mb-0" id="totalWithdrawn" style="color: #ef4444;">€0.00</h3>
                        <div class="text-muted small mt-1" id="withdrawnFeesHint"></div>
                    </div>
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-download fa-2x text-danger"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Available to Withdraw</h6>
                        <h3 class="mb-0" id="availableToWithdraw">€0.00</h3>
                        <a href="{{ route('publisher.withdraw') }}" class="small">Go to Withdraw</a>
                    </div>
                    <div class="bg-info bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-wallet fa-2x text-info"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <ul class="nav nav-tabs publisher-reports-tabs" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders" type="button" role="tab">
                        <i class="fa fa-shopping-cart me-2"></i>Orders
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="withdrawals-tab" data-bs-toggle="tab" data-bs-target="#withdrawals" type="button" role="tab">
                        <i class="fa fa-download me-2"></i>Withdrawals
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="orders" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
                        <div>
                            <div class="fw-semibold"><i class="fa fa-shopping-cart me-2"></i><span id="ordersTabTitle">Orders</span></div>
                            <small class="text-muted" id="ordersResultsCount"></small>
                        </div>
                        <form id="ordersFilters" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="ordersDateFrom">From</label>
                                <input type="date" class="form-control form-control-sm" id="ordersDateFrom" name="date_from">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="ordersDateTo">To</label>
                                <input type="date" class="form-control form-control-sm" id="ordersDateTo" name="date_to">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="ordersStatus">Status</label>
                                <select class="form-select form-select-sm" id="ordersStatus" name="status">
                                    <option value="completed" selected>Completed</option>
                                    <option value="pending">Pending</option>
                                    <option value="processing">Processing</option>
                                    <option value="review">In Review</option>
                                    <option value="scheduled">Scheduled</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 publisher-reports-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Site</th>
                                    <th>Base Price</th>
                                    <th>Sensitive Price</th>
                                    <th>Homepage</th>
                                    <th id="ordersPayoutHeading">You earned</th>
                                    <th>Order Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTableBody">
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">Loading orders...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-center">
                        <nav id="ordersPaginationNav"></nav>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="withdrawals" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
                        <div>
                            <div class="fw-semibold"><i class="fa fa-download me-2"></i><span id="withdrawalsTabTitle">Withdrawals</span></div>
                            <small class="text-muted" id="withdrawalsResultsCount"></small>
                        </div>
                        <form id="withdrawalsFilters" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="withdrawalsDateFrom">From</label>
                                <input type="date" class="form-control form-control-sm" id="withdrawalsDateFrom" name="date_from">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="withdrawalsDateTo">To</label>
                                <input type="date" class="form-control form-control-sm" id="withdrawalsDateTo" name="date_to">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0" for="withdrawalsStatus">Status</label>
                                <select class="form-select form-select-sm" id="withdrawalsStatus" name="status">
                                    <option value="completed" selected>Paid</option>
                                    <option value="pending">Requested</option>
                                    <option value="processing">Processing</option>
                                    <option value="cancelled">Cancelled / Rejected</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 publisher-reports-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Fee</th>
                                    <th>Net Paid</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Reference</th>
                                    <th>Statement</th>
                                </tr>
                            </thead>
                            <tbody id="withdrawalsTableBody">
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="text-muted">Loading withdrawals...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-center">
                        <nav id="withdrawalsPaginationNav"></nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="orderDetailsModalLabel">Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="orderDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.publisher-reports-container .table td,
.publisher-reports-container .table th {
    padding: 12px 15px;
    vertical-align: middle;
}
.publisher-reports-container .card-header {
    border-bottom: 1px solid #eee;
}
.publisher-reports-container .status-badge {
    padding: 4px 10px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}
.publisher-reports-container .status-pending { background-color: #fff7ed; color: #9a3412; }
.publisher-reports-container .status-processing { background-color: #eff6ff; color: #1e40af; }
.publisher-reports-container .status-completed { background-color: #ecfdf5; color: #0f766e; }
.publisher-reports-container .status-cancelled { background-color: #fef2f2; color: #dc2626; }
.publisher-reports-container .status-clawed { background-color: #f8fafc; color: #475569; }
.publisher-reports-container .sensitive-badge {
    background-color: #fef3c7;
    color: #d97706;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
}
.publisher-reports-container .earned-amount {
    color: #10b981;
    font-weight: 600;
    font-size: 15px;
}
.publisher-reports-container .withdrawn-amount {
    color: #ef4444;
    font-weight: 600;
    font-size: 15px;
}
.publisher-reports-container .publisher-reports-tabs {
    border-bottom: 1px solid #e5e7eb;
    padding: 0 20px;
    background: white;
    border-radius: 8px 8px 0 0;
}
.publisher-reports-container .publisher-reports-tabs .nav-link {
    border: none;
    padding: 12px 20px;
    color: #6b7280;
    font-weight: 500;
}
.publisher-reports-container .publisher-reports-tabs .nav-link:hover { color: #1a585e; }
.publisher-reports-container .publisher-reports-tabs .nav-link.active {
    color: #1a585e;
    border-bottom: 2px solid #1a585e;
    background: transparent;
}
</style>
@endsection

@push('scripts')
<script>
(function () {
    const root = document.querySelector('.publisher-reports-container');
    if (!root) return;

    const urls = {
        stats: root.dataset.statsUrl,
        orders: root.dataset.ordersUrl,
        orderDetailsTemplate: root.dataset.orderDetailsTemplate,
        withdrawals: root.dataset.withdrawalsUrl,
    };

    function orderDetailsUrl(id) {
        return String(urls.orderDetailsTemplate || '').replace('__ID__', encodeURIComponent(id));
    }

    let ordersPage = 1;
    let withdrawalsPage = 1;

    function money(n) {
        const v = parseFloat(n);
        return (Number.isFinite(v) ? v : 0).toFixed(2);
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

    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        if (Number.isNaN(date.getTime())) return 'N/A';
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function resultsCountLabel(pagination) {
        if (!pagination || !pagination.total) {
            return 'No entries';
        }
        return 'Showing ' + pagination.from + ' to ' + pagination.to + ' of ' + pagination.total + ' entries';
    }

    function paymentStatusBadge(status) {
        const s = String(status || 'unknown').toLowerCase();
        if (s === 'paid') return '<span class="badge bg-success">Paid</span>';
        if (s === 'pending') return '<span class="badge bg-warning text-dark">Pending</span>';
        if (s === 'refunded') return '<span class="badge bg-secondary">Refunded</span>';
        return '<span class="badge bg-secondary">' + escapeHtml(s) + '</span>';
    }

    function orderStatusMeta(orderStatus, clawed) {
        if (clawed) {
            return { cls: 'status-clawed', text: 'Clawed back' };
        }
        switch (orderStatus) {
            case 'pending': return { cls: 'status-pending', text: 'Pending' };
            case 'processing': return { cls: 'status-processing', text: 'Processing' };
            case 'review': return { cls: 'status-processing', text: 'In Review' };
            case 'scheduled': return { cls: 'status-processing', text: 'Scheduled' };
            case 'completed': return { cls: 'status-completed', text: 'Completed' };
            case 'cancelled': return { cls: 'status-cancelled', text: 'Cancelled' };
            default: return { cls: 'status-pending', text: orderStatus || 'Unknown' };
        }
    }

    function payoutColumnHeading(status) {
        if (status === 'completed') return 'You earned';
        if (status === 'cancelled' || status === 'all') return 'Payout';
        return 'You earn';
    }

    function payoutCell(item) {
        const state = item.payout_state || '';
        const label = item.payout_label || '';
        if (state === 'none' || !label) {
            return '<span class="text-muted">—</span>';
        }
        const amount = '€' + money(item.price);
        if (state === 'you_earned') {
            return '<span class="earned-amount">' + escapeHtml(label) + ' ' + amount + '</span>';
        }
        return '<span class="fw-semibold">' + escapeHtml(label) + ' ' + amount + '</span>';
    }

    function homepageCell(homepagePrice, homepageDays) {
        if (!(homepagePrice > 0)) {
            return '<span class="text-muted">—</span>';
        }
        const daysBit = homepageDays ? ' · ' + homepageDays + 'd' : '';
        return '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> +€' + money(homepagePrice) + daysBit + '</span>';
    }

    function linkOrDash(url, label) {
        if (!url) return '<span class="text-muted">—</span>';
        const safe = escapeHtml(url);
        const text = escapeHtml(label || url);
        return '<a href="' + safe + '" target="_blank" rel="noopener" class="text-primary text-break">' + text + ' <i class="fa fa-external-link fa-xs"></i></a>';
    }

    function loadStatistics() {
        $.ajax({
            url: urls.stats,
            method: 'GET',
            dataType: 'json',
            success: function (response) {
                if (!response.success) return;
                const d = response.data || {};
                $('#totalEarned').html('<span style="color:#10b981;">+ €' + money(d.total_earned) + '</span>');
                $('#completedOrders').text(d.completed_orders || 0);
                $('#openOrders').text(d.open_orders != null ? d.open_orders : (d.pending_orders || 0));
                $('#totalWithdrawn').html('<span style="color:#ef4444;">- €' + money(d.total_withdrawn) + '</span>');
                $('#availableToWithdraw').text('€' + money(d.available_to_withdraw));
                const fees = parseFloat(d.total_withdrawal_fees || 0);
                $('#withdrawnFeesHint').text(fees > 0 ? ('Fees paid: €' + money(fees) + ' · net received') : 'Net received');
            },
            error: function (xhr) {
                if (typeof slbHandleHttpError === 'function') {
                    slbHandleHttpError(xhr, { fallback: 'Could not load report statistics' });
                }
            }
        });
    }

    function ordersFilterParams(page) {
        return {
            page: page || 1,
            status: $('#ordersStatus').val() || 'completed',
            date_from: $('#ordersDateFrom').val() || '',
            date_to: $('#ordersDateTo').val() || '',
        };
    }

    function withdrawalsFilterParams(page) {
        return {
            page: page || 1,
            status: $('#withdrawalsStatus').val() || 'completed',
            date_from: $('#withdrawalsDateFrom').val() || '',
            date_to: $('#withdrawalsDateTo').val() || '',
        };
    }

    function loadOrders(page) {
        page = page || 1;
        ordersPage = page;
        const params = ordersFilterParams(page);
        const statusLabel = $('#ordersStatus option:selected').text();
        $('#ordersTabTitle').text(params.status === 'all' ? 'Orders' : (statusLabel + ' Orders'));
        $('#ordersPayoutHeading').text(payoutColumnHeading(params.status));

        $('#ordersTableBody').html(
            '<tr><td colspan="9" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted mb-0">Loading orders...</p></td></tr>'
        );

        $.ajax({
            url: urls.orders,
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#ordersTableBody').html('<tr><td colspan="9" class="text-center text-danger py-5">' + escapeHtml(response.message || 'Failed to load orders') + '</td></tr>');
                    return;
                }
                renderOrdersTable(response.data);
                renderPagination('#ordersPaginationNav', response.pagination, loadOrders);
                $('#ordersResultsCount').text(resultsCountLabel(response.pagination));
            },
            error: function (xhr) {
                $('#ordersTableBody').html('<tr><td colspan="9" class="text-center text-danger py-5">Error loading orders. Please refresh the page.</td></tr>');
                if (typeof slbHandleHttpError === 'function') {
                    slbHandleHttpError(xhr, { fallback: 'Could not load orders' });
                }
            }
        });
    }

    function renderOrdersTable(orderItems) {
        if (!orderItems || orderItems.length === 0) {
            $('#ordersTableBody').html(
                '<tr><td colspan="9" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2 mb-0">No orders match this filter</p><p class="text-muted small mb-0">Try another status or date range.</p></td></tr>'
            );
            return;
        }

        let html = '';
        for (let i = 0; i < orderItems.length; i++) {
            const item = orderItems[i];
            const orderNumber = item.order ? item.order.order_number : 'N/A';
            const orderStatus = item.order && item.order.is_awaiting_scheduled_release
                ? 'scheduled'
                : (item.order ? item.order.status : 'pending');
            const additionalPrice = parseFloat(item.additional_price || 0);
            const homepagePrice = parseFloat(item.homepage_price || 0);
            const homepageDays = item.homepage_days != null && item.homepage_days !== ''
                ? parseInt(item.homepage_days, 10)
                : null;
            const basePrice = parseFloat(item.publisher_base_price != null
                ? item.publisher_base_price
                : (item.price - additionalPrice - homepagePrice));
            const sensitiveType = item.sensitive_type || null;
            const meta = orderStatusMeta(orderStatus, item.is_clawed_back);

            html += '<tr>' +
                '<td class="fw-semibold"><strong>#' + escapeHtml(orderNumber) + '</strong></td>' +
                '<td>' + formatDate(item.created_at) + '</td>' +
                '<td><div class="fw-semibold">' + escapeHtml(item.site_name) + '</div><div class="text-muted small">' + linkOrDash(item.site_url, item.site_url) + '</div></td>' +
                '<td class="text-primary">€' + money(basePrice) + '</td>' +
                '<td>' + (additionalPrice > 0
                    ? '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> ' + escapeHtml(sensitiveType || 'Extra') + ' (+€' + money(additionalPrice) + ')</span>'
                    : '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + homepageCell(homepagePrice, homepageDays) + '</td>' +
                '<td>' + payoutCell(item) + '</td>' +
                '<td><span class="status-badge ' + meta.cls + '">' + escapeHtml(meta.text) + '</span></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-info btn-view-order" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button></td>' +
                '</tr>';
        }
        $('#ordersTableBody').html(html);
    }

    function viewOrderDetails(orderItemId) {
        fetch(orderDetailsUrl(orderItemId), {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            }
        })
            .then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); })
            .then(function (result) {
                if (result.ok && result.data.success) {
                    renderOrderDetailsModal(result.data.data);
                    const el = document.getElementById('orderDetailsModal');
                    if (window.bootstrap && bootstrap.Modal) {
                        bootstrap.Modal.getOrCreateInstance(el).show();
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Order Details',
                            html: document.getElementById('orderDetailsContent').innerHTML,
                            width: 800,
                        });
                    }
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire('Error', (result.data && result.data.message) || 'Failed to load order details', 'error');
                }
            })
            .catch(function () {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Error', 'Failed to load order details', 'error');
                }
            });
    }

    function renderOrderDetailsModal(orderItem) {
        const order = orderItem.order || {};
        const additionalPrice = parseFloat(orderItem.additional_price || 0);
        const homepagePrice = parseFloat(orderItem.homepage_price || 0);
        const homepageDays = orderItem.homepage_days != null && orderItem.homepage_days !== ''
            ? parseInt(orderItem.homepage_days, 10)
            : null;
        const basePrice = parseFloat(orderItem.publisher_base_price != null
            ? orderItem.publisher_base_price
            : (orderItem.price - additionalPrice - homepagePrice));
        const totalPrice = parseFloat(orderItem.price);
        const sensitiveType = orderItem.sensitive_type || null;

        const liveUrlHtml = orderItem.live_url
            ? '<p class="mb-1"><strong>Live URL:</strong></p><p class="mb-2">' + linkOrDash(orderItem.live_url) + '</p>'
            : '<p class="mb-2 text-muted">Live URL not submitted yet</p>';

        const contentHtml = orderItem.content_link
            ? '<p class="mb-1"><strong>Content Link:</strong></p><p class="mb-2">' + linkOrDash(orderItem.content_link) + '</p>'
            : '<p class="mb-1"><strong>Content Link:</strong></p><p class="mb-2 text-muted">—</p>';

        const html = '<div class="row mb-4">' +
            '<div class="col-md-6"><div class="bg-light p-3 rounded">' +
                '<h6 class="mb-3">Order Information</h6>' +
                '<p class="mb-1"><strong>Order Number:</strong> #' + escapeHtml(order.order_number || 'N/A') + '</p>' +
                '<p class="mb-1"><strong>Date:</strong> ' + formatDate(order.created_at || orderItem.created_at) + '</p>' +
                '<p class="mb-1"><strong>Payment Status:</strong> ' + paymentStatusBadge(order.payment_status) + '</p>' +
                '<p class="mb-1"><strong>Reference Code:</strong> ' + escapeHtml(order.reference_code || '-') + '</p>' +
            '</div></div>' +
            '<div class="col-md-6"><div class="bg-light p-3 rounded">' +
                '<h6 class="mb-3">Earnings Summary</h6>' +
                '<p class="mb-1"><strong>Base Price:</strong> €' + money(basePrice) + '</p>' +
                (additionalPrice > 0
                    ? '<p class="mb-1"><strong>Sensitive Price:</strong> <span class="text-warning">+ €' + money(additionalPrice) + ' (' + escapeHtml(sensitiveType || 'Extra') + ')</span></p>'
                    : '') +
                (homepagePrice > 0
                    ? '<p class="mb-1"><strong>Homepage:</strong> <span class="text-warning">+ €' + money(homepagePrice)
                        + (homepageDays
                            ? ' (' + homepageDays + ' day' + (homepageDays === 1 ? '' : 's') + ')'
                            : '')
                        + '</span></p>'
                    : '') +
                '<p class="mb-1"><strong>' + escapeHtml((orderItem.payout_label || 'Payout')) + ':</strong> ' +
                    (orderItem.payout_state === 'none'
                        ? '<span class="text-muted">—</span>'
                        : '<span class="' + (orderItem.payout_state === 'you_earned' ? 'earned-amount fs-4' : 'fw-semibold') + '">€' + money(totalPrice) + '</span>') +
                '</p>' +
                (orderItem.is_clawed_back ? '<p class="mb-1 text-muted">Clawed back — not counted as earned.</p>' : '') +
            '</div></div></div>' +
            '<h6 class="mb-3">Placement</h6>' +
            '<div class="border rounded p-3"><div class="row">' +
                '<div class="col-md-6">' +
                    '<p class="mb-1"><strong>Site Name:</strong></p><p class="mb-2">' + escapeHtml(orderItem.site_name) + '</p>' +
                    '<p class="mb-1"><strong>Site URL:</strong></p><p class="mb-2">' + linkOrDash(orderItem.site_url) + '</p>' +
                '</div>' +
                '<div class="col-md-6">' + contentHtml + liveUrlHtml + '</div>' +
            '</div></div>';

        $('#orderDetailsContent').html(html);
    }

    function loadWithdrawals(page) {
        page = page || 1;
        withdrawalsPage = page;
        const params = withdrawalsFilterParams(page);
        const statusLabel = $('#withdrawalsStatus option:selected').text();
        $('#withdrawalsTabTitle').text(params.status === 'all' ? 'Withdrawals' : statusLabel);

        $('#withdrawalsTableBody').html(
            '<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted mb-0">Loading withdrawals...</p></td></tr>'
        );

        $.ajax({
            url: urls.withdrawals,
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#withdrawalsTableBody').html('<tr><td colspan="8" class="text-center text-danger py-5">' + escapeHtml(response.message || 'Failed to load withdrawals') + '</td></tr>');
                    return;
                }
                renderWithdrawalsTable(response.data);
                renderPagination('#withdrawalsPaginationNav', response.pagination, loadWithdrawals);
                $('#withdrawalsResultsCount').text(resultsCountLabel(response.pagination));
            },
            error: function (xhr) {
                $('#withdrawalsTableBody').html('<tr><td colspan="8" class="text-center text-danger py-5">Error loading withdrawals. Please refresh the page.</td></tr>');
                if (typeof slbHandleHttpError === 'function') {
                    slbHandleHttpError(xhr, { fallback: 'Could not load withdrawals' });
                }
            }
        });
    }

    function withdrawalStatusBadge(withdrawal) {
        const label = escapeHtml(withdrawal.status_label || withdrawal.status || 'Unknown');
        switch (withdrawal.status) {
            case 'pending': return '<span class="badge bg-warning text-dark">' + label + '</span>';
            case 'processing': return '<span class="badge bg-info text-dark">' + label + '</span>';
            case 'completed': return '<span class="badge bg-success">' + label + '</span>';
            case 'cancelled': return '<span class="badge bg-danger">' + label + '</span>';
            default: return '<span class="badge bg-secondary">' + label + '</span>';
        }
    }

    function statementLinks(withdrawal) {
        if (!withdrawal.statement_url) {
            return '<span class="text-muted">—</span>';
        }
        let html = '<a href="' + escapeHtml(withdrawal.statement_url) + '">View</a>';
        if (withdrawal.statement_pdf_url) {
            html += ' · <a href="' + escapeHtml(withdrawal.statement_pdf_url) + '" target="_blank" rel="noopener">PDF</a>';
        }
        return html;
    }

    function renderWithdrawalsTable(withdrawals) {
        if (!withdrawals || withdrawals.length === 0) {
            $('#withdrawalsTableBody').html(
                '<tr><td colspan="8" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2 mb-0">No withdrawals match this filter</p><p class="text-muted small mb-0">Try another status or date range.</p></td></tr>'
            );
            return;
        }

        let html = '';
        for (let i = 0; i < withdrawals.length; i++) {
            const w = withdrawals[i];
            html += '<tr>' +
                '<td>' + formatDate(w.created_at) + '</td>' +
                '<td>€' + money(w.amount) + '</td>' +
                '<td class="text-muted">€' + money(w.fee) + '</td>' +
                '<td class="withdrawn-amount"><strong>- €' + money(w.net_amount) + '</strong></td>' +
                '<td><span class="badge bg-secondary">' + escapeHtml(w.payment_method || 'Bank Transfer') + '</span></td>' +
                '<td>' + withdrawalStatusBadge(w) + '</td>' +
                '<td><span class="text-muted small">' + escapeHtml(w.reference || ('WD-' + w.id)) + '</span></td>' +
                '<td>' + statementLinks(w) + '</td>' +
                '</tr>';
        }
        $('#withdrawalsTableBody').html(html);
    }

    function renderPagination(navSelector, pagination, loadFn) {
        const $nav = $(navSelector);
        if (!pagination || pagination.last_page <= 1) {
            $nav.html('');
            return;
        }

        let html = '<ul class="pagination justify-content-center mb-0">';
        if (pagination.current_page > 1) {
            html += '<li class="page-item"><button type="button" class="page-link" data-page="' + (pagination.current_page - 1) + '">Previous</button></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
        }

        for (let i = 1; i <= pagination.last_page; i++) {
            if (i >= pagination.current_page - 2 && i <= pagination.current_page + 2) {
                const activeClass = i === pagination.current_page ? 'active' : '';
                html += '<li class="page-item ' + activeClass + '"><button type="button" class="page-link" data-page="' + i + '">' + i + '</button></li>';
            }
        }

        if (pagination.current_page < pagination.last_page) {
            html += '<li class="page-item"><button type="button" class="page-link" data-page="' + (pagination.current_page + 1) + '">Next</button></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">Next</span></li>';
        }
        html += '</ul>';
        $nav.html(html);

        $nav.find('.page-link[data-page]').on('click', function (e) {
            e.preventDefault();
            const page = parseInt($(this).data('page'), 10);
            if (page) {
                loadFn(page);
                $('html, body').animate({ scrollTop: root.offsetTop }, 'fast');
            }
        });
    }

    $(function () {
        loadStatistics();
        loadOrders(1);
        loadWithdrawals(1);

        $('#ordersFilters').on('submit', function (e) {
            e.preventDefault();
            loadOrders(1);
        });
        $('#withdrawalsFilters').on('submit', function (e) {
            e.preventDefault();
            loadWithdrawals(1);
        });

        $('#orders-tab').on('shown.bs.tab', function () {
            loadOrders(ordersPage);
        });
        $('#withdrawals-tab').on('shown.bs.tab', function () {
            loadWithdrawals(withdrawalsPage);
        });

        $(document).on('click', '.btn-view-order', function () {
            const id = $(this).data('id');
            if (id) viewOrderDetails(id);
        });
    });
})();
</script>
@endpush
