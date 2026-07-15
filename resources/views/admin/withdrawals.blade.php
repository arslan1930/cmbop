@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">

    <h4 class="mb-4 fw-bold">Withdrawals Management</h4>

    <!-- Filters Section -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Status</label>
                    <select id="statusFilter" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
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
                        <input type="date" id="dateFrom" class="form-control form-control-sm" placeholder="From">
                        <input type="date" id="dateTo" class="form-control form-control-sm" placeholder="To">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Search</label>
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="User name or email...">
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12">
                    <button id="filterBtn" class="btn btn-primary btn-sm px-4">
                        <i class="fa fa-search"></i> Filter
                    </button>
                    <button id="resetFiltersBtn" class="btn btn-secondary btn-sm px-3">
                        <i class="fa fa-undo"></i> Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Withdrawals Table -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold">
            Withdrawal Requests
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Publisher</th>
                        <th>Amount</th>
                        <th>Fee (18%)</th>
                        <th>Net Amount</th>
                        <th>Payment Method</th>
                        <th>Status</th>
                        <th>Request Date</th>
                        <th width="150">Actions</th>
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
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="fa fa-tasks me-2"></i>Update Withdrawal Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="statusWithdrawalId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Status</label>
                    <select id="newStatus" class="form-select">
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes (Optional)</label>
                    <textarea id="statusNotes" class="form-control" rows="3" placeholder="Add any notes about this withdrawal..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="updateStatusBtn">Update Status</button>
            </div>
        </div>
    </div>
</div>

<style>
.btn-action-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.btn-action-group .row-1 {
    display: flex;
    gap: 5px;
    justify-content: center;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.status-pending {
    background-color: #fef3c7;
    color: #d97706;
}

.status-processing {
    background-color: #dbeafe;
    color: #2563eb;
}

.status-completed {
    background-color: #dcfce7;
    color: #16a34a;
}

.status-cancelled {
    background-color: #fee2e2;
    color: #dc2626;
}

.btn-sm-custom {
    font-size: 12px;
    padding: 4px 8px;
    line-height: 1.3;
    white-space: nowrap;
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let currentPage = 1;
let currentFilters = {};

function toast(msg, icon = 'success') {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: icon,
        title: msg,
        showConfirmButton: false,
        timer: 2000
    });
}

function loadWithdrawals(page = 1) {
    currentPage = page;
    
    const status = $('#statusFilter').val();
    const paymentMethod = $('#paymentMethodFilter').val();
    const dateFrom = $('#dateFrom').val();
    const dateTo = $('#dateTo').val();
    const search = $('#searchInput').val();
    
    $.ajax({
        url: '/admin/withdrawals/data',
        method: 'GET',
        data: {
            page: page,
            status: status,
            payment_method: paymentMethod,
            date_from: dateFrom,
            date_to: dateTo,
            search: search
        },
        success: function(response) {
            if (response.success) {
                renderWithdrawals(response.data);
                renderPagination(response.pagination);
            } else {
                $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-danger py-5">' + (response.message || 'Failed to load withdrawals') + '</td></tr>');
            }
        },
        error: function() {
            $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-danger py-5">Error loading withdrawals</td></tr>');
        }
    });
}

function renderWithdrawals(withdrawals) {
    if (!withdrawals || withdrawals.length === 0) {
        $('#withdrawalsTable').html('<tr><td colspan="10" class="text-center text-muted py-5">No withdrawal requests found</td></tr>');
        return;
    }
    
    let html = '';
    withdrawals.forEach(function(w, index) {
        const statusClass = getStatusClass(w.status);
        const statusText = capitalize(w.status);
        
        html += `
            <tr>
                <td>${((currentPage - 1) * 20) + (index + 1)}</td>
                <td>
                    <div class="d-flex flex-column">
                        <span class="fw-semibold">${escapeHtml(w.user?.name || 'N/A')}</span>
                        <small class="text-muted">${escapeHtml(w.user?.email || 'N/A')}</small>
                    </div>
                </td>
                <td class="fw-bold">€${parseFloat(w.amount).toFixed(2)}</td>
                <td class="text-danger">-€${parseFloat(w.fee).toFixed(2)}</td>
                <td class="fw-bold text-success">€${parseFloat(w.net_amount).toFixed(2)}</td>
                <td>${getPaymentMethodBadge(w.payment_method)}</td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                <td>${formatDate(w.created_at)}</td>
                <td>
                   <div class="btn-action-group">
    <div class="row-1 d-flex align-items-center gap-2 flex-nowrap">
        <button class="btn btn-sm btn-outline-info d-flex align-items-center view-details" data-id="${w.id}">
            <i class="fa fa-eye me-1"></i>
            <span>View</span>
        </button>

        ${w.status !== 'completed' && w.status !== 'cancelled' ? 
            `<button class="btn btn-sm btn-outline-warning d-flex align-items-center update-status" 
                data-id="${w.id}" data-status="${w.status}">
                <i class="fa fa-edit me-1"></i>
                <span>Update</span>
            </button>` : ''
        }
    </div>
</div>
                </td>
            </tr>
        `;
    });
    
    $('#withdrawalsTable').html(html);
}

function getStatusClass(status) {
    const classes = {
        'pending': 'status-pending',
        'processing': 'status-processing',
        'completed': 'status-completed',
        'cancelled': 'status-cancelled'
    };
    return classes[status] || 'status-pending';
}

function getPaymentMethodBadge(method) {
    const badges = {
        'bank': '<span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2"><i class="fa fa-university me-1"></i> Bank</span>',
        'paypal': '<span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2"><i class="fab fa-paypal me-1"></i> PayPal</span>',
        'wise': '<span class="badge bg-info bg-opacity-10 text-info px-3 py-2"><i class="fa fa-exchange-alt me-1"></i> Wise</span>',
        'crypto': '<span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2"><i class="fab fa-bitcoin me-1"></i> Crypto</span>'
    };
    return badges[method] || '<span class="badge bg-secondary">' + method + '</span>';
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

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function renderPagination(pagination) {
    if (!pagination || pagination.last_page <= 1) {
        $('#paginationLinks').html('');
        return;
    }
    
    let paginationHtml = '<nav><ul class="pagination justify-content-center">';
    
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
        if (page) {
            loadWithdrawals(page);
        }
    });
}

// View Details
$(document).on('click', '.view-details', function() {
    const id = $(this).data('id');
    
    $.ajax({
        url: `/admin/withdrawals/${id}`,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderDetails(response.data);
                $('#detailsModal').modal('show');
            } else {
                toast('Failed to load details', 'error');
            }
        },
        error: function() {
            toast('Failed to load details', 'error');
        }
    });
});

function renderDetails(withdrawal) {
    const paymentDetails = withdrawal.payment_details || {};
    
    let paymentDetailsHtml = '';
    switch (withdrawal.payment_method) {
        case 'bank':
            paymentDetailsHtml = `
                <p><strong>Bank Name:</strong> ${escapeHtml(paymentDetails.bank_name || 'N/A')}</p>
                <p><strong>Account Holder:</strong> ${escapeHtml(paymentDetails.account_holder || 'N/A')}</p>
                <p><strong>Account Number:</strong> ${escapeHtml(paymentDetails.account_number || 'N/A')}</p>
                <p><strong>SWIFT Code:</strong> ${escapeHtml(paymentDetails.swift_code || 'N/A')}</p>
            `;
            break;
        case 'paypal':
        case 'wise':
            paymentDetailsHtml = `<p><strong>Email:</strong> ${escapeHtml(paymentDetails.email || 'N/A')}</p>`;
            break;
        case 'crypto':
            paymentDetailsHtml = `
                <p><strong>Cryptocurrency:</strong> ${escapeHtml(paymentDetails.crypto_type || 'N/A')}</p>
                <p><strong>Wallet Address:</strong> ${escapeHtml(paymentDetails.wallet_address || 'N/A')}</p>
            `;
            break;
    }
    
    const html = `
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Publisher Information</h6>
                    <p class="mb-1"><strong>Name:</strong> ${escapeHtml(withdrawal.user?.name || 'N/A')}</p>
                    <p class="mb-1"><strong>Email:</strong> ${escapeHtml(withdrawal.user?.email || 'N/A')}</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Withdrawal Information</h6>
                    <p class="mb-1"><strong>Request Date:</strong> ${formatDate(withdrawal.created_at)}</p>
                    <p class="mb-1"><strong>Status:</strong> <span class="status-badge ${getStatusClass(withdrawal.status)}">${capitalize(withdrawal.status)}</span></p>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Financial Details</h6>
                    <p class="mb-1"><strong>Requested Amount:</strong> €${parseFloat(withdrawal.amount).toFixed(2)}</p>
                    <p class="mb-1"><strong>Platform Fee (18%):</strong> <span class="text-danger">-€${parseFloat(withdrawal.fee).toFixed(2)}</span></p>
                    <p class="mb-1"><strong>Net Amount:</strong> <span class="fw-bold text-success">€${parseFloat(withdrawal.net_amount).toFixed(2)}</span></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="bg-light p-3 rounded">
                    <h6 class="mb-3">Payment Details</h6>
                    <p class="mb-1"><strong>Payment Method:</strong> ${capitalize(withdrawal.payment_method)}</p>
                    ${paymentDetailsHtml}
                </div>
            </div>
        </div>
        
        <div class="bg-light p-3 rounded">
            <h6 class="mb-3">Timeline</h6>
            <p class="mb-1"><strong>Requested:</strong> ${formatDateTime(withdrawal.created_at)}</p>
            <p class="mb-1"><strong>Last Updated:</strong> ${formatDateTime(withdrawal.updated_at)}</p>
        </div>
    `;
    
    $('#detailsContent').html(html);
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Update Status
$(document).on('click', '.update-status', function() {
    const id = $(this).data('id');
    const currentStatus = $(this).data('status');
    
    $('#statusWithdrawalId').val(id);
    $('#newStatus').val(currentStatus);
    $('#statusNotes').val('');
    $('#statusModal').modal('show');
});

$('#updateStatusBtn').on('click', function() {
    const id = $('#statusWithdrawalId').val();
    const newStatus = $('#newStatus').val();
    const notes = $('#statusNotes').val();
    
    const $btn = $(this);
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
    
    $.ajax({
        url: `/admin/withdrawals/${id}/status`,
        method: 'POST',
        data: {
            status: newStatus,
            notes: notes,
            _token: '{{ csrf_token() }}'
        },
        success: function(response) {
            if (response.success) {
                toast('Status updated successfully');
                $('#statusModal').modal('hide');
                loadWithdrawals(currentPage);
            } else {
                toast(response.message || 'Failed to update status', 'error');
            }
        },
        error: function() {
            toast('Failed to update status', 'error');
        },
        complete: function() {
            $btn.prop('disabled', false).html('Update Status');
        }
    });
});

// Filters
$('#filterBtn').on('click', function() {
    currentPage = 1;
    loadWithdrawals();
});

$('#resetFiltersBtn').on('click', function() {
    $('#statusFilter').val('');
    $('#paymentMethodFilter').val('');
    $('#dateFrom').val('');
    $('#dateTo').val('');
    $('#searchInput').val('');
    currentPage = 1;
    loadWithdrawals();
});

// Initialize (support deep-links from ops dashboard, e.g. ?status=pending)
$(document).ready(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('status')) {
        $('#statusFilter').val(params.get('status'));
    }
    if (params.get('payment_method')) {
        $('#paymentMethodFilter').val(params.get('payment_method'));
    }
    if (params.get('search')) {
        $('#searchInput').val(params.get('search'));
    }
    loadWithdrawals();
});
</script>

@endsection