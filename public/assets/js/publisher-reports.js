/**
 * Publisher Reports — lifetime KPIs, orders, and withdrawals.
 */
(function () {
    'use strict';

    const root = document.querySelector('.publisher-reports-container');
    if (!root) return;

    const urls = {
        stats: root.dataset.statsUrl,
        orders: root.dataset.ordersUrl,
        orderDetailsTemplate: root.dataset.orderDetailsTemplate,
        withdrawals: root.dataset.withdrawalsUrl,
        withdraw: root.dataset.withdrawUrl,
    };

    function orderDetailsUrl(id) {
        return String(urls.orderDetailsTemplate || '').replace('__ID__', encodeURIComponent(id));
    }

    let ordersPage = 1;
    let withdrawalsPage = 1;

    function money(n) {
        const v = parseFloat(n);
        return (Number.isFinite(v) ? v : 0).toFixed(2);
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

    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        if (Number.isNaN(date.getTime())) return 'N/A';
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function resultsCountLabel(pagination) {
        if (!pagination || !pagination.total) {
            return 'No entries';
        }
        return 'Showing ' + pagination.from + ' to ' + pagination.to + ' of ' + pagination.total + ' entries';
    }

    function paymentStatusBadge(status) {
        const s = String(status || 'unknown').toLowerCase();
        if (s === 'paid') return '<span class="badge bg-success">Paid</span>';
        if (s === 'pending') return '<span class="badge bg-warning text-dark">Pending</span>';
        if (s === 'refunded') return '<span class="badge bg-secondary">Refunded</span>';
        return '<span class="badge bg-secondary">' + escapeHtml(s) + '</span>';
    }

    function orderStatusMeta(orderStatus, clawed) {
        if (clawed) {
            return { cls: 'status-clawed', text: 'Clawed back' };
        }
        switch (orderStatus) {
            case 'pending': return { cls: 'status-pending', text: 'Pending' };
            case 'processing': return { cls: 'status-processing', text: 'Processing' };
            case 'review': return { cls: 'status-processing', text: 'In Review' };
            case 'scheduled': return { cls: 'status-processing', text: 'Scheduled' };
            case 'completed': return { cls: 'status-completed', text: 'Completed' };
            case 'cancelled': return { cls: 'status-cancelled', text: 'Cancelled' };
            default: return { cls: 'status-pending', text: orderStatus || 'Unknown' };
        }
    }

    function payoutColumnHeading(status) {
        if (status === 'completed') return 'You earned';
        if (status === 'cancelled' || status === 'all') return 'Payout';
        return 'You earn';
    }

    function dateColumnHeading(status) {
        return status === 'completed' ? 'Completed' : 'Date';
    }

    function payoutCell(item) {
        const state = item.payout_state || '';
        const label = item.payout_label || '';
        if (state === 'none' || !label) {
            return '<span class="text-muted">—</span>';
        }
        const amount = '€' + money(item.price);
        if (state === 'you_earned') {
            return '<span class="earned-amount">' + escapeHtml(label) + ' ' + amount + '</span>';
        }
        return '<span class="fw-semibold">' + escapeHtml(label) + ' ' + amount + '</span>';
    }

    function homepageCell(homepagePrice, homepageDays) {
        if (!(homepagePrice > 0)) {
            return '<span class="text-muted">—</span>';
        }
        const daysBit = homepageDays ? ' · ' + homepageDays + 'd' : '';
        return '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> +€' + money(homepagePrice) + daysBit + '</span>';
    }

    function linkOrDash(url, label) {
        if (!url) return '<span class="text-muted">—</span>';
        const safe = escapeHtml(url);
        const text = escapeHtml(label || url);
        return '<a href="' + safe + '" target="_blank" rel="noopener" class="text-primary text-break">' + text + ' <i class="fa fa-external-link fa-xs"></i></a>';
    }

    function loadStatistics() {
        $.ajax({
            url: urls.stats,
            method: 'GET',
            dataType: 'json',
            success: function (response) {
                if (!response.success) return;
                const d = response.data || {};
                $('#totalEarned').html('<span style="color:#10b981;">+ €' + money(d.total_earned) + '</span>');
                $('#completedOrders').text(d.completed_orders || 0);
                $('#openOrders').text(d.open_orders != null ? d.open_orders : (d.pending_orders || 0));
                $('#totalWithdrawn').html('<span style="color:#ef4444;">- €' + money(d.total_withdrawn) + '</span>');
                $('#availableToWithdraw').text('€' + money(d.available_to_withdraw));
                $('#pendingPayout').text('€' + money(d.pending_payout));
                const fees = parseFloat(d.total_withdrawal_fees || 0);
                $('#withdrawnFeesHint').text(fees > 0 ? ('Fees paid: €' + money(fees) + ' · net received') : 'Net received');
                const withdrawHref = urls.withdraw || '#';
                const debt = parseFloat(d.debt_balance || 0);
                const minWithdrawal = parseFloat(d.min_withdrawal_amount || 20);
                const available = parseFloat(d.available_to_withdraw || 0);
                if (debt > 0) {
                    $('#availableNote').html(
                        'Outstanding clawback debt €' + money(debt)
                        + ' — withdrawals are blocked. <a href="' + withdrawHref + '">Withdraw</a>'
                    );
                } else if (available < minWithdrawal) {
                    $('#availableNote').html(
                        'Minimum payout €' + money(minWithdrawal)
                        + '. <a href="' + withdrawHref + '">Withdraw</a>'
                    );
                } else {
                    $('#availableNote').html('<a href="' + withdrawHref + '">Go to Withdraw</a>');
                }
            },
            error: function (xhr) {
                if (typeof slbHandleHttpError === 'function') {
                    slbHandleHttpError(xhr, { fallback: 'Could not load report statistics' });
                }
            }
        });
    }

    function ordersFilterParams(page) {
        return {
            page: page || 1,
            status: $('#ordersStatus').val() || 'completed',
            date_from: $('#ordersDateFrom').val() || '',
            date_to: $('#ordersDateTo').val() || '',
        };
    }

    function withdrawalsFilterParams(page) {
        return {
            page: page || 1,
            status: $('#withdrawalsStatus').val() || 'completed',
            date_from: $('#withdrawalsDateFrom').val() || '',
            date_to: $('#withdrawalsDateTo').val() || '',
        };
    }

    function queryInt(params, key, fallback) {
        const n = parseInt(params.get(key) || '', 10);
        return n > 0 ? n : fallback;
    }

    function normalizeDatePair($from, $to) {
        const from = $from.val() || '';
        const to = $to.val() || '';
        if (from && to && from > to) {
            $from.val(to);
            $to.val(from);
        }
    }

    function currentReportsTab() {
        return $('#withdrawals-tab').hasClass('active') ? 'withdrawals' : 'orders';
    }

    function replaceReportsUrl() {
        const o = ordersFilterParams(ordersPage);
        const w = withdrawalsFilterParams(withdrawalsPage);
        const qs = new URLSearchParams();
        qs.set('tab', currentReportsTab());
        if (o.status && o.status !== 'completed') qs.set('o_status', o.status);
        if (o.date_from) qs.set('o_from', o.date_from);
        if (o.date_to) qs.set('o_to', o.date_to);
        if (o.page > 1) qs.set('o_page', String(o.page));
        if (w.status && w.status !== 'completed') qs.set('w_status', w.status);
        if (w.date_from) qs.set('w_from', w.date_from);
        if (w.date_to) qs.set('w_to', w.date_to);
        if (w.page > 1) qs.set('w_page', String(w.page));
        const next = window.location.pathname + (qs.toString() ? '?' + qs.toString() : '');
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', next);
        }
    }

    function applyQueryToControls() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('o_status')) $('#ordersStatus').val(params.get('o_status'));
        if (params.get('o_from')) $('#ordersDateFrom').val(params.get('o_from'));
        if (params.get('o_to')) $('#ordersDateTo').val(params.get('o_to'));
        if (params.get('w_status')) $('#withdrawalsStatus').val(params.get('w_status'));
        if (params.get('w_from')) $('#withdrawalsDateFrom').val(params.get('w_from'));
        if (params.get('w_to')) $('#withdrawalsDateTo').val(params.get('w_to'));
        ordersPage = queryInt(params, 'o_page', 1);
        withdrawalsPage = queryInt(params, 'w_page', 1);
        normalizeDatePair($('#ordersDateFrom'), $('#ordersDateTo'));
        normalizeDatePair($('#withdrawalsDateFrom'), $('#withdrawalsDateTo'));
    }

    function loadOrders(page) {
        page = page || 1;
        ordersPage = page;
        const params = ordersFilterParams(page);
        const statusLabel = $('#ordersStatus option:selected').text();
        $('#ordersTabTitle').text(params.status === 'all' ? 'Orders' : (statusLabel + ' Orders'));
        $('#ordersPayoutHeading').text(payoutColumnHeading(params.status));
        $('#ordersDateHeading').text(dateColumnHeading(params.status));

        $('#ordersTableBody').html(
            '<tr><td colspan="9" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted mb-0">Loading orders...</p></td></tr>'
        );

        $.ajax({
            url: urls.orders,
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#ordersTableBody').html('<tr><td colspan="9" class="text-center text-danger py-5">' + escapeHtml(response.message || 'Failed to load orders') + '</td></tr>');
                    return;
                }
                renderOrdersTable(response.data, params.status);
                renderPagination('#ordersPaginationNav', response.pagination, loadOrders);
                $('#ordersResultsCount').text(resultsCountLabel(response.pagination));
                replaceReportsUrl();
            },
            error: function (xhr) {
                $('#ordersTableBody').html('<tr><td colspan="9" class="text-center text-danger py-5">Error loading orders. Please refresh the page.</td></tr>');
                if (typeof slbHandleHttpError === 'function') {
                    slbHandleHttpError(xhr, { fallback: 'Could not load orders' });
                }
            }
        });
    }

    function renderOrdersTable(orderItems, status) {
        if (!orderItems || orderItems.length === 0) {
            $('#ordersTableBody').html(
                '<tr><td colspan="9" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2 mb-0">No orders match this filter</p><p class="text-muted small mb-0">Try another status or date range.</p></td></tr>'
            );
            return;
        }

        let html = '';
        for (let i = 0; i < orderItems.length; i++) {
            const item = orderItems[i];
            const orderNumber = item.order ? item.order.order_number : 'N/A';
            const rowDate = (status === 'completed' || status === 'all')
                ? (item.completed_at || item.created_at)
                : item.created_at;
            const orderStatus = item.order && item.order.is_awaiting_scheduled_release
                ? 'scheduled'
                : (item.order ? item.order.status : 'pending');
            const additionalPrice = parseFloat(item.additional_price || 0);
            const homepagePrice = parseFloat(item.homepage_price || 0);
            const homepageDays = item.homepage_days != null && item.homepage_days !== ''
                ? parseInt(item.homepage_days, 10)
                : null;
            const basePrice = parseFloat(item.publisher_base_price != null
                ? item.publisher_base_price
                : (item.price - additionalPrice - homepagePrice));
            const sensitiveType = item.sensitive_type || null;
            const meta = orderStatusMeta(orderStatus, item.is_clawed_back);

            html += '<tr>' +
                '<td class="fw-semibold"><strong>#' + escapeHtml(orderNumber) + '</strong></td>' +
                '<td>' + formatDate(rowDate) + '</td>' +
                '<td><div class="fw-semibold">' + escapeHtml(item.site_name) + '</div><div class="text-muted small">' + linkOrDash(item.site_url, item.site_url) + '</div></td>' +
                '<td class="text-primary">€' + money(basePrice) + '</td>' +
                '<td>' + (additionalPrice > 0
                    ? '<span class="sensitive-badge"><i class="fa fa-plus-circle"></i> ' + escapeHtml(sensitiveType || 'Extra') + ' (+€' + money(additionalPrice) + ')</span>'
                    : '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + homepageCell(homepagePrice, homepageDays) + '</td>' +
                '<td>' + payoutCell(item) + '</td>' +
                '<td><span class="status-badge ' + meta.cls + '">' + escapeHtml(meta.text) + '</span></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-info btn-view-order" data-id="' + item.id + '"><i class="fa fa-eye"></i> View</button></td>' +
                '</tr>';
        }
        $('#ordersTableBody').html(html);
    }

    function viewOrderDetails(orderItemId) {
        fetch(orderDetailsUrl(orderItemId), {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            }
        })
            .then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); })
            .then(function (result) {
                if (result.ok && result.data.success) {
                    renderOrderDetailsModal(result.data.data);
                    const el = document.getElementById('orderDetailsModal');
                    if (window.bootstrap && bootstrap.Modal) {
                        bootstrap.Modal.getOrCreateInstance(el).show();
                    }
                } else {
                    const msg = (result.data && result.data.message) || 'Failed to load order details';
                    if (typeof slbAlert === 'function') {
                        slbAlert({ icon: 'error', title: msg });
                    }
                }
            })
            .catch(function () {
                if (typeof slbAlert === 'function') {
                    slbAlert({ icon: 'error', title: 'Failed to load order details' });
                }
            });
    }

    function renderOrderDetailsModal(orderItem) {
        const order = orderItem.order || {};
        const additionalPrice = parseFloat(orderItem.additional_price || 0);
        const homepagePrice = parseFloat(orderItem.homepage_price || 0);
        const homepageDays = orderItem.homepage_days != null && orderItem.homepage_days !== ''
            ? parseInt(orderItem.homepage_days, 10)
            : null;
        const basePrice = parseFloat(orderItem.publisher_base_price != null
            ? orderItem.publisher_base_price
            : (orderItem.price - additionalPrice - homepagePrice));
        const totalPrice = parseFloat(orderItem.price);
        const sensitiveType = orderItem.sensitive_type || null;

        const liveUrlHtml = orderItem.live_url
            ? '<p class="mb-1"><strong>Live URL:</strong></p><p class="mb-2">' + linkOrDash(orderItem.live_url) + '</p>'
            : '<p class="mb-2 text-muted">Live URL not submitted yet</p>';

        const contentHtml = orderItem.content_link
            ? '<p class="mb-1"><strong>Content Link:</strong></p><p class="mb-2">' + linkOrDash(orderItem.content_link) + '</p>'
            : '<p class="mb-1"><strong>Content Link:</strong></p><p class="mb-2 text-muted">—</p>';

        const html = '<div class="row mb-4">' +
            '<div class="col-md-6"><div class="bg-light p-3 rounded">' +
                '<h6 class="mb-3">Order Information</h6>' +
                '<p class="mb-1"><strong>Order Number:</strong> #' + escapeHtml(order.order_number || 'N/A') + '</p>' +
                '<p class="mb-1"><strong>Date:</strong> ' + formatDate(order.created_at || orderItem.created_at) + '</p>' +
                '<p class="mb-1"><strong>Payment Status:</strong> ' + paymentStatusBadge(order.payment_status) + '</p>' +
                '<p class="mb-1"><strong>Reference Code:</strong> ' + escapeHtml(order.reference_code || '-') + '</p>' +
            '</div></div>' +
            '<div class="col-md-6"><div class="bg-light p-3 rounded">' +
                '<h6 class="mb-3">Earnings Summary</h6>' +
                '<p class="mb-1"><strong>Base Price:</strong> €' + money(basePrice) + '</p>' +
                (additionalPrice > 0
                    ? '<p class="mb-1"><strong>Sensitive Price:</strong> <span class="text-warning">+ €' + money(additionalPrice) + ' (' + escapeHtml(sensitiveType || 'Extra') + ')</span></p>'
                    : '') +
                (homepagePrice > 0
                    ? '<p class="mb-1"><strong>Homepage:</strong> <span class="text-warning">+ €' + money(homepagePrice)
                        + (homepageDays
                            ? ' (' + homepageDays + ' day' + (homepageDays === 1 ? '' : 's') + ')'
                            : '')
                        + '</span></p>'
                    : '') +
                '<p class="mb-1"><strong>' + escapeHtml((orderItem.payout_label || 'Payout')) + ':</strong> ' +
                    (orderItem.payout_state === 'none'
                        ? '<span class="text-muted">—</span>'
                        : '<span class="' + (orderItem.payout_state === 'you_earned' ? 'earned-amount fs-4' : 'fw-semibold') + '">€' + money(totalPrice) + '</span>') +
                '</p>' +
                (orderItem.is_clawed_back ? '<p class="mb-1 text-muted">Clawed back — not counted as earned.</p>' : '') +
            '</div></div></div>' +
            '<h6 class="mb-3">Placement</h6>' +
            '<div class="border rounded p-3"><div class="row">' +
                '<div class="col-md-6">' +
                    '<p class="mb-1"><strong>Site Name:</strong></p><p class="mb-2">' + escapeHtml(orderItem.site_name) + '</p>' +
                    '<p class="mb-1"><strong>Site URL:</strong></p><p class="mb-2">' + linkOrDash(orderItem.site_url) + '</p>' +
                '</div>' +
                '<div class="col-md-6">' + contentHtml + liveUrlHtml + '</div>' +
            '</div></div>';

        $('#orderDetailsContent').html(html);
    }

    function loadWithdrawals(page) {
        page = page || 1;
        withdrawalsPage = page;
        const params = withdrawalsFilterParams(page);
        const statusLabel = $('#withdrawalsStatus option:selected').text();
        $('#withdrawalsTabTitle').text(params.status === 'all' ? 'Withdrawals' : statusLabel);

        $('#withdrawalsTableBody').html(
            '<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted mb-0">Loading withdrawals...</p></td></tr>'
        );

        $.ajax({
            url: urls.withdrawals,
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#withdrawalsTableBody').html('<tr><td colspan="8" class="text-center text-danger py-5">' + escapeHtml(response.message || 'Failed to load withdrawals') + '</td></tr>');
                    return;
                }
                renderWithdrawalsTable(response.data);
                renderPagination('#withdrawalsPaginationNav', response.pagination, loadWithdrawals);
                $('#withdrawalsResultsCount').text(resultsCountLabel(response.pagination));
                replaceReportsUrl();
            },
            error: function (xhr) {
                $('#withdrawalsTableBody').html('<tr><td colspan="8" class="text-center text-danger py-5">Error loading withdrawals. Please refresh the page.</td></tr>');
                if (typeof slbHandleHttpError === 'function') {
                    slbHandleHttpError(xhr, { fallback: 'Could not load withdrawals' });
                }
            }
        });
    }

    function withdrawalStatusBadge(withdrawal) {
        const label = escapeHtml(withdrawal.status_label || withdrawal.status || 'Unknown');
        switch (withdrawal.status) {
            case 'pending': return '<span class="badge bg-warning text-dark">' + label + '</span>';
            case 'processing': return '<span class="badge bg-info text-dark">' + label + '</span>';
            case 'completed': return '<span class="badge bg-success">' + label + '</span>';
            case 'cancelled': return '<span class="badge bg-danger">' + label + '</span>';
            default: return '<span class="badge bg-secondary">' + label + '</span>';
        }
    }

    function statementLinks(withdrawal) {
        if (!withdrawal.statement_url) {
            return '<span class="text-muted">—</span>';
        }
        let html = '<a href="' + escapeHtml(withdrawal.statement_url) + '">View</a>';
        if (withdrawal.statement_pdf_url) {
            html += ' · <a href="' + escapeHtml(withdrawal.statement_pdf_url) + '" target="_blank" rel="noopener">PDF</a>';
        }
        return html;
    }

    function renderWithdrawalsTable(withdrawals) {
        if (!withdrawals || withdrawals.length === 0) {
            $('#withdrawalsTableBody').html(
                '<tr><td colspan="8" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2 mb-0">No withdrawals match this filter</p><p class="text-muted small mb-0">Try another status or date range.</p></td></tr>'
            );
            return;
        }

        let html = '';
        for (let i = 0; i < withdrawals.length; i++) {
            const w = withdrawals[i];
            html += '<tr>' +
                '<td>' + formatDate(w.created_at) + '</td>' +
                '<td>€' + money(w.amount) + '</td>' +
                '<td class="text-muted">€' + money(w.fee) + '</td>' +
                '<td class="withdrawn-amount"><strong>- €' + money(w.net_amount) + '</strong></td>' +
                '<td><span class="badge bg-secondary">' + escapeHtml(w.payment_method_label || 'Bank Transfer') + '</span></td>' +
                '<td>' + withdrawalStatusBadge(w) + '</td>' +
                '<td><span class="text-muted small">' + escapeHtml(w.reference || ('WD-' + w.id)) + '</span></td>' +
                '<td>' + statementLinks(w) + '</td>' +
                '</tr>';
        }
        $('#withdrawalsTableBody').html(html);
    }

    function renderPagination(navSelector, pagination, loadFn) {
        const $nav = $(navSelector);
        if (!pagination || pagination.last_page <= 1) {
            $nav.html('');
            return;
        }

        let html = '<ul class="pagination justify-content-center mb-0">';
        if (pagination.current_page > 1) {
            html += '<li class="page-item"><button type="button" class="page-link" data-page="' + (pagination.current_page - 1) + '">Previous</button></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
        }

        for (let i = 1; i <= pagination.last_page; i++) {
            if (i >= pagination.current_page - 2 && i <= pagination.current_page + 2) {
                const activeClass = i === pagination.current_page ? 'active' : '';
                html += '<li class="page-item ' + activeClass + '"><button type="button" class="page-link" data-page="' + i + '">' + i + '</button></li>';
            }
        }

        if (pagination.current_page < pagination.last_page) {
            html += '<li class="page-item"><button type="button" class="page-link" data-page="' + (pagination.current_page + 1) + '">Next</button></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">Next</span></li>';
        }
        html += '</ul>';
        $nav.html(html);

        $nav.find('.page-link[data-page]').on('click', function (e) {
            e.preventDefault();
            const page = parseInt($(this).data('page'), 10);
            if (page) {
                loadFn(page);
                $('html, body').animate({ scrollTop: root.offsetTop }, 'fast');
            }
        });
    }

    $(function () {
        applyQueryToControls();
        loadStatistics();
        loadOrders(ordersPage);
        loadWithdrawals(withdrawalsPage);

        $('#ordersFilters').on('submit', function (e) {
            e.preventDefault();
            normalizeDatePair($('#ordersDateFrom'), $('#ordersDateTo'));
            loadOrders(1);
        });
        $('#withdrawalsFilters').on('submit', function (e) {
            e.preventDefault();
            normalizeDatePair($('#withdrawalsDateFrom'), $('#withdrawalsDateTo'));
            loadWithdrawals(1);
        });

        $('#orders-tab').on('shown.bs.tab', function () {
            loadOrders(ordersPage);
            replaceReportsUrl();
        });
        $('#withdrawals-tab').on('shown.bs.tab', function () {
            loadWithdrawals(withdrawalsPage);
            replaceReportsUrl();
        });

        $(document).on('click', '.btn-view-order', function () {
            const id = $(this).data('id');
            if (id) viewOrderDetails(id);
        });
    });
})();
