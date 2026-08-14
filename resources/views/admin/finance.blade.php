@extends('admin.layouts.app')

@section('content')
@php
    $d = $data;
    $euro = fn ($n) => '€'.number_format((float) $n, 2);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Finance overview</h4>
            <p class="text-muted mb-0 small">
                Accounting truth for the period — GMV vs platform fees, cash in bank vs internal wallets, and what you owe publishers.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.finance.ledger') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-book me-1"></i> Wallet ledger
            </a>
            <a href="{{ route('admin.finance.export', request()->query()) }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-file-csv me-1"></i> Export period CSV
            </a>
        </div>
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <div class="btn-group btn-group-sm" role="group">
                        @foreach(['week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $key => $label)
                            <a href="{{ route('admin.finance', ['period' => $key]) }}"
                               class="btn {{ $periodKey === $key && !$dateFrom && !$dateTo ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-0">From</label>
                    <input type="date" name="date_from" value="{{ scalar_text($dateFrom) }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-0">To</label>
                    <input type="date" name="date_to" value="{{ scalar_text($dateTo) }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary">Apply range</button>
                </div>
                <div class="col-auto ms-auto">
                    <span class="badge bg-light text-dark border">Period: {{ $d['period']['label'] }}</span>
                </div>
            </div>
        </div>
    </form>

    {{-- Payout liability (split so ops is not confused) --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold">Due to pay now</div>
                    <div class="fs-2 fw-bold text-danger">{{ $euro($d['due_to_pay_now']) }}</div>
                    <div class="small text-muted mt-1">
                        Open withdrawal requests only (payout queue).
                        @if(($d['ops']['open_withdrawals']['count'] ?? 0) > 0)
                            {{ $d['ops']['open_withdrawals']['count'] }} request{{ $d['ops']['open_withdrawals']['count'] === 1 ? '' : 's' }}.
                        @else
                            Nothing waiting in the payout queue.
                        @endif
                    </div>
                    <a href="{{ route('admin.withdrawals') }}" class="btn btn-sm btn-outline-danger mt-3">Open payout queue</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold">In publisher wallets</div>
                    <div class="fs-2 fw-bold">{{ $euro($d['in_publisher_wallets']) }}</div>
                    <div class="small text-muted mt-1">
                        Earned but not withdrawn yet — not a payout task until they request it.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold">Total publisher liability</div>
                    <div class="fs-2 fw-bold">{{ $euro($d['total_publisher_liability']) }}</div>
                    <div class="small text-muted mt-1">
                        Due now {{ $euro($d['due_to_pay_now']) }}
                        + wallets {{ $euro($d['in_publisher_wallets']) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($d['liability']['open_withdrawal_rows']) || !empty($d['liability']['top_publisher_wallets']))
        <div class="row g-3 mb-3">
            @if(!empty($d['liability']['open_withdrawal_rows']))
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">Open withdrawals (how Due to pay now is built)</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Ref</th><th>Publisher</th><th>Net</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($d['liability']['open_withdrawal_rows'] as $row)
                                        <tr>
                                            <td class="small">WD-{{ $row['id'] }}</td>
                                            <td class="small">
                                                <a href="{{ route('admin.finance.user', $row['user_id']) }}">{{ $row['name'] }}</a>
                                                <div class="text-muted">{{ $row['email'] }}</div>
                                            </td>
                                            <td class="fw-semibold">{{ $euro($row['net_amount']) }}</td>
                                            <td class="small text-capitalize">{{ $row['status'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
            @if(!empty($d['liability']['top_publisher_wallets']))
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">Publisher wallets (how In wallets is built)</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Publisher</th><th>Withdrawable</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @foreach($d['liability']['top_publisher_wallets'] as $row)
                                        <tr>
                                            <td class="small">
                                                <div class="fw-semibold">{{ $row['name'] }}</div>
                                                <div class="text-muted">{{ $row['email'] }}</div>
                                            </td>
                                            <td class="fw-semibold">{{ $euro($row['withdrawable']) }}</td>
                                            <td class="text-end">
                                                <a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-secondary">Dossier</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Ops queues --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <a href="{{ $d['ops']['pending_deposits']['url'] }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Pending deposits</div>
                        <div class="fs-4 fw-bold text-warning">{{ $d['ops']['pending_deposits']['count'] }}</div>
                        <div class="small">{{ $euro($d['ops']['pending_deposits']['amount']) }}</div>
                        @if($d['ops']['pending_deposits']['user_marked_paid_count'] > 0)
                            <div class="small text-success mt-1">
                                {{ $d['ops']['pending_deposits']['user_marked_paid_count'] }} user-reported paid
                                ({{ $euro($d['ops']['pending_deposits']['user_marked_paid_amount']) }})
                            </div>
                        @endif
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ $d['ops']['open_withdrawals']['url'] }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Open withdrawals</div>
                        <div class="fs-4 fw-bold text-danger">{{ $d['ops']['open_withdrawals']['count'] }}</div>
                        <div class="small">{{ $euro($d['ops']['open_withdrawals']['amount']) }}</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ $d['ops']['unpaid_orders']['url'] }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Unpaid orders</div>
                        <div class="fs-4 fw-bold text-info">{{ $d['ops']['unpaid_orders']['count'] }}</div>
                        <div class="small">{{ $euro($d['ops']['unpaid_orders']['amount']) }}</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    {{-- Platform truth --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Platform ({{ $d['period']['label'] }})</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg">
                    <div class="text-muted small">GMV (completed paid)</div>
                    <div class="fs-5 fw-bold">{{ $euro($d['platform']['gmv_completed']) }}</div>
                    <div class="small text-muted">What advertisers paid on completed orders</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Order platform fees</div>
                    <div class="fs-5 fw-bold text-success">{{ $euro($d['platform']['order_fees']) }}</div>
                    <div class="small text-muted">Your real product revenue</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Withdrawal fees</div>
                    <div class="fs-5 fw-bold">{{ $euro($d['platform']['withdrawal_fees']) }}</div>
                    <div class="small text-muted">Config {{ rtrim(rtrim(number_format($d['platform']['withdrawal_fee_percent'], 2), '0'), '.') }}%</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Refunds</div>
                    <div class="fs-5 fw-bold text-danger">{{ $euro($d['platform']['refunds']) }}</div>
                    <div class="small text-muted">{{ $d['platform']['refund_orders_count'] }} orders · wallet refunds {{ $euro($d['platform']['wallet_refunds']) }}</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Bonuses issued</div>
                    <div class="fs-5 fw-bold">{{ $euro($d['platform']['bonuses_issued']) }}</div>
                    <div class="small text-muted">Promo cost (not cash)</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Est. margin</div>
                    <div class="fs-5 fw-bold {{ $d['platform']['margin'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $euro($d['platform']['margin']) }}</div>
                    <div class="small text-muted">Fees − refunds − bonuses<br><span class="fst-italic">Stripe fees not tracked</span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        {{-- Money in --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Money in · Advertisers</div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Deposits completed</span>
                            <strong>{{ $euro($d['money_in']['deposits_completed']['amount']) }}</strong>
                        </div>
                        <div class="small text-muted">{{ $d['money_in']['deposits_completed']['count'] }} requests · Stripe {{ $euro($d['money_in']['deposits_completed']['stripe']) }} · Manual {{ $euro($d['money_in']['deposits_completed']['manual']) }}</div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Orders GMV (paid)</span>
                            <strong>{{ $euro($d['money_in']['orders_paid']['gmv']) }}</strong>
                        </div>
                        <div class="small text-muted">
                            Card {{ $euro($d['money_in']['orders_paid']['stripe_card']) }} ·
                            Wallet {{ $euro($d['money_in']['orders_paid']['wallet']) }} ·
                            Manual {{ $euro($d['money_in']['orders_paid']['manual']) }}
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Bonuses issued</span>
                            <strong>{{ $euro($d['money_in']['bonuses_issued']['amount']) }}</strong>
                        </div>
                        <div class="small text-muted">Welcome / promo — spend only</div>
                    </div>
                    <a href="{{ route('admin.deposits') }}" class="btn btn-sm btn-outline-secondary mt-3 w-100">Deposits</a>
                </div>
            </div>
        </div>

        {{-- Money out --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Money out · Publishers</div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Earnings credited</span>
                            <strong>{{ $euro($d['money_out']['earnings_credited']['amount']) }}</strong>
                        </div>
                        <div class="small text-muted">{{ $d['money_out']['earnings_credited']['count'] }} line items · ledger transfer-in {{ $euro($d['money_out']['earnings_credited']['ledger_transfer_in']) }}</div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Withdrawals paid (net)</span>
                            <strong>{{ $euro($d['money_out']['withdrawals_paid']['net']) }}</strong>
                        </div>
                        <div class="small text-muted">{{ $d['money_out']['withdrawals_paid']['count'] }} payouts · fees kept {{ $euro($d['money_out']['withdrawals_paid']['fees']) }}</div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Open withdrawals</span>
                            <strong class="text-danger">{{ $euro($d['money_out']['withdrawals_open']['net']) }}</strong>
                        </div>
                        <div class="small text-muted">{{ $d['money_out']['withdrawals_open']['count'] }} waiting to send</div>
                    </div>
                    <a href="{{ route('admin.withdrawals') }}" class="btn btn-sm btn-outline-secondary mt-3 w-100">Payout queue</a>
                </div>
            </div>
        </div>

        {{-- Cash split + liability --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Cash vs internal</div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Cash into your accounts</span>
                            <strong class="text-success">{{ $euro($d['cash_split']['cash_in_bank']) }}</strong>
                        </div>
                        <div class="small text-muted">Stripe/card + bank/Wise/crypto deposits &amp; manual order pays</div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Internal only</span>
                            <strong>{{ $euro($d['cash_split']['internal_only']) }}</strong>
                        </div>
                        <div class="small text-muted">Wallet checkouts + bonuses</div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Cash out (payouts)</span>
                            <strong>{{ $euro($d['cash_split']['cash_out_payouts']) }}</strong>
                        </div>
                    </div>
                    <hr>
                    <div class="small fw-semibold mb-2">Wallet liability (live)</div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Advertiser cash</span>
                        <span>{{ $euro($d['liability']['advertiser']['cash']) }}</span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Advertiser bonus</span>
                        <span>{{ $euro($d['liability']['advertiser']['bonus']) }}</span>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Reserved (in flight)</span>
                        <span>{{ $euro($d['liability']['open_reserved_total']) }}</span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-muted">Publisher withdrawable</span>
                        <span class="fw-semibold">{{ $euro($d['liability']['publisher']['withdrawable']) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-md-3"><a href="{{ route('admin.payments') }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-money-bill me-1"></i> Order payments</a></div>
        <div class="col-md-3"><a href="{{ route('admin.deposits') }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-wallet me-1"></i> Deposits</a></div>
        <div class="col-md-3"><a href="{{ route('admin.withdrawals') }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-money-bill-wave me-1"></i> Withdrawals</a></div>
        <div class="col-md-3"><a href="{{ route('admin.invoices.index') }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-file-invoice-dollar me-1"></i> Invoices</a></div>
    </div>
</div>
@endsection
