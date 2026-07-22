@extends('publisher.layouts.app')

@section('title', 'Reports')

@section('content')
<div class="publisher-reports-container">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Financial Reports</h2>
            <p class="text-muted mb-0">
                View your earnings and withdrawal history.
            </p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Earned</h6>
                        <h3 class="mb-0" id="totalEarned" style="color: #10b981;">€0</h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-euro-sign fa-2x text-success"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Completed Orders</h6>
                        <h3 class="mb-0" id="completedOrders">0</h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-check-circle fa-2x text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Withdrawn</h6>
                        <h3 class="mb-0" id="totalWithdrawn" style="color: #ef4444;">€0</h3>
                    </div>
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-download fa-2x text-danger"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
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

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Orders Tab -->
        <div class="tab-pane fade show active" id="orders" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa fa-shopping-cart me-2"></i> Completed Orders
                    </div>
                    <div>
                        <small class="text-muted" id="ordersResultsCount"></small>
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
                                    <th>Total Earned</th>
                                    <th>Order Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTableBody">
                                <tr>
                                    <td colspan="8" class="text-center py-5">
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

        <!-- Withdrawals Tab -->
        <div class="tab-pane fade" id="withdrawals" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa fa-download me-2"></i> Completed Withdrawals
                    </div>
                    <div>
                        <small class="text-muted" id="withdrawalsResultsCount"></small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 publisher-reports-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="withdrawalsTableBody">
                                <tr>
                                    <td colspan="5" class="text-center py-5">
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

<!-- Order Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="orderDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Scoped styles to prevent conflicts */
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

.publisher-reports-container .status-pending {
    background-color: #fef3c7;
    color: #282828;
}

.publisher-reports-container .status-processing {
    background-color: #dbeafe;
    color: #282828;
}

.publisher-reports-container .status-completed {
    background-color: #dcfce7;
    color: #282828;
}

.publisher-reports-container .status-cancelled {
    background-color: #fee2e2;
    color: #282828;
}

.publisher-reports-container .sensitive-badge {
    background-color: #fef3c7;
    color: #d97706;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
}

.publisher-reports-container .amount-positive {
    color: #10b981;
    font-weight: 600;
}

.publisher-reports-container .amount-negative {
    color: #ef4444;
    font-weight: 600;
}

.publisher-reports-container .btn-sm {
    padding: 4px 10px;
    font-size: 11px;
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

/* Tab styles scoped */
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
    transition: all 0.2s;
}

.publisher-reports-container .publisher-reports-tabs .nav-link:hover {
    color: #185054;
    background: transparent;
}

.publisher-reports-container .publisher-reports-tabs .nav-link.active {
    color: #185054;
    border-bottom: 2px solid #185054;
    background: transparent;
}

/* Dark mode styles */










</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
var ordersPage = 1;
var withdrawalsPage = 1;

$(document).ready(function() {
    loadOrders();
    loadWithdrawals();
    loadStatistics();

    $('#orders-tab').on('click', function() {
        loadOrders();
    });
    
    $('#withdrawals-tab').on('click', function() {
        loadWithdrawals();
    });
});

function loadStatistics() {
    $.ajax({
        url: '/publisher/reports/statistics',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#totalEarned').html('<span style="color: #10b981;">+ €' + parseFloat(response.data.total_earned).toFixed(2) + '</span>');
                $('#completedOrders').text(response.data.completed_orders);
                $('#totalWithdrawn').html('<span style="color: #ef4444;">- €' + parseFloat(response.data.total_withdrawn).toFixed(2) + '</span>');
            }
        },
        error: function() {
            console.error('Failed to load statistics');
        }
    });
}

function loadOrders(page) {
    page = page || 1;
    ordersPage = page;
    $('#ordersTableBody').html('<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Loading orders...</p></td></tr>');
    
    $.ajax({
        url: '/publisher/reports/orders',
        method: 'GET',
        data: { page: page, status: 'completed' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderOrdersTable(response.data);
                renderOrdersPagination(response.pagination);
                $('#ordersResultsCount').html('Showing ' + response.pagination.from + ' to ' + response.pagination.to + ' of ' + response.pagination.total + ' entries');
            } else {
                $('#ordersTableBody').html('<tr><td colspan="8" class="text-center text-danger py-5">' + (response.message || 'Failed to load orders') + '</td></tr>');
            }
        },
        error: function() {
            $('#ordersTableBody').html('<tr><td colspan="8" class="text-center text-danger py-5">Error loading orders. Please refresh the page.</td></tr>');
        }
    });
}

function renderOrdersTable(orderItems) {
    if (!orderItems || orderItems.length === 0) {
        $('#ordersTableBody').html('<tr><td colspan="8" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2">No completed orders found</p></td></tr>');
        return;
    }
    
    var html = '';
    for (var i = 0; i < orderItems.length; i++) {
        var item = orderItems[i];
        var orderNumber = item.order ? item.order.order_number : 'N/A';
        var orderStatus = item.order ? item.order.status : 'pending';
        var additionalPrice = parseFloat(item.additional_price || 0);
        var basePrice = parseFloat(item.price) - additionalPrice;
        var sensitiveType = item.sensitive_type || null;
        var totalPrice = parseFloat(item.price);
        
        var statusClass = '';
        var statusText = '';
        switch(orderStatus) {
            case 'pending': statusClass = 'status-pending'; statusText = 'Pending'; break;
            case 'processing': statusClass = 'status-processing'; statusText = 'Processing'; break;
            case 'completed': statusClass = 'status-completed'; statusText = 'Completed'; break;
            case 'cancelled': statusClass = 'status-cancelled'; statusText = 'Cancelled'; break;
            default: statusClass = 'status-pending'; statusText = orderStatus;
        }
        
        html += '<tr>' +
            '<td class="fw-semibold"><strong>#' + escapeHtml(orderNumber) + '</strong></td>' +
            '<td>' + formatDate(item.created_at) + '</td>' +
            '<td><div class="fw-semibold">' + escapeHtml(item.site_name) + '</div><div class="text-muted small"><a href="' + escapeHtml(item.site_url) + '" target="_blank">' + escapeHtml(item.site_url) + '</a></div></td>' +
            '<td class="text-primary">€' + basePrice.toFixed(2) + '</td>' +
            '<td>' + (additionalPrice > 0 ? '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> ' + escapeHtml(sensitiveType || 'Extra') + ' (+€' + additionalPrice.toFixed(2) + ')</span>' : '<span class="text-muted">—</span>') + '</td>' +
            '<td class="earned-amount"><strong>+ €' + totalPrice.toFixed(2) + '</strong></td>' +
            '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>' +
            '<td><button class="btn btn-sm btn-outline-info" onclick="viewOrderDetails(' + item.id + ')"><i class="fa fa-eye"></i> View</button></td>' +
            '</tr>';
    }
    
    $('#ordersTableBody').html(html);
}

function viewOrderDetails(orderItemId) {
    fetch('/publisher/reports/orders/' + orderItemId + '/details', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            renderOrderDetailsModal(data.data);
            $('#orderDetailsModal').modal('show');
        } else {
            Swal.fire('Error', data.message || 'Failed to load order details', 'error');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to load order details', 'error');
    });
}

function renderOrderDetailsModal(orderItem) {
    var order = orderItem.order;
    var additionalPrice = parseFloat(orderItem.additional_price || 0);
    var basePrice = parseFloat(orderItem.price) - additionalPrice;
    var sensitiveType = orderItem.sensitive_type || null;
    var liveUrl = orderItem.live_url || null;
    var totalPrice = parseFloat(orderItem.price);
    
    var liveUrlHtml = liveUrl 
        ? '<p class="mb-1"><strong>Live URL:</strong></p><p class="mb-2"><a href="' + escapeHtml(liveUrl) + '" target="_blank" class="text-success">' + escapeHtml(liveUrl) + ' <i class="fa fa-external-link fa-xs"></i></a></p>'
        : '<p class="mb-2 text-muted">Live URL not submitted yet</p>';
    
    var html = '<div class="row mb-4">' +
        '<div class="col-md-6">' +
            '<div class="bg-light p-3 rounded">' +
                '<h6 class="mb-3">Order Information</h6>' +
                '<p class="mb-1"><strong>Order Number:</strong> #' + escapeHtml(order.order_number) + '</p>' +
                '<p class="mb-1"><strong>Date:</strong> ' + formatDate(order.created_at) + '</p>' +
                '<p class="mb-1"><strong>Payment Status:</strong> <span class="badge bg-success">Paid</span></p>' +
                '<p class="mb-1"><strong>Reference Code:</strong> ' + escapeHtml(order.reference_code || '-') + '</p>' +
            '</div>' +
        '</div>' +
        '<div class="col-md-6">' +
            '<div class="bg-light p-3 rounded">' +
                '<h6 class="mb-3">Earnings Summary</h6>' +
                '<p class="mb-1"><strong>Base Price:</strong> €' + basePrice.toFixed(2) + '</p>' +
                (additionalPrice > 0 ? '<p class="mb-1"><strong>Sensitive Price:</strong> <span class="text-warning">+ €' + additionalPrice.toFixed(2) + ' (' + escapeHtml(sensitiveType) + ')</span></p>' : '') +
                '<p class="mb-1"><strong>Total Earned:</strong> <span class="earned-amount fs-4">+ €' + totalPrice.toFixed(2) + '</span></p>' +
            '</div>' +
        '</div>' +
    '</div>' +
    '<h6 class="mb-3">Order Items</h6>' +
    '<div class="border rounded p-3">' +
        '<div class="row">' +
            '<div class="col-md-6">' +
                '<p class="mb-1"><strong>Site Name:</strong></p>' +
                '<p class="mb-2">' + escapeHtml(orderItem.site_name) + '</p>' +
                '<p class="mb-1"><strong>Site URL:</strong></p>' +
                '<p class="mb-2"><a href="' + escapeHtml(orderItem.site_url) + '" target="_blank" class="text-primary">' + escapeHtml(orderItem.site_url) + ' <i class="fa fa-external-link fa-xs"></i></a></p>' +
            '</div>' +
            '<div class="col-md-6">' +
                '<p class="mb-1"><strong>Content Link:</strong></p>' +
                '<p class="mb-2"><a href="' + escapeHtml(orderItem.content_link) + '" target="_blank" class="text-primary text-break">' + escapeHtml(orderItem.content_link) + ' <i class="fa fa-external-link fa-xs"></i></a></p>' +
                liveUrlHtml +
            '</div>' +
        '</div>' +
    '</div>';
    
    $('#orderDetailsContent').html(html);
}

function loadWithdrawals(page) {
    page = page || 1;
    withdrawalsPage = page;
    $('#withdrawalsTableBody').html('<tr><td colspan="5" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Loading withdrawals...</p></td></tr>');
    
    $.ajax({
        url: '/publisher/reports/withdrawals',
        method: 'GET',
        data: { page: page, status: 'completed' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderWithdrawalsTable(response.data);
                renderWithdrawalsPagination(response.pagination);
                $('#withdrawalsResultsCount').html('Showing ' + response.pagination.from + ' to ' + response.pagination.to + ' of ' + response.pagination.total + ' entries');
            } else {
                $('#withdrawalsTableBody').html('<tr><td colspan="5" class="text-center text-danger py-5">' + (response.message || 'Failed to load withdrawals') + '</td></tr>');
            }
        },
        error: function() {
            $('#withdrawalsTableBody').html('<tr><td colspan="5" class="text-center text-danger py-5">Error loading withdrawals. Please refresh the page.</td></tr>');
        }
    });
}

function renderWithdrawalsTable(withdrawals) {
    if (!withdrawals || withdrawals.length === 0) {
        $('#withdrawalsTableBody').html('<tr><td colspan="5" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2">No completed withdrawals found</p></td></tr>');
        return;
    }
    
    var html = '';
    for (var i = 0; i < withdrawals.length; i++) {
        var withdrawal = withdrawals[i];
        
        var statusBadge = '';
        switch(withdrawal.status) {
            case 'pending': statusBadge = '<span class="badge bg-warning">Pending</span>'; break;
            case 'approved': statusBadge = '<span class="badge bg-info">Approved</span>'; break;
            case 'completed': statusBadge = '<span class="badge bg-success">Completed</span>'; break;
            case 'rejected': statusBadge = '<span class="badge bg-danger">Rejected</span>'; break;
            default: statusBadge = '<span class="badge bg-secondary">' + withdrawal.status + '</span>';
        }
        
        html += '<tr>' +
            '<td>' + formatDate(withdrawal.created_at) + '</td>' +
            '<td class="withdrawn-amount"><strong>- €' + parseFloat(withdrawal.amount).toFixed(2) + '</strong></td>' +
            '<td><span class="badge bg-secondary">' + escapeHtml(withdrawal.payment_method || 'Bank Transfer') + '</span></td>' +
            '<td>' + statusBadge + '</td>' +
            '<td>' + (withdrawal.payment_reference ? '<span class="text-muted small">Ref: ' + escapeHtml(withdrawal.payment_reference) + '</span>' : '<span class="text-muted">—</span>') + '</td>' +
            '</tr>';
    }
    
    $('#withdrawalsTableBody').html(html);
}

function renderOrdersPagination(pagination) {
    if (!pagination || pagination.last_page <= 1) {
        $('#ordersPaginationNav').html('');
        return;
    }
    
    var paginationHtml = '<ul class="pagination justify-content-center mb-0">';
    
    if (pagination.current_page > 1) {
        paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page - 1) + '">Previous</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    for (var i = 1; i <= pagination.last_page; i++) {
        if (i >= pagination.current_page - 2 && i <= pagination.current_page + 2) {
            var activeClass = i === pagination.current_page ? 'active' : '';
            paginationHtml += '<li class="page-item ' + activeClass + '"><button class="page-link" data-page="' + i + '">' + i + '</button></li>';
        }
    }
    
    if (pagination.current_page < pagination.last_page) {
        paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page + 1) + '">Next</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    paginationHtml += '</ul>';
    $('#ordersPaginationNav').html(paginationHtml);
    
    $('.page-link[data-page]').off('click').on('click', function(e) {
        e.preventDefault();
        var page = parseInt($(this).data('page'));
        if (page) {
            loadOrders(page);
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        }
    });
}

function renderWithdrawalsPagination(pagination) {
    if (!pagination || pagination.last_page <= 1) {
        $('#withdrawalsPaginationNav').html('');
        return;
    }
    
    var paginationHtml = '<ul class="pagination justify-content-center mb-0">';
    
    if (pagination.current_page > 1) {
        paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page - 1) + '">Previous</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    for (var i = 1; i <= pagination.last_page; i++) {
        if (i >= pagination.current_page - 2 && i <= pagination.current_page + 2) {
            var activeClass = i === pagination.current_page ? 'active' : '';
            paginationHtml += '<li class="page-item ' + activeClass + '"><button class="page-link" data-page="' + i + '">' + i + '</button></li>';
        }
    }
    
    if (pagination.current_page < pagination.last_page) {
        paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page + 1) + '">Next</button></li>';
    } else {
        paginationHtml += '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    paginationHtml += '</ul>';
    $('#withdrawalsPaginationNav').html(paginationHtml);
    
    $('.page-link[data-page]').off('click').on('click', function(e) {
        e.preventDefault();
        var page = parseInt($(this).data('page'));
        if (page) {
            loadWithdrawals(page);
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        }
    });
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    var date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function escapeHtml(str) {
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