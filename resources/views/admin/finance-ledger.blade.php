@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Wallet ledger</h4>
            <p class="text-muted mb-0 small">All wallet_transactions — deposits, purchases, refunds, withdrawals, bonuses, publisher earnings (transfer_in), and role moves.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.finance.ledger.export', $exportQuery ?? []) }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.finance') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chart-pie me-1"></i> Finance overview
            </a>
        </div>
    </div>

    @if(($userId ?? 0) > 0)
        <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 mb-3">
            <div class="small mb-0">
                Showing ledger for
                @if($ledgerUser)
                    <strong>{{ $ledgerUser->name }}</strong>
                    <span class="text-muted">{{ $ledgerUser->email }}</span>
                @else
                    <strong>#{{ $userId }}</strong>
                    <span class="text-muted">not found</span>
                @endif
            </div>
            <a href="{{ route('admin.finance.ledger', $clearUserQuery ?? []) }}" class="btn btn-sm btn-outline-secondary">Clear user</a>
        </div>
    @endif

    @if(($walletId ?? 0) > 0)
        <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 mb-3">
            <div class="small mb-0">
                Showing wallet
                <strong>#{{ $walletId }}</strong>
                @if($ledgerWallet)
                    <span class="text-muted">{{ $ledgerWallet->role?->name ? ucfirst($ledgerWallet->role->name) : '' }}</span>
                    <span class="text-muted">{{ $ledgerWallet->user?->email }}</span>
                @else
                    <span class="text-muted">not found</span>
                @endif
            </div>
            <a href="{{ route('admin.finance.ledger', $clearWalletQuery ?? []) }}" class="btn btn-sm btn-outline-secondary">Clear wallet</a>
        </div>
    @endif

    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            @if(($userId ?? 0) > 0)
                <input type="hidden" name="user_id" value="{{ $userId }}">
            @endif
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <x-slb-search-field name="search" id="adminFinanceLedgerSearch" :value="$search" placeholder="User, email, reference, or id…" />
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach($types as $txType)
                            <option value="{{ $txType }}" @selected($type === $txType)>{{ ($typeLabels ?? [])[$txType] ?? $txType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Direction</label>
                    <select name="direction" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="credit" @selected($direction === 'credit')>Credit</option>
                        <option value="debit" @selected($direction === 'debit')>Debit</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">From</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">To</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-sm btn-primary w-100">Filter</button>
                </div>
            </div>
            <div class="row g-2 align-items-end mt-1">
                <div class="col-md-2">
                    <label class="form-label small text-muted">Wallet</label>
                    <select name="wallet_role" class="form-select form-select-sm">
                        <option value="">Any wallet</option>
                        <option value="advertiser" @selected($walletRole === 'advertiser')>Advertiser</option>
                        <option value="publisher" @selected($walletRole === 'publisher')>Publisher</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Method</label>
                    <select name="payment_method" class="form-select form-select-sm">
                        <option value="">Any method</option>
                        @foreach($paymentMethods as $methodValue => $methodLabel)
                            <option value="{{ $methodValue }}" @selected($paymentMethod === $methodValue)>{{ $methodLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Any status</option>
                        @foreach($statuses as $statusValue)
                            <option value="{{ $statusValue }}" @selected($status === $statusValue)>{{ ucfirst($statusValue) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Wallet id</label>
                    <input type="text" inputmode="numeric" name="wallet_id" value="{{ ($walletId ?? 0) > 0 ? $walletId : '' }}" class="form-control form-control-sm" placeholder="e.g. 12" autocomplete="off">
                </div>
                @if($hasLedgerFilters)
                    <div class="col-auto">
                        <a href="{{ route('admin.finance.ledger', $clearFiltersQuery ?? []) }}" class="btn btn-sm btn-outline-secondary">Clear filters</a>
                    </div>
                @endif
            </div>
        </div>
    </form>

    <div class="alert alert-light border small d-flex flex-wrap gap-3 align-items-center py-2 mb-3">
        <span><strong>{{ number_format($totals['count']) }}</strong> rows</span>
        <span>Credits <span class="text-success fw-semibold">+€{{ number_format($totals['credits'], 2) }}</span></span>
        <span>Debits <span class="text-danger fw-semibold">-€{{ number_format($totals['debits'], 2) }}</span></span>
        <span>Net <strong class="{{ $totals['net'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $totals['net'] >= 0 ? '+' : '-' }}€{{ number_format(abs($totals['net']), 2) }}</strong></span>
        <span class="text-muted">across these filters, not this page</span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Wallet</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th>Dir</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Bonus</th>
                        <th>Balance after</th>
                        <th>Reference</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $tx)
                        <tr>
                            <td class="small text-muted">{{ $tx->created_at?->format('M d, Y H:i') }}</td>
                            <td>
                                <div class="fw-semibold small">{{ $tx->user?->name ?? '—' }}</div>
                                <div class="text-muted small">{{ $tx->user?->email }}</div>
                            </td>
                            <td class="small">
                                <div>{{ $tx->walletRoleLabel() }}</div>
                                @if($tx->wallet_id)
                                    <a href="{{ route('admin.finance.ledger', array_filter(array_merge($exportQuery ?? [], ['wallet_id' => $tx->wallet_id]))) }}" class="text-muted">#{{ $tx->wallet_id }}</a>
                                @endif
                            </td>
                            <td><span class="badge bg-light text-dark border">{{ $tx->typeLabel() }}</span></td>
                            <td class="small text-muted">{{ $tx->paymentMethodLabel() }}</td>
                            <td>
                                @if($tx->direction === 'credit')
                                    <span class="text-success small fw-semibold">credit</span>
                                @else
                                    <span class="text-danger small fw-semibold">debit</span>
                                @endif
                            </td>
                            <td>
                                @if(strtolower((string) ($tx->status ?? '')) === 'pending')
                                    <span class="badge bg-warning text-dark">{{ $tx->statusLabel() }}</span>
                                @else
                                    <span class="badge bg-light text-dark border">{{ $tx->statusLabel() }}</span>
                                @endif
                            </td>
                            <td class="fw-semibold {{ $tx->direction === 'credit' ? 'text-success' : 'text-danger' }}">
                                {{ $tx->direction === 'credit' ? '+' : '-' }}€{{ number_format((float) $tx->amount, 2) }}
                            </td>
                            <td class="small text-muted">€{{ number_format((float) $tx->bonus_amount, 2) }}</td>
                            <td class="small">€{{ number_format((float) $tx->balance_after, 2) }}</td>
                            <td class="small text-muted">
                                <div>
                                    @if($relatedUrl = $tx->adminRelatedUrl())
                                        <a href="{{ $relatedUrl }}">{{ $tx->reference ?: 'Open' }}</a>
                                    @else
                                        {{ $tx->reference }}
                                    @endif
                                </div>
                                <div class="text-truncate" style="max-width:180px" title="{{ $tx->description }}">{{ $tx->description }}</div>
                            </td>
                            <td>
                                @if($tx->user_id)
                                    <a href="{{ route('admin.finance.user', $tx->user_id) }}" class="btn btn-sm btn-outline-secondary">Dossier</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center text-muted py-5">
                                @if($hasLedgerFilters)
                                    <div class="mb-2">No ledger rows match these filters</div>
                                    <a href="{{ route('admin.finance.ledger', $clearFiltersQuery ?? []) }}" class="btn btn-sm btn-outline-secondary">Clear filters</a>
                                @elseif(($userId ?? 0) > 0)
                                    No ledger rows for this user
                                @else
                                    No wallet transactions yet
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $transactions->links() }}</div>
    </div>
</div>
@endsection
