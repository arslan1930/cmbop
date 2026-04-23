@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">My Orders</h2>
            <p class="text-muted mb-0">
                View and manage all your orders.
            </p>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('advertiser.orders') }}" id="filterForm">
                <div class="row g-3 align-items-end">
                    <!-- Search -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                        <input type="text" 
                               name="search" 
                               id="searchInput"
                               class="form-control form-control-sm" 
                               placeholder="Order #, Site name..."
                               value="{{ request('search') }}">
                    </div>

                    <!-- Status Filter -->
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small text-muted mb-1">Order Status</label>
                        <select name="status" id="statusFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Processing</option>
                            <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                            <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </div>

                    <!-- Payment Status Filter -->
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small text-muted mb-1">Payment Status</label>
                        <select name="payment_status" id="paymentStatusFilter" class="form-select form-select-sm">
                            <option value="">All Payment Status</option>
                            <option value="paid" {{ request('payment_status') == 'paid' ? 'selected' : '' }}>Paid</option>
                            <option value="pending" {{ request('payment_status') == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="failed" {{ request('payment_status') == 'failed' ? 'selected' : '' }}>Failed</option>
                        </select>
                    </div>

                    <!-- Payment Method Filter -->
                    <div class="col-md-2">
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

                    <!-- Date Range -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small text-muted mb-1">Date Range</label>
                        <div class="d-flex gap-2">
                            <input type="date" 
                                   name="date_from" 
                                   id="dateFrom"
                                   class="form-control form-control-sm" 
                                   placeholder="From"
                                   value="{{ request('date_from') }}">
                            <input type="date" 
                                   name="date_to" 
                                   id="dateTo"
                                   class="form-control form-control-sm" 
                                   placeholder="To"
                                   value="{{ request('date_to') }}">
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-md-2">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm px-4">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> Filter
                            </button>
                            <button type="button" id="resetFilters" class="btn btn-secondary btn-sm px-3">
                                <i class="fa-solid fa-rotate-right me-1"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <div>
                <i class="fa fa-shopping-bag me-2"></i> Order History
            </div>
            <div>
                <small class="text-muted" id="resultsCount"></small>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Site</th>
                            <th>Total</th>
                            <th>Payment Method</th>
                            <th>Status</th>
                            <th>Payment Status</th>
                            <th width="120">Action</th>
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
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        <nav id="paginationNav"></nav>
    </div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="orderDetailsContent">
                    <!-- Dynamic content will be inserted here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.table td, .table th {
    padding: 12px 15px;
    vertical-align: middle;
}

.card-header {
    border-bottom: 1px solid #eee;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
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

.payment-paid {
    background-color: #dcfce7;
    color: #16a34a;
}

.payment-pending {
    background-color: #fef3c7;
    color: #d97706;
}

.payment-failed {
    background-color: #fee2e2;
    color: #dc2626;
}

.btn-view-order {
    padding: 4px 12px;
    font-size: 12px;
}

/* Dark mode styles */
body.layout-dark .card-header {
    border-bottom-color: #333;
}

body.layout-dark .status-pending {
    background-color: #4a3a1e;
    color: #fbbf24;
}

body.layout-dark .status-processing {
    background-color: #1e3a5f;
    color: #60a5fa;
}

body.layout-dark .status-completed {
    background-color: #1e5a2e;
    color: #4ade80;
}

body.layout-dark .status-cancelled {
    background-color: #5a1e1e;
    color: #f87171;
}

body.layout-dark .payment-paid {
    background-color: #1e5a2e;
    color: #4ade80;
}

body.layout-dark .payment-pending {
    background-color: #4a3a1e;
    color: #fbbf24;
}

body.layout-dark .payment-failed {
    background-color: #5a1e1e;
    color: #f87171;
}
</style>

<script>
let currentPage = 1;
let currentFilters = {};

document.addEventListener('DOMContentLoaded', function() {
    // Initial fetch
    fetchOrders();
    
    // Reset filters button
    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('paymentStatusFilter').value = '';
        document.getElementById('paymentMethodFilter').value = '';
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        
        // Reset URL params
        const url = new URL(window.location.href);
        url.search = '';
        window.history.pushState({}, '', url);
        
        fetchOrders();
    });
    
    // Filter form submit
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        fetchOrders();
    });

    function fetchOrders(page = 1) {
        const search = document.getElementById('searchInput')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const paymentStatus = document.getElementById('paymentStatusFilter')?.value || '';
        const paymentMethod = document.getElementById('paymentMethodFilter')?.value || '';
        const dateFrom = document.getElementById('dateFrom')?.value || '';
        const dateTo = document.getElementById('dateTo')?.value || '';
        
        // Build URL with params
        let url = `{{ route("advertiser.orders.list") }}?page=${page}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;
        if (status) url += `&status=${status}`;
        if (paymentStatus) url += `&payment_status=${paymentStatus}`;
        if (paymentMethod) url += `&payment_method=${paymentMethod}`;
        if (dateFrom) url += `&date_from=${dateFrom}`;
        if (dateTo) url += `&date_to=${dateTo}`;
        
        fetch(url, {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderOrders(data.orders, data.pagination);
            } else {
                document.getElementById('ordersTableBody').innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <div class="text-muted">${data.message || 'No orders found'}</div>
                        </td>
                    </tr>
                `;
                document.getElementById('resultsCount').innerHTML = '';
                document.getElementById('paginationNav').innerHTML = '';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('ordersTableBody').innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <div class="text-danger">Failed to load orders. Please try again.</div>
                    </td>
                </tr>
            `;
        });
    }

    function renderOrders(orders, pagination) {
        if (!orders || orders.length === 0) {
            document.getElementById('ordersTableBody').innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <div class="text-muted">No orders found</div>
                        <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary btn-sm mt-3">
                            <i class="fa fa-shopping-cart"></i> Start Shopping
                        </a>
                    </td>
                </tr>
            `;
            document.getElementById('resultsCount').innerHTML = '';
            document.getElementById('paginationNav').innerHTML = '';
            return;
        }

        let html = '';
        orders.forEach(order => {
            const statusClass = getStatusClass(order.status);
            const paymentClass = getPaymentStatusClass(order.payment_status);
            const siteName = order.items && order.items[0] ? order.items[0].site_name : 'N/A';
            
            html += `
                <tr>
                    <td class="fw-semibold">${order.order_number}</td>
                    <td>${formatDate(order.created_at)}</td>
                    <td>${escapeHtml(siteName)}</td>
                    <td class="fw-semibold text-primary">€${parseFloat(order.total_amount).toFixed(2)}</td>
                    <td>${getPaymentMethodName(order.payment_method)}</td>
                    <td><span class="status-badge ${statusClass}">${capitalize(order.status)}</span></td>
                    <td><span class="status-badge ${paymentClass}">${capitalize(order.payment_status)}</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary btn-view-order" onclick="viewOrder(${order.id})">
                            <i class="fa fa-eye"></i> View
                        </button>
                    </td>
                </tr>
            `;
        });
        document.getElementById('ordersTableBody').innerHTML = html;
        
        // Update results count
        if (pagination && pagination.total) {
            document.getElementById('resultsCount').innerHTML = `Showing ${pagination.from || 0} to ${pagination.to || 0} of ${pagination.total} orders`;
        }
        
        // Render pagination
        renderPagination(pagination);
    }
    
    function renderPagination(pagination) {
        if (!pagination || pagination.last_page <= 1) {
            document.getElementById('paginationNav').innerHTML = '';
            return;
        }
        
        let paginationHtml = '<ul class="pagination justify-content-center">';
        
        // Previous button
        if (pagination.current_page > 1) {
            paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page - 1}">Previous</button></li>`;
        } else {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">Previous</span></li>`;
        }
        
        // Page numbers
        for (let i = 1; i <= pagination.last_page; i++) {
            if (i === pagination.current_page) {
                paginationHtml += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
            } else {
                paginationHtml += `<li class="page-item"><button class="page-link" data-page="${i}">${i}</button></li>`;
            }
        }
        
        // Next button
        if (pagination.current_page < pagination.last_page) {
            paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page + 1}">Next</button></li>`;
        } else {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">Next</span></li>`;
        }
        
        paginationHtml += '</ul>';
        document.getElementById('paginationNav').innerHTML = paginationHtml;
        
        // Add click handlers to pagination buttons
        document.querySelectorAll('.page-link[data-page]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const page = parseInt(this.dataset.page);
                fetchOrders(page);
            });
        });
    }

    window.viewOrder = function(orderId) {
        fetch(`{{ url("advertiser/orders") }}/${orderId}`, {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderOrderDetails(data.order);
                const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
                modal.show();
            } else {
                Swal.fire({
                    title: 'Error',
                    text: data.message || 'Failed to load order details',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error',
                text: 'Failed to load order details',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        });
    }

    function renderOrderDetails(order) {
        const item = order.items[0];
        
        const html = `
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="bg-light p-3 rounded">
                        <h6 class="mb-3">Order Information</h6>
                        <p class="mb-1"><strong>Order Number:</strong> ${order.order_number}</p>
                        <p class="mb-1"><strong>Date:</strong> ${formatDate(order.created_at)}</p>
                        <p class="mb-1"><strong>Payment Method:</strong> ${getPaymentMethodName(order.payment_method)}</p>
                        <p class="mb-1"><strong>Payment Status:</strong> <span class="status-badge ${getPaymentStatusClass(order.payment_status)}">${capitalize(order.payment_status)}</span></p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="bg-light p-3 rounded">
                        <h6 class="mb-3">Order Status</h6>
                        <p class="mb-1"><strong>Status:</strong> <span class="status-badge ${getStatusClass(order.status)}">${capitalize(order.status)}</span></p>
                        <p class="mb-1"><strong>Total Amount:</strong> <span class="fw-bold text-primary fs-5">€${parseFloat(order.total_amount).toFixed(2)}</span></p>
                    </div>
                </div>
            </div>
            
            <h6 class="mb-3">Order Item</h6>
            <div class="border rounded p-3">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Site Name:</strong></p>
                        <p class="mb-2">${escapeHtml(item.site_name)}</p>
                        <p class="mb-1"><strong>Site URL:</strong></p>
                        <p class="mb-2"><a href="${escapeHtml(item.site_url)}" target="_blank" class="text-primary">${escapeHtml(item.site_url)} <i class="fa fa-external-link fa-xs"></i></a></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Price:</strong></p>
                        <p class="mb-2 text-primary fw-bold">€${parseFloat(item.price).toFixed(2)}</p>
                        <p class="mb-1"><strong>Content Link:</strong></p>
                        <p class="mb-0"><a href="${escapeHtml(item.content_link)}" target="_blank" class="text-primary text-break">${escapeHtml(item.content_link).substring(0, 60)}${escapeHtml(item.content_link).length > 60 ? '...' : ''} <i class="fa fa-external-link fa-xs"></i></a></p>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('orderDetailsContent').innerHTML = html;
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

    function getPaymentStatusClass(status) {
        const classes = {
            'paid': 'payment-paid',
            'pending': 'payment-pending',
            'failed': 'payment-failed'
        };
        return classes[status] || 'payment-pending';
    }

    function getPaymentMethodName(method) {
        const methods = {
            'wallet': 'Wallet Balance',
            'wise': 'Wise Transfer',
            'crypto': 'Cryptocurrency',
            'bank': 'Bank Transfer',
            'card': 'Card Payment'
        };
        return methods[method] || method;
    }

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
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
});
</script>

<!-- SweetAlert2 for better alerts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

@endsection