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

    <!-- Orders Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="fa fa-shopping-bag me-2"></i> Order History
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th>
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    fetchOrders();

    function fetchOrders() {
        fetch('{{ route("advertiser.orders.list") }}', {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderOrders(data.orders);
            } else {
                document.getElementById('ordersTableBody').innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <div class="text-muted">${data.message || 'No orders found'}</div>
                        </td>
                    </tr>
                `;
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

    function renderOrders(orders) {
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
            return;
        }

        let html = '';
        orders.forEach(order => {
            const statusClass = getStatusClass(order.status);
            const paymentClass = getPaymentStatusClass(order.payment_status);
            const siteName = order.items && order.items[0] ? order.items[0].site_name : 'N/A';
            
            html += `
                <tr>
                    <td class="fw-semibold">#${order.order_number}</td>
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
                        <p class="mb-1"><strong>Order Number:</strong> #${order.order_number}</p>
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
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
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