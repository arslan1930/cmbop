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
                            <option value="pending">Pending</option>
                            <option value="processing">In Progress</option>
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
                            <button type="submit" class="btn btn-sm px-4" style="background-color: #3aaeb2; color: white;">
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
                    <small class="text-muted">Enter the live URL where the content is published. After submission, the advertiser has 48 hours to approve or request changes.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmComplete">Submit Live URL</button>
            </div>
        </div>
    </div>
</div>

<!-- Resubmit Live URL Modal (for modification) -->
<div class="modal fade" id="resubmitModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Resubmit Live URL</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="resubmit_order_item_id">
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> The advertiser has requested modifications. Please update your content and submit the new live URL.
                </div>
                <div class="mb-3">
                    <label for="resubmit_live_url" class="form-label">Updated Live URL <span class="text-danger">*</span></label>
                    <input type="url" id="resubmit_live_url" class="form-control" placeholder="https://example.com/your-updated-article">
                    <small class="text-muted">Enter the updated live URL with the requested changes</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="confirmResubmit">Resubmit Live URL</button>
            </div>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

.status-completed {
    background-color: #dcfce7;
    color: #282828;
}

.status-cancelled {
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let currentPage = 1;
let currentChatOrderId = null;
let refreshInterval = null;

// Get the base URL dynamically
const baseUrl = window.location.origin;

$(document).ready(function() {
    loadTasks();
    loadStatistics();
    
    // Auto-refresh every 30 seconds
    refreshInterval = setInterval(function() {
        loadTasks(currentPage, true); // silent refresh
        loadStatistics();
    }, 30000);

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        loadTasks();
    });

    $('#resetFiltersBtn').on('click', function() {
        $('#searchInput').val('');
        $('#statusFilter').val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        currentPage = 1;
        loadTasks();
    });

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

    $(document).on('click', '.resubmit-live-url', function() {
        $('#resubmit_order_item_id').val($(this).data('id'));
        $('#resubmit_live_url').val('');
        $('#resubmitModal').modal('show');
    });

    // Chat functionality
    window.openChat = function(orderId, orderNumber) {
        currentChatOrderId = orderId;
        document.getElementById('chatOrderId').value = orderId;
        document.getElementById('chatOrderNumber').innerText = orderNumber;
        loadChatMessages(orderId);
        $('#chatModal').modal('show');
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

    function loadChatMessages(orderId) {
        fetch(baseUrl + '/chat/messages/' + orderId, {
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
            } else {
                document.getElementById('chatMessages').innerHTML = '<div class="text-center text-danger py-5"><i class="fa fa-exclamation-circle fa-3x mb-3"></i><p>Failed to load messages. Please try again.</p></div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('chatMessages').innerHTML = '<div class="text-center text-danger py-5"><i class="fa fa-exclamation-circle fa-3x mb-3"></i><p>Failed to load messages. Please try again.</p></div>';
        });
    }

    function renderChatMessages(messages, currentUserId) {
        if (!messages || messages.length === 0) {
            document.getElementById('chatMessages').innerHTML = '<div class="text-center text-muted py-5"><i class="fa fa-comments fa-3x mb-3"></i><p>No messages yet. Start the conversation!</p></div>';
            return;
        }
        
        let html = '';
        
        messages.forEach(msg => {
            const isOwnMessage = msg.user_id === currentUserId;
            const messageClass = isOwnMessage ? 'bg-primary text-white' : 'bg-white border';
            const alignClass = isOwnMessage ? 'justify-content-end' : 'justify-content-start';
            const senderName = isOwnMessage ? 'You' : escapeHtml(msg.user.name);
            const time = new Date(msg.created_at).toLocaleString();
            const messageText = escapeHtml(msg.message || '');
            
            html += '<div class="d-flex ' + alignClass + ' mb-3">' +
                '<div class="' + messageClass + ' rounded-3 p-3" style="max-width: 70%;">' +
                    '<div class="small fw-semibold ' + (isOwnMessage ? 'text-white-50' : 'text-primary') + ' mb-1">' +
                        senderName + ' · ' + time +
                    '</div>' +
                    '<div class="mb-0">' + messageText + '</div>' +
                '</div>' +
            '</div>';
        });
        
        document.getElementById('chatMessages').innerHTML = html;
    }

    $('#chatForm').on('submit', function(e) {
        e.preventDefault();
        const orderId = $('#chatOrderId').val();
        const message = $('#chatMessageInput').val().trim();
        
        if (!message) return;
        
        const sendBtn = $(this).find('button[type="submit"]');
        sendBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Sending...');
        
        fetch(baseUrl + '/chat/send/' + orderId, {
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
                $('#chatMessageInput').val('');
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
            sendBtn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> Send');
        });
    });

    // Ctrl+Enter shortcut
    $('#chatMessageInput').on('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            $('#chatForm').submit();
        }
    });

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
                        html: response.message + '<br><br><small>The advertiser now has 48 hours to review your submission. If no action is taken, the order will be approved.</small>',
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

    $('#confirmResubmit').on('click', function() {
        var id = $('#resubmit_order_item_id').val();
        var liveUrl = $('#resubmit_live_url').val();
        
        if (!liveUrl) {
            Swal.fire('Warning!', 'Please enter the updated live URL', 'warning');
            return;
        }
        
        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/resubmit',
            method: 'POST',
            data: { live_url: liveUrl, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmResubmit').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        html: response.message,
                        icon: 'success'
                    });
                    $('#resubmitModal').modal('hide');
                    loadTasks();
                    loadStatistics();
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
                $('#confirmResubmit').prop('disabled', false).html('Resubmit URL');
            }
        });
    });

    function loadTasks(page = 1, silent = false) {
        currentPage = page;
        if (!silent) {
            $('#tasksTableBody').html('<tr><td colspan="9" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Loading tasks...</p></td></table>');
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
                } else if (!silent) {
                    $('#tasksTableBody').html('<tr><td colspan="9" class="text-center text-danger py-5">' + (response.message || 'Failed to load tasks') + '</td></table>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                if (!silent) {
                    $('#tasksTableBody').html('<tr><td colspan="9" class="text-center text-danger py-5">Error loading tasks. Please refresh the page.</td></tr>');
                }
            }
        });
    }

    function renderTasksTable(orderItems) {
        if (!orderItems || orderItems.length === 0) {
            $('#tasksTableBody').html('<tr><td colspan="9" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2">No tasks found</p></td></tr>');
            $('#resultsCount').html('');
            return;
        }
        
        var html = '';
        orderItems.forEach(function(item) {
            var orderStatus = item.order ? item.order.status : 'pending';
            var orderNumber = item.order ? item.order.order_number : 'N/A';
            var additionalPrice = parseFloat(item.additional_price || 0);
            var basePrice = parseFloat(item.price) - additionalPrice;
            var totalPrice = parseFloat(item.price);
            var sensitiveType = item.sensitive_type || null;
            
            var statusClass = '';
            var statusText = '';
            switch(orderStatus) {
                case 'pending': statusClass = 'status-pending'; statusText = 'Pending'; break;
                case 'processing': statusClass = 'status-processing'; statusText = 'In Progress'; break;
                case 'completed': statusClass = 'status-completed'; statusText = 'Completed'; break;
                case 'cancelled': statusClass = 'status-cancelled'; statusText = 'Rejected'; break;
                default: statusClass = 'status-pending'; statusText = orderStatus;
            }
            
            var actions = '';
            if (orderStatus === 'pending') {
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-success btn-action-sm accept-task" data-id="' + item.id + '"><i class="fa fa-check"></i> Accept</button>' +
                    '<button class="btn btn-danger btn-action-sm reject-task" data-id="' + item.id + '"><i class="fa fa-times"></i> Reject</button>' +
                    '<button class="btn btn-info btn-action-sm view-details" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button>' +
                    '<button class="btn btn-primary btn-action-sm" onclick="openChat(' + item.order_id + ', \'' + orderNumber + '\')"><i class="fa fa-comments"></i> Chat</button>' +
                    '</div>';
            } else if (orderStatus === 'processing') {
                // Check if modification was requested
                if (item.modification_requested === 'yes') {
                    actions = '<div class="action-buttons">' +
                        '<button class="btn btn-warning btn-action-sm resubmit-live-url" data-id="' + item.id + '"><i class="fa fa-edit"></i> Resubmit</button>' +
                        '<button class="btn btn-info btn-action-sm view-details" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button>' +
                        '<button class="btn btn-primary btn-action-sm" onclick="openChat(' + item.order_id + ', \'' + orderNumber + '\')"><i class="fa fa-comments"></i> Chat</button>' +
                        '</div>';
                } else {
                    actions = '<div class="action-buttons">' +
                        '<button class="btn btn-primary btn-action-sm submit-live-url" data-id="' + item.id + '"><i class="fa fa-link"></i> Submit Live URL</button>' +
                        '<button class="btn btn-info btn-action-sm view-details" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button>' +
                        '<button class="btn btn-primary btn-action-sm" onclick="openChat(' + item.order_id + ', \'' + orderNumber + '\')"><i class="fa fa-comments"></i> Chat</button>' +
                        '</div>';
                }
            } else if (orderStatus === 'completed') {
                var liveUrlBtn = '';
                if (item.live_url && item.live_url !== '') {
                    liveUrlBtn = '<a href="' + item.live_url + '" target="_blank" class="btn btn-secondary btn-action-sm"><i class="fa fa-external-link"></i> Live</a>';
                }
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-info btn-action-sm view-details" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button>' +
                    '<button class="btn btn-primary btn-action-sm" onclick="openChat(' + item.order_id + ', \'' + orderNumber + '\')"><i class="fa fa-comments"></i> Chat</button>' +
                    liveUrlBtn +
                    '</div>';
            } else if (orderStatus === 'cancelled') {
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-info btn-action-sm view-details" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button>' +
                    '<button class="btn btn-primary btn-action-sm" onclick="openChat(' + item.order_id + ', \'' + orderNumber + '\')"><i class="fa fa-comments"></i> Chat</button>' +
                    '</div>';
            }
            
            html += '<tr>' +
                '<td><strong>#' + escapeHtml(orderNumber) + '</strong></td>' +
                '<td><div class="fw-semibold">' + escapeHtml(item.site_name) + '</div><div class="text-muted small"><a href="' + escapeHtml(item.site_url) + '" target="_blank">' + escapeHtml(item.site_url) + '</a></div></td>' +
                '<td class="text-primary">€' + basePrice.toFixed(2) + '</td>' +
                '<td>' + (additionalPrice > 0 ? '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> ' + escapeHtml(sensitiveType || 'Extra') + ' (+€' + additionalPrice.toFixed(2) + ')</span>' : '<span class="text-muted">—</span>') + '</td>' +
                '<td class="fw-semibold total-price" style="color: #10b981;">€' + totalPrice.toFixed(2) + '</td>' +
                '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>' +
                '<td class="link-cell">' + (item.content_link ? '<a href="' + item.content_link + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa fa-external-link me-1"></i> View</a>' : '<span class="text-muted">Not submitted</span>') + '</td>' +
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
        
        var statusClass = '';
        var statusText = '';
        switch(orderStatus) {
            case 'pending': statusClass = 'status-pending'; statusText = 'Pending'; break;
            case 'processing': statusClass = 'status-processing'; statusText = 'In Progress'; break;
            case 'completed': statusClass = 'status-completed'; statusText = 'Completed'; break;
            case 'cancelled': statusClass = 'status-cancelled'; statusText = 'Rejected'; break;
            default: statusClass = 'status-pending'; statusText = orderStatus;
        }
        
        var autoApproveInfo = '';
        if (item.live_url_submitted_at) {
            if (item.modification_requested === 'yes') {
                autoApproveInfo = '<div class="alert alert-warning mt-3"><i class="fa fa-exclamation-triangle"></i> <strong>Auto-approve paused:</strong> The advertiser has requested modifications. Please update your content and resubmit.</div>';
            } else if (!item.auto_approve_triggered) {
                const submittedAt = new Date(item.live_url_submitted_at);
                const now = new Date();
                const hoursPassed = (now - submittedAt) / (1000 * 60 * 60);
                const hoursRemaining = 48 - hoursPassed;
                if (hoursRemaining > 0) {
                    autoApproveInfo = '<div class="alert alert-info mt-3"><i class="fa fa-info-circle"></i> <strong>Auto-approve active:</strong> The order will be automatically approved in ' + Math.ceil(hoursRemaining) + ' hours if the advertiser takes no action.</div>';
                } else {
                    autoApproveInfo = '<div class="alert alert-success mt-3"><i class="fa fa-check-circle"></i> <strong>Ready for approval:</strong> The order is ready to be approved. The advertiser has 48 hours to review.</div>';
                }
            }
        }
        
        var liveUrlHtml = item.live_url 
            ? '<p class="mb-1"><strong>Live URL:</strong></p><p class="mb-2"><a href="' + escapeHtml(item.live_url) + '" target="_blank" class="text-success">' + escapeHtml(item.live_url) + ' <i class="fa fa-external-link fa-xs"></i></a></p>'
            : '<p class="mb-2 text-muted">Live URL not submitted yet</p>';
        
        if (item.modification_requested === 'yes') {
            liveUrlHtml = '<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> Modification requested. Please update your content and resubmit.</div>' + liveUrlHtml;
        }
        
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
                    '<p class="mb-1"><strong>Base Price:</strong> €' + basePrice.toFixed(2) + '</p>' +
                    (additionalPrice > 0 ? '<p class="mb-1"><strong>Sensitive Price:</strong> <span class="text-warning">+ €' + additionalPrice.toFixed(2) + ' (' + escapeHtml(sensitiveType) + ')</span></p>' : '') +
                    '<p class="mb-1"><strong>Total Amount:</strong> <span class="fw-bold text-primary fs-5">€' + totalPrice.toFixed(2) + '</span></p>' +
                '</div>' +
            '</div>' +
        '</div>' +
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
                    '<p class="mb-1"><strong>Content Link:</strong></p>' +
                    '<p class="mb-2"><a href="' + escapeHtml(item.content_link) + '" target="_blank" class="text-primary text-break">' + escapeHtml(item.content_link) + ' <i class="fa fa-external-link fa-xs"></i></a></p>' +
                    liveUrlHtml +
                '</div>' +
            '</div>' +
        '</div>';
        
        $('#detailsContent').html(html);
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
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
});
</script>

@endsection