@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Reports</h2>
            <p class="text-muted mb-0">
                View your campaign performance, funds activity, and order history.
            </p>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-0">
            <ul class="nav nav-tabs rep-nav-tabs-custom" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="repFundsTab" data-bs-toggle="tab" data-bs-target="#repFunds" type="button" role="tab">
                        <i class="fa fa-wallet me-2"></i>Funds Activity
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="repOrdersTab" data-bs-toggle="tab" data-bs-target="#repOrders" type="button" role="tab">
                        <i class="fa fa-shopping-cart me-2"></i>Orders
                    </button>
                </li>
            </ul>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Funds Activity Tab -->
        <div class="tab-pane fade show active" id="repFunds" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa fa-wallet me-2"></i> Funds Activity
                    </div>
                    <div>
                        <small class="text-muted" id="repFundsResultsCount"></small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 rep-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="repFundsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2 text-muted">Loading funds activity...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-center">
                        <nav id="repFundsPaginationNav"></nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Tab -->
        <div class="tab-pane fade" id="repOrders" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa fa-shopping-cart me-2"></i> Orders
                    </div>
                    <div>
                        <small class="text-muted" id="repOrdersResultsCount"></small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 rep-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Site</th>
                                    <th>Base Price</th>
                                    <th>Sensitive Price</th>
                                    <th>Total</th>
                                    <th>Reference</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Payment Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="repOrdersTableBody">
                                <tr>
                                    <td colspan="11" class="text-center py-5">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2 text-muted">Loading orders...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-center">
                        <nav id="repOrdersPaginationNav"></nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Scoped styles with rep- prefix to prevent conflicts */
.rep-nav-tabs-custom {
    border-bottom: 1px solid #e5e7eb;
    padding: 0 20px;
    background: white;
    border-radius: 8px 8px 0 0;
}

.rep-nav-tabs-custom .nav-link {
    border: none;
    padding: 12px 20px;
    color: #6b7280;
    font-weight: 500;
    transition: all 0.2s;
}

.rep-nav-tabs-custom .nav-link:hover {
    color: #1a585e;
    background: transparent;
}

.rep-nav-tabs-custom .nav-link.active {
    color: #1a585e;
    border-bottom: 2px solid #1a585e;
    background: transparent;
}

.rep-table td,
.rep-table th {
    padding: 12px 15px;
    vertical-align: middle;
}

.rep-table .badge {
    font-size: 11px;
    padding: 4px 8px;
}

.rep-sensitive-badge {
    background-color: #fef3c7;
    color: #d97706;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.rep-btn-sm {
    padding: 4px 10px;
    font-size: 11px;
}

.rep-btn-outline-primary {
    border-color: #2563eb;
}

.rep-btn-outline-primary:hover {
    background-color: #2563eb;
    border-color: #2563eb;
}

/* Pagination Styles */
.rep-pagination {
    margin-bottom: 0;
    justify-content: center;
}

.rep-page-link {
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    cursor: pointer;
}

.rep-page-item.disabled .rep-page-link {
    cursor: not-allowed;
}

/* Dark mode styles */









</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
var repFundsPage = 1;
var repOrdersPage = 1;

$(document).ready(function() {
    loadRepFundsData();
    loadRepOrdersData();
    
    // Reload data when tabs are clicked
    $('#repFundsTab').on('click', function() {
        if ($('#repFundsTableBody').find('.rep-report-row').length === 0) {
            loadRepFundsData();
        }
    });
    
    $('#repOrdersTab').on('click', function() {
        if ($('#repOrdersTableBody').find('.rep-report-row').length === 0) {
            loadRepOrdersData();
        }
    });
});

function loadRepFundsData(page) {
    page = page || 1;
    repFundsPage = page;
    
    $('#repFundsTableBody').html('\
        <tr>\
            <td colspan="7" class="text-center py-5">\
                <div class="spinner-border text-primary" role="status">\
                    <span class="visually-hidden">Loading...</span>\
                </div>\
                <p class="mt-2 text-muted">Loading funds activity...</p>\
            </td>\
        </tr>\
    ');
    
    $.ajax({
        url: '{{ route("advertiser.reports.funds") }}',
        method: 'GET',
        data: { page: page },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderRepFundsTable(response.data);
                renderRepFundsPagination(response.pagination);
                $('#repFundsResultsCount').html('Showing ' + (response.pagination.from || 0) + ' to ' + (response.pagination.to || 0) + ' of ' + response.pagination.total + ' entries');
            } else {
                $('#repFundsTableBody').html('\
                    <tr>\
                        <td colspan="7" class="text-center text-danger py-5">\
                            ' + (response.message || 'Failed to load funds activity') + '\
                        </td>\
                    </tr>\
                ');
            }
        },
        error: function() {
            $('#repFundsTableBody').html('\
                <tr>\
                    <td colspan="7" class="text-center text-danger py-5">\
                        Error loading funds activity. Please refresh the page.\
                    </td>\
                </tr>\
            ');
        }
    });
}

function renderRepFundsTable(activities) {
    if (!activities || activities.length === 0) {
        $('#repFundsTableBody').html('\
            <tr>\
                <td colspan="7" class="text-center py-5">\
                    <i class="fa fa-inbox fa-3x text-muted"></i>\
                    <p class="mt-2">No funds activity found</p>\
                </td>\
            </tr>\
        ');
        return;
    }
    
    var html = '';
    for (var i = 0; i < activities.length; i++) {
        var activity = activities[i];
        var isCompleted = activity.status === 'completed';
        var isDeposit = activity.type === 'deposit';
        var amountClass = '';
        var amountPrefix = '';
        
        if (isCompleted && isDeposit) {
            amountClass = 'text-success';
            amountPrefix = '+';
        } else if (isCompleted && !isDeposit) {
            amountClass = 'text-danger';
            amountPrefix = '-';
        } else {
            amountClass = 'text-muted';
            amountPrefix = isDeposit ? '+' : '-';
        }
        
        var statusBadge = '';
        if (activity.status === 'pending') statusBadge = '<span class="badge bg-warning text-dark">Pending</span>';
        else if (activity.status === 'approved') statusBadge = '<span class="badge bg-info text-dark">Approved</span>';
        else if (activity.status === 'completed') statusBadge = '<span class="badge bg-success">Completed</span>';
        else if (activity.status === 'rejected') statusBadge = '<span class="badge bg-danger">Rejected</span>';
        
        var paymentMethod = paymentMethodLabel(activity.payment_method);
        var type = activity.type ? activity.type.charAt(0).toUpperCase() + activity.type.slice(1) : 'Deposit';
        
        html += '<tr class="rep-report-row">' +
            '<td class="text-muted">' + formatRepDate(activity.created_at) + '</td>' +
            '<td><code class="small bg-light px-2 py-1 rounded">' + escapeRepHtml(activity.reference_code) + '</code></td>' +
            '<td class="fw-semibold ' + amountClass + '">' + amountPrefix + ' €' + parseFloat(activity.amount).toFixed(2) + '</td>' +
            '<td><span class="badge bg-secondary">' + escapeRepHtml(paymentMethod) + '</span></td>' +
            '<td>' + statusBadge + '</td>' +
            '<td><span class="badge bg-primary">' + escapeRepHtml(type) + '</span></td>' +
            '<td>' + (activity.type === 'deposit' ? 
                '<a href="/advertiser/invoice/' + escapeRepHtml(activity.reference_code) + '" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fa fa-file-invoice"></i> Invoice</a>' : 
                '<span class="text-muted">—</span>') + 
            '</td>' +
            '</tr>';
    }
    
    $('#repFundsTableBody').html(html);
}

function loadRepOrdersData(page) {
    page = page || 1;
    repOrdersPage = page;
    
    $('#repOrdersTableBody').html('\
        <tr>\
            <td colspan="11" class="text-center py-5">\
                <div class="spinner-border text-primary" role="status">\
                    <span class="visually-hidden">Loading...</span>\
                </div>\
                <p class="mt-2 text-muted">Loading orders...</p>\
            </td>\
        </tr>\
    ');
    
    $.ajax({
        url: '{{ route("advertiser.reports.orders") }}',
        method: 'GET',
        data: { page: page },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderRepOrdersTable(response.orders);
                renderRepOrdersPagination(response.pagination);
                $('#repOrdersResultsCount').html('Showing ' + (response.pagination.from || 0) + ' to ' + (response.pagination.to || 0) + ' of ' + response.pagination.total + ' entries');
            } else {
                $('#repOrdersTableBody').html('\
                    <tr>\
                        <td colspan="11" class="text-center text-danger py-5">\
                            ' + (response.message || 'Failed to load orders') + '\
                        </td>\
                    </tr>\
                ');
            }
        },
        error: function() {
            $('#repOrdersTableBody').html('\
                <tr>\
                    <td colspan="11" class="text-center text-danger py-5">\
                        Error loading orders. Please refresh the page.\
                    </td>\
                </tr>\
            ');
        }
    });
}

function repOrderStatusBadge(order) {
    var label = order.status_label || '';
    if (!label && order.status) {
        label = String(order.status).charAt(0).toUpperCase() + String(order.status).slice(1);
    }
    if (!label) label = '—';
    var cls = 'bg-secondary';
    var payment = order.payment_status;
    if (payment === 'failed' || (payment === 'refunded' && order.status !== 'completed')) {
        cls = 'bg-secondary';
    } else if (order.status === 'completed') cls = 'bg-success';
    else if (order.status === 'cancelled') cls = 'bg-danger';
    else if (order.status === 'review') cls = 'bg-warning text-dark';
    else if (order.status === 'processing') cls = 'bg-info text-dark';
    else if (order.status === 'pending') cls = 'bg-warning text-dark';
    return '<span class="badge ' + cls + '">' + escapeRepHtml(label) + '</span>';
}

function renderRepOrderRow(order, item) {
    var additionalPrice = item ? parseFloat(item.additional_price || 0) : NaN;
    var linePrice = item ? parseFloat(item.price) : NaN;
    var basePrice = Number.isFinite(linePrice) ? (linePrice - (Number.isFinite(additionalPrice) ? additionalPrice : 0)) : NaN;
    var sensitiveType = item ? (item.sensitive_type || null) : null;
    var siteName = item && item.site_name ? item.site_name : (order.placements_missing ? 'No line items' : '—');
    var siteUrl = item && item.site_url ? item.site_url : '';
    var paymentStatusBadge = '';
    if (order.payment_status === 'pending') paymentStatusBadge = '<span class="badge bg-warning text-dark">Pending</span>';
    else if (order.payment_status === 'paid') paymentStatusBadge = '<span class="badge bg-success">Paid</span>';
    else if (order.payment_status === 'failed') paymentStatusBadge = '<span class="badge bg-danger">Failed</span>';
    else if (order.payment_status === 'refunded') paymentStatusBadge = '<span class="badge bg-info text-dark">Refunded</span>';
    else paymentStatusBadge = '<span class="badge bg-secondary">' + escapeRepHtml(order.payment_status || '—') + '</span>';

    var baseAmount = Number.isFinite(basePrice) ? ('€' + basePrice.toFixed(2)) : '—';
    var lineAmount = Number.isFinite(linePrice) ? ('€' + linePrice.toFixed(2)) : ('€' + parseFloat(order.total_amount || 0).toFixed(2));
    var baseCell = '<td class="text-primary">' + baseAmount + '</td>';
    var lineCell = '<td class="fw-semibold">' + lineAmount + '</td>';
    if (order.payment_status === 'refunded') {
        baseCell = '<td class="fw-semibold text-muted"><s>' + baseAmount + '</s> <span class="small">Refunded</span></td>';
        lineCell = '<td class="fw-semibold text-muted"><s>' + lineAmount + '</s> <span class="small">Refunded</span></td>';
    } else if (order.payment_status === 'failed') {
        baseCell = '<td class="fw-semibold text-muted">' + baseAmount + ' <span class="small">Failed</span></td>';
        lineCell = '<td class="fw-semibold text-muted">' + lineAmount + ' <span class="small">Failed</span></td>';
    }

    return '<tr class="rep-report-row">' +
        '<td><code class="fw-semibold bg-light px-2 py-1 rounded">#' + escapeRepHtml(order.order_number) + '</code></td>' +
        '<td class="text-muted">' + formatRepDate(order.created_at) + '</td>' +
        '<td>' +
            '<div class="fw-semibold">' + escapeRepHtml(siteName) + '</div>' +
            (siteUrl ? '<small class="text-muted">' + truncateRep(siteUrl, 30) + '</small>' : '') +
        '</td>' +
        baseCell +
        '<td>' + (Number.isFinite(additionalPrice) && additionalPrice > 0 ?
            '<span class="rep-sensitive-badge"><i class="fa fa-plus-circle"></i> ' + escapeRepHtml(sensitiveType || 'Sensitive') + ' (+€' + additionalPrice.toFixed(2) + ')</span>' :
            '<span class="text-muted">—</span>') +
        '</td>' +
        lineCell +
        '<td><code class="small bg-light px-2 py-1 rounded">' + escapeRepHtml(order.reference_code) + '</code></td>' +
        '<td><span class="badge bg-secondary">' + escapeRepHtml(paymentMethodLabel(order.payment_method)) + '</span></td>' +
        '<td>' + repOrderStatusBadge(order) + '</td>' +
        '<td>' + paymentStatusBadge + '</td>' +
        '<td>' +
            '<a href="/advertiser/invoice/' + escapeRepHtml(order.reference_code) + '" class="btn btn-sm btn-outline-primary" target="_blank"><i class="fa fa-file-invoice"></i> Invoice</a>' +
        '</td>' +
        '</tr>';
}

function renderRepOrdersTable(orders) {
    if (!orders || orders.length === 0) {
        $('#repOrdersTableBody').html('\
            <tr>\
                <td colspan="11" class="text-center py-5">\
                    <i class="fa fa-inbox fa-3x text-muted"></i>\
                    <p class="mt-2">No orders found</p>\
                </td>\
            </tr>\
        ');
        return;
    }

    var html = '';
    for (var i = 0; i < orders.length; i++) {
        var order = orders[i];
        var items = order.items && order.items.length ? order.items : [null];
        for (var j = 0; j < items.length; j++) {
            html += renderRepOrderRow(order, items[j]);
        }
    }

    $('#repOrdersTableBody').html(html);
}

function renderRepFundsPagination(pagination) {
    if (!pagination || pagination.last_page <= 1) {
        $('#repFundsPaginationNav').html('');
        return;
    }
    
    var paginationHtml = '<ul class="pagination justify-content-center mb-0">';
    
    if (pagination.current_page > 1) {
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="' + (pagination.current_page - 1) + '" data-type="funds">Previous</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">Previous</span></li>';
    }
    
    var startPage = Math.max(1, pagination.current_page - 2);
    var endPage = Math.min(pagination.last_page, pagination.current_page + 2);
    
    if (startPage > 1) {
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="1" data-type="funds">1</button></li>';
        if (startPage > 2) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">...</span></li>';
        }
    }
    
    for (var i = startPage; i <= endPage; i++) {
        var activeClass = i === pagination.current_page ? 'active' : '';
        paginationHtml += '<li class="page-item ' + activeClass + '"><button class="page-link rep-page-link" data-page="' + i + '" data-type="funds">' + i + '</button></li>';
    }
    
    if (endPage < pagination.last_page) {
        if (endPage < pagination.last_page - 1) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">...</span></li>';
        }
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="' + pagination.last_page + '" data-type="funds">' + pagination.last_page + '</button></li>';
    }
    
    if (pagination.current_page < pagination.last_page) {
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="' + (pagination.current_page + 1) + '" data-type="funds">Next</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">Next</span></li>';
    }
    
    paginationHtml += '</ul>';
    $('#repFundsPaginationNav').html(paginationHtml);
    
    $('#repFundsPaginationNav .page-link[data-page]').off('click').on('click', function(e) {
        e.preventDefault();
        var page = parseInt($(this).data('page'));
        if (page) {
            loadRepFundsData(page);
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        }
    });
}

function renderRepOrdersPagination(pagination) {
    if (!pagination || pagination.last_page <= 1) {
        $('#repOrdersPaginationNav').html('');
        return;
    }
    
    var paginationHtml = '<ul class="pagination justify-content-center mb-0">';
    
    if (pagination.current_page > 1) {
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="' + (pagination.current_page - 1) + '" data-type="orders">Previous</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">Previous</span></li>';
    }
    
    var startPage = Math.max(1, pagination.current_page - 2);
    var endPage = Math.min(pagination.last_page, pagination.current_page + 2);
    
    if (startPage > 1) {
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="1" data-type="orders">1</button></li>';
        if (startPage > 2) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">...</span></li>';
        }
    }
    
    for (var i = startPage; i <= endPage; i++) {
        var activeClass = i === pagination.current_page ? 'active' : '';
        paginationHtml += '<li class="page-item ' + activeClass + '"><button class="page-link rep-page-link" data-page="' + i + '" data-type="orders">' + i + '</button></li>';
    }
    
    if (endPage < pagination.last_page) {
        if (endPage < pagination.last_page - 1) {
            paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">...</span></li>';
        }
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="' + pagination.last_page + '" data-type="orders">' + pagination.last_page + '</button></li>';
    }
    
    if (pagination.current_page < pagination.last_page) {
        paginationHtml += '<li class="page-item"><button class="page-link rep-page-link" data-page="' + (pagination.current_page + 1) + '" data-type="orders">Next</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link rep-page-link">Next</span></li>';
    }
    
    paginationHtml += '</ul>';
    $('#repOrdersPaginationNav').html(paginationHtml);
    
    $('#repOrdersPaginationNav .page-link[data-page]').off('click').on('click', function(e) {
        e.preventDefault();
        var page = parseInt($(this).data('page'));
        if (page) {
            loadRepOrdersData(page);
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        }
    });
}

function paymentMethodLabel(method) {
    var labels = {
        card: 'Card',
        paypal: 'PayPal',
        wallet: 'Wallet',
        bank: 'Bank Transfer',
        bank_transfer: 'Bank Transfer',
        wise: 'Wise',
        crypto: 'Cryptocurrency'
    };
    if (!method) return 'N/A';
    var key = String(method).toLowerCase();
    return labels[key] || (key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' '));
}

function formatRepDate(dateString) {
    if (!dateString) return 'N/A';
    var date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function truncateRep(str, length) {
    if (!str) return '';
    if (str.length <= length) return str;
    return str.substring(0, length) + '...';
}

function escapeRepHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
</script>

@endsection