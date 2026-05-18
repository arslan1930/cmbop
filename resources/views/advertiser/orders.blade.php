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
                            <option value="review" {{ request('status') == 'review' ? 'selected' : '' }}>Under Review</option>
                            <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                            <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </div>

                    <!-- Payment Method & Status Filter (Combined) -->
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

                    <div class="col-md-2">
                        <label class="form-label fw-semibold small text-muted mb-1">Payment Status</label>
                        <select name="payment_status" id="paymentStatusFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="paid" {{ request('payment_status') == 'paid' ? 'selected' : '' }}>Paid</option>
                            <option value="pending" {{ request('payment_status') == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="failed" {{ request('payment_status') == 'failed' ? 'selected' : '' }}>Failed</option>
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
                            <button type="submit" class="btn btn-sm px-4" style="background-color: #3aaeb2; color: white;">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> Filter
                            </button>
                            <button type="button" id="resetFilters" class="btn btn-sm px-3" style="background-color: #e9ecef; color: #495057;">
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
                            <th>Site</th>
                            <th>Date</th>
                            <th>Price</th>
                            <th>Sensitive Price</th>
                            <th>Payment Info</th>
                            <th>Reference Code</th>
                            <th>Order Status</th>
                            <th>Content Link</th>
                            <th>Live URL</th>
                            <th width="150">Action</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <tr>
                            <td colspan="11" class="text-center py-5">
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

<!-- Modification Modal -->
<div class="modal fade" id="modificationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Request Modification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modificationOrderId">
                <div class="mb-3">
                    <label for="modificationReason" class="form-label">Reason for Modification <span class="text-danger">*</span></label>
                    <textarea id="modificationReason" class="form-control" rows="4" placeholder="Please explain what changes are needed..."></textarea>
                    <small class="text-muted mt-2 d-block">The publisher will be notified and can resubmit the live URL.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="confirmModification">Request Modification</button>
            </div>
        </div>
    </div>
</div>

<!-- Chat Modal -->
<div class="modal fade" id="chatModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fa fa-comments me-2"></i> 
                    Order Chat - <span id="chatOrderNumber"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="chatMessages" class="p-3" style="height: 400px; overflow-y: auto; background: #f8f9fa;">
                    <div class="text-center text-muted py-5">
                        <i class="fa fa-spinner fa-spin fa-2x"></i>
                        <p class="mt-2">Loading messages...</p>
                    </div>
                </div>
                <div class="p-3 border-top">
                    <form id="chatForm">
                        <input type="hidden" id="chatOrderId">
                        <div class="input-group">
                            <textarea id="chatMessageInput" class="form-control" rows="2" placeholder="Type your message..."></textarea>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-paper-plane"></i> Send
                            </button>
                        </div>
                        <small class="text-muted mt-1 d-block">Press Ctrl+Enter to send</small>
                    </form>
                </div>
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
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.status-pending {
    background-color: #fef3c7;
    color: #282828;
}

.status-processing {
    background-color: #dbeafe;
    color: #282828;
}

.status-review {
    background-color: #cff4fc;
    color: #055160;
}

.status-completed {
    background-color: #dcfce7;
    color: #282828;
}

.status-cancelled {
    background-color: #fee2e2;
    color: #282828;
}

.payment-paid {
    background-color: #dcfce7;
    color: #282828;
}

.payment-pending {
    background-color: #fef3c7;
    color: #282828;
}

.payment-failed {
    background-color: #fee2e2;
    color: #282828;
}

.sensitive-badge {
    background-color: #fef3c7;
    color: #d97706;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
}

.btn-approve {
    padding: 4px 12px;
    font-size: 12px;
    white-space: nowrap;
}

.btn-modify {
    padding: 4px 12px;
    font-size: 12px;
    white-space: nowrap;
}

.btn-view-order {
    padding: 4px 12px;
    font-size: 12px;
    white-space: nowrap;
}

.btn-chat {
    padding: 4px 12px;
    font-size: 12px;
    white-space: nowrap;
}

.link-cell {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.link-cell a {
    font-size: 12px;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.action-btn {
    justify-content: center;
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

body.layout-dark .status-review {
    background-color: #1a5a5a;
    color: #8bcbff;
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

body.layout-dark .sensitive-badge {
    background-color: #4a3a1e;
    color: #fbbf24;
}

td a {
    word-break: break-all;
}

#chatMessages::-webkit-scrollbar {
    width: 6px;
}
#chatMessages::-webkit-scrollbar-track {
    background: #f1f1f1;
}
#chatMessages::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}
#chatMessages::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>

<script>
let currentPage = 1;
let currentChatOrderId = null;

document.addEventListener('DOMContentLoaded', function() {
    fetchOrders();
    
    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('paymentStatusFilter').value = '';
        document.getElementById('paymentMethodFilter').value = '';
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        
        const url = new URL(window.location.href);
        url.search = '';
        window.history.pushState({}, '', url);
        
        fetchOrders();
    });
    
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        fetchOrders();
    });

    // Chat functionality
    window.openChat = function(orderId, orderNumber) {
        currentChatOrderId = orderId;
        document.getElementById('chatOrderId').value = orderId;
        document.getElementById('chatOrderNumber').innerText = orderNumber;
        loadChatMessages(orderId);
        $('#chatModal').modal('show');
    };

    function loadChatMessages(orderId) {
        fetch(`/chat/messages/${orderId}`, {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderChatMessages(data.messages, data.current_user_id);
                const chatDiv = document.getElementById('chatMessages');
                chatDiv.scrollTop = chatDiv.scrollHeight;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('chatMessages').innerHTML = `
                <div class="text-center text-danger py-5">
                    <i class="fa fa-exclamation-circle fa-3x mb-3"></i>
                    <p>Failed to load messages. Please try again.</p>
                </div>
            `;
        });
    }

    function renderChatMessages(messages, currentUserId) {
        if (!messages || messages.length === 0) {
            document.getElementById('chatMessages').innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fa fa-comments fa-3x mb-3"></i>
                    <p>No messages yet. Start the conversation!</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        
        messages.forEach(msg => {
            const isOwnMessage = msg.user_id === currentUserId;
            const messageClass = isOwnMessage ? 'bg-primary text-white' : 'bg-white border';
            const alignClass = isOwnMessage ? 'justify-content-end' : 'justify-content-start';
            const senderName = isOwnMessage ? 'You' : msg.user.name;
            const time = new Date(msg.created_at).toLocaleString();
            
            html += `
                <div class="d-flex ${alignClass} mb-3">
                    <div class="${messageClass} rounded-3 p-3" style="max-width: 70%;">
                        <div class="small fw-semibold ${isOwnMessage ? 'text-white-50' : 'text-primary'} mb-1">
                            ${senderName} · ${time}
                        </div>
                        <div class="mb-0">${escapeHtml(msg.message || '')}</div>
                    </div>
                </div>
            `;
        });
        
        document.getElementById('chatMessages').innerHTML = html;
    }

    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const orderId = document.getElementById('chatOrderId').value;
        const message = document.getElementById('chatMessageInput').value.trim();
        
        if (!message) return;
        
        const sendBtn = this.querySelector('button[type="submit"]');
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';
        
        fetch(`/chat/send/${orderId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ message: message })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('chatMessageInput').value = '';
                loadChatMessages(orderId);
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to send message', 'error');
        })
        .finally(() => {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="fa fa-paper-plane"></i> Send';
        });
    });

    // Ctrl+Enter shortcut
    document.getElementById('chatMessageInput').addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            document.getElementById('chatForm').dispatchEvent(new Event('submit'));
        }
    });

    // Request modification
    window.requestModification = function(orderId) {
        document.getElementById('modificationOrderId').value = orderId;
        document.getElementById('modificationReason').value = '';
        $('#modificationModal').modal('show');
    };

    document.getElementById('confirmModification').addEventListener('click', function() {
        const orderId = document.getElementById('modificationOrderId').value;
        const reason = document.getElementById('modificationReason').value.trim();
        
        if (!reason) {
            Swal.fire('Warning!', 'Please provide a reason for modification', 'warning');
            return;
        }
        
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
        
        fetch(`/advertiser/orders/${orderId}/request-modification`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ reason: reason })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success!', data.message, 'success');
                $('#modificationModal').modal('hide');
                fetchOrders(currentPage);
            } else {
                Swal.fire('Error!', data.message || 'Failed to request modification', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Failed to request modification', 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = 'Request Modification';
        });
    });

    function fetchOrders(page = 1) {
        const search = document.getElementById('searchInput')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const paymentStatus = document.getElementById('paymentStatusFilter')?.value || '';
        const paymentMethod = document.getElementById('paymentMethodFilter')?.value || '';
        const dateFrom = document.getElementById('dateFrom')?.value || '';
        const dateTo = document.getElementById('dateTo')?.value || '';
        
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
                        <td colspan="11" class="text-center py-5">
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
                    <td colspan="11" class="text-center py-5">
                        <div class="text-danger">Failed to load orders. Please try again.</div>
                    </td>
                </tr>
            `;
        });
    }

    function renderOrders(orders, pagination) {
        if (!orders || orders.length === 0) {
            document.getElementById('ordersTableBody').innerHTML = `
                <td>
                    <td colspan="11" class="text-center py-5">
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
            const siteName = order.items && order.items[0] ? order.items[0].site_name : 'N/A';
            const siteUrl = order.items && order.items[0] ? order.items[0].site_url : '#';
            const contentLink = order.items && order.items[0] ? order.items[0].content_link : '#';
            const liveUrl = order.items && order.items[0] ? order.items[0].live_url : null;
            const additionalPrice = order.items && order.items[0] ? parseFloat(order.items[0].additional_price || 0) : 0;
            const basePrice = order.items && order.items[0] ? (parseFloat(order.total_amount) - additionalPrice) : parseFloat(order.total_amount);
            const sensitiveType = order.items && order.items[0] ? order.items[0].sensitive_type : null;
            
            // Truncate long URLs
            const shortContentLink = contentLink.length > 40 ? contentLink.substring(0, 40) + '...' : contentLink;
            const shortLiveUrl = liveUrl && liveUrl.length > 40 ? liveUrl.substring(0, 40) + '...' : liveUrl;
            
            // Payment info combined
            const paymentMethodName = getPaymentMethodName(order.payment_method);
            const paymentStatusClass = getPaymentStatusClass(order.payment_status);
            
            // Check if order is in review status and has live URL (can approve or modify)
            const hasLiveUrl = liveUrl && liveUrl !== '';
            const isUnderReview = order.status === 'review';
            
            html += `
                <tr>
                    <td class="fw-semibold">${order.order_number}</td>
                    <td><div class="fw-semibold">${escapeHtml(siteName)}</div><div class="text-muted small"><a href="${escapeHtml(siteUrl)}" target="_blank">${escapeHtml(siteUrl)}</a></div></td>
                    <td>${formatDate(order.created_at)}</td>
                    <td class="fw-semibold text-primary">€${basePrice.toFixed(2)}</td>
                    <td>
                        ${additionalPrice > 0 ? 
                            `<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> ${escapeHtml(sensitiveType || 'Extra')} (+€${additionalPrice.toFixed(2)})</span>` : 
                            '<span class="text-muted">—</span>'
                        }
                    </td>
                    <td>
                        <div class="small mb-1">${paymentMethodName}</div>
                        <span class="status-badge ${paymentStatusClass}">${capitalize(order.payment_status)}</span>
                    </td>
                    <td>${order.reference_code || '-'}</td>
                    <td><span class="status-badge ${statusClass}">${capitalize(order.status)}</span></td>
                    <td class="link-cell">
                        <a href="${contentLink}" 
                           target="_blank" 
                           class="btn btn-sm btn-outline-primary"
                           title="Content Link">
                            <i class="fa fa-external-link me-1"></i> View
                        </a>
                    </td>
                    <td class="link-cell">
                        ${liveUrl 
                            ? `<a href="${liveUrl}" 
                                  target="_blank" 
                                  class="btn btn-sm btn-outline-success"
                                  title="Live URL">
                                    <i class="fa fa-external-link me-1"></i> Live
                               </a>`
                            : '<span class="text-muted">-</span>'
                        }
                    </td>
                    <td>
                        <div class="action-buttons d-flex align-items-center gap-2 flex-wrap">
                            <button 
                                class="btn btn-sm btn-outline-info action-btn d-flex align-items-center"
                                onclick="viewOrder(${order.id})">
                                <i class="fa fa-eye me-1"></i>
                                <span>View</span>
                            </button>

                            <button 
                                class="btn btn-sm btn-outline-success action-btn d-flex align-items-center"
                                onclick="openChat(${order.id}, '${order.order_number}')">
                                <i class="fa fa-comments me-1"></i>
                                <span>Chat</span>
                            </button>

                            ${isUnderReview && hasLiveUrl ? 
                                `<button class="btn btn-sm btn-success action-btn d-flex align-items-center"
                                    onclick="approveOrder(${order.id})">
                                    <i class="fa fa-check-circle me-1"></i>
                                    <span>Approve</span>
                                </button>
                                <button class="btn btn-sm btn-warning action-btn d-flex align-items-center"
                                    onclick="requestModification(${order.id})">
                                    <i class="fa fa-edit me-1"></i>
                                    <span>Modify</span>
                                </button>` : ''
                            }
                        </div>
                    </td>
                </tr>
            `;
        });
        document.getElementById('ordersTableBody').innerHTML = html;
        
        renderPagination(pagination);
    }
    
    // Approve order function
    window.approveOrder = function(orderId) {
        Swal.fire({
            title: 'Approve Order',
            text: 'Are you sure you want to approve this order? The publisher has submitted the live URL.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Approve',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#28a745'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(`/advertiser/orders/${orderId}/approve`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Approved!', data.message, 'success');
                        fetchOrders(currentPage);
                    } else {
                        Swal.fire('Error!', data.message || 'Failed to approve order', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error!', 'Failed to approve order', 'error');
                });
            }
        });
    };
    
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
                Swal.fire('Error', data.message || 'Failed to load order details', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load order details', 'error');
        });
    }

    function renderOrderDetails(order) {
        const item = order.items[0];
        const liveUrl = item.live_url || null;
        const additionalPrice = parseFloat(item.additional_price || 0);
        const basePrice = parseFloat(item.price) - additionalPrice;
        const isUnderReview = order.status === 'review';
        const hasLiveUrl = liveUrl && liveUrl !== '';
        
        const liveUrlHtml = liveUrl 
            ? `<p class="mb-1"><strong>Live URL:</strong></p>
               <p class="mb-2"><a href="${escapeHtml(liveUrl)}" target="_blank" class="text-success">${escapeHtml(liveUrl)} <i class="fa fa-external-link fa-xs"></i></a></p>`
            : `<p class="mb-2 text-muted">Live URL not submitted yet</p>`;
        
        const sensitiveHtml = additionalPrice > 0 
            ? `<p class="mb-1"><strong>Sensitive Price:</strong></p>
               <p class="mb-2 text-warning"><i class="fa fa-plus-circle"></i> ${escapeHtml(item.sensitive_type || 'Extra')}: €${additionalPrice.toFixed(2)}</p>`
            : '';
        
        let actionButtons = '';
        if (isUnderReview && hasLiveUrl) {
            actionButtons = `
                <div class="mt-4 text-center d-flex gap-3 justify-content-center">
                    <button class="btn btn-success" onclick="approveOrder(${order.id})">
                        <i class="fa fa-check-circle"></i> Approve Order
                    </button>
                    <button class="btn btn-warning" onclick="requestModification(${order.id})">
                        <i class="fa fa-edit"></i> Request Modification
                    </button>
                </div>
            `;
        }
        
        const html = `
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="bg-light p-3 rounded">
                        <h6 class="mb-3">Order Information</h6>
                        <p class="mb-1"><strong>Order Number:</strong> ${order.order_number}</p>
                        <p class="mb-1"><strong>Date:</strong> ${formatDate(order.created_at)}</p>
                        <p class="mb-1"><strong>Payment Method:</strong> ${getPaymentMethodName(order.payment_method)}</p>
                        <p class="mb-1"><strong>Payment Status:</strong> <span class="status-badge ${getPaymentStatusClass(order.payment_status)}">${capitalize(order.payment_status)}</span></p>
                        <p class="mb-1"><strong>Reference Code:</strong> ${order.reference_code || '-'}</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="bg-light p-3 rounded">
                        <h6 class="mb-3">Order Status</h6>
                        <p class="mb-1"><strong>Status:</strong> <span class="status-badge ${getStatusClass(order.status)}">${capitalize(order.status)}</span></p>
                        <p class="mb-1"><strong>Price:</strong> <span class="fw-bold">€${basePrice.toFixed(2)}</span></p>
                        ${additionalPrice > 0 ? `<p class="mb-1"><strong>Sensitive Price:</strong> <span class="text-warning">+ €${additionalPrice.toFixed(2)}</span></p>` : ''}
                        <p class="mb-1"><strong>Total Amount:</strong> <span class="fw-bold text-primary fs-5">€${parseFloat(order.total_amount).toFixed(2)}</span></p>
                    </div>
                </div>
            </div>
            
            <h6 class="mb-3">Order Items</h6>
            <div class="border rounded p-3">
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Site Name:</strong></p>
                        <p class="mb-2">${escapeHtml(item.site_name)}</p>
                        <p class="mb-1"><strong>Site URL:</strong></p>
                        <p class="mb-2"><a href="${escapeHtml(item.site_url)}" target="_blank" class="text-primary">${escapeHtml(item.site_url)} <i class="fa fa-external-link fa-xs"></i></a></p>
                        ${sensitiveHtml}
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Price Breakdown:</strong></p>
                        <p class="mb-1"><small>Base Price: €${basePrice.toFixed(2)}</small></p>
                        ${additionalPrice > 0 ? `<p class="mb-1"><small class="text-warning">+ ${escapeHtml(item.sensitive_type)}: €${additionalPrice.toFixed(2)}</small></p>` : ''}
                        <p class="mb-2"><strong class="text-primary">Total: €${parseFloat(item.price).toFixed(2)}</strong></p>
                        <p class="mb-1"><strong>Content Link:</strong></p>
                        <p class="mb-2"><a href="${escapeHtml(item.content_link)}" target="_blank" class="text-primary text-break">${escapeHtml(item.content_link)} <i class="fa fa-external-link fa-xs"></i></a></p>
                        ${liveUrlHtml}
                    </div>
                </div>
            </div>
            
            ${actionButtons}
        `;
        
        document.getElementById('orderDetailsContent').innerHTML = html;
    }

    function renderPagination(pagination) {
        if (!pagination || pagination.last_page <= 1) {
            document.getElementById('paginationNav').innerHTML = '';
            return;
        }
        
        let paginationHtml = '<ul class="pagination justify-content-center">';
        
        if (pagination.current_page > 1) {
            paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page - 1}">Previous</button></li>`;
        } else {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">Previous</span></li>`;
        }
        
        for (let i = 1; i <= pagination.last_page; i++) {
            if (i === pagination.current_page) {
                paginationHtml += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
            } else {
                paginationHtml += `<li class="page-item"><button class="page-link" data-page="${i}">${i}</button></li>`;
            }
        }
        
        if (pagination.current_page < pagination.last_page) {
            paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page + 1}">Next</button></li>`;
        } else {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">Next</span></li>`;
        }
        
        paginationHtml += '</ul>';
        document.getElementById('paginationNav').innerHTML = paginationHtml;
        
        document.querySelectorAll('.page-link[data-page]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const page = parseInt(this.dataset.page);
                currentPage = page;
                fetchOrders(page);
            });
        });
    }

    function getStatusClass(status) {
        const classes = {
            'pending': 'status-pending',
            'processing': 'status-processing',
            'review': 'status-review',
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

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

@endsection