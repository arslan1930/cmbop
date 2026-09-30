@extends('admin.layouts.app')

@section('content')
@php
    $d = $data;
    $euro = fn ($n) => '€'.number_format((float) $n, 2);
    $keepQuery = fn ($value) => $value !== null && $value !== '';
    $focus = $focus ?? 'due';
    $minWallet = (float) ($minWallet ?? 0);
    $allWallets = ! empty($allWallets);
    $allDebt = ! empty($allDebt);
    $allWithdrawals = ! empty($allWithdrawals);
    $moneyMatches = $moneyMatches ?? collect();
    $sticky = array_filter([
        'date_from' => $dateFrom ?: null,
        'date_to' => $dateTo ?: null,
        'period' => (! $dateFrom && ! $dateTo && in_array($periodKey, ['week', 'month', 'all'], true))
            ? $periodKey
            : null,
        'q' => $userQuery !== '' ? $userQuery : null,
        'min_wallet' => $minWallet > 0 ? $minWallet : null,
        'wallets' => $allWallets ? 'all' : null,
        'debt' => $allDebt ? 'all' : null,
        'withdrawals' => $allWithdrawals ? 'all' : null,
        'focus' => $focus !== 'due' ? $focus : null,
    ], $keepQuery);
    $exportQuery = array_filter([
        'date_from' => $dateFrom ?: null,
        'date_to' => $dateTo ?: null,
        'period' => (! $dateFrom && ! $dateTo && in_array($periodKey, ['week', 'month', 'all'], true))
            ? $periodKey
            : null,
    ], $keepQuery);
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
    $completedGmvUrl = route('admin.payments', array_merge([
        'finance' => 1,
        'date_field' => 'completed_at',
    ], $periodDates));
    $paidGmvUrl = route('admin.payments', array_merge([
        'finance' => 1,
        'date_field' => 'paid_at',
    ], $periodDates));
    $openPayoutUrl = $d['ops']['open_withdrawals']['url'] ?? route('admin.withdrawals', ['queue' => 'open']);
    $reportedDepositsUrl = route('admin.deposits', ['status' => 'pending', 'reported' => 1]);
    $walletListUrl = route('admin.finance', array_merge($sticky, ['wallets' => 'all'])).'#finance-wallets';
    $debtListUrl = route('admin.finance', array_merge($sticky, ['debt' => 'all', 'focus' => 'debt'])).'#finance-debt';
    $wdListUrl = route('admin.finance', array_merge($sticky, ['withdrawals' => 'all', 'focus' => 'due'])).'#finance-due';
    $bonusLedgerUrl = route('admin.finance.ledger', array_filter([
        'type' => 'bonus_credit',
        'date_from' => $periodStart?->toDateString(),
        'date_to' => $periodEnd?->toDateString(),
        'finance' => ($periodStart || $periodEnd) ? 1 : null,
    ]));
    $reservedUrl = route('admin.payments', ['status' => 'processing']);
    $chipLabels = [];
    if ($userQuery !== '') {
        $chipLabels['q'] = 'Search: '.$userQuery;
    }
    if ($dateFrom || $dateTo) {
        $chipLabels['period'] = 'Period: '.$d['period']['label'];
    } elseif ($periodKey !== 'month') {
        $chipLabels['period'] = 'Period: '.$d['period']['label'];
    }
    if ($minWallet > 0) {
        $chipLabels['min_wallet'] = 'Wallets ≥ €'.number_format($minWallet, 2);
    }
    if ($allWallets) {
        $chipLabels['wallets'] = 'All wallets';
    }
    if ($allDebt) {
        $chipLabels['debt'] = 'All debt';
    }
    if ($allWithdrawals) {
        $chipLabels['withdrawals'] = 'All open payouts';
    }
    $strip = [
        [
            'key' => 'due',
            'label' => 'Due now',
            'count' => (int) ($d['ops']['open_withdrawals']['count'] ?? 0),
            'hint' => 'Open withdrawal requests. Approve, then send.',
            'empty' => 'Nothing in the payout queue.',
        ],
        [
            'key' => 'deposits',
            'label' => 'Pending deposits',
            'count' => (int) ($d['ops']['pending_deposits']['count'] ?? 0),
            'hint' => 'Bank/Wise/crypto still waiting for you to mark paid.',
            'empty' => 'No pending deposits.',
        ],
        [
            'key' => 'unpaid',
            'label' => 'Unpaid orders',
            'count' => (int) ($d['ops']['unpaid_orders']['count'] ?? 0),
            'hint' => 'Open orders that are not paid or refunded.',
            'empty' => 'No unpaid orders.',
        ],
        [
            'key' => 'debt',
            'label' => 'Clawback debt',
            'count' => (int) ($d['ops']['publisher_debt']['count'] ?? 0),
            'hint' => 'Publisher wallets blocked until clawback debt is cleared on the dossier.',
            'empty' => 'No outstanding clawback debt.',
        ],
    ];
    $activeStrip = collect($strip)->firstWhere('key', $focus) ?? $strip[0];
    $stickyWithout = function (string $key) use ($sticky) {
        $without = $sticky;
        unset($without[$key]);
        if ($key === 'period') {
            unset($without['date_from'], $without['date_to'], $without['period']);
        }

        return $without;
    };
@endphp
<div class="container-fluid py-3 admin-finance-overview">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 admin-finance-toolbar">
        <div>
            <h4 class="mb-1 fw-bold">Finance overview</h4>
            <p class="text-muted mb-0 small">
                Accounting truth for the period — Completed GMV vs platform fees, cash in bank vs internal wallets, and what you owe publishers.
            </p>
        </div>
        <div class="admin-finance-toolbar d-flex flex-wrap align-items-end gap-2">
            <form method="GET" action="{{ route('admin.finance') }}" class="admin-finance-toolbar__form d-flex flex-nowrap align-items-end gap-2 admin-deposits-filters">
                @foreach($sticky as $queryKey => $queryValue)
                    @if($queryKey !== 'q')
                        <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                    @endif
                @endforeach
                <div class="admin-finance-toolbar__search">
                    <x-slb-search-field
                        name="q"
                        id="adminFinanceUserSearch"
                        :value="$userQuery"
                        placeholder="Name, email, DEP-, WD-, ORD-, or user id…"
                        label="Find user or money"
                        label-class="form-label"
                        input-class="form-control"
                    />
                </div>
                <div class="admin-finance-toolbar__action">
                    <label class="form-label fw-semibold small text-muted mb-1" for="adminFinanceUserOpen">&nbsp;</label>
                    <button type="submit" id="adminFinanceUserOpen" class="btn btn-primary">Open</button>
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

    <form method="GET" action="{{ route('admin.finance') }}" class="card border-0 shadow-sm mb-3 admin-finance-period admin-deposits-filter-card admin-deposits-filters">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                @foreach($sticky as $queryKey => $queryValue)
                    @if(! in_array($queryKey, ['date_from', 'date_to', 'period'], true))
                        <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                    @endif
                @endforeach
                <div class="col-auto">
                    <div class="btn-group btn-group-sm" role="group">
                        @foreach(['week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $key => $label)
                            <a href="{{ route('admin.finance', array_filter(array_merge($sticky, [
                                    'period' => $key,
                                    'date_from' => null,
                                    'date_to' => null,
                                ]), $keepQuery)) }}"
                               class="btn {{ $periodKey === $key && !$dateFrom && !$dateTo ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="adminFinanceDateFrom">From</label>
                    <input type="date" id="adminFinanceDateFrom" name="date_from" value="{{ $dateFrom }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="adminFinanceDateTo">To</label>
                    <input type="date" id="adminFinanceDateTo" name="date_to" value="{{ $dateTo }}" class="form-control">
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

    @if($chipLabels !== [])
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            @foreach($chipLabels as $key => $label)
                <a href="{{ route('admin.finance', $stickyWithout($key)) }}" class="badge rounded-pill text-bg-light border text-decoration-none">{{ $label }} ×</a>
            @endforeach
            <a href="{{ route('admin.finance') }}" class="small">Clear filters</a>
        </div>
    @endif

    @if($userQuery !== '')
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3">
                @if($userQueryTooShort ?? false)
                    <p class="text-muted mb-0 small">Type at least 2 characters to find a user dossier.</p>
                @elseif($userMatches->isEmpty() && $moneyMatches->isEmpty())
                    <p class="text-muted mb-0 small">No users or money records match “{{ $userQuery }}”.</p>
                @else
                    <div class="small text-muted mb-2">
                        @if($hasMoreMatches ?? false)
                            More than {{ $userMatches->count() }} users match “{{ $userQuery }}”
                        @elseif($userMatches->isNotEmpty())
                            {{ $userMatches->count() }} users match “{{ $userQuery }}”
                        @endif
                        @if($moneyMatches->isNotEmpty())
                            @if($userMatches->isNotEmpty()) · @endif
                            {{ $moneyMatches->count() }} money record{{ $moneyMatches->count() === 1 ? '' : 's' }}
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <tbody>
                                @foreach($moneyMatches as $hit)
                                    <tr>
                                        <td class="small">
                                            <span class="text-muted text-uppercase">{{ $hit['type'] }}</span>
                                            <div>{{ $hit['label'] }}</div>
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ $hit['url'] }}" class="btn btn-sm btn-outline-primary">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
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
                        @php
                            $pendingWd = (int) ($d['ops']['open_withdrawals']['pending_count'] ?? 0);
                            $processingWd = (int) ($d['ops']['open_withdrawals']['processing_count'] ?? 0);
                        @endphp
                        @if(($d['ops']['open_withdrawals']['count'] ?? 0) > 0)
                            {{ $pendingWd }} to approve · {{ $processingWd }} to send.
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
                    <a href="{{ $walletListUrl }}" class="btn btn-sm btn-outline-secondary mt-3">Publisher wallets</a>
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
                    @if(($d['liability']['open_reserved_total'] ?? 0) > 0)
                        <a href="{{ $reservedUrl }}" class="small d-block mt-2">Reserved in flight {{ $euro($d['liability']['open_reserved_total']) }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="staff-sites-strip mb-3" aria-label="Finance live queues">
        @foreach($strip as $cell)
            <a href="{{ route('admin.finance', array_filter(array_merge($sticky, ['focus' => $cell['key'] === 'due' ? null : $cell['key']]), $keepQuery)) }}#finance-{{ $cell['key'] }}"
               class="staff-sites-strip__cell {{ $focus === $cell['key'] ? 'is-active' : '' }}"
               @if($focus === $cell['key']) aria-current="page" @endif>
                <span class="staff-sites-strip__count">{{ number_format($cell['count']) }}</span>
                <span class="staff-sites-strip__label">{{ $cell['label'] }}</span>
            </a>
        @endforeach
    </div>
    <p class="small text-muted mb-3">{{ $activeStrip['hint'] }}</p>

    @if(! empty($d['ops']['alerts']))
        <div class="card border-0 shadow-sm mb-3" data-finance-alerts>
            <div class="card-body py-3">
                <div class="small text-muted text-uppercase fw-semibold mb-2">Needs a look</div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($d['ops']['alerts'] as $alert)
                        <a href="{{ $alert['url'] }}" class="badge rounded-pill text-bg-warning text-dark text-decoration-none">
                            {{ $alert['label'] }}
                            @if(isset($alert['count']))
                                · {{ $alert['count'] }}
                            @endif
                            @if(isset($alert['amount']))
                                · {{ $euro($alert['amount']) }}
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-3" id="finance-{{ $focus }}">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>{{ $activeStrip['label'] }}</span>
            @if($focus === 'due' && ($d['liability']['open_withdrawals_total'] ?? 0) > count($d['liability']['open_withdrawal_rows'] ?? []))
                <a href="{{ $allWithdrawals ? $openPayoutUrl : $wdListUrl }}" class="small">{{ count($d['liability']['open_withdrawal_rows'] ?? []) }} of {{ $d['liability']['open_withdrawals_total'] }}</a>
            @elseif($focus === 'deposits')
                <a href="{{ $d['ops']['pending_deposits']['url'] }}" class="small">Open deposits</a>
            @elseif($focus === 'unpaid')
                <a href="{{ $d['ops']['unpaid_orders']['url'] }}" class="small">Open unpaid</a>
            @elseif($focus === 'debt' && ($d['ops']['publisher_debt']['count'] ?? 0) > count($d['ops']['publisher_debt']['rows'] ?? []))
                <a href="{{ $debtListUrl }}" class="small">{{ count($d['ops']['publisher_debt']['rows'] ?? []) }} of {{ $d['ops']['publisher_debt']['count'] }}</a>
            @endif
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle staff-queue-table admin-finance-queue-table">
                @if($focus === 'due')
                    <thead class="table-light"><tr><th>Ref</th><th>Publisher</th><th>Net</th><th class="d-none d-md-table-cell">Work</th><th class="d-none d-md-table-cell">Waiting</th><th></th></tr></thead>
                    <tbody>
                        @forelse(($d['liability']['open_withdrawal_rows'] ?? []) as $row)
                            <tr>
                                <td class="small"><a href="{{ $row['url'] }}">WD-{{ $row['id'] }}</a></td>
                                <td class="small">
                                    <a href="{{ route('admin.finance.user', $row['user_id']) }}">{{ $row['name'] }}</a>
                                    <div class="text-muted">{{ $row['email'] }}</div>
                                </td>
                                <td class="fw-semibold">{{ $euro($row['net_amount']) }}</td>
                                <td class="small d-none d-md-table-cell">{{ $row['status_label'] ?? $row['status'] }}</td>
                                <td class="small d-none d-md-table-cell"><span class="{{ $row['age_class'] ?? 'text-muted' }}" title="{{ $row['age_title'] ?? '' }}">{{ $row['age_label'] ?? '—' }}</span></td>
                                <td class="text-end"><a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">{{ $activeStrip['empty'] }}</td></tr>
                        @endforelse
                    </tbody>
                @elseif($focus === 'deposits')
                    <thead class="table-light"><tr><th>Ref</th><th>Advertiser</th><th>Amount</th><th class="d-none d-md-table-cell">Waiting</th><th></th></tr></thead>
                    <tbody>
                        @forelse(($d['ops']['pending_deposits']['rows'] ?? []) as $row)
                            <tr>
                                <td class="small">
                                    <a href="{{ $row['url'] }}">{{ $row['label'] }}</a>
                                    @if(! empty($row['reported']))
                                        <div class="small text-success">User-reported paid</div>
                                    @endif
                                </td>
                                <td class="small">
                                    <div class="fw-semibold">{{ $row['name'] }}</div>
                                    <div class="text-muted">{{ $row['email'] }}</div>
                                </td>
                                <td class="fw-semibold">{{ $euro($row['amount']) }}</td>
                                <td class="small d-none d-md-table-cell"><span class="{{ $row['age_class'] ?? 'text-muted' }}" title="{{ $row['age_title'] ?? '' }}">{{ $row['age_label'] ?? '—' }}</span></td>
                                <td class="text-end"><a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">{{ $activeStrip['empty'] }}</td></tr>
                        @endforelse
                    </tbody>
                @elseif($focus === 'unpaid')
                    <thead class="table-light"><tr><th>Order</th><th>Advertiser</th><th>Total</th><th class="d-none d-md-table-cell">Waiting</th><th></th></tr></thead>
                    <tbody>
                        @forelse(($d['ops']['unpaid_orders']['rows'] ?? []) as $row)
                            <tr>
                                <td class="small"><a href="{{ $row['url'] }}">{{ $row['label'] }}</a></td>
                                <td class="small">
                                    <div class="fw-semibold">{{ $row['name'] }}</div>
                                    <div class="text-muted">{{ $row['email'] }}</div>
                                </td>
                                <td class="fw-semibold">{{ $euro($row['amount']) }}</td>
                                <td class="small d-none d-md-table-cell"><span class="{{ $row['age_class'] ?? 'text-muted' }}" title="{{ $row['age_title'] ?? '' }}">{{ $row['age_label'] ?? '—' }}</span></td>
                                <td class="text-end"><a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">{{ $activeStrip['empty'] }}</td></tr>
                        @endforelse
                    </tbody>
                @else
                    <thead class="table-light"><tr><th>Publisher</th><th>Debt</th><th class="d-none d-md-table-cell">Waiting</th><th></th></tr></thead>
                    <tbody>
                        @forelse(($d['ops']['publisher_debt']['rows'] ?? []) as $row)
                            <tr>
                                <td class="small">
                                    <a href="{{ $row['url'] }}">{{ $row['name'] }}</a>
                                    <div class="text-muted">{{ $row['email'] }}</div>
                                </td>
                                <td class="fw-semibold text-danger">{{ $euro($row['debt']) }}</td>
                                <td class="small d-none d-md-table-cell"><span class="{{ $row['age_class'] ?? 'text-muted' }}" title="{{ $row['age_title'] ?? '' }}">{{ $row['age_label'] ?? '—' }}</span></td>
                                <td class="text-end"><a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-secondary">Dossier</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">{{ $activeStrip['empty'] }}</td></tr>
                        @endforelse
                    </tbody>
                @endif
            </table>
        </div>
        @if($focus === 'deposits' && ($d['ops']['pending_deposits']['user_marked_paid_count'] ?? 0) > 0)
            <div class="card-body border-top py-2">
                <a href="{{ $reportedDepositsUrl }}" class="small text-success">
                    {{ $d['ops']['pending_deposits']['user_marked_paid_count'] }} user-reported paid
                    ({{ $euro($d['ops']['pending_deposits']['user_marked_paid_amount']) }})
                </a>
            </div>
        @endif
    </div>

    <div class="card border-0 shadow-sm mb-3" id="finance-wallets">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>{{ $minWallet > 0 ? 'Publisher wallets at or above €'.number_format($minWallet, 2) : 'Publisher wallets (how In wallets is built)' }}</span>
            <span class="d-flex flex-wrap align-items-center gap-2">
                @if(($d['liability']['publisher_wallets_total'] ?? 0) > count($d['liability']['top_publisher_wallets'] ?? []))
                    <a href="{{ $walletListUrl }}" class="small">{{ count($d['liability']['top_publisher_wallets'] ?? []) }} of {{ $d['liability']['publisher_wallets_total'] }}</a>
                @endif
                <form method="GET" action="{{ route('admin.finance') }}" class="admin-deposits-filters d-flex align-items-end gap-2">
                    @foreach($sticky as $queryKey => $queryValue)
                        @if($queryKey !== 'min_wallet' && $queryKey !== 'wallets')
                            <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                        @endif
                    @endforeach
                    <input type="hidden" name="wallets" value="all">
                    <label class="form-label mb-0" for="adminFinanceMinWallet">At least €</label>
                    <input type="number" min="0" step="0.01" name="min_wallet" id="adminFinanceMinWallet" value="{{ $minWallet > 0 ? $minWallet : '' }}" class="form-control" style="width:7rem">
                    <button type="submit" class="btn btn-primary">Apply</button>
                    @if($minWallet > 0 || $allWallets)
                        <a href="{{ route('admin.finance', array_filter(array_merge($sticky, ['min_wallet' => null, 'wallets' => null]), $keepQuery)) }}" class="btn btn-outline-secondary">Reset</a>
                    @endif
                </form>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle staff-queue-table admin-finance-queue-table">
                <thead class="table-light">
                    <tr><th>Publisher</th><th>Withdrawable</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse(($d['liability']['top_publisher_wallets'] ?? []) as $row)
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
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">
                                @if($minWallet > 0)
                                    No publisher wallets at or above €{{ number_format($minWallet, 2) }}.
                                @else
                                    No publisher withdrawable balances.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Platform ({{ $d['period']['label'] }})</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-lg">
                    <div class="text-muted small">Completed GMV</div>
                    <div class="fs-5 fw-bold"><a href="{{ $completedGmvUrl }}" class="link-dark">{{ $euro($d['platform']['gmv_completed']) }}</a></div>
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
                            <span class="text-muted small">Paid GMV</span>
                            <strong><a href="{{ $paidGmvUrl }}" class="link-dark">{{ $euro($d['money_in']['orders_paid']['gmv']) }}</a></strong>
                        </div>
                        <div class="small text-muted">
                            Dashboard month GMV is Paid GMV.
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
                        <span><a href="{{ $reservedUrl }}" class="link-dark">{{ $euro($d['liability']['open_reserved_total']) }}</a></span>
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
        <div class="col-md-3"><a href="{{ route('admin.payments', array_merge(['finance' => 1, 'date_field' => 'paid_at'], $periodDates)) }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-money-bill me-1"></i> Order payments</a></div>
        <div class="col-md-3"><a href="{{ $completedDepositsUrl }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-wallet me-1"></i> Deposits</a></div>
        <div class="col-md-3"><a href="{{ $paidWithdrawalsUrl }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-money-bill-wave me-1"></i> Withdrawals</a></div>
        <div class="col-md-3"><a href="{{ route('admin.invoices.index', array_filter(['finance' => 1, 'from' => $periodStart?->toDateString(), 'to' => $periodEnd?->toDateString()])) }}" class="btn btn-outline-secondary w-100 btn-sm"><i class="fa fa-file-invoice-dollar me-1"></i> Invoices</a></div>
    </div>
</div>
@endsection
