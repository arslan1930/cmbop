@extends('admin.layouts.app')

@section('content')
@php
    $d = $data;
    $euro = fn ($n) => '€'.number_format((float) $n, 2);
    $keepQuery = fn ($value) => $value !== null && $value !== '';
    $exportQuery = array_filter([
        'date_from' => $dateFrom ?: null,
        'date_to' => $dateTo ?: null,
        'period' => (! $dateFrom && ! $dateTo && in_array($periodKey, ['week', 'month', 'all'], true))
            ? $periodKey
            : null,
    ], $keepQuery);
    $financeQuery = $exportQuery;
    if ($userQuery !== '') {
        $financeQuery['q'] = $userQuery;
    }
    $periodStart = $d['period']['start'] ?? null;
    $periodEnd = $periodStart ? ($d['period']['end'] ?? null) : null;
    $periodDates = array_filter([
        'date_from' => $periodStart?->toDateString(),
        'date_to' => $periodEnd?->toDateString(),
    ]);
    $depositPaypal = (float) ($d['money_in']['deposits_completed']['by_method']['paypal']['amount'] ?? 0);
    $orderPaypal = (float) ($d['money_in']['orders_paid']['by_method']['paypal']['amount'] ?? 0);
    $paidWithdrawalsUrl = route('admin.withdrawals', array_merge([
        'queue' => 'history',
        'status' => 'completed',
        'finance' => 1,
    ], $periodDates));
    $completedDepositsUrl = route('admin.deposits', array_filter([
        'status' => 'completed',
        'finance' => 1,
        'from' => $periodStart?->toDateString(),
        'to' => $periodEnd?->toDateString(),
    ]));
    $gmvUrl = route('admin.payments', array_merge([
        'finance' => 1,
        'date_field' => 'completed_at',
    ], $periodDates));
    $openPayoutUrl = $d['ops']['open_withdrawals']['url'];
    $reportedDepositsUrl = route('admin.deposits', ['status' => 'pending', 'reported' => 1]);
    $walletListUrl = route('admin.finance', array_merge($financeQuery, ['wallets' => 'all']));
    $debtListUrl = route('admin.finance', array_merge($financeQuery, ['debt' => 'all'])).'#finance-debt';
    $bonusLedgerUrl = route('admin.finance.ledger', array_filter([
        'type' => 'bonus_credit',
        'date_from' => $periodStart?->toDateString(),
        'date_to' => $periodEnd?->toDateString(),
        'finance' => ($periodStart || $periodEnd) ? 1 : null,
    ]));
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Finance overview</h4>
            <p class="text-muted mb-0 small">
                Accounting truth for the period — GMV vs platform fees, cash in bank vs internal wallets, and what you owe publishers.
            </p>
        </div>
        <div class="admin-finance-toolbar d-flex flex-wrap align-items-end gap-2">
            <form method="GET" action="{{ route('admin.finance') }}" class="admin-finance-toolbar__form d-flex flex-nowrap align-items-end gap-2">
                @if($dateFrom)
                    <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                @endif
                @if($dateTo)
                    <input type="hidden" name="date_to" value="{{ $dateTo }}">
                @endif
                @if(! $dateFrom && ! $dateTo && in_array($periodKey, ['week', 'month', 'all'], true))
                    <input type="hidden" name="period" value="{{ $periodKey }}">
                @endif
                <div class="admin-finance-toolbar__search">
                    <x-slb-search-field
                        name="q"
                        id="adminFinanceUserSearch"
                        :value="$userQuery"
                        placeholder="Name, email, company, payout, or user id…"
                        label="Find user dossier"
                    />
                </div>
                <div class="admin-finance-toolbar__action">
                    <label class="form-label fw-semibold small text-muted mb-1" for="adminFinanceUserOpen">&nbsp;</label>
                    <button type="submit" id="adminFinanceUserOpen" class="btn btn-sm btn-outline-primary">Open</button>
                </div>
            </form>
            <div class="admin-finance-toolbar__action">
                <span class="form-label fw-semibold small text-muted mb-1" aria-hidden="true">&nbsp;</span>
                <a id="adminFinanceWalletLedger" href="{{ route('admin.finance.ledger') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-book me-1"></i> Wallet ledger
                </a>
            </div>
            <div class="admin-finance-toolbar__action">
                <span class="form-label fw-semibold small text-muted mb-1" aria-hidden="true">&nbsp;</span>
                <a id="adminFinanceExport" href="{{ route('admin.finance.export', $exportQuery) }}" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-file-csv me-1"></i> Export period CSV
                </a>
            </div>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.finance') }}" class="card border-0 shadow-sm mb-3 admin-finance-period">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                @if($userQuery !== '')
                    <input type="hidden" name="q" value="{{ $userQuery }}">
                @endif
                @if(! $dateFrom && ! $dateTo && in_array($periodKey, ['week', 'month', 'all'], true))
                    <input type="hidden" name="period" value="{{ $periodKey }}">
                @endif
                <div class="col-auto">
                    <div class="btn-group btn-group-sm" role="group">
                        @foreach(['week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $key => $label)
                            <a href="{{ route('admin.finance', array_filter([
                                    'period' => $key,
                                    'q' => $userQuery !== '' ? $userQuery : null,
                                ], $keepQuery)) }}"
                               class="btn {{ $periodKey === $key && !$dateFrom && !$dateTo ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="adminFinanceDateFrom">From</label>
                    <input type="date" id="adminFinanceDateFrom" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="adminFinanceDateTo">To</label>
                    <input type="date" id="adminFinanceDateTo" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto admin-finance-period__action">
                    <label class="form-label small text-muted mb-1" for="adminFinanceApplyRange">&nbsp;</label>
                    <button type="submit" id="adminFinanceApplyRange" class="btn btn-sm btn-primary">Apply range</button>
                </div>
                <div class="col-auto ms-auto">
                    <span class="badge bg-light text-dark border">Period: {{ $d['period']['label'] }}</span>
                </div>
            </div>
        </div>
    </form>

    @if($userQuery !== '')
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                @if($userQueryTooShort ?? false)
                    <p class="text-muted mb-0 small">Type at least 2 characters to find a user dossier.</p>
                @elseif($userMatches->isEmpty())
                    <p class="text-muted mb-0 small">No users match “{{ $userQuery }}”.</p>
                @else
                    <div class="small text-muted mb-2">
                        @if($hasMoreMatches ?? false)
                            More than {{ $userMatches->count() }} users match “{{ $userQuery }}”
                        @else
                            {{ $userMatches->count() }} users match “{{ $userQuery }}”
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <tbody>
                                @foreach($userMatches as $match)
                                    <tr>
                                        <td class="small">
                                            <a href="{{ route('admin.finance.user', $match) }}">{{ $match->name }}</a>
                                            <div class="text-muted">{{ $match->email }}</div>
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('admin.finance.user', $match) }}" class="btn btn-sm btn-outline-secondary">Dossier</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Payout liability is live. The period selector does not change these three. --}}
    <div class="d-flex justify-content-between align-items-baseline mb-2">
        <h2 class="h6 mb-0">Right now</h2>
        <span class="small text-muted">Not affected by the period above</span>
    </div>
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
                    <a href="{{ $openPayoutUrl }}" class="btn btn-sm btn-outline-danger mt-3">Open payout queue</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold">In publisher wallets</div>
                    <div class="fs-2 fw-bold">{{ $euro($d['in_publisher_wallets']) }}</div>
                    <div class="small text-muted mt-1">
                        Euro balances earned but not withdrawn yet — not a payout task until they request it.
                    </div>
                    <a href="{{ $walletListUrl }}#finance-wallets" class="btn btn-sm btn-outline-secondary mt-3">Publisher wallets</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-semibold">Total publisher liability</div>
                    <div class="fs-2 fw-bold">{{ $euro($d['total_publisher_liability']) }}</div>
                    <div class="small text-muted mt-1">
                        Euro ledger. Due now {{ $euro($d['due_to_pay_now']) }}
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
                        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                            <span>Open withdrawals (how Due to pay now is built)</span>
                            @if(($d['liability']['open_withdrawals_total'] ?? 0) > count($d['liability']['open_withdrawal_rows']))
                                <a href="{{ $openPayoutUrl }}" class="small">{{ count($d['liability']['open_withdrawal_rows']) }} of {{ $d['liability']['open_withdrawals_total'] }}</a>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Ref</th><th>Publisher</th><th>Net</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($d['liability']['open_withdrawal_rows'] as $row)
                                        <tr>
                                            <td class="small">
                                                <a href="{{ $row['url'] }}">WD-{{ $row['id'] }}</a>
                                            </td>
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
                        <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2" id="finance-wallets">
                            <span>{{ ($minWallet ?? 0) > 0 ? 'Publisher wallets at or above €'.number_format((float) $minWallet, 2) : 'Publisher wallets (how In wallets is built)' }}</span>
                            <span class="d-flex align-items-center gap-2">
                                @if(($d['liability']['publisher_wallets_total'] ?? 0) > count($d['liability']['top_publisher_wallets']))
                                    <a href="{{ $walletListUrl }}#finance-wallets" class="small">{{ count($d['liability']['top_publisher_wallets']) }} of {{ $d['liability']['publisher_wallets_total'] }}</a>
                                @endif
                                <form method="GET" action="{{ route('admin.finance') }}" class="d-flex align-items-center gap-1">
                                    @foreach($financeQuery as $queryKey => $queryValue)
                                        <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                                    @endforeach
                                    <input type="hidden" name="wallets" value="all">
                                    <label class="small text-muted mb-0" for="adminFinanceMinWallet">At least €</label>
                                    <input type="number" min="0" step="0.01" name="min_wallet" id="adminFinanceMinWallet" value="{{ ($minWallet ?? 0) > 0 ? $minWallet : '' }}" class="form-control form-control-sm" style="width:6rem">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
                                </form>
                            </span>
                        </div>
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
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <a href="{{ $d['ops']['pending_deposits']['url'] }}" class="text-decoration-none">
                        <div class="text-muted small">Pending deposits</div>
                        <div class="fs-4 fw-bold text-warning">{{ $d['ops']['pending_deposits']['count'] }}</div>
                        <div class="small">{{ $euro($d['ops']['pending_deposits']['amount']) }} euros</div>
                        @foreach(($d['ops']['pending_deposits']['charges'] ?? []) as $chargeCode => $chargeAmount)
                            <div class="small">{{ $chargeCode }} {{ number_format((float) $chargeAmount, 2) }}</div>
                        @endforeach
                    </a>
                    @if($d['ops']['pending_deposits']['user_marked_paid_count'] > 0)
                        <a href="{{ $reportedDepositsUrl }}" class="small text-success d-block mt-1">
                            {{ $d['ops']['pending_deposits']['user_marked_paid_count'] }} user-reported paid
                            ({{ $euro($d['ops']['pending_deposits']['user_marked_paid_amount']) }})
                        </a>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
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
        <div class="col-md-3">
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
        <div class="col-md-3" id="finance-debt">
            <a href="{{ $debtListUrl }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 {{ ($d['ops']['publisher_debt']['amount'] ?? 0) > 0 ? 'border-start border-4 border-danger' : '' }}">
                    <div class="card-body">
                        <div class="text-muted small">Clawback debt</div>
                        <div class="fs-4 fw-bold {{ ($d['ops']['publisher_debt']['amount'] ?? 0) > 0 ? 'text-danger' : '' }}">{{ $euro($d['ops']['publisher_debt']['amount'] ?? 0) }}</div>
                        <div class="small">
                            @if(($d['ops']['publisher_debt']['count'] ?? 0) > 0)
                                {{ $d['ops']['publisher_debt']['count'] }} publisher wallet{{ $d['ops']['publisher_debt']['count'] === 1 ? '' : 's' }} blocked
                            @else
                                No outstanding clawback debt
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    @if(!empty($d['ops']['publisher_debt']['rows']))
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Publishers with clawback debt</span>
                @if(($d['ops']['publisher_debt']['count'] ?? 0) > count($d['ops']['publisher_debt']['rows']))
                    <a href="{{ $debtListUrl }}" class="small">{{ count($d['ops']['publisher_debt']['rows']) }} of {{ $d['ops']['publisher_debt']['count'] }}</a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Publisher</th><th>Debt</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($d['ops']['publisher_debt']['rows'] as $row)
                            <tr>
                                <td class="small">
                                    <a href="{{ $row['url'] }}">{{ $row['name'] }}</a>
                                    <div class="text-muted">{{ $row['email'] }}</div>
                                </td>
                                <td class="fw-semibold text-danger">{{ $euro($row['debt']) }}</td>
                                <td class="text-end">
                                    <a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-secondary">Dossier</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Platform truth --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Platform ({{ $d['period']['label'] }})</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg">
                    <div class="text-muted small">GMV (completed paid)</div>
                    <div class="fs-5 fw-bold"><a href="{{ $gmvUrl }}" class="link-dark">{{ $euro($d['platform']['gmv_completed']) }}</a></div>
                    <div class="small text-muted">What advertisers paid on completed orders · Dated by completed date</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Order platform fees</div>
                    <div class="fs-5 fw-bold text-success">{{ $euro($d['platform']['order_fees']) }}</div>
                    <div class="small text-muted">Recognized on completed lines · later clawbacks reverse that line’s fee on the refund date</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Withdrawal fees</div>
                    <div class="fs-5 fw-bold">{{ $euro($d['platform']['withdrawal_fees']) }}</div>
                    <div class="small text-muted">Config {{ rtrim(rtrim(number_format($d['platform']['withdrawal_fee_percent'], 2), '0'), '.') }}%</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Refunds (order totals)</div>
                    <div class="fs-5 fw-bold text-danger">{{ $euro($d['platform']['refunds']) }}</div>
                    <div class="small text-muted">{{ $d['platform']['refund_orders_count'] }} orders · fee reversals {{ $euro($d['platform']['refunded_order_fees'] ?? 0) }} · wallet refunds {{ $euro($d['platform']['wallet_refunds']) }} · Dated by refund date</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Bonuses issued</div>
                    <div class="fs-5 fw-bold"><a href="{{ $bonusLedgerUrl }}" class="link-dark">{{ $euro($d['platform']['bonuses_issued']) }}</a></div>
                    <div class="small text-muted">Promo cost (not cash)</div>
                </div>
                <div class="col-6 col-lg">
                    <div class="text-muted small">Est. fee margin</div>
                    <div class="fs-5 fw-bold {{ $d['platform']['margin'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $euro($d['platform']['margin']) }}</div>
                    <div class="small text-muted">Fees − fee reversals − bonuses<br><span class="fst-italic">Stripe fees not tracked</span></div>
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
                        <div class="small text-muted">{{ $d['money_in']['deposits_completed']['count'] }} requests · Stripe {{ $euro($d['money_in']['deposits_completed']['stripe']) }} · PayPal {{ $euro($depositPaypal) }} · Manual {{ $euro($d['money_in']['deposits_completed']['manual']) }} · Euro ledger · Dated by approved date</div>
                        @foreach(($d['money_in']['collected']['deposits'] ?? []) as $chargeCode => $chargeAmount)
                            <div class="small text-muted">Charged {{ $chargeCode }} {{ number_format((float) $chargeAmount, 2) }}</div>
                        @endforeach
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Orders GMV (paid)</span>
                            <strong>{{ $euro($d['money_in']['orders_paid']['gmv']) }}</strong>
                        </div>
                        <div class="small text-muted">
                            Card {{ $euro($d['money_in']['orders_paid']['stripe_card']) }} ·
                            PayPal {{ $euro($orderPaypal) }} ·
                            Wallet {{ $euro($d['money_in']['orders_paid']['wallet']) }} ·
                            Manual {{ $euro($d['money_in']['orders_paid']['manual']) }}
                            · Dated by paid date
                        </div>
                    </div>
                    @if(($d['money_in']['unfulfilled_card_credits'] ?? 0) > 0)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Leftover card credits</span>
                            <strong>{{ $euro($d['money_in']['unfulfilled_card_credits']) }}</strong>
                        </div>
                        <div class="small text-muted">Stripe captured, listing left the catalog — credited to advertiser wallet</div>
                    </div>
                    @endif
                    <div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Bonuses issued</span>
                            <strong><a href="{{ $bonusLedgerUrl }}" class="link-dark">{{ $euro($d['money_in']['bonuses_issued']['amount']) }}</a></strong>
                        </div>
                        <div class="small text-muted">Welcome / promo — spend only</div>
                    </div>
                    <a href="{{ $completedDepositsUrl }}" class="btn btn-sm btn-outline-secondary mt-3 w-100">Deposits in this period</a>
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
                        <div class="small text-muted">{{ $d['money_out']['earnings_credited']['count'] }} line items · ledger net {{ $euro($d['money_out']['earnings_credited']['ledger_transfer_in']) }} · Dated by completed date, clawbacks / refunds by refund date</div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Withdrawals paid (net)</span>
                            <strong>{{ $euro($d['money_out']['withdrawals_paid']['net']) }}</strong>
                        </div>
                        <div class="small text-muted">{{ $d['money_out']['withdrawals_paid']['count'] }} payouts · fees kept {{ $euro($d['money_out']['withdrawals_paid']['fees']) }} · Euro nets · Dated by processed date</div>
                    </div>
                    <div class="small text-muted">Open withdrawals are under Right now, not this period.</div>
                    <a href="{{ $paidWithdrawalsUrl }}" class="btn btn-sm btn-outline-secondary mt-3 w-100">Payouts in this period</a>
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
                        <div class="small text-muted">Euro ledger: Stripe/card + PayPal + bank/Wise/crypto. Wallet refunds do not remove collected cash.</div>
                        @foreach(($d['money_in']['collected']['by_currency'] ?? []) as $chargeCode => $chargeParts)
                            <div class="small text-muted">Collected {{ $chargeCode }}
                                @if(($chargeParts['card'] ?? 0) != 0) · Card {{ number_format((float) $chargeParts['card'], 2) }} @endif
                                @if(($chargeParts['paypal'] ?? 0) != 0) · PayPal {{ number_format((float) $chargeParts['paypal'], 2) }} @endif
                                @if(($chargeParts['other'] ?? 0) != 0) · Bank/Wise/crypto {{ number_format((float) $chargeParts['other'], 2) }} @endif
                            </div>
                        @endforeach
                        @if(($d['money_in']['collected']['orders_not_recorded'] ?? 0) > 0)
                            <div class="small text-muted">{{ $d['money_in']['collected']['orders_not_recorded'] }} card or PayPal orders with charge not recorded</div>
                        @endif
                        @if(($d['money_in']['collected']['features_not_recorded'] ?? 0) > 0)
                            <div class="small text-muted">{{ $d['money_in']['collected']['features_not_recorded'] }} featured-site charges not recorded</div>
                        @endif
                        @if(($d['money_in']['site_feature_stripe'] ?? 0) > 0)
                            <div class="small text-muted">Featured-site Stripe {{ $euro($d['money_in']['site_feature_stripe']) }}</div>
                        @endif
                        @if(($d['money_in']['unfulfilled_card_credits'] ?? 0) > 0)
                            <div class="small text-muted">Leftover card credits {{ $euro($d['money_in']['unfulfilled_card_credits']) }}</div>
                        @endif
                        @if(($d['money_in']['failed_external_collected'] ?? 0) > 0)
                            <div class="small text-muted">Paid then failed captures {{ $euro($d['money_in']['failed_external_collected']) }}</div>
                        @endif
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
                    <div class="small fw-semibold mb-2">Wallet liability (live, euros)</div>
                    @foreach(($d['liability']['other_currencies'] ?? []) as $otherWallet)
                        <div class="small text-muted">{{ $otherWallet['currency'] }} wallets {{ number_format((float) $otherWallet['balance'], 2) }} ({{ $otherWallet['count'] }}) — left out of the euro totals</div>
                    @endforeach
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
        <div class="col-md-3"><a href="{{ route('admin.invoices.index', array_filter(['finance' => 1, 'from' => $periodStart?->toDateString(), 'to' => $periodEnd?->toDateString()])) }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-file-invoice-dollar me-1"></i> Invoices</a></div>
    </div>
</div>
@endsection
