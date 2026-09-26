@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Deposit Requests</h2>
            <p class="text-muted">Manage and approve user deposit requests</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'pending']) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Pending</h6>
                            <h2 class="mb-0 text-warning">{{ $stats['pending'] }}</h2>
                        </div>
                        <div class="bg-warning bg-opacity-10 p-3 rounded">
                            <i class="fa fa-clock fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'pending', 'reported' => 1]) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">User reported paid</h6>
                            <h2 class="mb-0 text-success">{{ $stats['user_reported_paid'] ?? 0 }}</h2>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded">
                            <i class="fa fa-user-check fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'approved']) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Approved</h6>
                            <h2 class="mb-0 text-info">{{ $stats['approved'] }}</h2>
                        </div>
                        <div class="bg-info bg-opacity-10 p-3 rounded">
                            <i class="fa fa-check-circle fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'completed']) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Completed</h6>
                            <h2 class="mb-0 text-success">{{ $stats['completed'] }}</h2>
                        </div>
                        <div class="bg-success bg-opacity-10 p-3 rounded">
                            <i class="fa fa-check-double fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <a href="{{ route('admin.deposits', ['status' => 'rejected']) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Rejected</h6>
                            <h2 class="mb-0 text-danger">{{ $stats['rejected'] ?? 0 }}</h2>
                        </div>
                        <div class="bg-danger bg-opacity-10 p-3 rounded">
                            <i class="fa fa-times-circle fa-2x text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
            </a>
        </div>
        <div class="col-md-3 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Amount</h6>
                            <h2 class="mb-0 text-primary">€{{ number_format($stats['total_amount'], 2) }}</h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 p-3 rounded">
                            <i class="fa fa-euro-sign fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 admin-deposits-filter-card">
        <div class="card-body">
            <form method="GET" class="admin-deposits-filters">
                @if(request()->boolean('finance'))
                    <input type="hidden" name="finance" value="1">
                @endif
                @if(request()->boolean('reported'))
                    <input type="hidden" name="reported" value="1">
                @endif
                <div class="admin-deposits-filters__grid">
                <div class="admin-deposits-filters__search">
                    <x-slb-search-field name="search" id="adminDepositsSearch" :value="request('search')" placeholder="Reference, Name, Email" input-class="form-control" label-class="form-label" />
                </div>
                <div>
                    <label class="form-label" for="adminDepositsStatus">Status</label>
                    <select name="status" id="adminDepositsStatus" class="form-select">
                        <option value="">All</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                        <option value="refunded" {{ request('status') == 'refunded' ? 'selected' : '' }}>Refunded</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="adminDepositsMethod">Method</label>
                    <select name="payment_method" id="adminDepositsMethod" class="form-select">
                        <option value="">All</option>
                        @foreach(['bank' => 'Bank', 'wise' => 'Wise', 'crypto' => 'Crypto', 'card' => 'Card', 'paypal' => 'PayPal'] as $methodValue => $methodLabel)
                            <option value="{{ $methodValue }}" @selected(request('payment_method') === $methodValue)>{{ $methodLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="adminDepositsFrom">{{ request('status') === 'completed' ? 'Approved from' : 'From' }}</label>
                    <input type="date" name="from" id="adminDepositsFrom" class="form-control" value="{{ request('from') }}">
                </div>
                <div>
                    <label class="form-label" for="adminDepositsTo">{{ request('status') === 'completed' ? 'Approved to' : 'To' }}</label>
                    <input type="date" name="to" id="adminDepositsTo" class="form-control" value="{{ request('to') }}">
                </div>
                <div>
                    <label class="form-label" for="adminDepositsSort">Sort</label>
                    <select name="sort" id="adminDepositsSort" class="form-select">
                        <option value="newest" @selected(request('sort', 'newest') === 'newest')>Newest</option>
                        <option value="oldest" @selected(request('sort') === 'oldest')>Oldest</option>
                        <option value="amount" @selected(request('sort') === 'amount')>Amount</option>
                    </select>
                </div>
                <div class="admin-deposits-filters__actions">
                    <div class="d-flex gap-2">
                        <button type="submit" id="adminDepositsFilter" class="btn btn-primary">
                            <i class="fa fa-search me-1"></i> Filter
                        </button>
                        <a href="{{ route('admin.deposits') }}" class="btn btn-outline-secondary">
                            <i class="fa fa-refresh me-1"></i> Reset
                        </a>
                        <a href="{{ route('admin.deposits.export', request()->query()) }}" class="btn btn-outline-primary">
                            Export CSV
                        </a>
                    </div>
                </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Deposits Table -->
    <div class="card border-0 shadow-sm admin-table-fit">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 admin-deposits-table">
                    <thead class="table-light">
                        <tr>
                            <th class="admin-num-col">ID</th>
                            <th>User</th>
                            <th>Reference Code</th>
                            <th class="admin-narrow-col">Amount</th>
                            <th>Payment Method</th>
                            <th class="admin-status-col">Status</th>
                            <th class="admin-narrow-col">Date</th>
                            <th class="admin-actions-col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deposits as $deposit)
                        <tr>
                            <td class="admin-deposit-id">#{{ $deposit->id }}</td>
                            <td>
                                @php
                                    $depositUser = $deposit->user;
                                    $depositUserName = $depositUser?->name ?? 'Unknown';
                                    $depositUserInitial = strtoupper(substr($depositUserName, 0, 1) ?: '?');
                                @endphp
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-2" style="width: 32px; height: 32px; background: linear-gradient(135deg, #1a585e 0%, #3faeb2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                        {{ $depositUserInitial }}
                                    </div>
                                    <div>
                                        @if($deposit->user_id)
                                            <a href="{{ route('admin.finance.user', $deposit->user_id) }}"><strong>{{ $depositUserName }}</strong></a><br>
                                        @else
                                            <strong>{{ $depositUserName }}</strong><br>
                                        @endif
                                        @if($depositUser?->email)
                                            <small class="text-muted slb-text-break">{{ $depositUser->email }}</small>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td><code class="ref-code">{{ $deposit->reference_code }}</code></td>
                            <td class="fw-semibold text-primary">
                                €{{ number_format($deposit->amount, 2) }}
                                @php
                                    $chargeCurrency = strtoupper(trim((string) ($deposit->charge_currency ?? '')));
                                @endphp
                                @if($chargeCurrency !== '' && $chargeCurrency !== 'EUR' && $deposit->charge_amount !== null && $deposit->charge_amount !== '')
                                    <div class="small text-muted">Charged {{ $chargeCurrency }} {{ number_format((float) $deposit->charge_amount, 2) }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="admin-deposit-method">{{ $deposit->paymentMethodLabel() }}</span>
                            </td>
                            <td>
                                @if($deposit->status == 'pending')
                                    <span class="status-badge status-pending">Pending</span>
                                    @if($deposit->user_marked_paid_at)
                                        <div class="small text-success mt-1">
                                            <i class="fa fa-check-circle"></i> User reported paid
                                        </div>
                                    @endif
                                @elseif($deposit->status == 'approved')
                                    <span class="status-badge status-processing">Approved</span>
                                @elseif($deposit->status == 'completed')
                                    <span class="status-badge status-completed">Completed</span>
                                @elseif($deposit->status == 'rejected')
                                    <span class="status-badge status-cancelled">Rejected</span>
                                @elseif($deposit->status == 'refunded')
                                    <span class="status-badge status-refunded">Refunded</span>
                                @endif
                            </td>
                            <td class="admin-deposit-date">
                                @if($deposit->created_at)
                                    <div>{{ $deposit->created_at->format('M d, Y') }}</div>
                                    <div class="admin-deposit-date__time">{{ $deposit->created_at->format('H:i') }}</div>
                                @else
                                    —
                                @endif
                                @if($deposit->user_marked_paid_at)
                                    <div class="small text-success">Reported {{ $deposit->user_marked_paid_at->format('M d, H:i') }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="admin-deposit-actions">
                                    <button class="btn btn-sm btn-outline-primary view-deposit"
                                            data-id="{{ $deposit->id }}"
                                            data-show-url="{{ route('admin.deposits.show', $deposit->id) }}">
                                        <i class="fa fa-eye"></i> View
                                    </button>
                                    @if(!empty($invoiceLinks[$deposit->id]))
                                        <a href="{{ $invoiceLinks[$deposit->id]['url'] }}" class="btn btn-sm btn-outline-secondary">
                                            Invoice
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No deposit requests found</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="mt-3">
        {{ $deposits->links() }}
    </div>
</div>

<!-- Deposit Details Modal -->
<div class="modal fade" id="depositModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable deposit-sheet-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="depositModalTitle">Deposit request details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="depositModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.admin-deposits-filters select').forEach(function (select) {
        if (select.closest('.admin-deposits-theme-select')) return;
        const parent = select.parentNode;
        if (!parent) return;
        const wrap = document.createElement('div');
        wrap.className = 'single-select-wrapper admin-deposits-theme-select';
        parent.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('visually-hidden');
        select.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'single-select-input';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        const labelled = select.id ? document.querySelector('label[for="' + select.id + '"]') : null;
        trigger.setAttribute('aria-label', labelled ? labelled.textContent.trim() : 'Choose');

        const valueEl = document.createElement('span');
        valueEl.className = 'single-select-value';
        const arrow = document.createElement('i');
        arrow.className = 'fa fa-chevron-down single-select-arrow';
        arrow.setAttribute('aria-hidden', 'true');
        trigger.append(valueEl, arrow);

        const dropdown = document.createElement('div');
        dropdown.className = 'single-select-dropdown';
        const options = document.createElement('div');
        options.className = 'single-select-options';
        options.setAttribute('role', 'listbox');
        dropdown.appendChild(options);
        wrap.append(trigger, dropdown);

        function sync() {
            const current = String(select.value || '');
            options.replaceChildren();
            Array.from(select.options).forEach(function (opt) {
                const el = document.createElement('div');
                const on = opt.value === current;
                el.className = 'single-select-option' + (on ? ' selected' : '');
                el.setAttribute('role', 'option');
                el.setAttribute('data-value', opt.value);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
                el.textContent = (opt.textContent || '').trim();
                options.appendChild(el);
            });
            const selected = select.options[select.selectedIndex];
            valueEl.textContent = selected ? String(selected.textContent || '').trim() : 'All';
        }

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const willOpen = !dropdown.classList.contains('show');
            document.querySelectorAll('.admin-deposits-filters .single-select-dropdown.show').forEach(function (dd) {
                if (dd === dropdown) return;
                dd.classList.remove('show');
                const other = dd.parentElement && dd.parentElement.querySelector('.single-select-input');
                if (other) other.setAttribute('aria-expanded', 'false');
            });
            dropdown.classList.toggle('show', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        dropdown.addEventListener('click', function (event) {
            const opt = event.target.closest('.single-select-option');
            if (!opt) return;
            event.stopPropagation();
            select.value = opt.getAttribute('data-value') || '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            dropdown.classList.remove('show');
            trigger.setAttribute('aria-expanded', 'false');
            sync();
        });

        sync();
    });

    document.addEventListener('click', function () {
        document.querySelectorAll('.admin-deposits-filters .single-select-dropdown.show').forEach(function (dd) {
            dd.classList.remove('show');
            const trigger = dd.parentElement && dd.parentElement.querySelector('.single-select-input');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    });

    const csrfToken = @json(csrf_token());
    const rejectUrlTemplate = @json(route('admin.deposits.reject', ['id' => '__ID__']));
    const paypalRefundUrlTemplate = @json(route('admin.deposits.paypal-refund', ['id' => '__ID__']));

    function paypalDepositFields(deposit) {
        const response = (deposit && typeof deposit.paypal_response === 'object' && deposit.paypal_response)
            ? deposit.paypal_response
            : {};
        const refund = (response.refund && typeof response.refund === 'object') ? response.refund : {};
        const orderId = deposit.paypal_order_id || '';
        const captureId = deposit.paypal_capture_id || '';
        const refundId = refund.id || '';
        if (!orderId && !captureId && !refundId) {
            return '';
        }

        let html = '';
        if (orderId) {
            html += depositFact('PayPal order ID', `<code class="ref-code">${escapeHtml(orderId)}</code>`, true);
        }
        if (captureId) {
            html += depositFact('PayPal capture ID', `<code class="ref-code">${escapeHtml(captureId)}</code>`, true);
        }
        if (refundId) {
            html += depositFact('PayPal refund ID', `<code class="ref-code">${escapeHtml(refundId)}</code>`, true);
        }
        if (refund.debited != null && refund.debited !== '') {
            html += depositFact('Wallet debit', `€${parseFloat(refund.debited).toFixed(2)}`);
        }
        if (refund.debt_created != null && parseFloat(refund.debt_created) > 0.009) {
            html += depositFact('Wallet debt created', `€${parseFloat(refund.debt_created).toFixed(2)}`);
        }

        return html;
    }

    function paymentMethodLabel(method) {
        const labels = {
            card: 'Card',
            paypal: 'PayPal',
            wallet: 'Wallet',
            bank: 'Bank Transfer',
            bank_transfer: 'Bank Transfer',
            wise: 'Wise',
            crypto: 'Cryptocurrency'
        };
        const key = String(method || '').toLowerCase();
        return labels[key] || (key ? String(method) : '—');
    }

    function depositActionUrl(template, id) {
        return String(template).replace('__ID__', encodeURIComponent(id));
    }

    function jsonHeaders(extra) {
        return Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken
        }, extra || {});
    }

    function readJsonResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            const expired = response.status === 419;
            return Promise.reject(new Error(expired
                ? 'Your session expired. Refresh the page and try again.'
                : 'Request failed (' + response.status + ').'));
        }

        return response.json().then(function (data) {
            if (!data || typeof data !== 'object') {
                return { success: false, message: 'Request failed (' + response.status + ').' };
            }
            if (!data.message && !response.ok) {
                data.message = 'Request failed (' + response.status + ').';
            }
            return data;
        });
    }

    // View deposit details
    document.querySelectorAll('.view-deposit').forEach(button => {
        button.addEventListener('click', function() {
            const url = this.dataset.showUrl;
            if (!url) {
                Swal.fire('Error', 'Failed to load deposit details', 'error');
                return;
            }

            fetch(url, {
                method: 'GET',
                headers: jsonHeaders()
            })
            .then(readJsonResponse)
            .then(data => {
                if (data.success) {
                    renderDepositModal(data.deposit, data.invoice, data.can_refund_paypal, data.approve_context, data.can_approve_manual, data.finance_url, data.approve_confirm_url);
                    const modal = new bootstrap.Modal(document.getElementById('depositModal'));
                    modal.show();
                } else {
                    Swal.fire('Error', data.message || 'Failed to load deposit details', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', error.message || 'Failed to load deposit details', 'error');
            });
        });
    });
    
    function chargeLine(deposit) {
        const currency = String(deposit.charge_currency || '').toUpperCase();
        const amount = deposit.charge_amount;
        if (!currency || currency === 'EUR' || amount === null || amount === undefined || amount === '') {
            return '';
        }
        return `<div class="small text-muted">Charged ${escapeHtml(currency)} ${parseFloat(amount).toFixed(2)}</div>`;
    }

    function stripeDepositFields(deposit) {
        const sessionId = deposit.stripe_session_id || '';
        const intentId = deposit.stripe_payment_intent_id || '';
        if (!sessionId && !intentId) return '';
        return `
            ${sessionId ? depositFact('Stripe session', `<code class="ref-code">${escapeHtml(sessionId)}</code>`, true) : ''}
            ${intentId ? depositFact('Stripe payment intent', `<code class="ref-code">${escapeHtml(intentId)}</code>`, true) : ''}
        `;
    }

    function approveContextHtml(context) {
        if (!context) return '';
        const dupes = Array.isArray(context.duplicate_matches) ? context.duplicate_matches : [];
        const prior = Array.isArray(context.prior_deposits) ? context.prior_deposits : [];
        const warning = context.possible_duplicate
            ? `<div class="alert alert-warning py-2">Same amount was credited recently${dupes.map(row => ` · #${row.id} ${escapeHtml(row.reference_code || '')}`).join('')}</div>`
            : '';
        const priorHtml = prior.length
            ? `<div class="small text-muted mt-2">Recent completed: ${prior.map(row => `#${row.id} €${parseFloat(row.amount).toFixed(2)}`).join(', ')}</div>`
            : '';
        const projected = context.projected_balance === null || context.projected_balance === undefined
            ? ''
            : `<div>After approve: <strong>€${parseFloat(context.projected_balance).toFixed(2)}</strong></div>`;
        return `
            <section class="deposit-sheet__block">
                <h6 class="deposit-sheet__label">Wallet</h6>
                <div class="deposit-sheet__panel">
                    ${warning}
                    <div>Current balance: <strong>€${parseFloat(context.current_balance || 0).toFixed(2)}</strong></div>
                    ${projected}
                    ${priorHtml}
                </div>
            </section>
        `;
    }

    function depositFact(label, valueHtml, wide) {
        return `<div class="${wide ? 'is-wide' : ''}"><dt>${label}</dt><dd>${valueHtml}</dd></div>`;
    }

    function statusBadgeHtml(status) {
        const map = {
            pending: ['status-pending', 'Pending'],
            approved: ['status-processing', 'Approved'],
            completed: ['status-completed', 'Completed'],
            rejected: ['status-cancelled', 'Rejected'],
            refunded: ['status-refunded', 'Refunded'],
        };
        const row = map[status];
        if (!row) return '';
        return `<span class="status-badge ${row[0]}">${row[1]}</span>`;
    }

    function renderDepositModal(deposit, invoice, canRefundPaypal, approveContext, canApproveManual, financeUrl, approveConfirmUrl) {
        const statusBadge = statusBadgeHtml(deposit.status);

        const method = String(deposit.payment_method || '').toLowerCase();
        const isInstant = method === 'card' || method === 'paypal';
        const titleEl = document.getElementById('depositModalTitle');
        if (titleEl) {
            titleEl.textContent = isInstant ? 'Deposit details' : 'Deposit request details';
        }
        
        const user = deposit.user || {};
        const userName = user.name || 'Unknown';
        const userEmail = user.email || '';
        const userInitial = String(userName).charAt(0).toUpperCase() || '?';

        let html = `
            <div class="deposit-sheet">
                <div class="deposit-sheet__user">
                    <div class="deposit-sheet__avatar" aria-hidden="true">${escapeHtml(userInitial)}</div>
                    <div>
                        <div class="deposit-sheet__name">${financeUrl ? `<a href="${escapeHtml(financeUrl)}">${escapeHtml(userName)}</a>` : escapeHtml(userName)}</div>
                        ${userEmail ? `<div class="deposit-sheet__email">${escapeHtml(userEmail)}</div>` : ''}
                    </div>
                </div>
                <dl class="deposit-sheet__facts">
                    ${depositFact('Reference', `<code class="ref-code">${escapeHtml(deposit.reference_code)}</code>`)}
                    ${depositFact('Amount', `<div class="deposit-sheet__amount">€${parseFloat(deposit.amount).toFixed(2)}</div>${chargeLine(deposit)}`)}
                    ${depositFact('Payment method', `<span class="admin-deposit-method">${escapeHtml(paymentMethodLabel(deposit.payment_method))}</span>`)}
                    ${depositFact('Status', statusBadge || '—')}
                    ${depositFact('Reported paid', deposit.user_marked_paid_at
                        ? `<span class="status-badge status-completed">Yes</span> <span class="deposit-sheet__meta">${formatDateTime(deposit.user_marked_paid_at)}</span>`
                        : '<span class="deposit-sheet__meta">Not yet</span>')}
                    ${depositFact('Submitted', formatDateTime(deposit.created_at))}
                    ${deposit.user_payment_note ? depositFact('Payment note', escapeHtml(deposit.user_payment_note), true) : ''}
                    ${paypalDepositFields(deposit)}
                    ${stripeDepositFields(deposit)}
                </dl>
            </div>
        `;

        if (invoice && invoice.url) {
            html += `
                <section class="deposit-sheet__block">
                    <h6 class="deposit-sheet__label">Invoice</h6>
                    <div class="deposit-sheet__panel">
                        <a href="${escapeHtml(invoice.url)}">${escapeHtml(invoice.invoice_number || 'Open invoice')}</a>
                        <span class="deposit-sheet__meta"> · ${escapeHtml(invoice.type_label || 'Deposit Receipt')}</span>
                    </div>
                </section>
            `;
        }
        
        if (deposit.admin_notes) {
            html += `
                <section class="deposit-sheet__block">
                    <h6 class="deposit-sheet__label">Admin notes</h6>
                    <div class="deposit-sheet__panel">${escapeHtml(deposit.admin_notes)}</div>
                </section>
            `;
        }
        
        if (deposit.status === 'pending' && canApproveManual) {
            html += approveContextHtml(approveContext);
        }

        let footerActions = '';
        if (deposit.status === 'pending' || canRefundPaypal) {
            html += `
                <section class="deposit-sheet__block">
                    <label class="deposit-sheet__label" for="adminNotes">Admin notes${deposit.status === 'pending' ? ' (required to reject, at least 10 characters)' : ' (optional)'}</label>
                    <textarea id="adminNotes" class="form-control" rows="3" placeholder="Reason the advertiser will see if you reject"></textarea>
                </section>
            `;
            footerActions = `
                <div class="deposit-sheet__actions">
                    ${deposit.status === 'pending' && canApproveManual ? `
                    <button class="btn btn-success approve-deposit" data-id="${deposit.id}" data-confirm-url="${escapeHtml(approveConfirmUrl || '')}">
                        <i class="fa fa-check"></i> Approve & Add Funds
                    </button>` : ''}
                    ${deposit.status === 'pending' ? `
                    <button class="btn btn-danger reject-deposit" data-id="${deposit.id}">
                        <i class="fa fa-times"></i> Reject
                    </button>` : ''}
                    ${canRefundPaypal ? `
                    <button class="btn btn-outline-danger refund-paypal-deposit" data-id="${deposit.id}">
                        <img src="{{ asset('assets/img/payments/paypal.png') }}" alt="" width="20" height="20" style="width:20px;height:20px;vertical-align:-4px"> Refund PayPal capture
                    </button>` : ''}
                </div>
            `;
        }
        
        document.getElementById('depositModalBody').innerHTML = html;
        const footer = document.querySelector('#depositModal .modal-footer');
        if (footer) {
            footer.innerHTML = footerActions + '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>';
        }
        
        // Attach event listeners to new buttons
        document.querySelectorAll('.approve-deposit').forEach(btn => {
            btn.addEventListener('click', function() {
                approveDeposit(this.dataset.confirmUrl);
            });
        });
        
        document.querySelectorAll('.reject-deposit').forEach(btn => {
            btn.addEventListener('click', function() {
                rejectDeposit(this.dataset.id);
            });
        });

        document.querySelectorAll('.refund-paypal-deposit').forEach(btn => {
            btn.addEventListener('click', function() {
                refundPaypalDeposit(this.dataset.id);
            });
        });
    }
    
    function approveDeposit(confirmUrl) {
        if (!confirmUrl) {
            Swal.fire('Error', 'Open the confirm page from a pending deposit.', 'error');
            return;
        }

        Swal.fire({
            title: 'Approve Deposit?',
            text: 'You will confirm the wallet credit on the next page. Nothing is added until you confirm there.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }
            const notes = (document.getElementById('adminNotes')?.value || '').trim();
            const idMatch = String(confirmUrl).match(/\/deposits\/(\d+)\/approve-confirm/);
            if (idMatch && notes !== '') {
                sessionStorage.setItem('slb-deposit-approve-notes-' + idMatch[1], notes);
            }
            window.location.href = confirmUrl;
        });
    }
    
    function refundPaypalDeposit(id) {
        const notes = document.getElementById('adminNotes')?.value || '';

        Swal.fire({
            title: 'Refund this PayPal deposit?',
            text: 'This refunds the PayPal capture to the buyer and removes the credit from their wallet. If they already spent part of it, the leftover becomes wallet debt.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, refund on PayPal',
            cancelButtonText: 'Cancel',
            customClass: { confirmButton: 'slb-swal-danger' }
        }).then((result) => {
            if (! result.isConfirmed) {
                return;
            }

            Swal.fire({
                title: 'Refunding PayPal…',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch(depositActionUrl(paypalRefundUrlTemplate, id), {
                method: 'POST',
                headers: jsonHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ admin_notes: notes })
            })
            .then(readJsonResponse)
            .then(data => {
                if (data.success) {
                    Swal.fire('Refunded', data.message || 'PayPal capture refunded.', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'PayPal refund failed. The wallet was not changed.', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', error.message || 'PayPal refund failed. The wallet was not changed.', 'error');
            });
        });
    }

    function rejectDeposit(id) {
        const typed = document.getElementById('adminNotes')?.value || '';
        
        Swal.fire({
            title: 'Reject Deposit?',
            text: 'The advertiser will see this reason.',
            icon: 'warning',
            input: 'textarea',
            inputValue: typed,
            inputPlaceholder: 'Reason (min. 10 characters)',
            showCancelButton: true,
            confirmButtonText: 'Yes, Reject',
            cancelButtonText: 'Cancel',
            customClass: { confirmButton: 'slb-swal-danger' },
            preConfirm: (value) => {
                const reason = String(value || '').trim();
                if (reason.length < 10) {
                    Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                    return false;
                }
                if (reason.length > 1000) {
                    Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                    return false;
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const notes = result.value;
                // Show loading
                Swal.fire({
                    title: 'Processing...',
                    text: 'Please wait',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                fetch(depositActionUrl(rejectUrlTemplate, id), {
                    method: 'POST',
                    headers: jsonHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ admin_notes: notes })
                })
                .then(readJsonResponse)
                .then(data => {
                    if (data.success) {
                        let message = data.message;
                        if (data.email_sent) {
                            message += ' ✓ Email sent to user.';
                        } else {
                            message += ' ⚠ Email could not be sent.';
                        }
                        Swal.fire('Success', message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to reject deposit', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', error.message || 'Failed to reject deposit', 'error');
                });
            }
        });
    }
    
    function formatDateTime(value) {
        if (!value) {
            return '—';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return '—';
        }
        return date.toLocaleString();
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

<!-- SweetAlert2 -->
<script src="{{ asset('assets/vendor/sweetalert2/sweetalert2.min.js') }}?v={{ @filemtime(public_path('assets/vendor/sweetalert2/sweetalert2.min.js')) ?: '1' }}"></script>

@endsection