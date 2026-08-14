@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Orders',
        'subtitle' => 'Inspect marketplace orders, parties, chat, and activity. Payment changes stay on Order Payments.',
        'actionUrl' => route('admin.payments'),
        'actionLabel' => 'Order Payments',
        'actionIcon' => 'fa-money-bill',
    ])

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form id="orderFilterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="searchInput">Search</label>
                    <div class="slb-search-wrap">
                        <input type="search"
                               id="searchInput"
                               class="form-control form-control-sm"
                               placeholder="Order #, reference, user, site, publisher…"
                               title="Results update as you type"
                               autocomplete="off"
                               enterkeyhint="search"
                               aria-describedby="adminOrdersSearchStatus">
                        <button type="button" id="adminOrdersSearchClear" class="btn btn-sm btn-link slb-search-clear d-none" aria-label="Clear search">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div id="adminOrdersSearchStatus" class="visually-hidden" role="status" aria-live="polite"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Order status</label>
                    <select id="statusFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="review">Review</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="scheduled">Scheduled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Payment status</label>
                    <select id="paymentStatusFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="unpaid">Unpaid (ops queue)</option>
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Disputes</label>
                    <select id="disputeFilter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="open">Open</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Date range</label>
                    <div class="input-group input-group-sm">
                        <input type="date" id="dateFrom" class="form-control">
                        <input type="date" id="dateTo" class="form-control">
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <button type="button" id="resetFiltersBtn" class="btn btn-outline-secondary btn-sm">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm admin-table-fit">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="admin-id-col">Order</th>
                            <th>Advertiser</th>
                            <th>Site / Publisher</th>
                            <th class="admin-status-col">Status</th>
                            <th class="admin-narrow-col">Payment</th>
                            <th class="admin-narrow-col">Total</th>
                            <th class="admin-narrow-col">Created</th>
                            <th class="admin-actions-col"></th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <div class="small text-muted" id="ordersPaginationMeta"></div>
            <div id="ordersPagination"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const ordersDataUrl = @json(route('admin.orders.data'));
    const ordersIndexUrl = @json(route('admin.orders.index'));
    const money = (n) => '€' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let currentPage = 1;

    function readFilters() {
        return {
            search: document.getElementById('searchInput').value.trim(),
            status: document.getElementById('statusFilter').value,
            payment_status: document.getElementById('paymentStatusFilter').value,
            dispute: document.getElementById('disputeFilter').value,
            date_from: document.getElementById('dateFrom').value,
            date_to: document.getElementById('dateTo').value,
        };
    }

    function syncOrdersUrl(page) {
        const params = new URLSearchParams();
        const filters = readFilters();
        Object.keys(filters).forEach((key) => {
            if (filters[key]) params.set(key, filters[key]);
        });
        if (page > 1) params.set('page', String(page));
        const next = params.toString() ? (ordersIndexUrl + '?' + params.toString()) : ordersIndexUrl;
        if (history.replaceState) {
            history.replaceState({}, '', next);
        }
    }

    function statusBadge(status) {
        const map = {
            pending: 'secondary',
            processing: 'primary',
            review: 'info',
            completed: 'success',
            cancelled: 'danger',
            scheduled: 'warning',
        };
        const cls = map[status] || 'secondary';
        return '<span class="badge bg-' + cls + '">' + (status || '—') + '</span>';
    }

    function paymentBadge(status) {
        const map = { pending: 'warning', paid: 'success', failed: 'danger', refunded: 'secondary' };
        const cls = map[status] || 'secondary';
        return '<span class="badge text-bg-' + cls + '">' + (status || '—') + '</span>';
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

    function isHttpUrl(value) {
        return /^https?:\/\//i.test(String(value || ''));
    }

    function textLink(url, label, extraClass, attrs) {
        const text = escapeHtml(label || '—');
        const cls = extraClass ? (' class="' + extraClass + '"') : '';
        if (!url) {
            return extraClass ? ('<span' + cls + '>' + text + '</span>') : text;
        }
        return '<a href="' + escapeHtml(url) + '"' + cls + (attrs || '') + '>' + text + '</a>';
    }

    function signalBadges(order) {
        const chips = [];
        if (order.has_open_dispute) {
            chips.push(textLink(order.url + '#order-disputes', 'Dispute', 'badge text-bg-danger text-decoration-none'));
        }
        if (order.has_live_url) {
            chips.push(isHttpUrl(order.live_url)
                ? textLink(order.live_url, 'Live', 'badge text-bg-success text-decoration-none', ' target="_blank" rel="noopener"')
                : '<span class="badge text-bg-success">Live</span>');
        }
        if (order.is_scheduled) {
            const when = order.scheduled_publish_at_human
                ? ' title="' + escapeHtml(order.scheduled_publish_at_human) + '"'
                : '';
            chips.push(textLink(order.url + '#order-schedule', 'Scheduled', 'badge text-bg-warning text-dark text-decoration-none', when));
        }
        if (!chips.length) return '';
        return '<div class="d-flex flex-wrap gap-1 mt-1">' + chips.join('') + '</div>';
    }

    function loadOrders(page) {
        currentPage = page || 1;
        const filters = readFilters();
        const params = new URLSearchParams({
            page: String(currentPage),
            per_page: '20',
        });
        Object.keys(filters).forEach((key) => {
            if (filters[key]) params.set(key, filters[key]);
        });
        syncOrdersUrl(currentPage);

        const body = document.getElementById('ordersTableBody');
        body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr>';

        fetch(ordersDataUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then(r => r.json())
            .then(json => {
                if (!json.success) {
                    body.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Failed to load orders</td></tr>';
                    return;
                }
                const pagination = json.pagination || {};
                const lastPage = Number(pagination.last_page) || 1;
                const requested = Number(pagination.current_page) || currentPage;
                if (requested > lastPage && lastPage >= 1 && currentPage !== lastPage) {
                    loadOrders(lastPage);
                    return;
                }
                if (!json.data.length) {
                    body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No orders found</td></tr>';
                    document.getElementById('ordersPaginationMeta').textContent = '';
                    document.getElementById('ordersPagination').innerHTML = '';
                    return;
                }

                body.innerHTML = json.data.map(order => {
                    const adv = order.advertiser
                        ? '<div class="fw-semibold">' + textLink(order.advertiser.url, order.advertiser.name, 'link-dark') + '</div>'
                            + '<div class="small text-muted slb-text-break">' + escapeHtml(order.advertiser.email) + '</div>'
                        : '—';
                    const publisherName = (order.publisher && order.publisher.name) || order.publisher_name || '';
                    const publisherUrl = order.publisher && order.publisher.url;
                    const site = textLink(order.site_admin_url, order.site_name || '—', 'fw-semibold slb-text-break link-dark')
                        + (publisherName
                            ? '<div>' + textLink(publisherUrl, publisherName, 'small text-muted slb-text-break') + '</div>'
                            : '');
                    return '<tr>'
                        + '<td><strong class="admin-id-clamp" title="' + escapeHtml(order.order_number) + '">#'
                            + escapeHtml(order.order_number) + '</strong>' + signalBadges(order) + '</td>'
                        + '<td>' + adv + '</td>'
                        + '<td>' + site + '</td>'
                        + '<td>' + statusBadge(order.status) + '</td>'
                        + '<td>' + paymentBadge(order.payment_status) + '</td>'
                        + '<td class="fw-semibold">' + money(order.total_amount) + '</td>'
                        + '<td class="small text-muted">' + escapeHtml(order.created_at_human || '') + '</td>'
                        + '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="' + escapeHtml(order.url) + '">Open</a></td>'
                        + '</tr>';
                }).join('');

                renderAdminPagination(json.pagination, {
                    links: '#ordersPagination',
                    info: '#ordersPaginationMeta',
                    label: 'orders',
                    onNavigate: loadOrders,
                });
            })
            .catch(() => {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Failed to load orders</td></tr>';
            });
    }

    document.getElementById('orderFilterForm').addEventListener('submit', function (e) {
        e.preventDefault();
        loadOrders(1);
    });
    document.getElementById('resetFiltersBtn').addEventListener('click', function () {
        document.getElementById('orderFilterForm').reset();
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('paymentStatusFilter').value = '';
        document.getElementById('disputeFilter').value = '';
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        loadOrders(1);
    });
    {{-- Page clicks are handled by renderAdminPagination's delegated listener. --}}

    if (typeof window.SlbLiveSearch !== 'undefined') {
        window.SlbLiveSearch.init(document.getElementById('searchInput'), {
            mode: 'event',
            statusEl: document.getElementById('adminOrdersSearchStatus'),
            clearBtn: document.getElementById('adminOrdersSearchClear'),
            onSearch: function () { loadOrders(1); },
        });
    }

    const boot = new URLSearchParams(window.location.search);
    if (boot.get('status')) document.getElementById('statusFilter').value = boot.get('status');
    if (boot.get('payment_status')) document.getElementById('paymentStatusFilter').value = boot.get('payment_status');
    if (boot.get('dispute')) document.getElementById('disputeFilter').value = boot.get('dispute');
    if (boot.get('search')) document.getElementById('searchInput').value = boot.get('search');
    if (boot.get('date_from')) document.getElementById('dateFrom').value = boot.get('date_from');
    if (boot.get('date_to')) document.getElementById('dateTo').value = boot.get('date_to');
    const bootPage = parseInt(boot.get('page') || '1', 10);

    loadOrders(Number.isFinite(bootPage) && bootPage > 0 ? bootPage : 1);
})();
</script>
@endsection
