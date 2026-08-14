@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Wallet ledger</h4>
            <p class="text-muted mb-0 small">All wallet_transactions — deposits, purchases, refunds, withdrawals, bonuses, publisher earnings (transfer_in), and role moves.</p>
        </div>
        <a href="{{ route('admin.finance') }}" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-chart-pie me-1"></i> Finance overview
        </a>
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <x-slb-search-field name="search" id="adminFinanceLedgerSearch" :value="request('search')" placeholder="User, email, reference…" />
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach($types as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ (new \App\Models\WalletTransaction(['type' => $type]))->typeLabel() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Direction</label>
                    <select name="direction" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="credit" @selected(request('direction') === 'credit')>Credit</option>
                        <option value="debit" @selected(request('direction') === 'debit')>Debit</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">From</label>
                    <input type="date" name="date_from" value="{{ scalar_text(request('date_from')) }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">To</label>
                    <input type="date" name="date_to" value="{{ scalar_text(request('date_to')) }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-sm btn-primary w-100">Filter</button>
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
                                    <a href="{{ route('admin.finance.user', $tx->user_id) }}" class="btn btn-sm btn-outline-secondary">Dossier</a>
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
