@extends('admin.layouts.app')

@section('content')
@php
    $ledgerMoney = function ($amount, $currency) {
        $code = strtoupper(trim((string) ($currency ?: 'EUR')));
        $formatted = number_format((float) $amount, 2);
        if ($code === '' || $code === 'EUR') {
            return '€'.$formatted;
        }

        return $code.' '.$formatted;
    };
    $roleLabel = function ($roleId) use ($advertiserRoleId, $publisherRoleId) {
        $roleId = (int) $roleId;
        if ($advertiserRoleId && $roleId === (int) $advertiserRoleId) {
            return 'Advertiser';
        }
        if ($publisherRoleId && $roleId === (int) $publisherRoleId) {
            return 'Publisher';
        }

        return '—';
    };
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Wallet ledger</h4>
            <p class="text-muted mb-0 small">All wallet_transactions — deposits, purchases, refunds, withdrawals, bonuses, publisher earnings (transfer_in), and role moves.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.finance.ledger.export', request()->query()) }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.finance') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chart-pie me-1"></i> Finance overview
            </a>
        </div>
    </div>

    @if($exportLimited)
        <div class="alert alert-warning py-2 small">Export includes the first {{ number_format(\App\Http\Controllers\Admin\FinanceController::LEDGER_EXPORT_LIMIT) }} rows. This filter matches {{ number_format($totals['count'] ?? 0) }}.</div>
    @endif

    @if($dateError)
        <div class="alert alert-danger py-2 small">{{ $dateError }}</div>
    @endif

    @if($ledgerUser)
        <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 mb-3">
            <div class="small mb-0">
                Showing ledger for
                <strong>{{ $ledgerUser->name }}</strong>
                <span class="text-muted">{{ $ledgerUser->email }}</span>
            </div>
            <a href="{{ route('admin.finance.ledger', request()->except('user_id')) }}" class="btn btn-sm btn-outline-secondary">Clear user</a>
        </div>
    @endif

    <form method="GET" class="card border-0 shadow-sm mb-3 finance-ledger-filters">
        <div class="card-body">
            @if($ledgerUser)
                <input type="hidden" name="user_id" value="{{ $ledgerUser->id }}">
            @endif
            @if(request()->boolean('finance'))
                <input type="hidden" name="finance" value="1">
            @endif
            <div class="row g-3 align-items-end">
                <div class="col-12 col-sm-6 col-lg">
                    <x-slb-search-field
                        name="search"
                        id="adminFinanceLedgerSearch"
                        :value="is_string(request('search')) ? request('search') : ''"
                        placeholder="User, email, company, payout, reference…"
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
                    <label class="form-label small text-muted mb-1" for="adminFinanceLedgerWallet">Wallet</label>
                    <select name="wallet" id="adminFinanceLedgerWallet" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="advertiser" @selected(request('wallet') === 'advertiser')>Advertiser</option>
                        <option value="publisher" @selected(request('wallet') === 'publisher')>Publisher</option>
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
                    <label class="form-label small text-muted mb-1" for="adminFinanceLedgerSort">Sort</label>
                    <select name="sort" id="adminFinanceLedgerSort" class="form-select form-select-sm">
                        <option value="">Newest</option>
                        <option value="oldest" @selected(request('sort') === 'oldest')>Oldest</option>
                        <option value="amount" @selected(request('sort') === 'amount')>Amount</option>
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
                <div class="col-12 col-sm-6 col-lg-auto finance-ledger-filters__action d-flex gap-2">
                    <div>
                        <label class="form-label small text-muted mb-1" for="adminFinanceLedgerFilter">&nbsp;</label>
                        <button type="submit" id="adminFinanceLedgerFilter" class="btn btn-sm btn-primary">Filter</button>
                    </div>
                    <div>
                        <label class="form-label small text-muted mb-1">&nbsp;</label>
                        <a href="{{ route('admin.finance.ledger') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2 small">
            <strong>{{ number_format($totals['count'] ?? $transactions->total()) }}</strong> rows match
            @forelse(($totals['by_currency'] ?? []) as $code => $parts)
                <span class="ms-3">{{ $code === 'EUR' ? '€' : $code }} credits {{ $ledgerMoney($parts['credit'], $code) }} · debits {{ $ledgerMoney($parts['debit'], $code) }} · net {{ $ledgerMoney($parts['credit'] - $parts['debit'], $code) }}</span>
            @empty
                <span class="text-muted ms-2">No amounts in this filter</span>
            @endforelse
        </div>
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
                            <td class="small">
                                <div>{{ $roleLabel($tx->wallet?->role_id) }}</div>
                                <div class="text-muted text-capitalize">{{ $tx->status ?: '—' }}</div>
                                @if($tx->payment_method)
                                    <div class="text-muted">{{ $tx->payment_method }}</div>
                                @endif
                            </td>
                            <td><span class="badge bg-light text-dark border">{{ $tx->typeLabel() }}</span></td>
                            <td>
                                @if($tx->direction === 'credit')
                                    <span class="text-success small fw-semibold">credit</span>
                                @else
                                    <span class="text-danger small fw-semibold">debit</span>
                                @endif
                            </td>
                            <td class="fw-semibold">{{ $ledgerMoney($tx->amount, $tx->currency) }}</td>
                            <td class="small text-muted">
                                <div>{{ $ledgerMoney($tx->bonus_amount, $tx->currency) }}</div>
                                <div>left {{ $ledgerMoney($tx->bonus_balance_after, $tx->currency) }}</div>
                            </td>
                            <td class="small">
                                <div>{{ $ledgerMoney($tx->balance_after, $tx->currency) }}</div>
                                <div class="text-muted">{{ $roleLabel($tx->wallet?->role_id) }} wallet</div>
                            </td>
                            <td class="small text-muted">
                                <div>
                                    @if($tx->adminRelatedUrl())
                                        <a href="{{ $tx->adminRelatedUrl() }}">{{ $tx->reference }}</a>
                                    @else
                                        {{ $tx->reference }}
                                    @endif
                                </div>
                                <div class="text-truncate" style="max-width:180px" title="{{ $tx->description }}">{{ $tx->description }}</div>
                                @if($tx->metaNote())
                                    <div>{{ $tx->metaNote() }}</div>
                                @endif
                            </td>
                            <td>
                                @if($tx->user_id)
                                    <a href="{{ route('admin.finance.user', $tx->user_id) }}" class="btn btn-sm btn-outline-secondary">Dossier</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-5">No ledger rows match these filters</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $transactions->links() }}</div>
    </div>
</div>
@endsection
