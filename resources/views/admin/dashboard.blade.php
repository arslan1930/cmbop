@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="mb-4">
        <h1 class="h3 mb-1">Ops dashboard</h1>
        <p class="text-muted mb-0">
            Things that need your attention right now.
            @if(($counts['total_attention'] ?? 0) === 0)
                <span class="text-success">You’re all caught up.</span>
            @else
                <span class="text-warning fw-semibold">{{ $counts['total_attention'] }} item{{ $counts['total_attention'] === 1 ? '' : 's' }} waiting.</span>
            @endif
        </p>
    </div>

    {{-- Summary cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <a href="{{ route('admin.deposits', ['status' => 'pending']) }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 ops-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="text-muted small">Pending deposits</div>
                                <div class="display-6 fw-bold text-warning">{{ $counts['pending_deposits'] }}</div>
                                @if($aging['deposits_over_24h'] > 0)
                                    <div class="small text-danger mt-1">{{ $aging['deposits_over_24h'] }} older than 24h</div>
                                @else
                                    <div class="small text-muted mt-1">Wallet top-ups to approve</div>
                                @endif
                            </div>
                            <i class="fa fa-wallet fa-2x text-warning opacity-50"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('admin.withdrawals') }}?status=pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 ops-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="text-muted small">Pending withdrawals</div>
                                <div class="display-6 fw-bold text-danger">{{ $counts['pending_withdrawals'] }}</div>
                                @if($aging['withdrawals_over_24h'] > 0)
                                    <div class="small text-danger mt-1">{{ $aging['withdrawals_over_24h'] }} older than 24h</div>
                                @else
                                    <div class="small text-muted mt-1">Publisher payouts</div>
                                @endif
                            </div>
                            <i class="fa fa-money-bill-wave fa-2x text-danger opacity-50"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('admin.sites.index') }}?verified=0" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 ops-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="text-muted small">Unverified sites</div>
                                <div class="display-6 fw-bold text-primary">{{ $counts['unverified_sites'] }}</div>
                                @if($aging['sites_over_48h'] > 0)
                                    <div class="small text-danger mt-1">{{ $aging['sites_over_48h'] }} older than 48h</div>
                                @else
                                    <div class="small text-muted mt-1">Publisher site reviews</div>
                                @endif
                            </div>
                            <i class="fa fa-globe fa-2x text-primary opacity-50"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('admin.payments') }}?payment_status=pending" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 ops-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="text-muted small">Unpaid orders</div>
                                <div class="display-6 fw-bold text-info">{{ $counts['pending_payments'] }}</div>
                                @if($aging['payments_over_24h'] > 0)
                                    <div class="small text-danger mt-1">{{ $aging['payments_over_24h'] }} older than 24h</div>
                                @else
                                    <div class="small text-muted mt-1">Bank / Wise / crypto</div>
                                @endif
                            </div>
                            <i class="fa fa-file-invoice-dollar fa-2x text-info opacity-50"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Registered users</div>
                        <div class="h4 mb-0">{{ number_format($counts['users']) }}</div>
                    </div>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">View users</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Orders created today</div>
                        <div class="h4 mb-0">{{ number_format($counts['orders_today']) }}</div>
                    </div>
                    <a href="{{ route('admin.payments') }}" class="btn btn-sm btn-outline-secondary">View payments</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- Deposits queue --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-wallet text-warning me-2"></i>Deposit queue</strong>
                    <a href="{{ route('admin.deposits', ['status' => 'pending']) }}" class="small">Open all</a>
                </div>
                <div class="card-body p-0">
                    @if($pendingDeposits->isEmpty())
                        <div class="p-4 text-muted text-center">No pending deposits</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>User</th>
                                        <th>Amount</th>
                                        <th>Age</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pendingDeposits as $deposit)
                                        <tr class="{{ $deposit->created_at->lt(now()->subDay()) ? 'table-warning' : '' }}">
                                            <td>
                                                <div class="fw-semibold">{{ $deposit->user->name ?? 'Unknown' }}</div>
                                                <div class="small text-muted">{{ $deposit->reference_code }}</div>
                                            </td>
                                            <td>€{{ number_format($deposit->amount, 2) }}</td>
                                            <td class="small">{{ $deposit->created_at->diffForHumans() }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.deposits', ['status' => 'pending', 'search' => $deposit->reference_code]) }}" class="btn btn-sm btn-outline-primary">Review</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Withdrawals queue --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-money-bill-wave text-danger me-2"></i>Withdrawal queue</strong>
                    <a href="{{ route('admin.withdrawals') }}?status=pending" class="small">Open all</a>
                </div>
                <div class="card-body p-0">
                    @if($pendingWithdrawals->isEmpty())
                        <div class="p-4 text-muted text-center">No pending withdrawals</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Publisher</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Age</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pendingWithdrawals as $withdrawal)
                                        <tr class="{{ $withdrawal->created_at->lt(now()->subDay()) ? 'table-warning' : '' }}">
                                            <td>
                                                <div class="fw-semibold">{{ $withdrawal->user->name ?? 'Unknown' }}</div>
                                                <div class="small text-muted">{{ ucfirst($withdrawal->payment_method) }}</div>
                                            </td>
                                            <td>€{{ number_format($withdrawal->amount, 2) }}</td>
                                            <td><span class="badge bg-secondary">{{ ucfirst($withdrawal->status) }}</span></td>
                                            <td class="small">{{ $withdrawal->created_at->diffForHumans() }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.withdrawals') }}?status={{ $withdrawal->status }}" class="btn btn-sm btn-outline-primary">Review</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Unverified sites --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-globe text-primary me-2"></i>Unverified sites</strong>
                    <a href="{{ route('admin.sites.index') }}?verified=0" class="small">Open all</a>
                </div>
                <div class="card-body p-0">
                    @if($unverifiedSites->isEmpty())
                        <div class="p-4 text-muted text-center">No sites waiting for verification</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Site</th>
                                        <th>Publisher</th>
                                        <th>Age</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($unverifiedSites as $site)
                                        <tr class="{{ $site->created_at->lt(now()->subDays(2)) ? 'table-warning' : '' }}">
                                            <td>
                                                <div class="fw-semibold">{{ $site->site_name }}</div>
                                                <div class="small text-muted text-truncate" style="max-width:180px;">{{ $site->site_url }}</div>
                                            </td>
                                            <td class="small">{{ $site->publisher->name ?? 'Unknown' }}</td>
                                            <td class="small">{{ $site->created_at->diffForHumans() }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary">Review</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Manual payments --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-file-invoice-dollar text-info me-2"></i>Manual payment queue</strong>
                    <a href="{{ route('admin.payments') }}?payment_status=pending" class="small">Open all</a>
                </div>
                <div class="card-body p-0">
                    @if($pendingPayments->isEmpty())
                        <div class="p-4 text-muted text-center">No unpaid manual orders</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Age</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pendingPayments as $order)
                                        <tr class="{{ $order->created_at->lt(now()->subDay()) ? 'table-warning' : '' }}">
                                            <td>
                                                <div class="fw-semibold">{{ $order->order_number }}</div>
                                                <div class="small text-muted">{{ $order->user->name ?? 'Unknown' }}</div>
                                            </td>
                                            <td>€{{ number_format($order->total_amount, 2) }}</td>
                                            <td class="small">{{ ucfirst($order->payment_method) }}</td>
                                            <td class="small">{{ $order->created_at->diffForHumans() }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.payments') }}?payment_status=pending&search={{ urlencode($order->order_number) }}" class="btn btn-sm btn-outline-primary">Review</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ops-card { transition: transform .15s ease, box-shadow .15s ease; color: inherit; }
.ops-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.08) !important; }
.display-6 { font-size: 2rem; line-height: 1.2; }
body.layout-dark .ops-card,
body.layout-dark .card { background: #1e1e2f; color: #ddd; }
body.layout-dark .card-header { background: #1e1e2f !important; border-color: #333; color: #ddd; }
body.layout-dark .table { color: #ddd; }
body.layout-dark .table-light { --bs-table-bg: #252538; color: #ddd; }
</style>
@endsection
