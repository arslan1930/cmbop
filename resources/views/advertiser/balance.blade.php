{{-- resources/views/advertiser/balance.blade.php --}}
@extends('advertiser.layouts.app')

@section('title', 'Wallet & Balance')

@section('content')
@php
    $summary = $summary ?? [];
    $available = (float) ($summary['available_balance'] ?? $advertiserWithdrawableBalance ?? 0);
    $bonus = (float) ($summary['bonus_balance'] ?? $advertiserBonusBalance ?? 0);
    $pending = (float) ($summary['pending_balance'] ?? 0);
    $spendable = (float) ($summary['spendable_balance'] ?? $advertiserBalance ?? 0);
    $lifetimeDeposits = (float) ($summary['lifetime_deposits'] ?? 0);
    $lifetimeSpending = (float) ($summary['lifetime_spending'] ?? 0);
    $lifetimeWithdrawals = (float) ($summary['lifetime_withdrawals'] ?? 0);
    $pendingWithdrawals = (float) ($summary['pending_withdrawals'] ?? 0);
    $bonusReceived = (float) ($summary['bonus_received'] ?? $bonus);
    $bonusUsed = (float) ($summary['bonus_used'] ?? 0);
    $canWithdraw = $available > 0;
@endphp

<style>
.wallet-kpi {
    display: flex; align-items: flex-start; gap: 14px; width: 100%;
    padding: 16px 18px; border: 1px solid #e5eef0; border-radius: 12px;
    background: #fff; color: inherit; height: 100%;
    transition: border-color .2s ease, background .2s ease, box-shadow .2s ease, transform .2s ease;
}
.wallet-kpi:hover {
    border-color: #4ECDCB; background: #f0fbfb;
    box-shadow: 0 8px 20px rgba(11, 98, 102, 0.08);
    transform: translateY(-2px);
}
.wallet-kpi .kpi-icon {
    width: 44px; height: 44px; border-radius: 12px; display: flex;
    align-items: center; justify-content: center; color: #fff; flex-shrink: 0;
}
.wallet-kpi .kpi-icon--available { background: linear-gradient(135deg, #0b6266, #3aaeb2); }
.wallet-kpi .kpi-icon--bonus { background: linear-gradient(135deg, #f59e0b, #d97706); }
.wallet-kpi .kpi-icon--pending { background: linear-gradient(135deg, #94a3b8, #64748b); }
.wallet-kpi .kpi-icon--deposits { background: linear-gradient(135deg, #10b981, #059669); }
.wallet-kpi .kpi-icon--spending { background: linear-gradient(135deg, #6366f1, #4f46e5); }
.wallet-kpi .kpi-icon--withdrawals { background: linear-gradient(135deg, #ef4444, #dc2626); }
.wallet-kpi .kpi-icon--pending-wd { background: linear-gradient(135deg, #f97316, #ea580c); }
.wallet-kpi .kpi-label { font-size: 12px; color: #6b7280; display: block; font-weight: 600; letter-spacing: .01em; }
.wallet-kpi .kpi-value { font-size: 1.45rem; font-weight: 700; color: #0b6266; line-height: 1.15; }
.wallet-kpi .kpi-desc { font-size: 12px; color: #94a3b8; margin-top: 4px; display: block; }

.wallet-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.wallet-actions .btn { border-radius: 10px; }

.wallet-chart-card, .wallet-panel {
    border: 0; border-radius: 12px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.wallet-panel .card-header {
    background: #fff; border-bottom: 1px solid #eef2f5;
    border-radius: 12px 12px 0 0 !important;
}

.wallet-type-icon {
    width: 34px; height: 34px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    background: #e8f8f7; color: #0b6266; font-size: 13px;
}
.wallet-type-icon.is-debit { background: #fee2e2; color: #dc2626; }
.wallet-type-icon.is-bonus { background: #fef3c7; color: #d97706; }

.wallet-amount-credit { color: #059669; font-weight: 700; }
.wallet-amount-debit { color: #dc2626; font-weight: 700; }

.wallet-status {
    padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
    display: inline-block; text-transform: capitalize;
}
.wallet-status--completed, .wallet-status--paid, .wallet-status--approved { background: #d1fae5; color: #065f46; }
.wallet-status--pending, .wallet-status--processing { background: #fef3c7; color: #92400e; }
.wallet-status--cancelled, .wallet-status--rejected, .wallet-status--failed { background: #fee2e2; color: #991b1b; }

.wallet-quick-amt {
    border: 1px solid #e5eef0; background: #fff; border-radius: 10px;
    padding: 10px 12px; font-weight: 600; color: #0b6266; width: 100%;
    transition: all .15s ease;
}
.wallet-quick-amt:hover, .wallet-quick-amt.is-active {
    border-color: #0b6266; background: #e8f8f7;
}

.wallet-empty {
    text-align: center; padding: 48px 20px;
}
.wallet-empty-illu {
    width: 88px; height: 88px; margin: 0 auto 16px; border-radius: 24px;
    background: linear-gradient(145deg, #e8f8f7, #f1f5f9);
    display: flex; align-items: center; justify-content: center;
    color: #0b6266; font-size: 34px;
}

.wallet-tx-row { cursor: pointer; transition: background .15s ease; }
.wallet-tx-row:hover { background: #f8fafb; }

.wallet-bonus-meter {
    height: 8px; border-radius: 999px; background: #f1f5f9; overflow: hidden;
}
.wallet-bonus-meter > span {
    display: block; height: 100%; border-radius: 999px;
    background: linear-gradient(90deg, #f59e0b, #0b6266);
}

.wallet-offcanvas .offcanvas-header { border-bottom: 1px solid #eef2f5; }
.wallet-detail-row {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 10px 0; border-bottom: 1px solid #f1f5f9;
}
.wallet-detail-row:last-child { border-bottom: 0; }
.wallet-detail-row span { color: #64748b; font-size: 13px; }
.wallet-detail-row strong { color: #0f172a; font-size: 13px; text-align: right; }

@media (max-width: 767.98px) {
    .wallet-kpi .kpi-value { font-size: 1.25rem; }
    .wallet-actions { width: 100%; }
    .wallet-actions .btn { flex: 1 1 auto; }
}
</style>

<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-lg-7">
            <h2 class="mb-1 fw-semibold">Wallet &amp; Balance</h2>
            <p class="text-muted mb-0">
                Manage deposits, promotional credit, withdrawals, and your full transaction history.
            </p>
        </div>
        <div class="col-lg-5">
            <div class="wallet-actions justify-content-lg-end">
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                    <i class="fa fa-plus me-1"></i> Add Funds
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="withdrawOpenBtn"
                        data-bs-toggle="modal" data-bs-target="#withdrawModal"
                        @disabled(! $canWithdraw)>
                    <i class="fa fa-arrow-up me-1"></i> Withdraw
                </button>
                <a href="{{ route('advertiser.billing.index') }}" class="btn btn-sm btn-cta-tertiary">
                    <i class="fa fa-file-invoice me-1"></i> Billing
                </a>
            </div>
        </div>
    </div>

    {{-- Primary balances --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="wallet-kpi">
                <span class="kpi-icon kpi-icon--available"><i class="fa fa-wallet"></i></span>
                <span>
                    <span class="kpi-label">Available Balance</span>
                    <span class="kpi-value" id="kpiAvailable">€{{ number_format($available, 2) }}</span>
                    <span class="kpi-desc">Real money — spend or withdraw</span>
                </span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="wallet-kpi">
                <span class="kpi-icon kpi-icon--bonus"><i class="fa fa-gift"></i></span>
                <span>
                    <span class="kpi-label">Bonus Balance</span>
                    <span class="kpi-value" id="kpiBonus">€{{ number_format($bonus, 2) }}</span>
                    <span class="kpi-desc">Promotional credit — purchases only</span>
                </span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="wallet-kpi">
                <span class="kpi-icon kpi-icon--pending"><i class="fa fa-clock"></i></span>
                <span>
                    <span class="kpi-label">Pending Balance</span>
                    <span class="kpi-value" id="kpiPending">€{{ number_format($pending, 2) }}</span>
                    <span class="kpi-desc">Awaiting verification or settlement</span>
                </span>
            </div>
        </div>
    </div>

    {{-- Lifetime KPIs --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="wallet-kpi">
                <span class="kpi-icon kpi-icon--deposits"><i class="fa fa-arrow-down"></i></span>
                <span>
                    <span class="kpi-label">Lifetime Deposits</span>
                    <span class="kpi-value" style="font-size:1.2rem;" id="kpiDeposits">€{{ number_format($lifetimeDeposits, 2) }}</span>
                    <span class="kpi-desc">Approved &amp; completed</span>
                </span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="wallet-kpi">
                <span class="kpi-icon kpi-icon--spending"><i class="fa fa-shopping-cart"></i></span>
                <span>
                    <span class="kpi-label">Lifetime Spending</span>
                    <span class="kpi-value" style="font-size:1.2rem;" id="kpiSpending">€{{ number_format($lifetimeSpending, 2) }}</span>
                    <span class="kpi-desc">Orders paid from wallet</span>
                </span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="wallet-kpi">
                <span class="kpi-icon kpi-icon--withdrawals"><i class="fa fa-arrow-up"></i></span>
                <span>
                    <span class="kpi-label">Lifetime Withdrawals</span>
                    <span class="kpi-value" style="font-size:1.2rem;" id="kpiWithdrawals">€{{ number_format($lifetimeWithdrawals, 2) }}</span>
                    <span class="kpi-desc">Requested to date</span>
                </span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="wallet-kpi">
                <span class="kpi-icon kpi-icon--pending-wd"><i class="fa fa-hourglass-half"></i></span>
                <span>
                    <span class="kpi-label">Pending Withdrawals</span>
                    <span class="kpi-value" style="font-size:1.2rem;" id="kpiPendingWd">€{{ number_format($pendingWithdrawals, 2) }}</span>
                    <span class="kpi-desc">In review or processing</span>
                </span>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        {{-- Quick actions + bonus activity --}}
        <div class="col-lg-4">
            <div class="card wallet-panel mb-3">
                <div class="card-header fw-semibold py-3">
                    <i class="fa fa-bolt me-2 text-primary"></i> Quick Actions
                </div>
                <div class="card-body d-grid gap-2">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                        <i class="fa fa-plus me-1"></i> Add Funds
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#withdrawModal" @disabled(! $canWithdraw)>
                        <i class="fa fa-arrow-up me-1"></i> Withdraw
                    </button>
                    <a href="#walletHistory" class="btn btn-outline-secondary">
                        <i class="fa fa-list me-1"></i> View Transactions
                    </a>
                    <a href="{{ route('advertiser.balance.export') }}" class="btn btn-outline-secondary" id="exportStatementBtn">
                        <i class="fa fa-download me-1"></i> Download Statement
                    </a>
                    <a href="{{ route('advertiser.billing.index') }}" class="btn btn-cta-tertiary">
                        <i class="fa fa-file-invoice-dollar me-1"></i> Billing &amp; Invoices
                    </a>
                    @if(! $canWithdraw)
                        <div class="alert alert-warning mb-0 py-2 small">
                            <i class="fa fa-info-circle me-1"></i>
                            @if($bonus > 0)
                                {{ $promotionalBonusMessage }}
                            @else
                                Add funds to enable withdrawals from your Available Balance.
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <div class="card wallet-panel">
                <div class="card-header fw-semibold py-3">
                    <i class="fa fa-gift me-2 text-warning"></i> Bonus Activity
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Bonus Received</span>
                        <strong id="bonusReceivedLabel">€{{ number_format($bonusReceived, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Bonus Used</span>
                        <strong id="bonusUsedLabel">€{{ number_format($bonusUsed, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted small">Bonus Remaining</span>
                        <strong class="text-primary" id="bonusRemainingLabel">€{{ number_format($bonus, 2) }}</strong>
                    </div>
                    @php
                        $bonusPct = $bonusReceived > 0 ? min(100, round(($bonusUsed / $bonusReceived) * 100)) : 0;
                    @endphp
                    <div class="wallet-bonus-meter mb-2"><span style="width: {{ $bonusPct }}%"></span></div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted small">Expiration</span>
                        <strong class="small">Does not expire</strong>
                    </div>
                    <p class="small text-muted mt-3 mb-0">
                        Bonus credit is never mixed with cash withdrawals, refunds to payment methods, or external transfers.
                    </p>
                </div>
            </div>
        </div>

        {{-- Chart --}}
        <div class="col-lg-8">
            <div class="card wallet-chart-card h-100">
                <div class="card-header bg-white fw-semibold py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <i class="fa fa-chart-area me-2 text-primary"></i> Spending Overview
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Chart range">
                        @foreach(['week' => 'Week', 'month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year', 'lifetime' => 'Lifetime'] as $key => $label)
                            <button type="button" class="btn btn-outline-secondary chart-range-btn {{ ($key === 'month') ? 'active' : '' }}" data-range="{{ $key }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="walletChart" height="120"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- History --}}
    <div class="card wallet-panel mb-4" id="walletHistory">
        <div class="card-header fw-semibold py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <i class="fa fa-history me-2"></i> Balance History
            </div>
            <small class="text-muted" id="historyCount"></small>
        </div>
        <div class="card-body border-bottom">
            <form id="historyFilters" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                    <input type="text" class="form-control form-control-sm" name="search" placeholder="Reference, description…">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">Type</label>
                    <select class="form-select form-select-sm" name="type">
                        <option value="">All</option>
                        <option value="deposit">Deposit</option>
                        <option value="bonus_credit">Bonus Credit</option>
                        <option value="purchase">Purchase</option>
                        <option value="refund">Refund</option>
                        <option value="withdrawal">Withdrawal</option>
                        <option value="transfer_out">Transfer Out</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">Status</label>
                    <select class="form-select form-select-sm" name="status">
                        <option value="">All</option>
                        <option value="completed">Completed</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">From</label>
                    <input type="date" class="form-control form-control-sm" name="from">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">To</label>
                    <input type="date" class="form-control form-control-sm" name="to">
                </div>
                <div class="col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">Go</button>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Balance After</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsBody">
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            <nav id="historyPagination" class="d-flex justify-content-center"></nav>
        </div>
    </div>

    {{-- Transfer (secondary, collapsed) --}}
    <div class="card wallet-panel mb-4">
        <div class="card-header fw-semibold py-3 d-flex justify-content-between align-items-center">
            <div>
                <i class="fa fa-exchange-alt me-2 text-info"></i> Transfer to Publisher Wallet
            </div>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#transferPanel">
                Toggle
            </button>
        </div>
        <div class="collapse" id="transferPanel">
            <div class="card-body">
                <div class="alert alert-success py-2">
                    <i class="fa fa-check-circle me-1"></i>
                    0% transfer fee. Only Available Balance can be transferred — bonus credit stays here for marketplace purchases.
                </div>
                <div class="row">
                    <div class="col-md-6 mx-auto">
                        <label class="form-label fw-semibold">Amount (€)</label>
                        <div class="input-group input-group-lg mb-2">
                            <span class="input-group-text bg-light"><i class="fa fa-euro-sign"></i></span>
                            <input type="number" id="amount" class="form-control" placeholder="0.00" step="0.01" min="1">
                        </div>
                        <small class="text-muted d-block mb-3">
                            Available to transfer: <strong id="transferAvailableLabel">€{{ number_format($available, 2) }}</strong>
                        </small>
                        <div class="d-grid">
                            <button class="btn btn-primary" id="transferBtn" disabled>
                                <i class="fa fa-exchange-alt me-2"></i> Transfer to Publisher Wallet
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Add Funds Modal --}}
<div class="modal fade" id="addFundsModal" tabindex="-1" aria-labelledby="addFundsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-semibold" id="addFundsModalLabel">Add Funds</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Choose an amount to continue with the existing secure payment workflow.</p>
                <div class="row g-2 mb-3">
                    @foreach([25, 50, 100, 250, 500] as $amt)
                        <div class="col-4">
                            <button type="button" class="wallet-quick-amt add-fund-amt" data-amount="{{ $amt }}">€{{ $amt }}</button>
                        </div>
                    @endforeach
                    <div class="col-4">
                        <button type="button" class="wallet-quick-amt add-fund-amt" data-amount="custom">Custom</button>
                    </div>
                </div>
                <div class="input-group mb-2" id="customAmountWrap" style="display:none;">
                    <span class="input-group-text">€</span>
                    <input type="number" id="modalCustomAmount" class="form-control" min="10" step="1" placeholder="Enter amount (min €10)">
                </div>
                <div class="alert alert-info py-2 small mb-0">
                    After payment succeeds, your Available Balance updates automatically and an invoice email is sent.
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-cta-tertiary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="continueAddFundsBtn" disabled>Continue to Payment</button>
            </div>
        </div>
    </div>
</div>

{{-- Withdraw Modal --}}
<div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-semibold" id="withdrawModalLabel">Withdraw Funds</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="withdrawForm">
                <div class="modal-body">
                    <div class="p-3 rounded mb-3" style="background:#e8f8f7;border:1px solid #b8e8e6;">
                        <div class="small text-muted">Available for Withdrawal</div>
                        <div class="fs-3 fw-bold text-primary" id="withdrawAvailableLabel">€{{ number_format($available, 2) }}</div>
                        <div class="small text-muted mt-1">Bonus Balance (€{{ number_format($bonus, 2) }}) cannot be withdrawn.</div>
                    </div>

                    @if(! $canWithdraw)
                        <div class="alert alert-warning">
                            <i class="fa fa-lock me-1"></i>
                            {{ $bonus > 0 ? $promotionalBonusMessage : 'You have no available balance to withdraw.' }}
                        </div>
                    @else
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Amount (€)</label>
                                <input type="number" name="amount" id="withdrawAmount" class="form-control" step="0.01" min="0.01" max="{{ $available }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Payment Method</label>
                                <select name="payment_method" id="withdrawMethod" class="form-select" required>
                                    <option value="bank">Bank Transfer</option>
                                    <option value="paypal">PayPal</option>
                                    <option value="wise">Wise</option>
                                    <option value="crypto">Crypto</option>
                                </select>
                            </div>
                        </div>
                        <div id="withdrawMethodFields" class="row g-3 mt-1"></div>
                    @endif
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-cta-tertiary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="withdrawSubmitBtn" @disabled(! $canWithdraw)>
                        Submit Withdrawal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Transaction detail offcanvas --}}
<div class="offcanvas offcanvas-end wallet-offcanvas" tabindex="-1" id="txDetailOffcanvas" aria-labelledby="txDetailLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title fw-semibold" id="txDetailLabel">Transaction Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="txDetailBody">
        <div class="text-center py-5 text-muted">Select a transaction to view details.</div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const csrf = '{{ csrf_token() }}';
    const routes = {
        transactions: @json(route('advertiser.balance.transactions')),
        transactionShow: @json(url('/advertiser/balance/transactions')),
        analytics: @json(route('advertiser.balance.analytics')),
        export: @json(route('advertiser.balance.export')),
        withdraw: @json(route('advertiser.balance.withdraw')),
        transfer: @json(route('advertiser.balance.transfer')),
        addFunds: @json(route('advertiser.add-funds')),
    };
    const promoMessage = @json($promotionalBonusMessage);
    let availableBalance = {{ json_encode($available) }};
    let bonusBalance = {{ json_encode($bonus) }};
    let advertiserBalance = {{ json_encode($spendable) }};
    let publisherBalance = {{ json_encode((float) ($publisherBalance ?? 0)) }};
    let selectedAddAmount = null;
    let currentPage = 1;
    let walletChart = null;
    let chartData = @json($analytics);

    function money(n) {
        return '€' + (parseFloat(n || 0)).toFixed(2);
    }

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, function (m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
        });
    }

    function statusClass(status) {
        return 'wallet-status wallet-status--' + String(status || 'pending').toLowerCase();
    }

    function renderWithdrawFields(method) {
        const wrap = $('#withdrawMethodFields');
        if (method === 'bank') {
            wrap.html(`
                <div class="col-md-6"><label class="form-label small fw-semibold">Bank Name</label><input class="form-control" name="bank_name" required></div>
                <div class="col-md-6"><label class="form-label small fw-semibold">Account Holder</label><input class="form-control" name="account_holder" required></div>
                <div class="col-md-6"><label class="form-label small fw-semibold">Account Number / IBAN</label><input class="form-control" name="account_number" required></div>
                <div class="col-md-6"><label class="form-label small fw-semibold">SWIFT / BIC</label><input class="form-control" name="swift_code"></div>
            `);
        } else if (method === 'paypal') {
            wrap.html(`<div class="col-12"><label class="form-label small fw-semibold">PayPal Email</label><input type="email" class="form-control" name="paypal_email" required></div>`);
        } else if (method === 'wise') {
            wrap.html(`<div class="col-12"><label class="form-label small fw-semibold">Wise Email</label><input type="email" class="form-control" name="wise_email" required></div>`);
        } else {
            wrap.html(`
                <div class="col-md-4"><label class="form-label small fw-semibold">Crypto</label>
                    <select class="form-select" name="crypto_type" required>
                        <option value="USDT">USDT</option><option value="BTC">BTC</option>
                        <option value="ETH">ETH</option><option value="BNB">BNB</option>
                    </select>
                </div>
                <div class="col-md-8"><label class="form-label small fw-semibold">Wallet Address</label><input class="form-control" name="wallet_address" required></div>
            `);
        }
    }

    function loadTransactions(page = 1) {
        currentPage = page;
        const params = $('#historyFilters').serialize() + '&page=' + page;
        $('#exportStatementBtn').attr('href', routes.export + '?' + $('#historyFilters').serialize());

        $.get(routes.transactions + '?' + params)
            .done(function (res) {
                if (!res.success) return;
                renderTransactions(res.transactions || []);
                renderPagination(res.pagination || {});
                const p = res.pagination || {};
                $('#historyCount').text((p.total ? ('Showing ' + (p.from || 0) + '–' + (p.to || 0) + ' of ' + p.total) : 'No results'));
            })
            .fail(function () {
                $('#transactionsBody').html('<tr><td colspan="7" class="text-center text-danger py-4">Failed to load transactions</td></tr>');
            });
    }

    function renderTransactions(rows) {
        if (!rows.length) {
            $('#transactionsBody').html(`
                <tr><td colspan="7">
                    <div class="wallet-empty">
                        <div class="wallet-empty-illu"><i class="fa fa-wallet"></i></div>
                        <h5 class="fw-semibold mb-1">No wallet activity yet.</h5>
                        <p class="text-muted mb-3">Add funds to start purchasing placements on the marketplace.</p>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                            <i class="fa fa-plus me-1"></i> Add Funds
                        </button>
                    </div>
                </td></tr>
            `);
            return;
        }

        let html = '';
        rows.forEach(function (row) {
            const debit = row.direction === 'debit';
            const iconClass = row.type === 'bonus_credit' ? 'is-bonus' : (debit ? 'is-debit' : '');
            const amountClass = debit ? 'wallet-amount-debit' : 'wallet-amount-credit';
            const sign = debit ? '−' : '+';
            const bal = row.balance_after != null ? money(row.balance_after) : '—';
            html += `<tr class="wallet-tx-row" data-source="${escapeHtml(row.source)}" data-id="${escapeHtml(row.id)}">
                <td><small>${row.date ? new Date(row.date).toLocaleString() : '—'}</small></td>
                <td>
                    <span class="d-inline-flex align-items-center gap-2">
                        <span class="wallet-type-icon ${iconClass}"><i class="fa ${escapeHtml(row.icon || 'fa-circle')}"></i></span>
                        <span class="fw-semibold small">${escapeHtml(row.type_label)}</span>
                    </span>
                </td>
                <td><span class="small">${escapeHtml(row.description || '')}</span></td>
                <td><code class="small">${escapeHtml(row.reference || '—')}</code></td>
                <td class="${amountClass}">${sign} ${money(row.amount)}</td>
                <td><span class="${statusClass(row.status)}">${escapeHtml(row.status || '')}</span></td>
                <td><small class="text-muted">${bal}</small></td>
            </tr>`;
        });
        $('#transactionsBody').html(html);
    }

    function renderPagination(p) {
        if (!p.last_page || p.last_page <= 1) {
            $('#historyPagination').html('');
            return;
        }
        let html = '<ul class="pagination pagination-sm mb-0">';
        html += `<li class="page-item ${p.current_page <= 1 ? 'disabled' : ''}"><button type="button" class="page-link" data-page="${p.current_page - 1}">Prev</button></li>`;
        for (let i = 1; i <= p.last_page; i++) {
            if (i === p.current_page || (i >= p.current_page - 2 && i <= p.current_page + 2)) {
                html += `<li class="page-item ${i === p.current_page ? 'active' : ''}"><button type="button" class="page-link" data-page="${i}">${i}</button></li>`;
            }
        }
        html += `<li class="page-item ${p.current_page >= p.last_page ? 'disabled' : ''}"><button type="button" class="page-link" data-page="${p.current_page + 1}">Next</button></li>`;
        html += '</ul>';
        $('#historyPagination').html(html);
    }

    function renderChart(data) {
        const ctx = document.getElementById('walletChart');
        if (!ctx) return;
        if (walletChart) walletChart.destroy();
        walletChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: [
                    { label: 'Deposits', data: data.deposits || [], borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.12)', tension: .35, fill: true },
                    { label: 'Orders', data: data.orders || [], borderColor: '#0b6266', backgroundColor: 'rgba(11,98,102,.10)', tension: .35, fill: true },
                    { label: 'Withdrawals', data: data.withdrawals || [], borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.08)', tension: .35, fill: false },
                    { label: 'Bonus Usage', data: data.bonus_usage || [], borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,.08)', tension: .35, fill: false },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (v) => '€' + v } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    function openTxDetail(source, id) {
        const body = $('#txDetailBody');
        body.html('<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>');
        const canvas = bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('txDetailOffcanvas'));
        canvas.show();

        $.get(routes.transactionShow + '/' + encodeURIComponent(source) + '/' + encodeURIComponent(id))
            .done(function (res) {
                if (!res.success || !res.transaction) {
                    body.html('<div class="text-danger">Transaction not found.</div>');
                    return;
                }
                const t = res.transaction;
                let invoiceBtn = '';
                if (t.invoice_download_url) {
                    invoiceBtn = `<a class="btn btn-sm btn-primary w-100 mt-3" href="${escapeHtml(t.invoice_download_url)}"><i class="fa fa-download me-1"></i> Download Invoice</a>`;
                } else if (t.invoice_view_url) {
                    invoiceBtn = `<a class="btn btn-sm btn-outline-secondary w-100 mt-3" href="${escapeHtml(t.invoice_view_url)}">View Invoice</a>`;
                }
                body.html(`
                    <div class="wallet-detail-row"><span>Transaction ID</span><strong>${escapeHtml(t.reference || t.id)}</strong></div>
                    <div class="wallet-detail-row"><span>Date</span><strong>${t.date ? new Date(t.date).toLocaleString() : '—'}</strong></div>
                    <div class="wallet-detail-row"><span>Type</span><strong>${escapeHtml(t.type_label || '')}</strong></div>
                    <div class="wallet-detail-row"><span>Amount</span><strong>${money(t.signed_amount ?? t.amount)}</strong></div>
                    <div class="wallet-detail-row"><span>Payment Method</span><strong>${escapeHtml(t.payment_method || '—')}</strong></div>
                    <div class="wallet-detail-row"><span>Order Reference</span><strong>${escapeHtml(t.order_reference || '—')}</strong></div>
                    <div class="wallet-detail-row"><span>Invoice</span><strong>${escapeHtml(t.invoice_number || '—')}</strong></div>
                    <div class="wallet-detail-row"><span>Status</span><strong><span class="${statusClass(t.status)}">${escapeHtml(t.status || '')}</span></strong></div>
                    <div class="wallet-detail-row"><span>Balance After</span><strong>${t.balance_after != null ? money(t.balance_after) : '—'}</strong></div>
                    <p class="small text-muted mt-3 mb-0">${escapeHtml(t.description || '')}</p>
                    ${invoiceBtn}
                `);
            })
            .fail(function () {
                body.html('<div class="text-danger">Could not load transaction details.</div>');
            });
    }

    $(function () {
        loadTransactions(1);
        renderChart(chartData);
        renderWithdrawFields($('#withdrawMethod').val() || 'bank');

        $('#historyFilters').on('submit', function (e) {
            e.preventDefault();
            loadTransactions(1);
        });

        $(document).on('click', '#historyPagination .page-link', function () {
            const page = parseInt($(this).data('page'), 10);
            if (page) loadTransactions(page);
        });

        $(document).on('click', '.wallet-tx-row', function () {
            openTxDetail($(this).data('source'), $(this).data('id'));
        });

        $('.chart-range-btn').on('click', function () {
            $('.chart-range-btn').removeClass('active');
            $(this).addClass('active');
            const range = $(this).data('range');
            $.get(routes.analytics, { range: range }).done(function (res) {
                if (res.success) renderChart(res.analytics);
            });
        });

        $('.add-fund-amt').on('click', function () {
            $('.add-fund-amt').removeClass('is-active');
            $(this).addClass('is-active');
            const amt = $(this).data('amount');
            if (amt === 'custom') {
                $('#customAmountWrap').show();
                selectedAddAmount = null;
                $('#continueAddFundsBtn').prop('disabled', true);
            } else {
                $('#customAmountWrap').hide();
                selectedAddAmount = parseFloat(amt);
                $('#continueAddFundsBtn').prop('disabled', false);
            }
        });

        $('#modalCustomAmount').on('input', function () {
            const v = parseFloat($(this).val());
            selectedAddAmount = (!isNaN(v) && v >= 10) ? v : null;
            $('#continueAddFundsBtn').prop('disabled', !selectedAddAmount);
        });

        $('#continueAddFundsBtn').on('click', function () {
            if (!selectedAddAmount) return;
            window.location.href = routes.addFunds + '?amount=' + encodeURIComponent(selectedAddAmount);
        });

        $('#withdrawMethod').on('change', function () {
            renderWithdrawFields($(this).val());
        });

        $('#withdrawForm').on('submit', function (e) {
            e.preventDefault();
            if (availableBalance <= 0) {
                Swal.fire('Unavailable', promoMessage, 'info');
                return;
            }
            const amount = parseFloat($('#withdrawAmount').val());
            if (!amount || amount <= 0) {
                Swal.fire('Error', 'Enter a valid withdrawal amount.', 'error');
                return;
            }
            if (amount > availableBalance) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cannot withdraw bonus',
                    text: promoMessage + ' Available for withdrawal: ' + money(availableBalance) + '.',
                });
                return;
            }

            const data = $(this).serialize() + '&_token=' + encodeURIComponent(csrf);
            $('#withdrawSubmitBtn').prop('disabled', true).text('Submitting…');
            $.post(routes.withdraw, data)
                .done(function (res) {
                    if (res.success) {
                        Swal.fire('Submitted', res.message, 'success').then(function () {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Unable to withdraw', res.message || promoMessage, 'warning');
                    }
                })
                .fail(function (xhr) {
                    const msg = xhr.responseJSON?.message || promoMessage;
                    Swal.fire('Unable to withdraw', msg, 'warning');
                })
                .always(function () {
                    $('#withdrawSubmitBtn').prop('disabled', availableBalance <= 0).text('Submit Withdrawal');
                });
        });

        function validateTransfer() {
            const amount = parseFloat($('#amount').val()) || 0;
            $('#transferBtn').prop('disabled', !(amount >= 1 && amount <= availableBalance));
        }

        $('#amount').on('keyup change', validateTransfer);

        $('#transferBtn').on('click', function () {
            const amount = parseFloat($('#amount').val());
            if (!amount || amount < 1) {
                Swal.fire('Error', 'Please enter a valid amount (minimum €1)', 'error');
                return;
            }
            if (amount > availableBalance) {
                Swal.fire('Unable to transfer', promoMessage, 'warning');
                return;
            }
            Swal.fire({
                title: 'Confirm Transfer',
                html: `<p>Transfer <strong>${money(amount)}</strong> to your Publisher wallet?</p><p class="small text-muted mb-0">Bonus credit will not be transferred.</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Confirm Transfer',
                confirmButtonColor: '#0b6266'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                $.post(routes.transfer, { _token: csrf, amount: amount })
                    .done(function (res) {
                        if (res.success) {
                            Swal.fire('Success', res.message, 'success').then(function () {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire('Error', res.message || promoMessage, 'error');
                        }
                    })
                    .fail(function (xhr) {
                        Swal.fire('Error', xhr.responseJSON?.message || 'Transfer failed', 'error');
                    });
            });
        });
    });
})();
</script>
@endsection
