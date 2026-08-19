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
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4 publisher-task-stats">
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <h6 class="text-muted mb-1 small">Total</h6>
                    <h3 class="mb-0" id="statTotalOrders">0</h3>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <h6 class="text-muted mb-1 small">Pending</h6>
                    <h3 class="mb-0" id="statPendingOrders">0</h3>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <h6 class="text-muted mb-1 small">In progress</h6>
                    <h3 class="mb-0" id="statProcessingOrders">0</h3>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <h6 class="text-muted mb-1 small">In review</h6>
                    <h3 class="mb-0" id="statReviewOrders">0</h3>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <h6 class="text-muted mb-1 small">Earnings</h6>
                    <h3 class="mb-0" id="statTotalEarnings" style="color: #10b981;">€0</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="filterForm">
                <div class="row g-3 align-items-end publisher-task-filters">
                    <!-- Search -->
                    <div class="col-12 col-lg">
                        <label class="form-label fw-semibold small text-muted mb-1" for="searchInput">Search</label>
                        <div class="slb-search-wrap">
                            <input type="search"
                                   id="searchInput"
                                   class="form-control form-control-sm"
                                   placeholder="Order #, Site name…"
                                   title="Results update as you type"
                                   autocomplete="off"
                                   enterkeyhint="search"
                                   aria-describedby="tasksSearchStatus">
                            <button type="button" id="tasksSearchClear" class="btn btn-sm btn-link slb-search-clear d-none" aria-label="Clear search">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div id="tasksSearchStatus" class="form-text slb-search-status" role="status" aria-live="polite"></div>
                    </div>

                    <!-- Order Status Filter -->
                    <div class="col-12 col-sm-6 col-lg-auto publisher-task-filters__status">
                        <label class="form-label fw-semibold small text-muted mb-1">Order Status</label>
                        <input type="hidden" id="needsActionFilter" value="">
                        <select id="statusFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="pending">New — needs accept</option>
                            <option value="scheduled">Scheduled — waiting for date</option>
                            <option value="processing">In progress — publish content</option>
                            <option value="review">Waiting for advertiser</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Rejected</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="col-12 col-sm-6 col-lg-auto">
                        <label class="form-label fw-semibold small text-muted mb-1">Date Range</label>
                        <div class="d-flex gap-2 publisher-task-filters__dates">
                            <input type="date" id="dateFrom" class="form-control form-control-sm" placeholder="From">
                            <input type="date" id="dateTo" class="form-control form-control-sm" placeholder="To">
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="col-12 col-lg-auto">
                        <div class="d-flex gap-2 publisher-task-filters__actions">
                            <button type="submit" class="btn btn-sm btn-primary px-3">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> Filter
                            </button>
                            <button type="button" id="resetFiltersBtn" class="btn btn-sm btn-cta-secondary px-3">
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
                            <th class="text-nowrap">Base</th>
                            <th class="text-nowrap">Sensitive</th>
                            <th class="text-nowrap">You earn</th>
                            <th>Order Status</th>
                            <th>Content Link</th>
                            <th class="publisher-task-actions-col">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tasksTableBody">
                        <tr>
                            <td colspan="8" class="text-center py-5">
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
                <h5 class="modal-title">Cancel / Reject Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="reject_order_item_id">
                <div class="ui-callout ui-callout--attention mb-3">
                    <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                    <div class="ui-callout__body">
                        <span id="rejectModalBaseHint">The advertiser is refunded to their wallet. You can cancel after accepting if you cannot fulfill the order.</span>
                        <span id="rejectModalMultiHint" class="d-none mt-1 fw-semibold">This cancels the <em>whole order</em> (all sites in the cart), not only this row.</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="reject_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea id="reject_reason" class="form-control" rows="4" placeholder="Please explain why you cannot fulfill this order..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-danger" id="confirmReject">Cancel order &amp; refund</button>
            </div>
        </div>
    </div>
</div>

<!-- Request revised article Modal -->
<div class="modal fade" id="contentRevisionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="contentRevisionModalTitle">Request revised article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="content_revision_order_item_id">
                <input type="hidden" id="content_revision_is_update" value="0">
                <p class="small text-muted" id="contentRevisionModalHint">Ask the advertiser to upload or link an updated article. Live URL submit stays blocked until they send it. One request at a time — you can update the reason while waiting.</p>
                <div class="mb-3">
                    <label for="content_revision_reason" class="form-label">What needs to change <span class="text-danger">*</span></label>
                    <textarea id="content_revision_reason" class="form-control" rows="4" placeholder="e.g. Please fix the brand name spelling and shorten the intro…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="confirmContentRevision">Send request</button>
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
                <div id="completeSocialFields" class="d-none">
                    <p class="small fw-semibold mb-1">Social post URLs <span class="text-muted fw-normal">(optional)</span></p>
                    <p class="small text-muted mb-2" id="completeSocialHint">
                        This order includes a social share. You can paste post links now or add them later after you publish. Live URL alone is enough to submit.
                    </p>
                    <div class="mb-2 d-none" data-social-channel="facebook">
                        <label for="social_facebook" class="form-label small mb-1">Facebook post URL</label>
                        <input type="url" id="social_facebook" class="form-control form-control-sm social-post-url" data-channel="facebook" placeholder="https://facebook.com/…">
                    </div>
                    <div class="mb-2 d-none" data-social-channel="instagram">
                        <label for="social_instagram" class="form-label small mb-1">Instagram post URL</label>
                        <input type="url" id="social_instagram" class="form-control form-control-sm social-post-url" data-channel="instagram" placeholder="https://instagram.com/…">
                    </div>
                    <div class="mb-2 d-none" data-social-channel="x">
                        <label for="social_x" class="form-label small mb-1">X post URL</label>
                        <input type="url" id="social_x" class="form-control form-control-sm social-post-url" data-channel="x" placeholder="https://x.com/…">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmComplete">Submit Live URL</button>
            </div>
        </div>
    </div>
</div>

<!-- Add / update social post URLs after live URL is already submitted -->
<div class="modal fade" id="socialPostsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="socialPostsModalTitle">Social post URLs</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="social_posts_order_item_id">
                <p class="small text-muted mb-3" id="socialPostsHint">
                    Optional. Paste links to the social posts for the channels included on this order. You can leave any field blank.
                </p>
                <div id="socialPostsFields">
                    <div class="mb-2 d-none" data-social-channel="facebook">
                        <label for="social_posts_facebook" class="form-label small mb-1">Facebook post URL</label>
                        <input type="url" id="social_posts_facebook" class="form-control form-control-sm social-post-url" data-channel="facebook" placeholder="https://facebook.com/…">
                    </div>
                    <div class="mb-2 d-none" data-social-channel="instagram">
                        <label for="social_posts_instagram" class="form-label small mb-1">Instagram post URL</label>
                        <input type="url" id="social_posts_instagram" class="form-control form-control-sm social-post-url" data-channel="instagram" placeholder="https://instagram.com/…">
                    </div>
                    <div class="mb-2 d-none" data-social-channel="x">
                        <label for="social_posts_x" class="form-label small mb-1">X post URL</label>
                        <input type="url" id="social_posts_x" class="form-control form-control-sm social-post-url" data-channel="x" placeholder="https://x.com/…">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmSocialPosts">Save social links</button>
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

<link href="{{ asset('assets/css/publisher-tasks.css') }}?v={{ @filemtime(public_path('assets/css/publisher-tasks.css')) ?: '1' }}" rel="stylesheet">

<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('assets/js/article-preview-tools.js') }}?v={{ @filemtime(public_path('assets/js/article-preview-tools.js')) ?: '1' }}"></script>

<script>
let currentPage = 1;
let currentChatOrderId = null;
let refreshInterval = null;

// Get the base URL dynamically
const baseUrl = window.location.origin;

/** Bootstrap 5 modal helpers (jQuery .modal() is unavailable without the BS4 plugin). */
function showTasksModal(id) {
    var el = document.getElementById(id);
    if (!el || !window.bootstrap || !bootstrap.Modal) return;
    bootstrap.Modal.getOrCreateInstance(el).show();
}
function hideTasksModal(id) {
    var el = document.getElementById(id);
    if (!el || !window.bootstrap || !bootstrap.Modal) return;
    var inst = bootstrap.Modal.getInstance(el) || bootstrap.Modal.getOrCreateInstance(el);
    inst.hide();
}

const AUTO_APPROVE_HOURS = {{ (int) \App\Models\OrderItem::autoApproveHours() }};
const AUTO_APPROVE_DAYS = {{ (int) max(1, (int) ceil(\App\Models\OrderItem::autoApproveHours() / 24)) }};

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
        $('#needsActionFilter').val('1');
        syncTasksFiltersToUrl(1);
        loadTasks(1);
        $('html, body').animate({ scrollTop: $('#tasksTableBody').offset().top - 120 }, 'fast');
    });
    
    // Auto-refresh every 30 seconds
    refreshInterval = setInterval(function() {
        loadTasks(currentPage, true); // silent refresh
        loadStatistics();
    }, 30000);

    (function initTasksLiveSearch() {
        var input = document.getElementById('searchInput');
        if (!input || typeof window.SlbLiveSearch === 'undefined') return;
        window.SlbLiveSearch.init(input, {
            mode: 'event',
            statusEl: document.getElementById('tasksSearchStatus'),
            clearBtn: document.getElementById('tasksSearchClear'),
            onSearch: function () {
                currentPage = 1;
                syncTasksFiltersToUrl(1);
                loadTasks(1);
            },
        });
    })();

    $('#resetFiltersBtn').on('click', function() {
        $('#searchInput').val('');
        $('#statusFilter').val('');
        $('#needsActionFilter').val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        currentPage = 1;
        syncTasksFiltersToUrl(1);
        loadTasks(1);
    });

    $('#statusFilter').on('change', function() {
        // Manual status pick clears the needs-action mode.
        $('#needsActionFilter').val('');
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        if ($('#statusFilter').val()) {
            $('#needsActionFilter').val('');
        }
        syncTasksFiltersToUrl(1);
        loadTasks(1);
    });

    function hydrateTasksFiltersFromUrl() {
        const params = new URLSearchParams(window.location.search);
        if (params.has('search')) $('#searchInput').val(params.get('search') || '');
        if (params.has('status')) $('#statusFilter').val(params.get('status') || '');
        if (params.get('needs_action') === '1') {
            $('#needsActionFilter').val('1');
            $('#statusFilter').val('');
        } else {
            $('#needsActionFilter').val('');
        }
        if (params.has('date_from')) $('#dateFrom').val(params.get('date_from') || '');
        if (params.has('date_to')) $('#dateTo').val(params.get('date_to') || '');
        const page = parseInt(params.get('page') || '1', 10);
        currentPage = Number.isFinite(page) && page > 0 ? page : 1;
    }

    function syncTasksFiltersToUrl(page) {
        const url = new URL(window.location.href);
        const map = {
            search: $('#searchInput').val() || '',
            status: $('#needsActionFilter').val() === '1' ? '' : ($('#statusFilter').val() || ''),
            needs_action: $('#needsActionFilter').val() === '1' ? '1' : '',
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

    $(document).on('click', '.open-task-chat', function() {
        var orderId = $(this).data('order-id');
        var orderNumber = $(this).data('order-number') || '';
        if (orderId) openChat(orderId, orderNumber);
    });

    $(document).on('click', '.accept-task', function() {
        $('#accept_order_item_id').val($(this).data('id'));
        showTasksModal('acceptModal');
    });

    $(document).on('click', '.reject-task', function() {
        var $btn = $(this);
        $('#reject_order_item_id').val($btn.data('id'));
        $('#reject_reason').val('');
        var itemsCount = parseInt($btn.data('order-items') || '1', 10);
        var $multi = $('#rejectModalMultiHint');
        if (itemsCount > 1) {
            $multi.removeClass('d-none');
        } else {
            $multi.addClass('d-none');
        }
        showTasksModal('rejectModal');
    });

    $(document).on('click', '.request-content-revision', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var isUpdate = String($btn.data('update') || '') === '1' || $btn.hasClass('is-update');
        var existingReason = (window._contentRevisionReasons && window._contentRevisionReasons[String(id)]) || '';
        $('#content_revision_order_item_id').val(id);
        $('#content_revision_is_update').val(isUpdate ? '1' : '0');
        $('#content_revision_reason').val(existingReason || '');
        $('#contentRevisionModalTitle').text(isUpdate ? 'Update revision reason' : 'Request revised article');
        $('#confirmContentRevision').text(isUpdate ? 'Update reason' : 'Send request');
        $('#contentRevisionModalHint').text(isUpdate
            ? 'Update what the advertiser should change. Live URL submit stays blocked until they send a revised article.'
            : 'Ask the advertiser to upload or link an updated article. Live URL submit stays blocked until they send it. One request at a time — you can update the reason while waiting.');
        showTasksModal('contentRevisionModal');
    });

    function parseSocialChannelsAttr($raw, fallback) {
        var channels = [];
        try {
            channels = JSON.parse(raw || '[]');
        } catch (e) {
            channels = [];
        }
        if (!Array.isArray(channels) || !channels.length) {
            channels = Array.isArray(fallback) ? fallback : [];
        }
        return channels.filter(Boolean);
    }

    function taskItemById(id) {
        var rows = window._publisherTaskItems || [];
        var needle = String(id);
        for (var i = 0; i < rows.length; i++) {
            if (String(rows[i].id) === needle) return rows[i];
        }
        return null;
    }

    $(document).on('click', '.submit-live-url', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        $('#complete_order_item_id').val(id);
        $('#live_url').val('');
        $('#completeModal .social-post-url').val('');
        var item = taskItemById(id) || {};
        var channels = parseSocialChannelsAttr($btn.attr('data-social-channels'), item.social_channels);
        var $wrap = $('#completeSocialFields');
        $wrap.find('[data-social-channel]').addClass('d-none');
        if (channels.length) {
            channels.forEach(function (ch) {
                $wrap.find('[data-social-channel="' + ch + '"]').removeClass('d-none');
            });
            $('#completeSocialHint').text(
                'This order includes a social share on '
                + channels.map(socialChannelLabel).join(', ')
                + '. Paste post links now or add them later — live URL alone is enough to submit.'
            );
            $wrap.removeClass('d-none');
        } else {
            $wrap.addClass('d-none');
        }
        showTasksModal('completeModal');
    });

    $(document).on('click', '.update-social-posts', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        $('#social_posts_order_item_id').val(id);
        $('#socialPostsModal .social-post-url').val('');
        var item = taskItemById(id) || {};
        var channels = parseSocialChannelsAttr($btn.attr('data-social-channels'), item.social_channels);
        var existing = {};
        try {
            existing = JSON.parse($btn.attr('data-social-post-urls') || '{}') || {};
        } catch (e) {
            existing = {};
        }
        if (typeof existing !== 'object' || existing === null || !Object.keys(existing).length) {
            existing = (item.social_post_urls && typeof item.social_post_urls === 'object') ? item.social_post_urls : {};
        }

        var $wrap = $('#socialPostsFields');
        $wrap.find('[data-social-channel]').addClass('d-none');
        channels.forEach(function (ch) {
            var $row = $wrap.find('[data-social-channel="' + ch + '"]');
            $row.removeClass('d-none');
            var val = existing[ch] || '';
            $row.find('.social-post-url').val(val);
        });
        var hasAny = Object.keys(existing).some(function (k) { return !!(existing[k]); });
        $('#socialPostsModalTitle').text(hasAny ? 'Update social post URLs' : 'Add social post URLs');
        $('#socialPostsHint').text(
            'Optional. Channels on this order: '
            + channels.map(socialChannelLabel).join(', ')
            + '. Leave any field blank if you have not posted there yet.'
        );
        showTasksModal('socialPostsModal');
    });

    function collectSocialPostUrls($root) {
        var urls = {};
        ($root || $(document)).find('.social-post-url:visible').each(function () {
            var ch = $(this).data('channel');
            var val = ($(this).val() || '').trim();
            if (ch && val) urls[ch] = val;
        });
        return urls;
    }

    function socialChannelLabel(ch) {
        if (ch === 'x') return 'X';
        if (!ch) return '';
        return String(ch).charAt(0).toUpperCase() + String(ch).slice(1);
    }

    function formatPlacementExtrasHtml(item) {
        var parts = [];
        var homepageDays = item.homepage_days != null && item.homepage_days !== '' ? parseInt(item.homepage_days, 10) : null;
        var homepageFee = parseFloat(item.homepage_price || 0) || 0;
        if (homepageDays) {
            parts.push('<p class="mb-1"><strong>Homepage placement:</strong></p><p class="mb-2">'
                + homepageDays + ' day' + (homepageDays === 1 ? '' : 's')
                + (homepageFee > 0 ? ' (+€' + homepageFee.toFixed(2) + ')' : ' (Free)')
                + '</p>');
        }
        var channels = Array.isArray(item.social_channels) ? item.social_channels : [];
        var postUrls = item.social_post_urls && typeof item.social_post_urls === 'object' ? item.social_post_urls : {};
        if (channels.length) {
            parts.push('<p class="mb-1"><strong>Social promotion:</strong></p><p class="mb-2">'
                + channels.map(socialChannelLabel).join(', ')
                + ' <span class="text-muted">(included)</span></p>');
            var linkBits = channels.map(function (ch) {
                var url = postUrls[ch];
                if (!url) {
                    return '<li class="small text-muted">' + socialChannelLabel(ch) + ': not submitted yet</li>';
                }
                return '<li class="small"><strong>' + socialChannelLabel(ch) + ':</strong> <a href="'
                    + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" class="live-url">'
                    + escapeHtml(url) + '</a></li>';
            }).join('');
            parts.push('<ul class="mb-2 ps-3">' + linkBits + '</ul>');
        }
        return parts.join('');
    }

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

        const websiteName = escapeHtml(details.website_name || '—');
        const websiteUrl = details.website_url
            ? '<a class="chat-od__url" href="' + escapeHtml(details.website_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(details.website_url) + '</a>'
            : '';

        const metaItems = [];
        metaItems.push({ label: 'Ordered', value: formatChatDate(details.order_date, false) });
        metaItems.push({ label: 'Started', value: formatChatDate(details.started_at, true) });

        if (details.df_links !== null && details.df_links !== undefined) {
            const dfLabel = details.df_links === 1 ? '1 DF link' : (details.df_links + ' DF links');
            const linkType = details.link_type ? (' · ' + details.link_type) : '';
            metaItems.push({ label: 'Links', value: dfLabel + linkType });
        } else if (details.link_type) {
            metaItems.push({ label: 'Link type', value: details.link_type });
        }

        if (details.sensitive_type) {
            metaItems.push({ label: 'Sensitive', value: details.sensitive_type });
        }

        const metaHtml = metaItems.map(function (item) {
            return '<div class="chat-od__meta-item">'
                + '<dt>' + escapeHtml(item.label) + '</dt>'
                + '<dd>' + escapeHtml(String(item.value)) + '</dd>'
                + '</div>';
        }).join('');

        const statusLabel = escapeHtml(details.status_label || details.status || '—');
        const nextAction = escapeHtml(details.next_action || '');
        const statusBlock = '<div class="chat-od__status">'
            + '<strong>' + statusLabel + '</strong>'
            + (nextAction ? '<span class="chat-od__next">' + nextAction + '</span>' : '')
            + '</div>';

        let revisionBlock = '';
        if (details.can_resubmit || details.modification_requested === 'yes') {
            const reason = details.completion_notes
                ? '<div class="small mt-1"><strong>Reason:</strong> ' + escapeHtml(details.completion_notes) + '</div>'
                : '';
            const itemId = details.order_item_id || '';
            const currentUrl = details.live_url
                ? '<div class="small mt-1 text-muted">Current URL: <a href="' + escapeHtml(details.live_url) + '" target="_blank" rel="noopener noreferrer" class="live-url">' + escapeHtml(details.live_url) + '</a></div>'
                : '';
            // Editing in place is the normal case, so reporting the fix is the
            // primary action and re-pasting a URL is the exception.
            const fixedBtn = details.can_resubmit && itemId && details.live_url
                ? '<button type="button" class="btn btn-success btn-sm chat-revision-fixed-btn mt-2" data-item-id="' + escapeHtml(String(itemId)) + '">'
                    + '<i class="fa fa-check me-1" aria-hidden="true"></i>I have fixed it'
                    + '</button>'
                    + '<div class="form-text">Sends the article back to the advertiser to approve.</div>'
                : '';

            revisionBlock = '<div class="chat-resubmit-panel mt-2">'
                + '<div class="chat-resubmit-panel__title"><i class="fa fa-exclamation-circle me-1" aria-hidden="true"></i>Changes requested</div>'
                + '<div class="chat-resubmit-panel__guidance">Make the corrections on the live article, then tell the advertiser you are done.</div>'
                + reason
                + currentUrl
                + fixedBtn
                + (details.can_resubmit && itemId
                    ? '<form class="chat-resubmit-form mt-3" data-item-id="' + escapeHtml(String(itemId)) + '">'
                        + '<label class="form-label small mb-1" for="chatResubmitUrl-' + escapeHtml(String(itemId)) + '">Published at a different URL?</label>'
                        + '<div class="input-group input-group-sm">'
                        + '<input type="url" class="form-control" id="chatResubmitUrl-' + escapeHtml(String(itemId)) + '" name="live_url" placeholder="https://example.com/your-updated-article" required autocomplete="url">'
                        + '<button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Resubmit URL</button>'
                        + '</div>'
                        + '<div class="form-text">Only if the address changed — add the URL here again so the advertiser can review.</div>'
                        + (Array.isArray(details.social_channels) && details.social_channels.length
                            ? '<div class="mt-2">'
                                + '<div class="small fw-semibold mb-1">Update social post URLs (optional)</div>'
                                + details.social_channels.map(function (ch) {
                                    var existing = (details.social_post_urls && details.social_post_urls[ch]) ? details.social_post_urls[ch] : '';
                                    return '<div class="mb-1">'
                                        + '<label class="form-label small mb-0" for="chatSocial-' + escapeHtml(String(itemId)) + '-' + escapeHtml(ch) + '">'
                                        + socialChannelLabel(ch) + '</label>'
                                        + '<input type="url" class="form-control form-control-sm social-post-url" data-channel="'
                                        + escapeHtml(ch) + '" id="chatSocial-' + escapeHtml(String(itemId)) + '-' + escapeHtml(ch)
                                        + '" value="' + escapeHtml(existing) + '" placeholder="https://…">'
                                        + '</div>';
                                }).join('')
                                + '</div>'
                            : '')
                        + '</form>'
                    : '')
                + '</div>';
        }

        el.innerHTML = '<div class="chat-od">'
            + '<div class="chat-od__site">'
            + '<span class="chat-detail-primary">' + websiteName + '</span>'
            + websiteUrl
            + '</div>'
            + '<dl class="chat-od__meta">' + metaHtml + '</dl>'
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
                return;
            }
            // Off-page deep link: resolve item id via locate endpoint.
            $.getJSON(baseUrl + '/publisher/orders/locate', { order_id: orderId })
                .done(function (res) {
                    if (res && res.success && res.order_item_id) {
                        if (!window._publisherTasksByOrderId) window._publisherTasksByOrderId = {};
                        window._publisherTasksByOrderId[String(orderId)] = res.order_item_id;
                        viewOrderDetails(res.order_item_id);
                    }
                });
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
                    $('#statProcessingOrders').text(response.data.accepted_orders || 0);
                    $('#statReviewOrders').text(response.data.review_orders || 0);
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
                $('#confirmAccept').addClass('is-loading').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire('Success!', response.message, 'success');
                    hideTasksModal('acceptModal');
                    loadTasks();
                    loadStatistics();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to accept order', 'error');
                }
            },
            error: function(xhr) {
                slbHandleHttpError(xhr, { fallback: 'Failed to accept order' });
            },
            complete: function() {
                $('#confirmAccept').removeClass('is-loading').prop('disabled', false);
            }
        });
    });

    $('#confirmReject').on('click', function() {
        var id = $('#reject_order_item_id').val();
        var reason = ($('#reject_reason').val() || '').trim();

        if (reason.length < 10) {
            Swal.fire('Warning!', 'Please provide a reason (at least 10 characters).', 'warning');
            return;
        }

        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/reject',
            method: 'POST',
            data: { reason: reason, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmReject').addClass('is-loading').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire('Cancelled', response.message, 'success');
                    hideTasksModal('rejectModal');
                    loadTasks();
                    loadStatistics();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to cancel order', 'error');
                }
            },
            error: function(xhr) {
                slbHandleHttpError(xhr, { fallback: 'Failed to cancel order' });
            },
            complete: function() {
                $('#confirmReject').removeClass('is-loading').prop('disabled', false);
            }
        });
    });

    $('#confirmContentRevision').on('click', function() {
        var id = $('#content_revision_order_item_id').val();
        var reason = ($('#content_revision_reason').val() || '').trim();
        if (reason.length < 10) {
            Swal.fire('Warning!', 'Please explain what needs to change (at least 10 characters).', 'warning');
            return;
        }
        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/request-content-revision',
            method: 'POST',
            data: { reason: reason, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmContentRevision').addClass('is-loading').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire('Request sent', response.message, 'success');
                    hideTasksModal('contentRevisionModal');
                    loadTasks();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to send request', 'error');
                }
            },
            error: function(xhr) {
                slbHandleHttpError(xhr, { fallback: 'Failed to send revision request' });
            },
            complete: function() {
                $('#confirmContentRevision').removeClass('is-loading').prop('disabled', false);
            }
        });
    });

    $('#confirmComplete').on('click', function() {
        var id = $('#complete_order_item_id').val();
        var liveUrl = $('#live_url').val();
        var socialPostUrls = collectSocialPostUrls($('#completeModal'));
        
        if (!liveUrl) {
            Swal.fire('Warning!', 'Please enter the live URL', 'warning');
            return;
        }
        
        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/complete',
            method: 'POST',
            data: { live_url: liveUrl, social_post_urls: socialPostUrls, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmComplete').addClass('is-loading').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        html: response.message + '<br><br><small>The advertiser now has ' + AUTO_APPROVE_DAYS + ' day(s) to review your submission. If no action is taken, the order will be approved.</small>',
                        icon: 'success'
                    });
                    hideTasksModal('completeModal');
                    loadTasks();
                    loadStatistics();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to submit live URL', 'error');
                }
            },
            error: function(xhr) {
                slbHandleHttpError(xhr, { fallback: 'Failed to submit live URL' });
            },
            complete: function() {
                $('#confirmComplete').removeClass('is-loading').prop('disabled', false);
            }
        });
    });

    $('#confirmSocialPosts').on('click', function() {
        var id = $('#social_posts_order_item_id').val();
        var socialPostUrls = collectSocialPostUrls($('#socialPostsModal'));
        if (!id) {
            Swal.fire('Error!', 'Missing order item for social links.', 'error');
            return;
        }

        $.ajax({
            url: baseUrl + '/publisher/orders/' + id + '/social-posts',
            method: 'POST',
            data: { social_post_urls: socialPostUrls, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $('#confirmSocialPosts').addClass('is-loading').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire('Saved!', response.message || 'Social post links saved.', 'success');
                    hideTasksModal('socialPostsModal');
                    loadTasks();
                } else {
                    Swal.fire('Error!', response.message || 'Failed to save social links', 'error');
                }
            },
            error: function(xhr) {
                slbHandleHttpError(xhr, { fallback: 'Failed to save social links' });
            },
            complete: function() {
                $('#confirmSocialPosts').removeClass('is-loading').prop('disabled', false);
            }
        });
    });

    $(document).on('submit', '.chat-resubmit-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var id = $form.data('item-id');
        var $input = $form.find('input[name="live_url"]');
        var liveUrl = ($input.val() || '').trim();
        var socialPostUrls = collectSocialPostUrls($form);
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
            data: { live_url: liveUrl, social_post_urls: socialPostUrls, _token: '{{ csrf_token() }}' },
            dataType: 'json',
            beforeSend: function() {
                $btn.addClass('is-loading').prop('disabled', true);
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
                slbHandleHttpError(xhr, { fallback: 'Failed to resubmit live URL' });
            },
            complete: function() {
                $btn.removeClass('is-loading').prop('disabled', false);
            }
        });
    });

    $(document).on('click', '.chat-revision-fixed-btn', function() {
        var $btn = $(this);
        var id = $btn.data('item-id');

        if (!id) {
            Swal.fire('Error!', 'Missing order item for this change request.', 'error');
            return;
        }

        Swal.fire({
            title: 'Send back for review?',
            text: 'We will tell the advertiser the requested changes are done, so they can check the article and approve it.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, I have fixed it',
            cancelButtonText: 'Not yet',
        }).then(function(result) {
            if (!result.isConfirmed) return;

            $.ajax({
                url: baseUrl + '/publisher/orders/' + id + '/revision-fixed',
                method: 'POST',
                data: { _token: '{{ csrf_token() }}' },
                dataType: 'json',
                beforeSend: function() {
                    // is-loading keeps the label in the layout and overlays the
                    // spinner. Replacing the markup collapsed this button to icon
                    // width and lost the label it was rendered with — it appears
                    // both in the chat panel and, more compactly, on the task row.
                    $btn.addClass('is-loading').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ title: 'Sent for review', html: response.message, icon: 'success' });
                        loadTasks();
                        loadStatistics();
                        if (orderChat && typeof orderChat.load === 'function' && orderChat.currentOrderId) {
                            orderChat.load(false);
                        }
                        refreshNeedsActionBanner();
                        if (typeof window.refreshHeaderAlerts === 'function') window.refreshHeaderAlerts();
                    } else {
                        Swal.fire('Error!', response.message || 'Could not report the fix', 'error');
                    }
                },
                error: function(xhr) {
                    slbHandleHttpError(xhr, { fallback: 'Could not report the fix' });
                },
                complete: function() {
                    $btn.removeClass('is-loading').prop('disabled', false);
                }
            });
        });
    });

    function loadTasks(page = 1, silent = false) {
        currentPage = page;
        if (!silent && typeof window.syncTasksFiltersToUrl === 'function') {
            window.syncTasksFiltersToUrl(page);
        }
        if (!silent) {
            $('#tasksTableBody').html('<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Loading tasks...</p></td></tr>');
        }
        
        $.ajax({
            url: baseUrl + '/publisher/orders/data',
            method: 'GET',
            data: {
                page: page,
                search: $('#searchInput').val(),
                status: $('#needsActionFilter').val() === '1' ? '' : $('#statusFilter').val(),
                needs_action: $('#needsActionFilter').val() === '1' ? 1 : 0,
                date_from: $('#dateFrom').val(),
                date_to: $('#dateTo').val()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderTasksTable(response.data);
                    if (response.pagination) {
                        renderPagination(response.pagination);
                        var p = response.pagination;
                        var from = p.from || 0;
                        var to = p.to || 0;
                        var total = p.total || 0;
                        $('#resultsCount').text(total ? ('Showing ' + from + '–' + to + ' of ' + total) : 'No tasks');
                    } else {
                        $('#resultsCount').text('');
                    }
                    refreshNeedsActionBanner();
                } else if (!silent) {
                    $('#tasksTableBody').html('<tr><td colspan="8" class="text-center text-danger py-5">' + (response.message || 'Failed to load tasks') + '</td></tr>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                if (!silent) {
                    $('#tasksTableBody').html(
                        '<tr><td colspan="8" class="text-center py-5">' +
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
        window._publisherTaskItems = Array.isArray(orderItems) ? orderItems : [];
        if (!orderItems || orderItems.length === 0) {
            $('#tasksTableBody').html(
                '<tr><td colspan="8" class="text-center py-5">' +
                '<div class="mx-auto" style="max-width:420px">' +
                '<div class="mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,#1a585e)" aria-hidden="true"><i class="fa-solid fa-inbox"></i></div>' +
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
            var homepagePrice = parseFloat(item.homepage_price || 0);
            var homepageDays = item.homepage_days !== null && item.homepage_days !== undefined
                ? parseInt(item.homepage_days, 10) : null;
            var basePrice = item.publisher_base !== undefined && item.publisher_base !== null
                ? parseFloat(item.publisher_base)
                : Math.max(0, parseFloat(item.price) - additionalPrice - homepagePrice);
            var totalPrice = item.you_earn !== undefined && item.you_earn !== null
                ? parseFloat(item.you_earn)
                : parseFloat(item.price);
            var sensitiveType = item.sensitive_type || null;
            var homepageLine = homepagePrice > 0
                ? '<div class="text-muted small">Homepage'
                    + (homepageDays ? ' · ' + homepageDays + 'd' : '')
                    + ' +€' + homepagePrice.toFixed(2) + '</div>'
                : '';
            
            var hasLiveUrl = !!(item.live_url && item.live_url !== '');
            var modificationRequested = item.modification_requested === 'yes';
            var contentRevisionRequested = item.content_revision_requested === 'yes';
            var orderHeldForContentRevision = !!(item.order && item.order.has_open_content_revision);
            // True advertiser review only — not a sibling live URL parked in processing
            // while another line waits on a revised article.
            var awaitingAdvertiser = orderStatus === 'review'
                || (orderStatus === 'processing' && hasLiveUrl && !modificationRequested && !orderHeldForContentRevision);
            var statusMeta = getPublisherStatusMeta(
                orderStatus,
                hasLiveUrl,
                modificationRequested,
                item.live_url_submitted_at,
                contentRevisionRequested,
                orderHeldForContentRevision,
                !!(item.order && item.order.is_awaiting_scheduled_release),
                item.order && item.order.scheduled_label ? item.order.scheduled_label : null
            );
            var unreadBadge = item.unread_chat > 0
                ? '<span class="chat-unread-dot pulse-badge is-pulsing">' + item.unread_chat + '</span>'
                : '';
            var chatBtn = '<button type="button" class="btn btn-primary btn-action-sm open-task-chat" data-order-id="' + item.order_id + '" data-order-number="' + escapeHtml(orderNumber) + '" aria-label="Open chat"><i class="fa fa-comments"></i> Chat' + unreadBadge + '</button>';
            var viewBtn = '<button type="button" class="btn btn-outline-secondary btn-action-sm view-details" data-id="' + item.id + '" aria-label="View order details"><i class="fa fa-eye"></i> View</button>';
            var liveBtn = hasLiveUrl
                ? '<a href="' + escapeHtml(item.live_url) + '" target="_blank" class="btn btn-live-url btn-action-sm"><i class="fa fa-external-link"></i> Live</a>'
                : '';
            var socialChannels = Array.isArray(item.social_channels) ? item.social_channels : [];
            var socialPostUrls = (item.social_post_urls && typeof item.social_post_urls === 'object') ? item.social_post_urls : {};
            var hasSocialPosts = socialChannels.some(function (ch) { return !!(socialPostUrls && socialPostUrls[ch]); });
            var socialBtn = (hasLiveUrl && socialChannels.length && !contentRevisionRequested
                && (orderStatus === 'processing' || orderStatus === 'review'))
                ? '<button type="button" class="btn btn-outline-primary btn-action-sm update-social-posts" data-id="' + item.id
                    + '" data-social-channels="' + escapeHtml(JSON.stringify(socialChannels))
                    + '" data-social-post-urls="' + escapeHtml(JSON.stringify(socialPostUrls))
                    + '"><i class="fa fa-share-nodes"></i> '
                    + (hasSocialPosts ? 'Update social' : 'Add social') + '</button>'
                : '';
            var orderItemsCount = parseInt(item.order_items_count || 1, 10);
            var cancelBtn = '<button class="btn btn-outline-danger btn-action-sm reject-task" data-id="' + item.id + '" data-order-items="' + orderItemsCount + '" aria-label="Cancel order"><i class="fa fa-times"></i> Cancel</button>';

            var actions = '';
            var awaitingSchedule = !!(item.order && item.order.is_awaiting_scheduled_release);
            if (orderStatus === 'pending' && awaitingSchedule) {
                actions = '<div class="action-buttons">' +
                    viewBtn + chatBtn +
                    '</div>';
            } else if (orderStatus === 'pending') {
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-success btn-action-sm accept-task" data-id="' + item.id + '" aria-label="Accept order"><i class="fa fa-check"></i> Accept</button>' +
                    '<button class="btn btn-danger btn-action-sm reject-task" data-id="' + item.id + '" data-order-items="' + orderItemsCount + '" aria-label="Reject order"><i class="fa fa-times"></i> Reject</button>' +
                    viewBtn + chatBtn +
                    '</div>';
            } else if (contentRevisionRequested && orderStatus === 'processing') {
                if (!window._contentRevisionReasons) window._contentRevisionReasons = {};
                window._contentRevisionReasons[String(item.id)] = item.content_revision_reason || '';
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-outline-warning btn-action-sm request-content-revision is-update" data-update="1" data-id="' + item.id + '"><i class="fa fa-pencil"></i> Update reason</button>' +
                    cancelBtn +
                    viewBtn + chatBtn +
                    '</div>';
            } else if (contentRevisionRequested && orderStatus === 'review') {
                // Cancel/reject is only allowed while processing — update reason still helps.
                if (!window._contentRevisionReasons) window._contentRevisionReasons = {};
                window._contentRevisionReasons[String(item.id)] = item.content_revision_reason || '';
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-outline-warning btn-action-sm request-content-revision is-update" data-update="1" data-id="' + item.id + '"><i class="fa fa-pencil"></i> Update reason</button>' +
                    viewBtn + chatBtn +
                    '</div>';
            } else if (modificationRequested && (orderStatus === 'processing' || orderStatus === 'review')) {
                // Handing the article back was only reachable from inside the chat
                // panel, so revisions sat in processing forever and the advertiser
                // never got an Approve button. The same delegated handler drives
                // this, it just needs to be findable from the task list.
                var fixedBtn = '<button class="btn btn-success btn-action-sm chat-revision-fixed-btn" data-item-id="' + item.id + '">' +
                    '<i class="fa fa-check"></i> I have fixed it</button>';
                actions = '<div class="action-buttons">' +
                    fixedBtn + socialBtn + viewBtn + chatBtn + liveBtn +
                    '</div>';
            } else if (orderStatus === 'processing' && hasLiveUrl && orderHeldForContentRevision) {
                // Live URL saved, but order stays in processing until the sibling
                // revised article arrives — keep Cancel available.
                actions = '<div class="action-buttons">' +
                    cancelBtn + socialBtn + viewBtn + chatBtn + liveBtn +
                    '</div>';
            } else if (awaitingAdvertiser) {
                actions = '<div class="action-buttons">' +
                    socialBtn + viewBtn + chatBtn + liveBtn +
                    '</div>';
            } else if (orderStatus === 'processing') {
                actions = '<div class="action-buttons">' +
                    '<button class="btn btn-primary btn-action-sm submit-live-url" data-id="' + item.id + '" data-social-channels="' + escapeHtml(JSON.stringify(socialChannels)) + '"><i class="fa fa-link"></i> Submit Live URL</button>' +
                    '<button class="btn btn-outline-warning btn-action-sm request-content-revision" data-id="' + item.id + '"><i class="fa fa-file-text"></i> Request revised article</button>' +
                    cancelBtn +
                    viewBtn + chatBtn +
                    '</div>';
            } else {
                actions = '<div class="action-buttons">' + viewBtn + chatBtn + liveBtn + '</div>';
            }
            
            html += '<tr class="tasks-row">' +
                '<td data-label="Order ID"><strong>#' + escapeHtml(orderNumber) + '</strong></td>' +
                '<td data-label="Site"><div class="fw-semibold">' + escapeHtml(item.site_name) + '</div><div class="text-muted small"><a href="' + escapeHtml(item.site_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.site_url) + '</a></div></td>' +
                '<td data-label="Base" class="text-primary text-nowrap">€' + basePrice.toFixed(2) + '</td>' +
                '<td data-label="Sensitive">' + (additionalPrice > 0 ? '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> ' + escapeHtml(sensitiveType || 'Extra') + ' (+€' + additionalPrice.toFixed(2) + ')</span>' : '<span class="text-muted">—</span>') + '</td>' +
                '<td data-label="You earn" class="fw-semibold total-price text-nowrap" style="color: #10b981;">€' + totalPrice.toFixed(2) + homepageLine + '</td>' +
                '<td data-label="Status"><span class="status-badge ' + statusMeta.statusClass + '">' + statusMeta.statusText + '</span><div class="next-step-hint">' + statusMeta.nextStep + '</div></td>' +
                '<td class="link-cell" data-label="Content">' + ((item.content_download_url || item.content_link) ? '<a href="' + escapeHtml(item.content_download_url || item.content_link) + '" class="btn btn-sm btn-outline-primary" rel="noopener noreferrer"><i class="fa fa-download me-1"></i> ' + (item.content_original_name ? 'Document' : 'View') + '</a>' : '<span class="text-muted">Not submitted</span>') + '</td>' +
                '<td data-label="Action">' + actions + '</td>' +
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
                    showTasksModal('detailsModal');
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
                slbHandleHttpError(xhr, { fallback: 'Failed to load order details' });
            }
        });
    }
    
    function renderDetailsModal(item) {
        var order = item.order;
        var orderStatus = order ? order.status : 'pending';
        var paymentStatus = order ? order.payment_status : 'pending';
        var additionalPrice = parseFloat(item.additional_price || 0);
        var homepagePrice = parseFloat(item.homepage_price || 0) || 0;
        var totalPrice = parseFloat(item.price);
        var basePrice = Math.max(0, totalPrice - additionalPrice - homepagePrice);
        var sensitiveType = item.sensitive_type || null;
        
        var paymentStatusHtml = paymentStatus === 'paid' 
            ? '<span class="badge bg-success">Paid</span>' 
            : '<span class="badge bg-warning text-dark">Pending</span>';
        
        var hasLiveUrl = !!(item.live_url && item.live_url !== '');
        var modificationRequested = item.modification_requested === 'yes';
        var contentRevisionRequested = item.content_revision_requested === 'yes';
        var orderHeldForContentRevision = !!(order && order.has_open_content_revision);
        var statusMeta = getPublisherStatusMeta(
            orderStatus,
            hasLiveUrl,
            modificationRequested,
            item.live_url_submitted_at,
            contentRevisionRequested,
            orderHeldForContentRevision,
            !!(order && order.is_awaiting_scheduled_release),
            order && order.scheduled_label ? order.scheduled_label : null
        );
        var statusClass = statusMeta.statusClass;
        var statusText = statusMeta.statusText;
        
        var autoApproveInfo = '';
        if (
            orderStatus === 'review'
            && item.live_url_submitted_at
            && !modificationRequested
            && !contentRevisionRequested
            && !orderHeldForContentRevision
            && !item.auto_approve_triggered
        ) {
            const hoursRemaining = getAutoApproveHoursRemaining(item.live_url_submitted_at);
            if (hoursRemaining > 0) {
                autoApproveInfo = '<div class="ui-callout ui-callout--info mt-3"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span><div class="ui-callout__body"><strong>Waiting for advertiser:</strong> They can approve or request changes. ' + escapeHtml(formatAutoApproveCountdown(hoursRemaining)) + '.</div></div>';
            } else {
                autoApproveInfo = '<div class="ui-callout ui-callout--success mt-3"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span><div class="ui-callout__body"><strong>Ready for approval:</strong> The advertiser review window has ended — this should auto-approve soon.</div></div>';
            }
        } else if (orderStatus === 'processing' && hasLiveUrl && orderHeldForContentRevision && !contentRevisionRequested) {
            autoApproveInfo = '<div class="ui-callout ui-callout--info mt-3"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span><div class="ui-callout__body"><strong>Live URL saved:</strong> Advertiser review starts after the revised article is sent for the other placement on this order.</div></div>';
        }
        
        var liveUrlHtml = item.live_url 
            ? '<p class="mb-1"><strong>Live URL:</strong></p><p class="mb-2"><a href="' + escapeHtml(item.live_url) + '" target="_blank" class="live-url">' + escapeHtml(item.live_url) + ' <i class="fa fa-external-link fa-xs"></i></a></p>'
            : '<p class="mb-2 text-muted">Live URL not submitted yet</p>';
        
        if (contentRevisionRequested) {
            var revReason = item.content_revision_reason ? '<div class="small mt-1">Reason: ' + escapeHtml(item.content_revision_reason) + '</div>' : '';
            liveUrlHtml = '<div class="ui-callout ui-callout--attention mb-2"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></span><div class="ui-callout__body">Waiting for the advertiser to send a revised article.' + revReason + '</div></div>' + liveUrlHtml;
        }

        if (modificationRequested) {
            var reason = item.completion_notes ? '<div class="small mt-1">Reason: ' + escapeHtml(item.completion_notes) + '</div>' : '';
            liveUrlHtml = '<div class="ui-callout ui-callout--attention mb-2"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span><div class="ui-callout__body">The advertiser asked for changes. Make the corrections, then open <strong>Chat</strong> to paste and resubmit the live URL.' + reason + '</div></div>' + liveUrlHtml;
        }

        var timelineHtml = buildPublisherTimeline(orderStatus, hasLiveUrl, modificationRequested, orderHeldForContentRevision);
        
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
                    (homepagePrice > 0 || (item.homepage_days != null && parseInt(item.homepage_days, 10) > 0)
                        ? '<p class="mb-1"><strong>Homepage:</strong> ' + (parseInt(item.homepage_days, 10) || '') + ' day(s)'
                            + (homepagePrice > 0 ? ' <span class="text-muted">(+€' + homepagePrice.toFixed(2) + ')</span>' : ' <span class="text-success">(Free)</span>')
                            + '</p>'
                        : '') +
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
                    formatPlacementExtrasHtml(item) +
                '</div>' +
                '<div class="col-md-6">' +
                    '<p class="mb-1"><strong>Price Breakdown:</strong></p>' +
                    '<p class="mb-1"><small>Base Price: €' + basePrice.toFixed(2) + '</small></p>' +
                    (additionalPrice > 0 ? '<p class="mb-1"><small class="text-warning">+ ' + escapeHtml(sensitiveType) + ': €' + additionalPrice.toFixed(2) + '</small></p>' : '') +
                    (homepagePrice > 0 ? '<p class="mb-1"><small>+ Homepage: €' + homepagePrice.toFixed(2) + '</small></p>' : '') +
                    '<p class="mb-2"><strong class="text-primary">Total: €' + totalPrice.toFixed(2) + '</strong></p>' +
                    '<p class="mb-1"><strong>Uploaded Document:</strong></p>' +
                    '<p class="mb-2">' + ((item.content_download_url || item.content_link) ? '<a href="' + escapeHtml(item.content_download_url || item.content_link) + '" class="text-primary" download><i class="fa fa-download me-1"></i>' + escapeHtml(item.content_original_name || 'Download article') + '</a><br><small class="text-muted">Download only — do not enable macros. Word files can run code on your computer.</small>' : '—') + '</p>' +
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

    function getPublisherStatusMeta(orderStatus, hasLiveUrl, modificationRequested, liveUrlSubmittedAt, contentRevisionRequested, orderHeldForContentRevision, isAwaitingScheduled, scheduledLabel) {
        if (isAwaitingScheduled) {
            return {
                statusClass: 'status-processing',
                statusText: 'Scheduled',
                nextStep: scheduledLabel ? ('Publishes on ' + scheduledLabel) : 'Publishes on the scheduled date'
            };
        }
        if (orderStatus === 'pending') {
            return { statusClass: 'status-pending', statusText: 'New order', nextStep: 'Accept or reject this order' };
        }
        if (contentRevisionRequested) {
            return { statusClass: 'status-pending', statusText: 'Waiting for revised article', nextStep: 'Advertiser must upload or link an updated article' };
        }
        if (modificationRequested) {
            return { statusClass: 'status-pending', statusText: 'Changes requested', nextStep: 'Make corrections, then use “I have fixed it” (or Chat) to send it back' };
        }
        if (orderStatus === 'processing' && hasLiveUrl && orderHeldForContentRevision) {
            return {
                statusClass: 'status-processing',
                statusText: 'In progress',
                nextStep: 'Live URL saved — waiting for revised article on another placement before advertiser review'
            };
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
            return { statusClass: 'status-cancelled', statusText: 'Cancelled', nextStep: 'No further action needed' };
        }
        return { statusClass: 'status-pending', statusText: orderStatus, nextStep: '' };
    }

    function buildPublisherTimeline(orderStatus, hasLiveUrl, modificationRequested, orderHeldForContentRevision) {
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
        else if (orderStatus === 'processing' && (!hasLiveUrl || orderHeldForContentRevision)) activeIndex = 1;
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
                    $('#needsActionText').text(res.needs_action + ' task' + (res.needs_action === 1 ? '' : 's') + ' need you (accept, publish, or use “I have fixed it” after a change request).');
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