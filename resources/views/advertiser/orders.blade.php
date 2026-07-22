@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">My Orders</h2>
            <p class="text-muted mb-0">
                Track each order from payment to live publication.
            </p>
        </div>
    </div>

    <div id="needsActionBanner" class="alert alert-warning border-0 shadow-sm d-none mb-4" role="status">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <strong><i class="fa fa-exclamation-circle me-1"></i> Needs your review</strong>
                <span class="ms-1" id="needsActionText"></span>
            </div>
            <button type="button" class="btn btn-sm btn-dark" id="showNeedsReviewBtn">Show orders to review</button>
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
                            <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Waiting for payment</option>
                            <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Publisher working</option>
                            <option value="review" {{ request('status') == 'review' ? 'selected' : '' }}>Needs your review</option>
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
                            <option value="refunded" {{ request('payment_status') == 'refunded' ? 'selected' : '' }}>Refunded</option>
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
                                <i class="fa-solid fa-filter me-1"></i> Filter
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
                <table class="table table-hover align-middle mb-0 data-table">
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

<!-- Request changes Modal -->
<div class="modal fade" id="modificationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Request changes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modificationOrderId">
                <div class="mb-3">
                    <label for="modificationReason" class="form-label">What needs to change? <span class="text-danger">*</span></label>
                    <textarea id="modificationReason" class="form-control" rows="4" placeholder="Explain the fixes needed on the live post…"></textarea>
                    <small class="text-muted mt-2 d-block">The publisher will see this reason, update the post, and resubmit the live URL. Auto-approve pauses until they resubmit.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="confirmModification">Send change request</button>
            </div>
        </div>
    </div>
</div>

@include('partials.order-chat-modal')
    </div>
</div>

<style>
.chat-order-details {
    padding: 10px 16px;
    background: #f4f6f8;
    border-bottom: 1px solid #e6eaee;
    color: #8a94a0;
    font-size: 0.78rem;
    line-height: 1.45;
}
.chat-order-details .chat-detail-primary {
    color: #6c757d;
    font-weight: 500;
}
.chat-order-details .chat-detail-sep {
    color: #c5ccd4;
    margin: 0 0.35rem;
}
.chat-order-details a {
    color: #8a94a0;
    text-decoration: none;
}
.chat-order-details a:hover {
    color: #6c757d;
    text-decoration: underline;
}

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
    /* uses app-shell status tokens */
}

.status-processing {
    /* uses app-shell status tokens */
}

.status-review {
    /* uses app-shell status tokens */
}

.status-completed {
    /* uses app-shell status tokens */
}

.status-cancelled {
    /* uses app-shell status tokens */
}

.payment-paid {
    /* uses app-shell status tokens */
}

.payment-pending {
    /* uses app-shell status tokens */
}

.payment-failed {
    /* uses app-shell status tokens */
}

.sensitive-badge {
    background-color: var(--brand-primary-bg, #e8f8f7);
    color: var(--brand-primary, #0b6266);
    border: 1px solid var(--brand-primary-border, #b8e8e6);
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

.chat-unread-dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    margin-left: 4px;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
}

.next-step-hint {
    font-size: 11px;
    color: #6b7280;
    margin-top: 4px;
    max-width: 180px;
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
    hydrateOrdersFiltersFromUrl();
    fetchOrders(currentPage);

    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('paymentStatusFilter').value = '';
        document.getElementById('paymentMethodFilter').value = '';
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        currentPage = 1;
        syncOrdersFiltersToUrl(1);
        fetchOrders(1);
    });

    document.getElementById('showNeedsReviewBtn')?.addEventListener('click', function() {
        document.getElementById('statusFilter').value = 'review';
        currentPage = 1;
        syncOrdersFiltersToUrl(1);
        fetchOrders(1);
    });
    
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        syncOrdersFiltersToUrl(1);
        fetchOrders(1);
    });

    function hydrateOrdersFiltersFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const setVal = (id, key) => {
            const el = document.getElementById(id);
            if (el && params.has(key)) el.value = params.get(key) || '';
        };
        setVal('searchInput', 'search');
        setVal('statusFilter', 'status');
        setVal('paymentStatusFilter', 'payment_status');
        setVal('paymentMethodFilter', 'payment_method');
        setVal('dateFrom', 'date_from');
        setVal('dateTo', 'date_to');
        const page = parseInt(params.get('page') || '1', 10);
        currentPage = Number.isFinite(page) && page > 0 ? page : 1;
    }

    function syncOrdersFiltersToUrl(page = 1) {
        const url = new URL(window.location.href);
        const map = {
            search: document.getElementById('searchInput')?.value || '',
            status: document.getElementById('statusFilter')?.value || '',
            payment_status: document.getElementById('paymentStatusFilter')?.value || '',
            payment_method: document.getElementById('paymentMethodFilter')?.value || '',
            date_from: document.getElementById('dateFrom')?.value || '',
            date_to: document.getElementById('dateTo')?.value || '',
        };
        Object.keys(map).forEach((key) => {
            if (map[key]) url.searchParams.set(key, map[key]);
            else url.searchParams.delete(key);
        });
        if (page > 1) url.searchParams.set('page', String(page));
        else url.searchParams.delete('page');
        window.history.pushState({}, '', url);
    }
    window.syncOrdersFiltersToUrl = syncOrdersFiltersToUrl;

    function escapeHtml(str) {
        return window.OrderChatEscapeHtml ? window.OrderChatEscapeHtml(str) : String(str || '');
    }

    function renderChatOrderDetails(details) {
        const el = document.getElementById('chatOrderDetails');
        if (!el) return;
        if (!details) {
            el.classList.add('d-none');
            el.innerHTML = '';
            window._chatOrderId = null;
            return;
        }

        window._chatOrderId = details.order_id || window._chatOrderId || null;
        const websiteName = escapeHtml(details.website_name || '—');
        const statusLabel = escapeHtml(details.status_label || details.status || '—');
        const nextAction = escapeHtml(details.next_action || '');
        const autoHint = details.auto_approve_hint
            ? `<div class="small text-muted mt-1">${escapeHtml(details.auto_approve_hint)}</div>`
            : '';

        let actions = '';
        if (details.can_approve || details.can_request_changes) {
            const oid = window._chatOrderId;
            actions = `<div class="d-flex flex-wrap gap-2 mt-2">
                ${details.can_approve ? `<button type="button" class="btn btn-sm btn-success" onclick="approveOrder(${oid})"><i class="fa fa-check-circle me-1"></i>Approve</button>` : ''}
                ${details.can_request_changes ? `<button type="button" class="btn btn-sm btn-warning" onclick="requestModification(${oid})"><i class="fa fa-edit me-1"></i>Request changes</button>` : ''}
                ${details.live_url ? `<a class="btn btn-sm btn-outline-secondary" href="${escapeHtml(details.live_url)}" target="_blank" rel="noopener">Open live URL</a>` : ''}
            </div>`;
        }

        el.innerHTML = `
            <div class="small">
                <div><span class="chat-detail-primary">${websiteName}</span>
                ${details.website_url ? ` · <a href="${escapeHtml(details.website_url)}" target="_blank" rel="noopener">${escapeHtml(details.website_url)}</a>` : ''}</div>
                <div class="mt-1"><strong>${statusLabel}</strong>${nextAction ? ` — ${nextAction}` : ''}</div>
                ${autoHint}
                ${actions}
            </div>`;
        el.classList.remove('d-none');
    }

    const orderChat = new OrderChat({
        baseUrl: window.location.origin,
        renderOrderDetails: renderChatOrderDetails,
        onFocusOrder: function(orderId) {
            if (typeof viewOrder === 'function') viewOrder(orderId);
        },
        onFocusMessagesFallback: function() {
            const table = document.getElementById('ordersTableBody');
            if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        onClose: function() {
            fetchOrders(currentPage);
            if (typeof window.refreshHeaderAlerts === 'function') window.refreshHeaderAlerts();
        },
    });
    orderChat.init();

    window.openChat = function(orderId, orderNumber) {
        currentChatOrderId = orderId;
        window._chatOrderId = orderId;
        orderChat.open(orderId, orderNumber);
    };

    window.raiseIssue = function(orderId, orderNumber, statusLabel) {
        openChat(orderId, orderNumber || ('#' + orderId));
        const input = document.getElementById('chatMessageInput');
        if (input && !input.disabled) {
            const label = statusLabel || 'unknown';
            input.value = `I'd like to raise an issue with order #${orderNumber} (status: ${label}). Please help resolve this.`;
            setTimeout(() => input.focus(), 300);
        }
    };

    window.recheckLiveUrl = function(orderId) {
        const btn = document.getElementById('recheckLiveUrlBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Checking…';
        }
        fetch(`{{ url('advertiser/orders') }}/${orderId}/recheck-live-url`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Checked', data.message || 'URL check finished.', data.live_url_check?.ok ? 'success' : 'warning');
                viewOrder(orderId);
            } else {
                Swal.fire('Error', data.message || 'Could not recheck URL.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Could not recheck URL.', 'error'))
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-refresh me-1"></i>Recheck';
            }
        });
    };

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
            btn.innerHTML = 'Send change request';
        });
    });

    function fetchOrders(page = 1) {
        currentPage = page;
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

        if (typeof window.syncOrdersFiltersToUrl === 'function') {
            window.syncOrdersFiltersToUrl(page);
        }
        
        fetch(url, {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) throw new Error('Request failed');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                renderOrders(data.orders, data.pagination);
                updateNeedsActionBanner(data.needs_action || 0);
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
                updateNeedsActionBanner(0);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('ordersTableBody').innerHTML = `
                <tr>
                    <td colspan="11" class="text-center py-5">
                        <div class="text-danger mb-2">Failed to load orders.</div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="retryOrdersBtn">Retry</button>
                    </td>
                </tr>
            `;
            document.getElementById('retryOrdersBtn')?.addEventListener('click', () => fetchOrders(currentPage));
        });
    }

    function updateNeedsActionBanner(count) {
        const banner = document.getElementById('needsActionBanner');
        const text = document.getElementById('needsActionText');
        if (!banner || !text) return;
        if (count > 0) {
            text.textContent = `${count} order${count === 1 ? '' : 's'} have a live URL ready — approve or request changes.`;
            banner.classList.remove('d-none');
        } else {
            banner.classList.add('d-none');
        }
    }

    function getAdvertiserStatusMeta(order) {
        if (order.status_label && order.next_action) {
            return {
                label: order.status_label,
                next: order.next_action,
                cls: getStatusClass(order.status),
                autoHint: order.auto_approve_hint || null,
            };
        }

        const item = order.items && order.items[0] ? order.items[0] : null;
        const hasLiveUrl = !!(item && item.live_url);
        const modRequested = item && item.modification_requested === 'yes';
        const payment = order.payment_status;
        const status = order.status;
        let autoHint = null;
        if (status === 'review' && hasLiveUrl && !modRequested && item && typeof item.auto_approve_hours_remaining === 'number') {
            const hours = item.auto_approve_hours_remaining;
            autoHint = hours > 0
                ? (hours >= 24
                    ? `Auto-approves in about ${Math.ceil(hours / 24)} day(s) if you take no action`
                    : `Auto-approves in about ${hours} hour(s) if you take no action`)
                : 'Ready for auto-approve — approve now or request changes';
        }

        if (status === 'cancelled' && payment === 'refunded') {
            return { label: 'Cancelled · refunded', next: 'Refunded to your wallet (usually instant). No further action needed.', cls: 'status-cancelled', autoHint: null };
        }
        if (status === 'cancelled') {
            return { label: 'Cancelled', next: 'No further action needed.', cls: 'status-cancelled', autoHint: null };
        }
        if (payment === 'failed') {
            return { label: 'Payment failed', next: 'Pay again from Orders, or choose another payment method.', cls: 'status-cancelled', autoHint: null };
        }
        if (status === 'pending' && payment !== 'paid') {
            return { label: 'Awaiting payment', next: 'Complete payment so the publisher can start.', cls: 'status-pending', autoHint: null };
        }
        if (status === 'pending' && payment === 'paid') {
            return { label: 'Paid · waiting for publisher', next: 'Publisher will accept the order and start working.', cls: 'status-pending', autoHint: null };
        }
        if (status === 'processing' && modRequested) {
            return { label: 'Revision requested', next: 'Waiting on the publisher to update the post and resubmit the live URL.', cls: 'status-processing', autoHint: null };
        }
        if (status === 'processing') {
            const accepted = item && item.accepted_at;
            return {
                label: accepted ? 'Accepted · processing' : 'Processing',
                next: 'Publisher is preparing and publishing your content, then will send a live URL.',
                cls: 'status-processing',
                autoHint: null,
            };
        }
        if (status === 'review') {
            return {
                label: 'URL delivered · your review',
                next: hasLiveUrl ? 'Check the live URL, then approve or request changes.' : 'Waiting for live URL.',
                cls: 'status-review',
                autoHint,
            };
        }
        if (status === 'completed') {
            return { label: 'Completed', next: 'All done — the publisher has been paid for this placement.', cls: 'status-completed', autoHint: null };
        }
        return { label: capitalize(status), next: '', cls: getStatusClass(status), autoHint: null };
    }

    function buildAdvertiserTimeline(order) {
        const item = order.items && order.items[0] ? order.items[0] : {};
        const status = order.status;
        const paid = ['paid', 'completed', 'refunded'].includes(order.payment_status)
            || ['processing', 'review', 'completed'].includes(status);
        const acceptedOrLater = ['processing', 'review', 'completed'].includes(status) || !!item.accepted_at;
        const urlDelivered = status === 'review' || status === 'completed';
        const completed = status === 'completed';
        const modRequested = item.modification_requested === 'yes';

        if (status === 'cancelled' && order.payment_status === 'refunded') {
            return `<div class="alert alert-secondary mt-3 mb-0 py-2 small">Cancelled · refunded to your wallet (usually instant).</div>
                <div class="mt-3">
                    <h6 class="mb-2">Activity Timeline</h6>
                    <div id="orderActivityTimeline" class="bg-white border rounded p-3">
                        <div class="text-muted small">Loading activity…</div>
                    </div>
                </div>`;
        }
        if (status === 'cancelled') {
            return `<div class="alert alert-secondary mt-3 mb-0 py-2 small">This order was cancelled.</div>
                <div class="mt-3">
                    <h6 class="mb-2">Activity Timeline</h6>
                    <div id="orderActivityTimeline" class="bg-white border rounded p-3">
                        <div class="text-muted small">Loading activity…</div>
                    </div>
                </div>`;
        }

        const steps = [
            { label: 'Paid', done: paid, current: false },
            { label: 'Accepted', done: acceptedOrLater, current: false },
            { label: modRequested && status === 'processing' ? 'Revision' : 'Processing', done: urlDelivered || completed, current: false },
            { label: 'URL delivered', done: completed, current: false },
            { label: 'Completed', done: completed, current: false },
        ];

        if (status === 'pending' && !paid) {
            steps[0].current = true;
            steps[0].done = false;
        } else if (status === 'pending' && paid) {
            steps[1].current = true;
        } else if (status === 'processing' && modRequested) {
            steps[2].current = true;
            steps[2].done = false;
            steps[3].done = false;
        } else if (status === 'processing') {
            steps[2].current = true;
        } else if (status === 'review') {
            steps[3].current = true;
            steps[3].done = false;
        } else if (status === 'completed') {
            steps[4].current = true;
        }

        const statusSteps = `<div class="d-flex flex-wrap gap-2 mt-3 mb-3">${steps.map((step, i) => {
            const cls = step.done ? 'bg-success text-white' : (step.current ? 'bg-info text-white' : 'bg-light text-muted');
            const arrow = i < steps.length - 1 ? '<span class="text-muted align-self-center">→</span>' : '';
            return `<span class="badge ${cls} px-3 py-2">${i + 1}. ${step.label}</span>${arrow}`;
        }).join('')}</div>`;

        const meta = getAdvertiserStatusMeta(order);
        const hint = meta.autoHint
            ? `<div class="small text-muted mb-2"><i class="fa fa-clock-o me-1"></i>${escapeHtml(meta.autoHint)}</div>`
            : '';

        return `${statusSteps}${hint}
            <div class="mt-3">
                <h6 class="mb-2">Activity Timeline</h6>
                <div id="orderActivityTimeline" class="bg-white border rounded p-3">
                    <div class="text-muted small">Loading activity…</div>
                </div>
            </div>`;
    }

    function loadOrderActivityTimeline(orderId) {
        const container = document.getElementById('orderActivityTimeline');
        if (!container) return;
        fetch(`{{ url('/notifications/order') }}/${orderId}/timeline`, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = '<div class="text-muted small">Unable to load activity.</div>';
                return;
            }
            if (window.renderOrderActivityTimeline) {
                window.renderOrderActivityTimeline(container, data.activities || []);
            } else {
                container.innerHTML = '<div class="text-muted small">No activity recorded yet.</div>';
            }
        })
        .catch(() => {
            container.innerHTML = '<div class="text-muted small">Unable to load activity.</div>';
        });
    }

    function renderOrders(orders, pagination) {
        if (!orders || orders.length === 0) {
            document.getElementById('ordersTableBody').innerHTML = `
                <tr>
                    <td colspan="11" class="text-center py-5">
                        <div class="mx-auto" style="max-width:420px">
                            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                                 style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e8f8f7);color:var(--brand-primary,#0b6266)"
                                 aria-hidden="true">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h5 class="mb-2">No orders yet</h5>
                            <p class="text-muted mb-3">When you buy placements from the catalog, they’ll show up here with status tracking.</p>
                            <div class="d-flex flex-wrap justify-content-center gap-2">
                                <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary btn-sm">
                                    <i class="fa fa-shopping-cart me-1"></i> Browse catalog
                                </a>
                                <a href="{{ route('advertiser.content-library') }}" class="btn btn-outline-secondary btn-sm">Content library</a>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
            document.getElementById('resultsCount').innerHTML = '';
            document.getElementById('paginationNav').innerHTML = '';
            return;
        }

        let html = '';
        orders.forEach(order => {
            const statusMeta = getAdvertiserStatusMeta(order);
            const siteName = order.items && order.items[0] ? order.items[0].site_name : 'N/A';
            const siteUrl = order.items && order.items[0] ? order.items[0].site_url : '#';
            const contentLink = order.items && order.items[0] ? order.items[0].content_link : '#';
            const liveUrl = order.items && order.items[0] ? order.items[0].live_url : null;
            const additionalPrice = order.items && order.items[0] ? parseFloat(order.items[0].additional_price || 0) : 0;
            const basePrice = order.items && order.items[0] ? (parseFloat(order.total_amount) - additionalPrice) : parseFloat(order.total_amount);
            const sensitiveType = order.items && order.items[0] ? order.items[0].sensitive_type : null;
            
            // Payment info combined
            const paymentMethodName = getPaymentMethodName(order.payment_method);
            const paymentStatusClass = getPaymentStatusClass(order.payment_status);
            
            // Check if order is in review status and has live URL (can approve or modify)
            const hasLiveUrl = liveUrl && liveUrl !== '';
            const isUnderReview = order.status === 'review';
            const unreadBadge = order.unread_chat > 0
                ? `<span class="chat-unread-dot pulse-badge is-pulsing">${order.unread_chat}</span>`
                : '';
            
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
                    <td>
                        <span class="status-badge ${statusMeta.cls}">${statusMeta.label}</span>
                        <div class="next-step-hint">${escapeHtml(statusMeta.next)}</div>
                        ${statusMeta.autoHint ? `<div class="next-step-hint text-muted"><i class="fa fa-clock-o me-1"></i>${escapeHtml(statusMeta.autoHint)}</div>` : ''}
                    </td>
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
                            ${order.can_retry_payment ? `
                            <button
                                type="button"
                                class="btn btn-sm btn-primary action-btn d-flex align-items-center"
                                onclick="retryOrderPayment(${order.id})">
                                <i class="fa fa-credit-card me-1"></i>
                                <span>Pay again</span>
                            </button>` : ''}
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
                                <span>Chat</span>${unreadBadge}
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
                                    <span>Request changes</span>
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
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        fetchOrders(currentPage);
                        if (data.ask_rating && Array.isArray(data.rateable) && data.rateable.length) {
                            askPublisherRatings(data.rateable, data.message || 'Order approved successfully!');
                        } else {
                            Swal.fire('Approved!', data.message, 'success');
                        }
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

    function starButtonsHtml(prefix) {
        let html = `<div class="d-flex justify-content-center gap-1 mb-2" id="${prefix}-stars">`;
        for (let i = 1; i <= 5; i++) {
            html += `<button type="button" class="btn btn-link p-0 rate-star-btn" data-value="${i}" style="font-size:28px;color:#cbd5e1;line-height:1;">
                <i class="fa-regular fa-star"></i>
            </button>`;
        }
        html += `</div><div class="small text-muted mb-2" id="${prefix}-label">Tap a star to rate</div>`;
        return html;
    }

    function bindStarPicker(prefix, state) {
        const wrap = document.getElementById(prefix + '-stars');
        const label = document.getElementById(prefix + '-label');
        if (!wrap) return;
        wrap.querySelectorAll('.rate-star-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                state.rating = parseInt(btn.dataset.value, 10);
                wrap.querySelectorAll('.rate-star-btn').forEach(b => {
                    const on = parseInt(b.dataset.value, 10) <= state.rating;
                    b.style.color = on ? '#f59e0b' : '#cbd5e1';
                    const icon = b.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('fa-solid', on);
                        icon.classList.toggle('fa-regular', !on);
                    }
                });
                if (label) label.textContent = state.rating + ' / 5';
            });
        });
    }

    async function askPublisherRatings(rateable, approvedMessage) {
        const ratingsPayload = [];
        for (let idx = 0; idx < rateable.length; idx++) {
            const item = rateable[idx];
            const prefix = 'rate-' + item.order_item_id;
            const state = { rating: 0 };
            const result = await Swal.fire({
                title: 'Rate this publisher',
                html: `
                    <p class="mb-1">${approvedMessage && idx === 0 ? `<span class="text-success">${escapeHtml(approvedMessage)}</span><br>` : ''}
                    How was your experience with <strong>${escapeHtml(item.site_name || 'this site')}</strong>?</p>
                    <p class="small text-muted mb-3">${escapeHtml(item.domain || '')}</p>
                    ${starButtonsHtml(prefix)}
                    <input id="${prefix}-comment" class="swal2-input" placeholder="Optional short feedback" maxlength="500">
                    <p class="small text-muted mt-2 mb-0">Ratings are only available after you approve a completed order.</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Submit rating',
                cancelButtonText: idx < rateable.length - 1 ? 'Skip' : 'Maybe later',
                confirmButtonColor: '#0b6266',
                didOpen: () => bindStarPicker(prefix, state),
                preConfirm: () => {
                    if (!state.rating) {
                        Swal.showValidationMessage('Please choose a star rating');
                        return false;
                    }
                    return {
                        order_item_id: item.order_item_id,
                        rating: state.rating,
                        comment: document.getElementById(prefix + '-comment')?.value || '',
                    };
                }
            });
            if (result.isConfirmed && result.value) {
                ratingsPayload.push(result.value);
            }
        }

        if (!ratingsPayload.length) {
            Swal.fire({icon:'success', title:'Approved!', text: approvedMessage, timer: 2200, showConfirmButton:false});
            return;
        }

        try {
            const res = await fetch(`{{ route('advertiser.ratings.batch') }}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ ratings: ratingsPayload })
            });
            const data = await res.json();
            Swal.fire({
                icon: data.success ? 'success' : 'error',
                title: data.success ? 'Thank you!' : 'Could not save rating',
                text: data.message || '',
                confirmButtonColor: '#0b6266'
            });
        } catch (e) {
            Swal.fire('Error', 'Failed to save rating', 'error');
        }
    }
    
    window.retryOrderPayment = function(orderId) {
        Swal.fire({
            title: 'Pay again?',
            text: 'We will open a new secure card checkout for this failed payment.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Continue to payment',
            confirmButtonColor: '#0b6266',
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }
            Swal.fire({
                title: 'Starting checkout…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });
            fetch(`{{ url('advertiser/orders') }}/${orderId}/retry-payment`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.success && data.checkout_url) {
                        window.location.href = data.checkout_url;
                        return;
                    }
                    Swal.fire('Unable to retry', data.message || 'Please try again from checkout.', 'error');
                })
                .catch(() => {
                    Swal.fire('Error', 'Failed to start payment retry.', 'error');
                });
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
                loadOrderActivityTimeline(orderId);
            } else {
                Swal.fire('Error', data.message || 'Failed to load order details', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load order details', 'error');
        });
    }

    function liveUrlHealthBadge(item) {
        if (item.live_url_check_ok === true) {
            return '<span class="badge bg-success">Reachable</span>';
        }
        if (item.live_url_check_ok === false) {
            return '<span class="badge bg-warning text-dark">Unreachable / unverified</span>';
        }
        return '<span class="badge bg-secondary">Not checked yet</span>';
    }

    function renderOrderDetails(order) {
        const item = order.items[0];
        const liveUrl = item.live_url || null;
        const additionalPrice = parseFloat(item.additional_price || 0);
        const basePrice = parseFloat(item.price) - additionalPrice;
        const isUnderReview = order.status === 'review';
        const hasLiveUrl = liveUrl && liveUrl !== '';
        const statusMeta = getAdvertiserStatusMeta(order);
        const timelineHtml = buildAdvertiserTimeline(order);
        const modRequested = item.modification_requested === 'yes';

        let healthHtml = '';
        if (liveUrl) {
            const checked = item.live_url_checked_at
                ? ` · checked ${formatDate(item.live_url_checked_at)}`
                : '';
            const http = item.live_url_http_status ? ` · HTTP ${item.live_url_http_status}` : '';
            healthHtml = `
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    ${liveUrlHealthBadge(item)}
                    <span class="small text-muted">We check the link is publicly reachable. Search indexing can take longer.${http}${checked}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="recheckLiveUrlBtn" onclick="recheckLiveUrl(${order.id})">
                        <i class="fa fa-refresh me-1"></i>Recheck
                    </button>
                </div>`;
        }

        const liveUrlHtml = liveUrl
            ? `<p class="mb-1"><strong>Live URL:</strong></p>
               <p class="mb-1"><a href="${escapeHtml(liveUrl)}" target="_blank" class="text-success">${escapeHtml(liveUrl)} <i class="fa fa-external-link fa-xs"></i></a></p>
               ${healthHtml}`
            : `<p class="mb-2 text-muted">Live URL not submitted yet</p>`;

        const revisionHtml = modRequested && item.completion_notes
            ? `<div class="alert alert-warning py-2 small mt-2 mb-0"><strong>Your change request:</strong> ${escapeHtml(item.completion_notes)}</div>`
            : '';

        const sensitiveHtml = additionalPrice > 0
            ? `<p class="mb-1"><strong>Sensitive Price:</strong></p>
               <p class="mb-2 text-warning"><i class="fa fa-plus-circle"></i> ${escapeHtml(item.sensitive_type || 'Extra')}: €${additionalPrice.toFixed(2)}</p>`
            : '';

        const refundRulesHtml = `
            <div class="border rounded p-3 mt-3 bg-light">
                <h6 class="mb-2">If something goes wrong</h6>
                <ul class="small mb-2 ps-3">
                    <li>Publisher declines → automatic wallet refund</li>
                    <li>Ask for changes if the live post needs fixes (before auto-approve)</li>
                    <li>Still stuck → Raise an issue (chat) or contact support</li>
                </ul>
                <a href="{{ route('refund-policy') }}" target="_blank" rel="noopener" class="small">Refund policy</a>
            </div>`;

        let actionButtons = '';
        if (order.can_retry_payment) {
            actionButtons = `
                <div class="mt-4 text-center d-flex gap-3 justify-content-center flex-wrap">
                    <button class="btn btn-primary" onclick="retryOrderPayment(${order.id})">
                        <i class="fa fa-credit-card"></i> Pay again
                    </button>
                </div>
            `;
        } else if (isUnderReview && hasLiveUrl) {
            actionButtons = `
                <div class="mt-4 text-center d-flex gap-3 justify-content-center flex-wrap">
                    <button class="btn btn-success" onclick="approveOrder(${order.id})">
                        <i class="fa fa-check-circle"></i> Approve
                    </button>
                    <button class="btn btn-warning" onclick="requestModification(${order.id})">
                        <i class="fa fa-edit"></i> Request changes
                    </button>
                    <button class="btn btn-outline-danger" onclick="raiseIssue(${order.id}, '${escapeHtml(order.order_number)}', '${escapeHtml(statusMeta.label)}')">
                        <i class="fa fa-flag"></i> Raise an issue
                    </button>
                </div>
            `;
        } else if (!['completed', 'cancelled'].includes(order.status) || order.payment_status === 'refunded') {
            actionButtons = `
                <div class="mt-4 text-center d-flex gap-3 justify-content-center flex-wrap">
                    <button class="btn btn-outline-secondary" onclick="openChat(${order.id}, '${escapeHtml(order.order_number)}')">
                        <i class="fa fa-comments"></i> Chat
                    </button>
                    ${order.status !== 'completed' ? `<button class="btn btn-outline-danger" onclick="raiseIssue(${order.id}, '${escapeHtml(order.order_number)}', '${escapeHtml(statusMeta.label)}')">
                        <i class="fa fa-flag"></i> Raise an issue
                    </button>` : ''}
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
                        <h6 class="mb-3">What's happening</h6>
                        <p class="mb-1"><strong>Status:</strong> <span class="status-badge ${statusMeta.cls}">${escapeHtml(statusMeta.label)}</span></p>
                        <p class="mb-2 text-muted small">${escapeHtml(statusMeta.next)}</p>
                        ${statusMeta.autoHint ? `<p class="mb-2 small text-muted"><i class="fa fa-clock-o me-1"></i>${escapeHtml(statusMeta.autoHint)}</p>` : ''}
                        <p class="mb-1"><strong>Price:</strong> <span class="fw-bold">€${basePrice.toFixed(2)}</span></p>
                        ${additionalPrice > 0 ? `<p class="mb-1"><strong>Sensitive Price:</strong> <span class="text-warning">+ €${additionalPrice.toFixed(2)}</span></p>` : ''}
                        <p class="mb-1"><strong>Total Amount:</strong> <span class="fw-bold text-primary fs-5">€${parseFloat(order.total_amount).toFixed(2)}</span></p>
                        ${revisionHtml}
                    </div>
                </div>
            </div>
            ${timelineHtml}

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
                        <p class="mb-1"><strong>Uploaded Document:</strong></p>
                        <p class="mb-2">${item.content_link ? `<a href="${escapeHtml(item.content_link)}" class="text-primary"><i class="fa fa-download me-1"></i>${escapeHtml(item.content_original_name || 'Download article')}</a>` : '—'}</p>
                        <p class="mb-1"><strong>Anchor Text:</strong></p>
                        <p class="mb-2">${escapeHtml(item.anchor_text || '—')}</p>
                        <p class="mb-1"><strong>Target URL:</strong></p>
                        <p class="mb-2">${item.target_url ? `<a href="${escapeHtml(item.target_url)}" target="_blank" rel="noopener">${escapeHtml(item.target_url)}</a>` : '—'}</p>
                        <p class="mb-1"><strong>Feature Image URL:</strong></p>
                        <p class="mb-2">${item.feature_image_url ? `<a href="${escapeHtml(item.feature_image_url)}" target="_blank" rel="noopener">${escapeHtml(item.feature_image_url)}</a>` : 'Publisher may choose'}</p>
                        <p class="mb-1"><strong>Compliance:</strong></p>
                        <p class="mb-2">${escapeHtml(item.moderation_status || '—')}</p>
                        ${liveUrlHtml}
                    </div>
                </div>
            </div>

            ${refundRulesHtml}
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

@endsection