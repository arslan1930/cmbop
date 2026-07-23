@extends('publisher.layouts.app')

@section('title', 'My Tasks')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">My Tasks</h2>
            <p class="text-muted mb-0">
                Manage and fulfill orders for your sites.
            </p>
        </div>
    </div>

    <div id="needsActionBanner" class="ui-callout ui-callout--attention ui-callout--banner d-none mb-4" role="status">
        <div class="ui-callout__main">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
            <div class="ui-callout__body">
                <strong>Needs your action</strong>
                <span class="ms-1" id="needsActionText"></span>
            </div>
        </div>
        <div class="ui-callout__actions">
            <button type="button" class="btn btn-sm btn-primary" id="showNeedsActionBtn">Show tasks that need me</button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Orders</h6>
                        <h3 class="mb-0" id="statTotalOrders">0</h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-tasks fa-2x text-primary"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Pending</h6>
                        <h3 class="mb-0" id="statPendingOrders">0</h3>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-clock fa-2x text-warning"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Earnings</h6>
                        <h3 class="mb-0" id="statTotalEarnings" style="color: #10b981;">€0</h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle">
                        <i class="fa fa-euro-sign fa-2x text-success"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="filterForm">
                <div class="row g-3 align-items-end">
                    <!-- Search -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Order #, Site name...">
                    </div>

                    <!-- Order Status Filter -->
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small text-muted mb-1">Order Status</label>
                        <select id="statusFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="pending">New — needs accept</option>
                            <option value="processing">In progress — publish content</option>
                            <option value="review">Waiting for advertiser</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Rejected</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small text-muted mb-1">Date Range</label>
                        <div class="d-flex gap-2">
                            <input type="date" id="dateFrom" class="form-control form-control-sm" placeholder="From">
                            <input type="date" id="dateTo" class="form-control form-control-sm" placeholder="To">
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm px-4" style="background-color: #3faeb2; color: white;">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> Filter
                            </button>
                            <button type="button" id="resetFiltersBtn" class="btn btn-sm px-3" style="background-color: #e9ecef; color: #495057;">
                                <i class="fa-solid fa-rotate-right me-1"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Tasks Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <div>
                <i class="fa fa-tasks me-2"></i> Task List
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
                            <th>Site Details</th>
                            <th>Base Price</th>
                            <th>Sensitive Price</th>
                            <th>Total Price</th>
                            <th>Order Status</th>
                            <th>Content Link</th>
                            <th width="120">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tasksTableBody">
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <div class="text-muted">Loading tasks...</div>
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

<!-- Accept Modal -->
<div class="modal fade" id="acceptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Accept Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="accept_order_item_id">
                <div class="text-center py-3">
                    <i class="fa fa-question-circle fa-3x text-success mb-3"></i>
                    <h5>Are you sure you want to accept this order?</h5>
                    <p class="text-muted">By accepting, you confirm that you will fulfill this order.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmAccept">Accept Order</button>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Reject Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="reject_order_item_id">
                <div class="mb-3">
                    <label for="reject_reason" class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                    <textarea id="reject_reason" class="form-control" rows="4" placeholder="Please explain why you cannot fulfill this order..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmReject">Reject Order</button>
            </div>
        </div>
    </div>
</div>

<!-- Submit Live URL Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Submit Live URL</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="complete_order_item_id">
                <div class="mb-3">
                    <label for="live_url" class="form-label">Live URL <span class="text-danger">*</span></label>
                    <input type="url" id="live_url" class="form-control" placeholder="https://example.com/your-article">
                    <small class="text-muted">Enter the live URL where the content is published. After submission, the advertiser has {{ (int) ceil(\App\Models\OrderItem::autoApproveHours() / 24) }} days to approve or request changes.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmComplete">Submit Live URL</button>
            </div>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@include('partials.order-chat-modal')
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
    max-width: 160px;
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

.btn-action-sm {
    padding: 4px 8px;
    font-size: 11px;
    min-width: 65px;
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
    gap: 4px;
}

.total-price {
    font-weight: bold;
    font-size: 14px;
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

.publisher-article-preview {
    border: 1px solid #dbe4ee;
    background: #fff;
    border-radius: 12px;
    padding: 14px 16px;
    max-height: 420px;
    overflow: auto;
    font-size: 0.92rem;
    line-height: 1.55;
    color: #334155;
}
.publisher-article-preview img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: .5rem 0;
    display: block;
}
.article-img-wrap {
    position: relative;
    display: inline-block;
    max-width: 100%;
}
.article-img-wrap img { display: block; max-width: 100%; height: auto; }
.article-img-download {
    position: absolute;
    right: 8px;
    bottom: 8px;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 2;
}
.article-img-wrap:hover .article-img-download,
.article-img-wrap:focus-within .article-img-download {
    opacity: 1;
}
</style>

<script src="{{ asset('js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('js/article-preview-tools.js') }}?v={{ @filemtime(public_path('js/article-preview-tools.js')) ?: '1' }}"></script>

<script>
let currentPage = 1;
let currentChatOrderId = null;
let refreshInterval = null;

// Get the base URL dynamically
const baseUrl = window.location.origin;
const AUTO_APPROVE_HOURS = {{ (int) \App\Models\OrderItem::autoApproveHours() }};
const AUTO_APPROVE_DAYS = {{ (int) max(1, (int) ceil(\App\Models\OrderItem::autoApproveHours() / 24)) }};

function clearFocusMessagesParam() {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('focus') && !url.searchParams.has('order')) return;
    url.searchParams.delete('focus');
    url.searchParams.delete('order');
    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
}

function clearFocusMessagesParam() {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('focus') && !url.searchParams.has('order')) return;
    url.searchParams.delete('focus');
    url.searchParams.delete('order');
    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
}

$(document).ready(function() {
    hydrateTasksFiltersFromUrl();
    loadTasks(currentPage);
    loadStatistics();
    refreshNeedsActionBanner();

    $('#showNeedsActionBtn').on('click', function() {
        $('#statusFilter').val('');
        syncTasksFiltersToUrl(1);
        loadTasks(1);
        $('html, body').animate({ scrollTop: $('#tasksTableBody').offset().top - 120 }, 'fast');
    });
    
    // Auto-refresh every 30 seconds
    refreshInterval = setInterval(function() {
        loadTasks(currentPage, true); // silent refresh
        loadStatistics();
    }, 30000);

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        syncTasksFiltersToUrl(1);
        loadTasks(1);
    });

    $('#resetFiltersBtn').on('click', function() {
        $('#searchInput').val('');
        $('#statusFilter').val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        currentPage = 1;
        syncTasksFiltersToUrl(1);
        loadTasks(1);
    });

    function hydrateTasksFiltersFromUrl() {
        const params = new URLSearchParams(window.location.search);
        if (params.has('search')) $('#searchInput').val(params.get('search') || '');
        if (params.has('status')) $('#statusFilter').val(params.get('status') || '');
        if (params.has('date_from')) $('#dateFrom').val(params.get('date_from') || '');
        if (params.has('date_to')) $('#dateTo').val(params.get('date_to') || '');
        const page = parseInt(params.get('page') || '1', 10);
        currentPage = Number.isFinite(page) && page > 0 ? page : 1;
    }

    function syncTasksFiltersToUrl(page) {
        const url = new URL(window.location.href);
        const map = {
            search: $('#searchInput').val() || '',
            status: $('#statusFilter').val() || '',
            date_from: $('#dateFrom').val() || '',
            date_to: $('#dateTo').val() || '',
        };
        Object.keys(map).forEach(function (key) {
            if (map[key]) url.searchParams.set(key, map[key]);
            else url.searchParams.delete(key);
        });
        if (page > 1) url.searchParams.set('page', String(page));
        else url.searchParams.delete('page');
        window.history.pushState({}, '', url);
    }
    window.syncTasksFiltersToUrl = syncTasksFiltersToUrl;

    $(document).on('click', '.accept-task', function() {
        $('#accept_order_item_id').val($(this).data('id'));
        $('#acceptModal').modal('show');
    });

    $(document).on('click', '.reject-task', function() {
        $('#reject_order_item_id').val($(this).data('id'));
        $('#reject_reason').val('');
        $('#rejectModal').modal('show');
    });

    $(document).on('click', '.submit-live-url', function() {
        $('#complete_order_item_id').val($(this).data('id'));
        $('#live_url').val('');
        $('#completeModal').modal('show');
    });

    // Chat functionality (shared OrderChat module)
    function formatChatDate(value, withTime) {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '—';
        if (withTime) {
            return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }
        return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderChatOrderDetails(details) {
        const el = document.getElementById('chatOrderDetails');
        if (!el) return;
        if (!details) {
            el.classList.add('d-none');
            el.innerHTML = '';
            return;
        }

        const parts = [];
        const websiteName = escapeHtml(details.website_name || '—');
        if (details.website_url) {
            parts.push('<span class="chat-detail-primary">' + websiteName + '</span> · <a href="' + escapeHtml(details.website_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(details.website_url) + '</a>');
        } else {
            parts.push('<span class="chat-detail-primary">' + websiteName + '</span>');
        }

        parts.push('Order date: ' + escapeHtml(formatChatDate(details.order_date, false)));
        parts.push('Started: ' + escapeHtml(formatChatDate(details.started_at, true)));

        if (details.df_links !== null && details.df_links !== undefined) {
            const dfLabel = details.df_links === 1 ? '1 DF link' : (details.df_links + ' DF links');
            const linkType = details.link_type ? (' (' + escapeHtml(details.link_type) + ')') : '';
            parts.push(escapeHtml(dfLabel) + linkType);
        } else if (details.link_type) {
            parts.push('Link type: ' + escapeHtml(details.link_type));
        }

        if (details.da != null || details.dr != null) {
            parts.push('DA ' + (details.da != null ? details.da : '—') + ' · DR ' + (details.dr != null ? details.dr : '—'));
        }

        if (details.sensitive_type) {
            parts.push('Sensitive: ' + escapeHtml(details.sensitive_type));
        }

        const statusLabel = escapeHtml(details.status_label || details.status || '—');
        const nextAction = escapeHtml(details.next_action || '');
        let statusBlock = '<div class="mt-2"><strong>' + statusLabel + '</strong>'
            + (nextAction ? ' — ' + nextAction : '') + '</div>';

        let revisionBlock = '';
        if (details.can_resubmit || details.modification_requested === 'yes') {
            const reason = details.completion_notes
                ? '<div class="small mt-1"><strong>Reason:</strong> ' + escapeHtml(details.completion_notes) + '</div>'
                : '';
            const itemId = details.order_item_id || '';
            const currentUrl = details.live_url
                ? '<div class="small mt-1 text-muted">Current URL: <a href="' + escapeHtml(details.live_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(details.live_url) + '</a></div>'
                : '';
            revisionBlock = '<div class="chat-resubmit-panel mt-2">'
                + '<div class="chat-resubmit-panel__title"><i class="fa fa-exclamation-circle me-1" aria-hidden="true"></i>Changes requested</div>'
                + '<div class="chat-resubmit-panel__guidance">Make the corrections on the live article, then paste the updated URL below to resubmit it here in chat.</div>'
                + reason
                + currentUrl
                + (details.can_resubmit && itemId
                    ? '<form class="chat-resubmit-form mt-2" data-item-id="' + escapeHtml(String(itemId)) + '">'
                        + '<label class="form-label small mb-1" for="chatResubmitUrl-' + escapeHtml(String(itemId)) + '">Updated live URL</label>'
                        + '<div class="input-group input-group-sm">'
                        + '<input type="url" class="form-control" id="chatResubmitUrl-' + escapeHtml(String(itemId)) + '" name="live_url" placeholder="https://example.com/your-updated-article" required autocomplete="url">'
                        + '<button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Resubmit URL</button>'
                        + '</div>'
                        + '<div class="form-text">After corrections, add the URL here again so the advertiser can review.</div>'
                        + '</form>'
                    : '')
                + '</div>';
        }

        el.innerHTML = '<div class="small">'
            + '<div>' + parts.join('<span class="chat-detail-sep">·</span>') + '</div>'
            + statusBlock
            + revisionBlock
            + '</div>';
        el.classList.remove('d-none');
    }

    function openTaskDetailsForOrder(orderId) {
        var attempts = 0;
        function tryOpen() {
            var itemId = window._publisherTasksByOrderId && window._publisherTasksByOrderId[String(orderId)];
            if (itemId) {
                viewOrderDetails(itemId);
                return;
            }
            if (++attempts < 25) {
                setTimeout(tryOpen, 200);
            }
        }
        tryOpen();
    }

    var orderChat = new OrderChat({
        baseUrl: baseUrl,
        renderOrderDetails: renderChatOrderDetails,
        onFocusOrder: openTaskDetailsForOrder,
        onFocusMessagesFallback: function() {
            var table = document.getElementById('tasksTableBody');
            if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        onClose: function() {
            loadTasks(currentPage, true);
            refreshNeedsActionBanner();
            if (typeof window.refreshHeaderAlerts === 'function') window.refreshHeaderAlerts();
        },
    });
    orderChat.init();

    window.openChat = function(orderId, orderNumber) {
        currentChatOrderId = orderId;
        orderChat.open(orderId, orderNumber);
    };

    function loadStatistics() {
        $.ajax({
            url: baseUrl + '/publisher/orders/statistics',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#statTotalOrders').text(response.data.total_orders || 0);
                    $('#statPendingOrders').text(response.data.pending_orders || 0);
                    $('#statTotalEarnings').html('€' + (response.data.total_earnings || 0).toFixed(2));
                }
            },
            error: function() {
                console.error('Failed to load statistics');
            }
        });
    }

    $('#confirmAccept').on('click', function() {
        var id = $('#accept_order_item_id').val();
        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/accept',
            method: 'POST',
            data: { _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmAccept').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire('Success!', response.message, 'success');
                    $('#acceptModal').modal('hide');
                    loadTasks();
                    loadStatistics();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to accept order', 'error');
                }
            },
            error: function(xhr) {
                let errorMsg = 'Failed to accept order';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                Swal.fire('Error!', errorMsg, 'error');
            },
            complete: function() {
                $('#confirmAccept').prop('disabled', false).html('Accept Order');
            }
        });
    });

    $('#confirmReject').on('click', function() {
        var id = $('#reject_order_item_id').val();
        var reason = $('#reject_reason').val();
        
        if (!reason) {
            Swal.fire('Warning!', 'Please provide a reason for rejection', 'warning');
            return;
        }
        
        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/reject',
            method: 'POST',
            data: { reason: reason, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmReject').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire('Rejected!', response.message, 'success');
                    $('#rejectModal').modal('hide');
                    loadTasks();
                    loadStatistics();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to reject order', 'error');
                }
            },
            error: function(xhr) {
                let errorMsg = 'Failed to reject order';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                Swal.fire('Error!', errorMsg, 'error');
            },
            complete: function() {
                $('#confirmReject').prop('disabled', false).html('Reject Order');
            }
        });
    });

    $('#confirmComplete').on('click', function() {
        var id = $('#complete_order_item_id').val();
        var liveUrl = $('#live_url').val();
        
        if (!liveUrl) {
            Swal.fire('Warning!', 'Please enter the live URL', 'warning');
            return;
        }
        
        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/complete',
            method: 'POST',
            data: { live_url: liveUrl, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmComplete').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        html: response.message + '<br><br><small>The advertiser now has ' + AUTO_APPROVE_DAYS + ' day(s) to review your submission. If no action is taken, the order will be approved.</small>',
                        icon: 'success'
                    });
                    $('#completeModal').modal('hide');
                    loadTasks();
                    loadStatistics();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to submit live URL', 'error');
                }
            },
            error: function(xhr) {
                let errorMsg = 'Failed to submit live URL';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                Swal.fire('Error!', errorMsg, 'error');
            },
            complete: function() {
                $('#confirmComplete').prop('disabled', false).html('Submit URL');
            }
        });
    });

    $(document).on('submit', '.chat-resubmit-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var id = $form.data('item-id');
        var $input = $form.find('input[name="live_url"]');
        var liveUrl = ($input.val() || '').trim();
        var $btn = $form.find('button[type="submit"]');

        if (!id) {
            Swal.fire('Error!', 'Missing order item for resubmit.', 'error');
            return;
        }
        if (!liveUrl) {
            Swal.fire('Warning!', 'Please enter the updated live URL', 'warning');
            $input.trigger('focus');
            return;
        }

        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/resubmit',
            method: 'POST',
            data: { live_url: liveUrl, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        html: response.message,
                        icon: 'success'
                    });
                    loadTasks();
                    loadStatistics();
                    if (orderChat && typeof orderChat.load === 'function' && orderChat.currentOrderId) {
                        orderChat.load(false);
                    }
                    refreshNeedsActionBanner();
                    if (typeof window.refreshHeaderAlerts === 'function') window.refreshHeaderAlerts();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to resubmit live URL', 'error');
                }
            },
            error: function(xhr) {
                let errorMsg = 'Failed to resubmit live URL';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                Swal.fire('Error!', errorMsg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Resubmit URL');
            }
        });
    });

    function loadTasks(page = 1, silent = false) {
        currentPage = page;
        if (!silent && typeof window.syncTasksFiltersToUrl === 'function') {
            window.syncTasksFiltersToUrl(page);
        }
        if (!silent) {
            $('#tasksTableBody').html('<tr><td colspan="9" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Loading tasks...</p></td></tr>');
        }
        
        $.ajax({
            url: baseUrl + '/publisher/orders/data',
            method: 'GET',
            data: {
                page: page,
                search: $('#searchInput').val(),
                status: $('#statusFilter').val(),
                date_from: $('#dateFrom').val(),
                date_to: $('#dateTo').val()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderTasksTable(response.data);
                    if (response.pagination) renderPagination(response.pagination);
                    refreshNeedsActionBanner();
                } else if (!silent) {
                    $('#tasksTableBody').html('<tr><td colspan="9" class="text-center text-danger py-5">' + (response.message || 'Failed to load tasks') + '</td></tr>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                if (!silent) {
                    $('#tasksTableBody').html(
                        '<tr><td colspan="9" class="text-center py-5">' +
                        '<div class="text-danger mb-2">Error loading tasks.</div>' +
                        '<button type="button" class="btn btn-sm btn-outline-primary" id="retryTasksBtn">Retry</button>' +
                        '</td></tr>'
                    );
                    $('#retryTasksBtn').on('click', function () { loadTasks(currentPage); });
                }
            }
        });
    }

    function renderTasksTable(orderItems) {
        if (!orderItems || orderItems.length === 0) {
            $('#tasksTableBody').html(
                '<tr><td colspan="9" class="text-center py-5">' +
                '<div class="mx-auto" style="max-width:420px">' +
                '<div class="mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,#185054)" aria-hidden="true"><i class="fa-solid fa-inbox"></i></div>' +
                '<h5 class="mb-2">No tasks yet</h5>' +
                '<p class="text-muted mb-3">When advertisers order your sites, new tasks will show up here.</p>' +
                '<a href="{{ route("publisher.websites") }}" class="btn btn-primary btn-sm">Manage my sites</a>' +
                '</div></td></tr>'
            );
            $('#resultsCount').html('');
            return;
        }
        
        var html = '';
        window._publisherTasksByOrderId = {};
        orderItems.forEach(function(item) {
            if (item.order_id) {
                window._publisherTasksByOrderId[String(item.order_id)] = item.id;
            }
            var orderStatus = item.order ? item.order.status : 'pending';
            var orderNumber = item.order ? item.order.order_number : 'N/A';
            var additionalPrice = parseFloat(item.additional_price || 0);
            var basePrice = parseFloat(item.price) - additionalPrice;
            var totalPrice = parseFloat(item.price);
            var sensitiveType = item.sensitive_type || null;
            
            var hasLiveUrl = !!(item.live_url && item.live_url !== '');
            var modificationRequested = item.modification_requested === 'yes';
            var awaitingAdvertiser = orderStatus === 'review' || (orderStatus === 'processing' && hasLiveUrl && !modificationRequested);
            var statusMeta = getPublisherStatusMeta(orderStatus, hasLiveUrl, modificationRequested, item.live_url_submitted_at);
            var unreadBadge = item.unread_chat > 0
                ? '<span class="chat-unread-dot pulse-badge is-pulsing">' + item.unread_chat + '</span>'
                : '';
            var chatBtn = '<button class="btn btn-primary btn-action-sm" onclick="openChat(' + item.order_id + ', \'' + orderNumber + '\')"><i class="fa fa-comments"></i> Chat' + unreadBadge + '</button>';
            var viewBtn = '<button class="btn btn-outline-secondary btn-action-sm view-details" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button>';
            var liveBtn = hasLiveUrl
                ? '<a href="' + escapeHtml(item.live_url) + '" target="_blank" class="btn btn-secondary btn-action-sm"><i class="fa fa-external-link"></i> Live</a>'
                : '';

            var actions = '';
            if (orderStatus === 'pending') {
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-success btn-action-sm accept-task" data-id="' + item.id + '"><i class="fa fa-check"></i> Accept</button>' +
                    '<button class="btn btn-danger btn-action-sm reject-task" data-id="' + item.id + '"><i class="fa fa-times"></i> Reject</button>' +
                    viewBtn + chatBtn +
                    '</div>';
            } else if (modificationRequested && (orderStatus === 'processing' || orderStatus === 'review')) {
                actions = '<div class="action-buttons">' +
                    viewBtn + chatBtn + liveBtn +
                    '</div>';
            } else if (awaitingAdvertiser) {
                actions = '<div class="action-buttons">' +
                    viewBtn + chatBtn + liveBtn +
                    '</div>';
            } else if (orderStatus === 'processing') {
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-primary btn-action-sm submit-live-url" data-id="' + item.id + '"><i class="fa fa-link"></i> Submit Live URL</button>' +
                    viewBtn + chatBtn +
                    '</div>';
            } else {
                actions = '<div class="action-buttons">' + viewBtn + chatBtn + liveBtn + '</div>';
            }
            
            html += '<tr>' +
                '<td><strong>#' + escapeHtml(orderNumber) + '</strong></td>' +
                '<td><div class="fw-semibold">' + escapeHtml(item.site_name) + '</div><div class="text-muted small"><a href="' + escapeHtml(item.site_url) + '" target="_blank">' + escapeHtml(item.site_url) + '</a></div></td>' +
                '<td class="text-primary">€' + basePrice.toFixed(2) + '</td>' +
                '<td>' + (additionalPrice > 0 ? '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> ' + escapeHtml(sensitiveType || 'Extra') + ' (+€' + additionalPrice.toFixed(2) + ')</span>' : '<span class="text-muted">—</span>') + '</td>' +
                '<td class="fw-semibold total-price" style="color: #10b981;">€' + totalPrice.toFixed(2) + '</td>' +
                '<td><span class="status-badge ' + statusMeta.statusClass + '">' + statusMeta.statusText + '</span><div class="next-step-hint">' + statusMeta.nextStep + '</div></td>' +
                '<td class="link-cell">' + ((item.content_download_url || item.content_link) ? '<a href="' + (item.content_download_url || item.content_link) + '" class="btn btn-sm btn-outline-primary"><i class="fa fa-download me-1"></i> ' + (item.content_original_name ? 'Document' : 'View') + '</a>' : '<span class="text-muted">Not submitted</span>') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>';
        });
        
        $('#tasksTableBody').html(html);
        
        // View details click handler
        $('.view-details').off('click').on('click', function() {
            var id = $(this).data('id');
            viewOrderDetails(id);
        });
    }
    
    function viewOrderDetails(itemId) {
        $.ajax({
            url: baseUrl + '/publisher/orders/' + itemId + '/details',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderDetailsModal(response.data);
                    $('#detailsModal').modal('show');
                    if (response.data && response.data.order_id) {
                        loadOrderActivityTimeline(response.data.order_id);
                    } else if (response.data && response.data.order && response.data.order.id) {
                        loadOrderActivityTimeline(response.data.order.id);
                    }
                } else {
                    Swal.fire('Error!', response.message || 'Failed to load order details', 'error');
                }
            },
            error: function(xhr) {
                let errorMsg = 'Failed to load order details';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                Swal.fire('Error!', errorMsg, 'error');
            }
        });
    }
    
    function renderDetailsModal(item) {
        var order = item.order;
        var orderStatus = order ? order.status : 'pending';
        var paymentStatus = order ? order.payment_status : 'pending';
        var additionalPrice = parseFloat(item.additional_price || 0);
        var basePrice = parseFloat(item.price) - additionalPrice;
        var totalPrice = parseFloat(item.price);
        var sensitiveType = item.sensitive_type || null;
        
        var paymentStatusHtml = paymentStatus === 'paid' 
            ? '<span class="badge bg-success">Paid</span>' 
            : '<span class="badge bg-warning text-dark">Pending</span>';
        
        var hasLiveUrl = !!(item.live_url && item.live_url !== '');
        var modificationRequested = item.modification_requested === 'yes';
        var statusMeta = getPublisherStatusMeta(orderStatus, hasLiveUrl, modificationRequested, item.live_url_submitted_at);
        var statusClass = statusMeta.statusClass;
        var statusText = statusMeta.statusText;
        
        var autoApproveInfo = '';
        if (item.live_url_submitted_at && !modificationRequested && !item.auto_approve_triggered) {
            const hoursRemaining = getAutoApproveHoursRemaining(item.live_url_submitted_at);
            if (hoursRemaining > 0) {
                autoApproveInfo = '<div class="ui-callout ui-callout--info mt-3"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span><div class="ui-callout__body"><strong>Waiting for advertiser:</strong> They can approve or request changes. ' + escapeHtml(formatAutoApproveCountdown(hoursRemaining)) + '.</div></div>';
            } else {
                autoApproveInfo = '<div class="ui-callout ui-callout--success mt-3"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span><div class="ui-callout__body"><strong>Ready for approval:</strong> The advertiser review window has ended — this should auto-approve soon.</div></div>';
            }
        }
        
        var liveUrlHtml = item.live_url 
            ? '<p class="mb-1"><strong>Live URL:</strong></p><p class="mb-2"><a href="' + escapeHtml(item.live_url) + '" target="_blank" class="text-success">' + escapeHtml(item.live_url) + ' <i class="fa fa-external-link fa-xs"></i></a></p>'
            : '<p class="mb-2 text-muted">Live URL not submitted yet</p>';
        
        if (modificationRequested) {
            var reason = item.completion_notes ? '<div class="small mt-1">Reason: ' + escapeHtml(item.completion_notes) + '</div>' : '';
            liveUrlHtml = '<div class="ui-callout ui-callout--attention mb-2"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span><div class="ui-callout__body">The advertiser asked for changes. Make the corrections, then open <strong>Chat</strong> to paste and resubmit the live URL.' + reason + '</div></div>' + liveUrlHtml;
        }

        var timelineHtml = buildPublisherTimeline(orderStatus, hasLiveUrl, modificationRequested);
        
        var createdAt = item.created_at ? new Date(item.created_at).toLocaleDateString() : 'N/A';
        
        var html = '<div class="row mb-4">' +
            '<div class="col-md-6">' +
                '<div class="bg-light p-3 rounded">' +
                    '<h6 class="mb-3">Order Information</h6>' +
                    '<p class="mb-1"><strong>Order Number:</strong> #' + escapeHtml(order.order_number) + '</p>' +
                    '<p class="mb-1"><strong>Date:</strong> ' + escapeHtml(createdAt) + '</p>' +
                    '<p class="mb-1"><strong>Payment Status:</strong> ' + paymentStatusHtml + '</p>' +
                    '<p class="mb-1"><strong>Reference Code:</strong> ' + escapeHtml(order.reference_code || '-') + '</p>' +
                '</div>' +
            '</div>' +
            '<div class="col-md-6">' +
                '<div class="bg-light p-3 rounded">' +
                    '<h6 class="mb-3">Order Status</h6>' +
                    '<p class="mb-1"><strong>Status:</strong> <span class="status-badge ' + statusClass + '">' + statusText + '</span></p>' +
                    '<p class="mb-1 text-muted small">' + statusMeta.nextStep + '</p>' +
                    '<p class="mb-1"><strong>Base Price:</strong> €' + basePrice.toFixed(2) + '</p>' +
                    (additionalPrice > 0 ? '<p class="mb-1"><strong>Sensitive Price:</strong> <span class="text-warning">+ €' + additionalPrice.toFixed(2) + ' (' + escapeHtml(sensitiveType) + ')</span></p>' : '') +
                    '<p class="mb-1"><strong>Total Amount:</strong> <span class="fw-bold text-primary fs-5">€' + totalPrice.toFixed(2) + '</span></p>' +
                '</div>' +
            '</div>' +
        '</div>' +
        timelineHtml +
        autoApproveInfo +
        '<h6 class="mb-3">Order Items</h6>' +
        '<div class="border rounded p-3">' +
            '<div class="row">' +
                '<div class="col-md-6">' +
                    '<p class="mb-1"><strong>Site Name:</strong></p>' +
                    '<p class="mb-2">' + escapeHtml(item.site_name) + '</p>' +
                    '<p class="mb-1"><strong>Site URL:</strong></p>' +
                    '<p class="mb-2"><a href="' + escapeHtml(item.site_url) + '" target="_blank" class="text-primary">' + escapeHtml(item.site_url) + ' <i class="fa fa-external-link fa-xs"></i></a></p>' +
                    (additionalPrice > 0 ? '<p class="mb-1"><strong>Sensitive Type:</strong></p><p class="mb-2 text-warning">' + escapeHtml(sensitiveType) + ' (+€' + additionalPrice.toFixed(2) + ')</p>' : '') +
                '</div>' +
                '<div class="col-md-6">' +
                    '<p class="mb-1"><strong>Price Breakdown:</strong></p>' +
                    '<p class="mb-1"><small>Base Price: €' + basePrice.toFixed(2) + '</small></p>' +
                    (additionalPrice > 0 ? '<p class="mb-1"><small class="text-warning">+ ' + escapeHtml(sensitiveType) + ': €' + additionalPrice.toFixed(2) + '</small></p>' : '') +
                    '<p class="mb-2"><strong class="text-primary">Total: €' + totalPrice.toFixed(2) + '</strong></p>' +
                    '<p class="mb-1"><strong>Uploaded Document:</strong></p>' +
                    '<p class="mb-2">' + ((item.content_download_url || item.content_link) ? '<a href="' + escapeHtml(item.content_download_url || item.content_link) + '" class="text-primary"><i class="fa fa-download me-1"></i>' + escapeHtml(item.content_original_name || 'Download article') + '</a>' : '—') + '</p>' +
                    '<p class="mb-1"><strong>Anchor Text:</strong></p><p class="mb-2">' + escapeHtml(item.anchor_text || '—') + '</p>' +
                    '<p class="mb-1"><strong>Target URL:</strong></p><p class="mb-2">' + (item.target_url ? '<a href="' + escapeHtml(item.target_url) + '" target="_blank" rel="noopener">' + escapeHtml(item.target_url) + '</a>' : '—') + '</p>' +
                    '<p class="mb-1"><strong>Feature Image URL:</strong></p><p class="mb-2">' + (item.feature_image_url ? '<a href="' + escapeHtml(item.feature_image_url) + '" target="_blank" rel="noopener">' + escapeHtml(item.feature_image_url) + '</a>' : 'Publisher may choose') + '</p>' +
                    '<p class="mb-1"><strong>Content Compliance:</strong></p><p class="mb-2">' + escapeHtml(item.moderation_status || '—') + '</p>' +
                    (item.order && item.order.scheduled_label ? '<p class="mb-1"><strong>Scheduled for:</strong></p><p class="mb-2 text-warning fw-semibold">Publish on ' + escapeHtml(item.order.scheduled_label) + '</p>' : '') +
                    liveUrlHtml +
                '</div>' +
            '</div>' +
        '</div>' +
        buildPublisherArticlePreview(item);
        
        $('#detailsContent').html(html);
        bindPublisherArticlePreviewTools(item);
    }

    function buildPublisherArticlePreview(item) {
        var htmlBody = item.preview_html || '';
        var links = Array.isArray(item.detected_links) ? item.detected_links : [];
        if (!htmlBody && !links.length) {
            return '';
        }
        var title = item.article_title || item.content_original_name || 'Article';
        return '<div class="mt-4" id="publisherArticlePreviewSection">' +
            '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">' +
                '<h6 class="mb-0">Article to publish</h6>' +
                '<div class="d-flex flex-wrap gap-2">' +
                    '<button type="button" class="btn btn-sm btn-outline-primary" id="publisherCopyHeadingBtn"><i class="fa fa-copy me-1"></i>Copy heading</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-primary" id="publisherCopyArticleBtn"><i class="fa fa-clone me-1"></i>Copy article</button>' +
                '</div>' +
            '</div>' +
            '<p class="small text-muted mb-2" id="publisherArticleHeadingHint"></p>' +
            (htmlBody
                ? '<div class="publisher-article-preview" id="publisherArticleBody"></div>'
                : '<p class="text-muted small mb-0">No HTML preview available — download the uploaded document instead.</p>') +
            '<div class="border-top mt-3 pt-3">' +
                '<div class="fw-semibold mb-2">Links in this article</div>' +
                '<div id="publisherArticleLinksList"></div>' +
                '<p class="small text-muted mb-0 mt-2">Shown outside the article so you can copy every anchor and URL when publishing.</p>' +
            '</div>' +
            '<div class="d-none" id="publisherArticleTitleStore">' + escapeHtml(title) + '</div>' +
        '</div>';
    }

    function bindPublisherArticlePreviewTools(item) {
        var tools = window.ArticlePreviewTools;
        if (!tools) return;

        var body = document.getElementById('publisherArticleBody');
        var title = (item && (item.article_title || item.content_original_name)) || 'Article';
        var links = (item && Array.isArray(item.detected_links)) ? item.detected_links : [];

        if (body && item && item.preview_html) {
            body.innerHTML = item.preview_html;
            body.querySelectorAll('img').forEach(function (img) {
                var src = img.getAttribute('src') || '';
                var match = src.match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
                if (match) img.setAttribute('src', match[1]);
            });
            tools.enhanceImages(body);
            var heading = tools.extractHeading(body, title);
            var hint = document.getElementById('publisherArticleHeadingHint');
            if (hint) hint.textContent = heading ? ('Heading: ' + heading) : '';
        }

        tools.renderLinkRows(document.getElementById('publisherArticleLinksList'), links, false);

        document.getElementById('publisherCopyHeadingBtn')?.addEventListener('click', async function () {
            var heading = tools.extractHeading(body, title);
            try {
                await tools.copyText(heading);
                tools.toast('Heading copied');
            } catch (e) {
                tools.toast('Could not copy heading', false);
            }
        });

        document.getElementById('publisherCopyArticleBtn')?.addEventListener('click', async function () {
            if (!body) {
                tools.toast('No article preview to copy', false);
                return;
            }
            try {
                await tools.copyHtml(body.innerHTML, body.innerText);
                tools.toast('Article copied — paste into your CMS');
            } catch (e) {
                tools.toast('Could not copy article', false);
            }
        });
    }

    function renderPagination(pagination) {
        if (!pagination || pagination.total === 0 || pagination.last_page <= 1) {
            $('#paginationNav').html('');
            return;
        }
        
        var paginationHtml = '<ul class="pagination justify-content-center">';
        
        if (pagination.current_page > 1) {
            paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page - 1) + '">Previous</button></li>';
        } else {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
        }
        
        for (var i = Math.max(1, pagination.current_page - 2); i <= Math.min(pagination.last_page, pagination.current_page + 2); i++) {
            paginationHtml += '<li class="page-item ' + (i === pagination.current_page ? 'active' : '') + '"><button class="page-link" data-page="' + i + '">' + i + '</button></li>';
        }
        
        if (pagination.current_page < pagination.last_page) {
            paginationHtml += '<li class="page-item"><button class="page-link" data-page="' + (pagination.current_page + 1) + '">Next</button></li>';
        } else {
            paginationHtml += '<li class="page-item disabled"><span class="page-link">Next</span></li>';
        }
        
        paginationHtml += '</ul>';
        $('#paginationNav').html(paginationHtml);
        
        $('.page-link[data-page]').off('click').on('click', function(e) {
            e.preventDefault();
            var page = parseInt($(this).data('page'));
            if (page) {
                loadTasks(page);
                $('html, body').animate({ scrollTop: 0 }, 'fast');
            }
        });
    }
    
    function getAutoApproveHoursRemaining(submittedAt) {
        if (!submittedAt) return null;
        const hoursPassed = (new Date() - new Date(submittedAt)) / (1000 * 60 * 60);
        return AUTO_APPROVE_HOURS - hoursPassed;
    }

    function formatAutoApproveCountdown(hoursRemaining) {
        if (hoursRemaining === null || hoursRemaining === undefined) return null;
        if (hoursRemaining <= 0) return 'Ready for auto-approve soon';
        if (hoursRemaining >= 24) {
            const days = Math.ceil(hoursRemaining / 24);
            return 'Auto-approve in ~' + days + ' day(s) if they take no action';
        }
        return 'Auto-approve in ~' + Math.ceil(hoursRemaining) + 'h if they take no action';
    }

    function getPublisherStatusMeta(orderStatus, hasLiveUrl, modificationRequested, liveUrlSubmittedAt) {
        if (orderStatus === 'pending') {
            return { statusClass: 'status-pending', statusText: 'New order', nextStep: 'Accept or reject this order' };
        }
        if (modificationRequested) {
            return { statusClass: 'status-pending', statusText: 'Changes requested', nextStep: 'Make corrections, then open Chat to resubmit the live URL' };
        }
        if (orderStatus === 'review' || (orderStatus === 'processing' && hasLiveUrl)) {
            const hoursRemaining = getAutoApproveHoursRemaining(liveUrlSubmittedAt);
            const countdown = formatAutoApproveCountdown(hoursRemaining) || 'Advertiser can approve anytime';
            return { statusClass: 'status-review', statusText: 'Waiting for advertiser', nextStep: countdown };
        }
        if (orderStatus === 'processing') {
            return { statusClass: 'status-processing', statusText: 'In progress', nextStep: 'Publish the content, then submit the live URL' };
        }
        if (orderStatus === 'completed') {
            return { statusClass: 'status-completed', statusText: 'Completed', nextStep: 'Payment released to your wallet' };
        }
        if (orderStatus === 'cancelled') {
            return { statusClass: 'status-cancelled', statusText: 'Rejected', nextStep: 'No further action needed' };
        }
        return { statusClass: 'status-pending', statusText: orderStatus, nextStep: '' };
    }

    function buildPublisherTimeline(orderStatus, hasLiveUrl, modificationRequested) {
        const steps = [
            { key: 'pending', label: 'Accepted' },
            { key: 'processing', label: 'Publishing' },
            { key: 'review', label: 'Advertiser review' },
            { key: 'completed', label: 'Done' }
        ];
        let activeIndex = 0;
        if (orderStatus === 'cancelled') {
            return '<div class="alert alert-secondary mt-3 mb-3 py-2 small">This order was rejected.</div>';
        }
        if (orderStatus === 'pending') activeIndex = 0;
        else if (orderStatus === 'processing' && !hasLiveUrl) activeIndex = 1;
        else if (orderStatus === 'review' || (orderStatus === 'processing' && hasLiveUrl) || modificationRequested) activeIndex = 2;
        else if (orderStatus === 'completed') activeIndex = 3;

        let html = '<div class="d-flex flex-wrap gap-2 mt-3 mb-3">';
        steps.forEach(function(step, index) {
            const done = index < activeIndex || orderStatus === 'completed';
            const current = index === activeIndex && orderStatus !== 'completed';
            const cls = done ? 'bg-success text-white' : (current ? 'bg-info text-white' : 'bg-light text-muted');
            html += '<span class="badge ' + cls + ' px-3 py-2">' + (index + 1) + '. ' + step.label + '</span>';
            if (index < steps.length - 1) html += '<span class="text-muted align-self-center">→</span>';
        });
        html += '</div>';
        html += '<div class="mt-3"><h6 class="mb-2">Activity Timeline</h6><div id="orderActivityTimeline" class="bg-white border rounded p-3"><div class="text-muted small">Loading activity…</div></div></div>';
        return html;
    }

    function loadOrderActivityTimeline(orderId) {
        var container = document.getElementById('orderActivityTimeline');
        if (!container || !orderId) return;
        fetch(baseUrl + '/notifications/order/' + orderId + '/timeline', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
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
        .catch(function() {
            container.innerHTML = '<div class="text-muted small">Unable to load activity.</div>';
        });
    }

    function refreshNeedsActionBanner() {
        $.getJSON(baseUrl + '/chat/unread-summary')
            .done(function(res) {
                if (res.success && res.needs_action > 0) {
                    $('#needsActionText').text(res.needs_action + ' task' + (res.needs_action === 1 ? '' : 's') + ' need you (accept, publish, or open Chat to resubmit).');
                    $('#needsActionBanner').removeClass('d-none');
                } else {
                    $('#needsActionBanner').addClass('d-none');
                }
            });
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
});
</script>

@endsection