@extends('admin.layouts.app')

@section('title', 'Payments')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Payments Management</h2>
            <p class="text-muted mb-0">Manage and update payment statuses for all orders</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Search</label>
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Order #, Reference, User...">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Payment Status</label>
                    <select id="paymentStatusFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Payment Method</label>
                    <select id="paymentMethodFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="card">Credit/Debit Card</option>
                        <option value="wallet">Wallet Balance</option>
                        <option value="wise">Wise Transfer</option>
                        <option value="crypto">Cryptocurrency</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Order Status</label>
                    <select id="orderStatusFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Date Range</label>
                    <div class="input-group">
                        <input type="date" id="dateFrom" class="form-control form-control-sm" placeholder="From">
                        <input type="date" id="dateTo" class="form-control form-control-sm" placeholder="To">
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="fa fa-search"></i> Filter
                    </button>
                    <button type="reset" id="resetFiltersBtn" class="btn btn-secondary btn-sm px-3">
                        <i class="fa fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th width="10%">Order #</th>
                            <th width="15%">User</th>
                            <th width="8%">Reference</th>
                            <th width="8%">Amount</th>
                            <th width="10%">Payment Method</th>
                            <th width="12%">Payment Status</th>
                            <th width="10%">Order Status</th>
                            <th width="12%">Paid At</th>
                            <th width="10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <tr>
                            <td colspan="10" class="text-center py-5">
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
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="update_order_id">
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">Order Number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fa fa-hashtag text-muted"></i>
                        </span>
                        <input type="text" id="update_order_number" class="form-control bg-light border-start-0" readonly>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">Current Payment Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fa fa-info-circle text-muted"></i>
                        </span>
                        <input type="text" id="update_current_status" class="form-control bg-light border-start-0" readonly>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Payment Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fa fa-exchange-alt text-muted"></i>
                        </span>
                        <select id="update_payment_status" class="form-select border-start-0">
                            <option value="pending">⏳ Pending</option>
                            <option value="paid">✅ Paid</option>
                            <option value="failed">❌ Failed</option>
                            <option value="refunded">🔄 Refunded</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-0">
                    <div class="form-check">
                        <input type="checkbox" id="send_notification" class="form-check-input" checked>
                        <label class="form-check-label" for="send_notification">
                            <i class="fa fa-envelope me-1"></i> Send email notification to customer
                        </label>
                        <small class="text-muted d-block mt-1">Notify the customer when payment status is updated to "Paid"</small>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let currentPage = 1;

$(document).ready(function() {
    // Support deep-links from ops dashboard, e.g. ?payment_status=pending&search=ORD-123
    const params = new URLSearchParams(window.location.search);
    if (params.get('payment_status')) {
        $('#paymentStatusFilter').val(params.get('payment_status'));
    }
    if (params.get('payment_method')) {
        $('#paymentMethodFilter').val(params.get('payment_method'));
    }
    if (params.get('status')) {
        $('#orderStatusFilter').val(params.get('status'));
    }
    if (params.get('search')) {
        $('#searchInput').val(params.get('search'));
    }

    loadPayments();

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        loadPayments();
    });

    $('#resetFiltersBtn').on('click', function() {
        $('#searchInput').val('');
        $('#paymentStatusFilter').val('');
        $('#paymentMethodFilter').val('');
        $('#orderStatusFilter').val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        currentPage = 1;
        loadPayments();
    });

    $(document).on('click', '.update-payment-btn', function() {
        var orderId = $(this).data('id');
        var orderNumber = $(this).data('order');
        var currentStatus = $(this).data('status');
        
        $('#update_order_id').val(orderId);
        $('#update_order_number').val(orderNumber);
        $('#update_current_status').val(currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1));
        $('#update_payment_status').val(currentStatus);
        $('#update_notes').val('');
        $('#send_notification').prop('checked', true);
        
        new bootstrap.Modal(document.getElementById('updatePaymentModal')).show();
    });

    $('#savePaymentUpdate').on('click', function() {
        var orderId = $('#update_order_id').val();
        var newStatus = $('#update_payment_status').val();
        var notes = $('#update_notes').val();
        var sendNotification = $('#send_notification').is(':checked');
        
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
        
        $.ajax({
            url: '/admin/payments/' + orderId + '/update-status',
            method: 'POST',
            data: {
                payment_status: newStatus,
                notes: notes,
                send_notification: sendNotification,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire('Success!', response.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('updatePaymentModal')).hide();
                    loadPayments();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: function(xhr) {
                Swal.fire('Error', xhr.responseJSON?.message || 'Failed to update payment status', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Update Payment');
            }
        });
    });

    function loadPayments(page = 1) {
        currentPage = page;
        $('#paymentsTableBody').html(
            '<tr>' +
                '<td colspan="10" class="text-center py-5">' +
                    '<div class="spinner-border text-primary" role="status">' +
                        '<span class="visually-hidden">Loading...</span>' +
                    '</div>' +
                    '<p class="mt-2 text-muted">Loading payments...</p>' +
                '</td>' +
            '</tr>'
        );
        
        $.ajax({
            url: '/admin/payments/data',
            method: 'GET',
            data: {
                page: page,
                search: $('#searchInput').val(),
                payment_status: $('#paymentStatusFilter').val(),
                payment_method: $('#paymentMethodFilter').val(),
                status: $('#orderStatusFilter').val(),
                date_from: $('#dateFrom').val(),
                date_to: $('#dateTo').val()
            },
            success: function(response) {
                if (response.success) {
                    renderPaymentsTable(response.data);
                    renderPagination(response.pagination);
                } else {
                    $('#paymentsTableBody').html('<tr><td colspan="10" class="text-center text-danger py-5">' + (response.message || 'Failed to load payments') + '</td></tr>');
                }
            },
            error: function() {
                $('#paymentsTableBody').html('<tr><td colspan="10" class="text-center text-danger py-5">Error loading payments. Please refresh the page.</td></tr>');
            }
        });
    }

    function renderPaymentsTable(orders) {
        if (!orders || orders.length === 0) {
            $('#paymentsTableBody').html('<tr><td colspan="10" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2">No payments found</p></td></tr>');
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
                    paymentStatusBadge = '<span class="badge bg-secondary px-3 py-2">' + order.payment_status + '</span>';
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
                default:
                    orderStatusBadge = '<span class="badge bg-secondary px-3 py-2">' + order.status + '</span>';
            }
            
            // Payment Method Badge
            var paymentMethodBadge = '';
            switch(order.payment_method) {
                case 'card':
                    paymentMethodBadge = '<span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2"><i class="fab fa-cc-visa me-1"></i> Card</span>';
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
                    paymentMethodBadge = '<span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">' + order.payment_method + '</span>';
            }
            
            // Format date without time
            var paidAt = '-';
            if (order.paid_at) {
                var date = new Date(order.paid_at);
                paidAt = date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }
            
            // Calculate row number
            var rowNumber = ((currentPage - 1) * 20) + (index + 1);
            
            html += '<tr>';
            html += '<td class="text-center">' + rowNumber + '</td>';
            html += '<td><strong>' + order.order_number + '</strong></td>';
            html += '<td>';
            html += '<div class="d-flex flex-column">';
            html += '<span class="fw-semibold">' + (order.user ? order.user.name : 'N/A') + '</span>';
            html += '<small class="text-muted">' + (order.user ? order.user.email : 'No email') + '</small>';
            html += '</div>';
            html += '</td>';
            html += '<td><code class="small">' + order.reference_code + '</code></td>';
            html += '<td class="fw-bold text-primary">€' + parseFloat(order.total_amount).toFixed(2) + '</td>';
            html += '<td>' + paymentMethodBadge + '</td>';
            html += '<td>' + paymentStatusBadge + '</td>';
            html += '<td>' + orderStatusBadge + '</td>';
            html += '<td>' + paidAt + '</td>';
            html += '<td>';
            
            // Only show Update button if payment status is NOT 'paid'
            if (order.payment_status !== 'paid') {
                html += '<button class="btn btn-sm btn-outline-primary update-payment-btn" ';
                html += 'data-id="' + order.id + '" ';
                html += 'data-order="' + order.order_number + '" ';
                html += 'data-status="' + order.payment_status + '">';
                html += '<i class="fa fa-edit"></i> Update';
                html += '</button>';
            } else {
                html += '<span class="badge bg-success px-3 py-2"><i class="fa fa-check-circle me-1"></i> Completed</span>';
            }
            
            html += '</td>';
            html += '</tr>';
        });
        
        $('#paymentsTableBody').html(html);
    }

    function renderPagination(pagination) {
        if (!pagination || pagination.total === 0) {
            $('#paginationInfo').html('Showing 0 entries');
            $('#paginationLinks').html('');
            return;
        }
        
        $('#paginationInfo').html('Showing <strong>' + pagination.from + '</strong> to <strong>' + pagination.to + '</strong> of <strong>' + pagination.total + '</strong> entries');
        
        var paginationHtml = '';
        
        if (pagination.current_page > 1) {
            paginationHtml += '<li class="page-item"><a class="page-link" href="#" data-page="' + (pagination.current_page - 1) + '">Previous</a></li>';
        }
        
        var startPage = Math.max(1, pagination.current_page - 2);
        var endPage = Math.min(pagination.last_page, pagination.current_page + 2);
        
        for (var i = startPage; i <= endPage; i++) {
            var activeClass = i === pagination.current_page ? 'active' : '';
            paginationHtml += '<li class="page-item ' + activeClass + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }
        
        if (pagination.current_page < pagination.last_page) {
            paginationHtml += '<li class="page-item"><a class="page-link" href="#" data-page="' + (pagination.current_page + 1) + '">Next</a></li>';
        }
        
        $('#paginationLinks').html(paginationHtml);
        
        $('.page-link').off('click').on('click', function(e) {
            e.preventDefault();
            var page = $(this).data('page');
            if (page) {
                loadPayments(page);
                $('html, body').animate({ scrollTop: 0 }, 'fast');
            }
        });
    }
});
</script>

<style>
/* Use more specific selectors to avoid conflicts with layout */
.admin-payments-container .table > :not(caption) > * > * {
    padding: 12px 8px;
    vertical-align: middle;
}

.admin-payments-container .badge {
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 6px;
}

.admin-payments-container .update-payment-btn {
    white-space: nowrap;
    padding: 4px 12px;
}

.modal-dialog-centered {
    display: flex;
    align-items: center;
    min-height: calc(100% - 1rem);
}

.admin-payments-container code {
    background-color: #f8f9fa;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
}

/* Pagination styles */
.pagination .page-link {
    color: #0d6efd;
    cursor: pointer;
    font-size: 13px;
    padding: 5px 10px;
}

.pagination .active .page-link {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: white;
}

.pagination .page-item.disabled .page-link {
    cursor: not-allowed;
    opacity: 0.6;
}

/* Card styles */
.card.border-0 {
    border: none !important;
}

.shadow-sm {
    box-shadow: 0 .125rem .25rem rgba(0,0,0,.075) !important;
}

/* Dark mode support */
body.layout-dark .admin-payments-container code {
    background-color: #2d2d2d;
    color: #e0e0e0;
}

body.layout-dark .bg-light {
    background-color: #2d2d2d !important;
}

body.layout-dark .text-muted {
    color: #9ca3af !important;
}

body.layout-dark .table-light {
    background-color: #374151;
    color: #e5e7eb;
}
</style>
@endsection