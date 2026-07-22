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
                    <label class="form-label fw-semibold small text-muted">Search</label>
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Order #, reference, user…">
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
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Date range</label>
                    <div class="input-group input-group-sm">
                        <input type="date" id="dateFrom" class="form-control">
                        <input type="date" id="dateTo" class="form-control">
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <button type="reset" id="resetFiltersBtn" class="btn btn-outline-secondary btn-sm">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Advertiser</th>
                            <th>Site / Publisher</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Total</th>
                            <th>Created</th>
                            <th></th>
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
    const money = (n) => '€' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let currentPage = 1;

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
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function loadOrders(page) {
        currentPage = page || 1;
        const params = new URLSearchParams({
            page: String(currentPage),
            per_page: '20',
        });
        const search = document.getElementById('searchInput').value.trim();
        const status = document.getElementById('statusFilter').value;
        const paymentStatus = document.getElementById('paymentStatusFilter').value;
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo = document.getElementById('dateTo').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (paymentStatus) params.set('payment_status', paymentStatus);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);

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
                if (!json.data.length) {
                    body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No orders found</td></tr>';
                    document.getElementById('ordersPaginationMeta').textContent = '';
                    document.getElementById('ordersPagination').innerHTML = '';
                    return;
                }

                body.innerHTML = json.data.map(order => {
                    const adv = order.advertiser
                        ? '<div class="fw-semibold">' + escapeHtml(order.advertiser.name) + '</div><div class="small text-muted">' + escapeHtml(order.advertiser.email) + '</div>'
                        : '—';
                    const site = '<div class="fw-semibold">' + escapeHtml(order.site_name || '—') + '</div>'
                        + '<div class="small text-muted">' + escapeHtml(order.publisher_name || '') + '</div>';
                    return '<tr>'
                        + '<td><strong>#' + escapeHtml(order.order_number) + '</strong></td>'
                        + '<td>' + adv + '</td>'
                        + '<td>' + site + '</td>'
                        + '<td>' + statusBadge(order.status) + '</td>'
                        + '<td>' + paymentBadge(order.payment_status) + '</td>'
                        + '<td class="fw-semibold">' + money(order.total_amount) + '</td>'
                        + '<td class="small text-muted">' + escapeHtml(order.created_at_human || '') + '</td>'
                        + '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="' + escapeHtml(order.url) + '">Open</a></td>'
                        + '</tr>';
                }).join('');

                const p = json.pagination;
                document.getElementById('ordersPaginationMeta').textContent =
                    'Showing page ' + p.current_page + ' of ' + p.last_page + ' · ' + p.total + ' orders';

                let pagHtml = '<nav><ul class="pagination pagination-sm mb-0">';
                pagHtml += '<li class="page-item' + (p.current_page <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (p.current_page - 1) + '">Prev</a></li>';
                pagHtml += '<li class="page-item' + (p.current_page >= p.last_page ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (p.current_page + 1) + '">Next</a></li>';
                pagHtml += '</ul></nav>';
                document.getElementById('ordersPagination').innerHTML = pagHtml;
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
        setTimeout(() => loadOrders(1), 0);
    });
    document.getElementById('ordersPagination').addEventListener('click', function (e) {
        const link = e.target.closest('[data-page]');
        if (!link) return;
        e.preventDefault();
        const page = parseInt(link.getAttribute('data-page'), 10);
        if (page >= 1) loadOrders(page);
    });

    loadOrders(1);
})();
</script>
@endsection
