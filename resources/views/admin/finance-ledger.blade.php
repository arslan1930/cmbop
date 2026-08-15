@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Wallet ledger</h4>
            <p class="text-muted mb-0 small">All wallet_transactions — deposits, purchases, refunds, withdrawals, bonuses, publisher earnings (transfer_in), and role moves.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.finance.ledger.export', request()->query(), false) }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.finance', [], false) }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chart-pie me-1"></i> Finance overview
            </a>
        </div>
    </div>

    @if($ledgerUser)
        <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 mb-3">
            <div class="small mb-0">
                Showing ledger for
                <strong>{{ $ledgerUser->name }}</strong>
                <span class="text-muted">{{ $ledgerUser->email }}</span>
            </div>
            <a href="{{ route('admin.finance.ledger', request()->except('user_id'), false) }}" class="btn btn-sm btn-outline-secondary">Clear user</a>
        </div>
    @endif

    <form method="GET" class="card border-0 shadow-sm mb-3 finance-ledger-filters">
        <div class="card-body">
            @if($ledgerUser)
                <input type="hidden" name="user_id" value="{{ $ledgerUser->id }}">
            @endif
            <div class="row g-3 align-items-end">
                <div class="col-12 col-sm-6 col-lg">
                    <x-slb-search-field
                        name="search"
                        id="adminFinanceLedgerSearch"
                        :value="is_string(request('search')) ? request('search') : ''"
                        placeholder="User, email, reference…"
                        label-class="form-label small text-muted mb-1"
                    />
                </div>
                <div class="col-6 col-sm-6 col-lg">
                    <label class="form-label small text-muted mb-1" for="adminFinanceLedgerType">Type</label>
                    <select name="type" id="adminFinanceLedgerType" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach($types as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ (new \App\Models\WalletTransaction(['type' => $type]))->typeLabel() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-sm-6 col-lg">
                    <label class="form-label small text-muted mb-1" for="adminFinanceLedgerDirection">Direction</label>
                    <select name="direction" id="adminFinanceLedgerDirection" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="credit" @selected(request('direction') === 'credit')>Credit</option>
                        <option value="debit" @selected(request('direction') === 'debit')>Debit</option>
                    </select>
                </div>
                <div class="col-6 col-sm-6 col-lg">
                    <label class="form-label small text-muted mb-1" for="adminFinanceLedgerDateFrom">From</label>
                    <input type="date" id="adminFinanceLedgerDateFrom" name="date_from" value="{{ search_text(request('date_from')) }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-sm-6 col-lg">
                    <label class="form-label small text-muted mb-1" for="adminFinanceLedgerDateTo">To</label>
                    <input type="date" id="adminFinanceLedgerDateTo" name="date_to" value="{{ search_text(request('date_to')) }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-sm-6 col-lg-auto finance-ledger-filters__action">
                    <label class="form-label small text-muted mb-1" for="adminFinanceLedgerFilter">&nbsp;</label>
                    <button type="submit" id="adminFinanceLedgerFilter" class="btn btn-sm btn-primary">Filter</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Dir</th>
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
                            <td><span class="badge bg-light text-dark border">{{ $tx->typeLabel() }}</span></td>
                            <td>
                                @if($tx->direction === 'credit')
                                    <span class="text-success small fw-semibold">credit</span>
                                @else
                                    <span class="text-danger small fw-semibold">debit</span>
                                @endif
                            </td>
                            <td class="fw-semibold">€{{ number_format((float) $tx->amount, 2) }}</td>
                            <td class="small text-muted">€{{ number_format((float) $tx->bonus_amount, 2) }}</td>
                            <td class="small">€{{ number_format((float) $tx->balance_after, 2) }}</td>
                            <td class="small text-muted">
                                <div>{{ $tx->reference }}</div>
                                <div class="text-truncate" style="max-width:180px" title="{{ $tx->description }}">{{ $tx->description }}</div>
                            </td>
                            <td>
                                @if($tx->user_id)
                                    <a href="{{ route('admin.finance.user', $tx->user_id, false) }}" class="btn btn-sm btn-outline-secondary">Dossier</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">No ledger rows match these filters</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $transactions->links() }}</div>
    </div>
</div>
@endsection
