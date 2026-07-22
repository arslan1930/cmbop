{{-- resources/views/advertiser/balance.blade.php --}}
@extends('advertiser.layouts.app')

@section('title', 'Add Funds')

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
    border-color: #5bc4c7; background: #f0fbfb;
    box-shadow: 0 8px 20px rgba(24, 80, 84, 0.08);
    transform: translateY(-2px);
}
.wallet-kpi .kpi-icon {
    width: 44px; height: 44px; border-radius: 12px; display: flex;
    align-items: center; justify-content: center; flex-shrink: 0;
    background: var(--brand-primary-bg, #e6f5f5);
    color: var(--brand-primary, #185054);
    border: 1px solid var(--brand-primary-border, #b8e4e4);
}
.wallet-kpi .kpi-icon--available { background: var(--brand-primary-bg, #e6f5f5); color: var(--brand-primary, #185054); }
.wallet-kpi .kpi-icon--bonus { background: var(--brand-warning-bg, #fffbeb); color: var(--brand-warning-ink, #92400e); border-color: var(--brand-warning-border, #fde68a); }
.wallet-kpi .kpi-icon--pending { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
.wallet-kpi .kpi-icon--deposits { background: var(--brand-success-bg, #d1fae5); color: var(--brand-success, #0f766e); border-color: rgba(15, 118, 110, 0.22); }
.wallet-kpi .kpi-icon--spending { background: var(--brand-primary-bg, #e6f5f5); color: var(--brand-primary, #185054); }
.wallet-kpi .kpi-icon--withdrawals { background: var(--brand-danger-bg, #fee2e2); color: var(--brand-danger, #dc2626); border-color: #fecaca; }
.wallet-kpi .kpi-icon--pending-wd { background: var(--brand-warning-bg, #fffbeb); color: var(--brand-warning-ink, #92400e); border-color: var(--brand-warning-border, #fde68a); }
.wallet-kpi .kpi-label { font-size: 12px; color: #6b7280; display: block; font-weight: 600; letter-spacing: .01em; }
.wallet-kpi .kpi-value { font-size: 1.45rem; font-weight: 700; color: var(--brand-primary, #185054); line-height: 1.15; }
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
    background: #e6f5f5; color: #185054; font-size: 13px;
}
.wallet-type-icon.is-debit { background: #fee2e2; color: #dc2626; }
.wallet-type-icon.is-bonus { background: #fef3c7; color: #d97706; }

.wallet-amount-credit { color: #059669; font-weight: 700; }
.wallet-amount-debit { color: #dc2626; font-weight: 700; }

.wallet-status {
    padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
    display: inline-block; text-transform: capitalize;
    border: 1px solid transparent;
}
.wallet-status--completed, .wallet-status--paid, .wallet-status--approved { background: #d1fae5; color: #065f46; border-color: rgba(15, 118, 110, 0.2); }
.wallet-status--pending, .wallet-status--processing {
    background: var(--brand-warning-bg, #fffbeb);
    color: var(--brand-warning-ink, #92400e);
    border-color: var(--brand-warning-border, #fde68a);
}
.wallet-status--cancelled, .wallet-status--rejected, .wallet-status--failed { background: #fee2e2; color: #991b1b; border-color: rgba(220, 38, 38, 0.2); }

.wallet-quick-amt {
    border: 1px solid #e5eef0; background: #fff; border-radius: 10px;
    padding: 10px 12px; font-weight: 600; color: #185054; width: 100%;
    transition: all .15s ease;
}
.wallet-quick-amt:hover, .wallet-quick-amt.is-active {
    border-color: #185054; background: #e6f5f5;
}

.wallet-empty {
    text-align: center; padding: 48px 20px;
}
.wallet-empty-illu {
    width: 88px; height: 88px; margin: 0 auto 16px; border-radius: 24px;
    background: linear-gradient(145deg, #e6f5f5, #f1f5f9);
    display: flex; align-items: center; justify-content: center;
    color: #185054; font-size: 34px;
}

.wallet-tx-row { cursor: pointer; transition: background .15s ease; }
.wallet-tx-row:hover { background: #f8fafb; }

.wallet-bonus-meter {
    height: 8px; border-radius: 999px; background: #f1f5f9; overflow: hidden;
}
.wallet-bonus-meter > span {
    display: block; height: 100%; border-radius: 999px;
    background: linear-gradient(90deg, #f59e0b, #185054);
}

.wallet-offcanvas .offcanvas-header { border-bottom: 1px solid #eef2f5; }
.wallet-detail-row {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 10px 0; border-bottom: 1px solid #f1f5f9;
}
.wallet-detail-row:last-child { border-bottom: 0; }
.wallet-detail-row span { color: #64748b; font-size: 13px; }
.wallet-detail-row strong { color: #0f172a; font-size: 13px; text-align: right; }

.chart-range-btn.active {
    background: #185054; border-color: #185054; color: #fff;
}
.wallet-chart-tooltip {
    position: absolute; z-index: 1090; min-width: 220px; max-width: 280px;
    padding: 12px 14px; border-radius: 12px; background: #fff;
    border: 1px solid #d9e7e8; box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
    opacity: 0; transition: opacity .15s ease; pointer-events: none;
}
.wallet-chart-tooltip__title {
    font-weight: 700; color: #185054; margin-bottom: 8px; font-size: 13px;
}
.wallet-chart-tooltip__row {
    display: flex; justify-content: space-between; gap: 12px;
    font-size: 12px; color: #64748b; padding: 3px 0;
}
.wallet-chart-tooltip__row strong { color: #0f172a; }
.wallet-chart-empty h5 { color: #185054; }

@media (max-width: 767.98px) {
    .wallet-kpi .kpi-value { font-size: 1.25rem; }
    .wallet-actions { width: 100%; }
    .wallet-actions .btn { flex: 1 1 auto; }
    #walletChartWrap { height: 260px !important; }
}

.af-spendable {
    display: flex; flex-direction: column; gap: 10px;
    padding: 16px 18px; border: 1px solid var(--border-subtle, #e2e8f0);
    border-radius: var(--radius-lg, 12px); background: var(--surface-1, #fff);
    max-width: 32rem;
}
.af-spendable__label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--brand-primary-soft, #3faeb2); }
.af-spendable__value { font-size: 1.6rem; font-weight: 700; color: var(--brand-primary, #185054); line-height: 1.1; }
.af-spendable__equation { margin-top: 2px; }
.af-spendable__breakdown {
    display: flex; flex-wrap: wrap; gap: 8px;
}
.af-spendable__chip {
    display: inline-flex; flex-direction: column; gap: 2px;
    min-width: 7.5rem; padding: 8px 12px;
    border-radius: var(--radius-md, 10px);
    border: 1px solid var(--border-subtle, #e2e8f0);
    background: var(--surface-2, #f8fafc);
}
.af-spendable__chip--bonus {
    background: var(--brand-primary-bg, #e6f5f5);
    border-color: var(--brand-primary-border, #b8e4e4);
}
.af-spendable__chip-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    color: var(--brand-ink-muted, #6b7280);
}
.af-spendable__chip--bonus .af-spendable__chip-label { color: var(--brand-primary, #185054); }
.af-spendable__chip-value {
    font-size: 0.95rem; font-weight: 700; color: var(--brand-ink, #1f2937);
}
.af-spendable__chip--bonus .af-spendable__chip-value { color: var(--brand-primary-deep, #123f42); }
.af-spendable__pending {
    font-size: 12px; color: var(--brand-neutral, #64748b);
}
.af-spendable__note {
    font-size: 12px; color: var(--brand-warning-ink, #92400e);
    margin: 0; padding: 8px 10px;
    background: var(--brand-warning-bg, #fffbeb);
    border: 1px solid var(--brand-warning-border, #fde68a);
    border-radius: var(--radius-sm, 8px);
}
.payment-option.selected .payment-option-card {
    border-color: var(--brand-primary, #185054) !important;
    background: var(--brand-primary-bg, #e6f5f5) !important;
}
</style>

<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-check-circle me-1"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-circle me-1"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="row mb-3 align-items-end g-3">
        <div class="col-lg-8">
            <h2 class="mb-1 fw-semibold">Add funds</h2>
            <p class="text-muted mb-0">Top up your wallet. Minimum €10.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
            <button type="button" class="btn btn-sm btn-cta-tertiary" id="withdrawOpenBtn"
                    data-bs-toggle="modal" data-bs-target="#withdrawModal"
                    @disabled(! $canWithdraw)>
                Withdraw
            </button>
            <a href="{{ route('advertiser.billing.index') }}" class="btn btn-sm btn-cta-tertiary">Billing &amp; invoices</a>
            <a href="{{ route('advertiser.analytics') }}" class="btn btn-sm btn-cta-tertiary">Spending</a>
        </div>
    </div>

    <div class="af-spendable mb-3" role="status" aria-label="Spendable balance">
        <div class="af-spendable__main">
            <span class="af-spendable__label">Spendable</span>
            <span class="af-spendable__value" id="kpiSpendable">€{{ number_format($spendable, 2) }}</span>
            <div class="af-spendable__equation small text-muted">Money + Bonus</div>
        </div>
        <div class="af-spendable__breakdown">
            <div class="af-spendable__chip" title="Withdrawable funds from deposits">
                <span class="af-spendable__chip-label">Money</span>
                <span class="af-spendable__chip-value" id="kpiAvailable">€{{ number_format($available, 2) }}</span>
            </div>
            <div class="af-spendable__chip af-spendable__chip--bonus" title="Promotional credit for marketplace purchases only">
                <span class="af-spendable__chip-label">Bonus</span>
                <span class="af-spendable__chip-value" id="kpiBonus">€{{ number_format($bonus, 2) }}</span>
            </div>
        </div>
        @if($pending > 0)
            <div class="af-spendable__pending">
                <span id="kpiPending">€{{ number_format($pending, 2) }}</span> pending deposit confirmation
            </div>
        @else
            <span id="kpiPending" class="d-none">€{{ number_format($pending, 2) }}</span>
        @endif
        @if($bonus > 0)
            <p class="af-spendable__note mb-0">
                <strong>Bonus €{{ number_format($bonus, 2) }}</strong>
                (purchases only) — {{ $promotionalBonusMessage ?? \App\Models\Wallet::PROMOTIONAL_BONUS_MESSAGE }}
            </p>
        @endif
    </div>
    <span id="kpiDeposits" class="d-none">€{{ number_format($lifetimeDeposits, 2) }}</span>
    <span id="bonusReceivedLabel" class="d-none">€{{ number_format($bonusReceived, 2) }}</span>
    <span id="bonusUsedLabel" class="d-none">€{{ number_format($bonusUsed, 2) }}</span>
    <span id="bonusRemainingLabel" class="d-none">€{{ number_format($bonus, 2) }}</span>

    @php
        $walletSavedCards = $savedCards ?? [];
        $stripeReady = $stripeConfigured ?? false;
        $openCardsTab = !empty($cardsTab);
    @endphp

    @if(($pendingRequests ?? collect())->isNotEmpty())
        <div class="card border-0 shadow-sm mb-4" id="pendingDepositsSection">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-clock me-2 text-primary"></i> Pending invoice deposits</span>
                <span class="badge badge-pending">{{ $pendingRequests->count() }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>REF</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingRequests as $pendingDeposit)
                                <tr data-deposit-id="{{ $pendingDeposit->id }}">
                                    <td><code class="ref-code font-monospace">REF{{ $pendingDeposit->reference_code }}</code></td>
                                    <td class="fw-semibold">€{{ number_format((float) $pendingDeposit->amount, 2) }}</td>
                                    <td><span class="badge text-bg-secondary text-uppercase">{{ $pendingDeposit->payment_method }}</span></td>
                                    <td>
                                        <span class="wallet-status wallet-status--pending">Pending</span>
                                        @if($pendingDeposit->userHasMarkedPaid())
                                            <div class="small text-success mt-1">
                                                <i class="fa fa-check-circle"></i> Payment reported
                                                <span class="text-muted">· {{ $pendingDeposit->user_marked_paid_at->diffForHumans() }}</span>
                                            </div>
                                        @else
                                            <div class="small text-muted mt-1">Awaiting your transfer confirmation</div>
                                        @endif
                                    </td>
                                    <td class="small text-muted">{{ $pendingDeposit->created_at->format('M j, Y g:i A') }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                            <a class="btn btn-sm btn-outline-secondary"
                                               href="{{ route('advertiser.invoice', $pendingDeposit->reference_code) }}"
                                               target="_blank">
                                                <i class="fa fa-file-invoice"></i> Invoice
                                            </a>
                                            @if($pendingDeposit->canUserMarkPaid())
                                                <button type="button"
                                                        class="btn btn-sm btn-primary mark-deposit-paid-btn"
                                                        data-deposit-id="{{ $pendingDeposit->id }}"
                                                        data-mark-url="{{ route('advertiser.add-funds.mark-paid', $pendingDeposit) }}"
                                                        data-ref="REF{{ $pendingDeposit->reference_code }}"
                                                        data-amount="{{ number_format((float) $pendingDeposit->amount, 2, '.', '') }}">
                                                    <i class="fa fa-check"></i> OK, I have made the payment
                                                </button>
                                            @else
                                                <button type="button" class="btn btn-sm btn-outline-success" disabled>
                                                    <i class="fa fa-check"></i> Payment reported — awaiting confirmation
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 small text-muted border-top">
                    Marking as paid notifies us that you sent the transfer. Your deposit stays <strong>Pending</strong> until an admin confirms and credits your wallet.
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3 mb-4" id="depositSection">
                <!-- Left Column - Add Funds Form -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-plus-circle me-2"></i> Add Funds
                </div>
                <div class="card-body">
                    <div class="alert alert-light border mb-3 d-none" id="depositWorkflowHint" style="background:var(--brand-primary-bg,#e6f5f5); border-color:var(--brand-primary-border,#b8e4e4) !important;">
                        <div class="fw-semibold mb-1" style="color:var(--brand-primary,#185054);">Manual funding</div>
                        <p class="small text-muted mb-0">We create an invoice with a REF. Transfer the exact amount, include the REF, then mark as paid — wallet credits after confirmation.</p>
                    </div>
                    
                    <!-- Amount Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Select Amount</label>
                        <div class="row g-2 mb-3">
                            @foreach([50, 100, 250, 500, 1000] as $amount)
                                <div class="col-4 col-md-3 col-lg-2">
                                    <button type="button" class="amount-btn w-100 btn btn-outline-secondary py-2" data-amount="{{ $amount }}">
                                        €{{ $amount }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="input-group" style="max-width: 250px;">
                            <span class="input-group-text bg-white">€</span>
                            <input type="number" id="customAmount" class="form-control" placeholder="Custom amount" min="10" step="1">
                        </div>
                        <small class="form-text text-muted mt-1">Minimum amount: €10</small>
                    </div>

                    <!-- Selected Amount Display -->
                    <div id="selectedAmountDisplay" class="alert alert-info py-2 px-3 mb-4" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Amount to add:</span>
                            <strong id="selectedAmountValue" class="fs-5 text-primary">€0</strong>
                        </div>
                    </div>

                    <!-- Payment Methods -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-3">Select Payment Method</label>
                        <div class="row g-3 payment-methods-row">
<div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="card" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with credit or debit card">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fab fa-stripe" style="font-size: 28px; color: #635bff;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Credit/Debit Card</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Instant — credited immediately</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Paypal Coming Soon -->
                            <div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" style="cursor:not-allowed;" aria-disabled="true" aria-label="PayPal coming soon">
                                    <div class="payment-option-card" style="border:2px solid #e5e7eb;border-radius:12px;padding:16px;text-align:center;background:white;transition:all 0.2s;position:relative;opacity:0.85;">
                                        <div style="width:48px;height:48px;display:flex;align-items:center;justify-content:center;background:#eff6ff;border-radius:8px;margin:0 auto 8px;">
                                            <i class="fab fa-paypal" style="font-size:28px;color:#0070ba;" aria-hidden="true"></i>
                                        </div>
                                        <span style="font-weight:600;font-size:12px;color:#1f2937;">PayPal</span>
                                        <span style="font-size:10px;color:#6b7280;display:block;margin-top:4px;">Coming Soon</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Paypal Coming Soon -->

<div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="bank" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with bank transfer">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fas fa-university" style="font-size: 28px; color: #185054;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Bank Transfer</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Invoice → SEPA/wire → wallet credit</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Payment with Stripe Checkout -->

<div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="wise" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with Wise transfer">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                            <img src="{{ asset('assets/img/wiseImg-logo.png') }}" alt="Wise Logo" style="width: 32px; height: 32px; object-fit: contain;">
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Wise Transfer</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Invoice → transfer → wallet credit</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Crypto Payment -->

<div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="crypto" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with cryptocurrency">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #fef3c7; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fab fa-bitcoin" style="font-size: 28px; color: #eab308;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Cryptocurrency</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Invoice → send crypto → wallet credit</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Bank Transfer -->

                                                </div>

                        <div id="paymentError" style="display: none; margin-top: 12px; font-size: 14px; color: #dc2626;">
                            Please select a payment method
                        </div>
                    </div>

                    <!-- Payment Details Section -->
                    <div id="paymentDetailsSection" style="display: none;">
                        <!-- Wise Payment Details -->
                        <div id="wisePaymentDetails" class="card border-0 shadow-sm mb-4" style="display: none;">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                                    <div style="width: 40px; height: 40px; background: #eff6ff; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                        <img src="{{ asset('assets/img/wiseImg-logo.png') }}" alt="Wise Logo" style="width: 24px; height: 24px;">
                                    </div>
                                    <div>
                                        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Wise Payment Instructions</h3>
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">Bank transfer via Wise</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-danger py-2 px-3 mb-3" style="background-color: #fee2e2; border-left: 4px solid #dc2626;">
                                    <i class="fas fa-exclamation-triangle me-1"></i> 
                                    <strong>Important:</strong> Please include <strong class="ref-code ref-code-display">XXXXXXXX</strong> in your payment note. Payments without this reference cannot be tracked.
                                </div>
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
                                    <p style="font-size: 14px; color: #6b7280; margin-bottom: 12px;">Send <strong id="wiseAmount" style="color: #1f2937;">€<span class="amount-display">0</span></strong> using the link or QR code below:</p>
                                    
                                    <div style="margin-bottom: 16px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">Wise Payment Link</p>
                                        <div id="wisePaymentLink" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; word-break: break-all; font-family: monospace;">
                                            https://wise.com/pay/business/topurlzltd?amount=<span class="amount-link">0</span>&currency=EUR
                                        </div>
                                        <button type="button" class="copy-btn mt-2" data-target="wisePaymentLink" style="padding: 4px 12px; font-size: 12px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">
                                            <i class="fas fa-copy"></i> Copy Payment Link
                                        </button>
                                    </div>
                                    
                                    <div style="text-align: center; margin-bottom: 16px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">QR Code for Payment</p>
                                        <img id="wiseQRCode" src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://wise.com/pay/business/topurlzltd?amount=0&currency=EUR" 
                                             alt="Wise Payment QR Code" style="width: 150px; height: 150px;">
                                    </div>
                                    
                                    <div style="background: #eff6ff; padding: 12px; border-radius: 8px; border: 1px solid #bfdbfe;">
                                        <div style="display: flex; align-items: center;">
                                            <i class="fas fa-info-circle" style="color: #2563eb; margin-right: 8px;"></i>
                                            <p style="font-size: 12px; color: #1e40af; margin: 0;">Click the link or scan QR code to open payment in Wise app.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Crypto Payment Details -->
                        <div id="cryptoPaymentDetails" class="card border-0 shadow-sm mb-4" style="display: none;">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                                    <div style="width: 40px; height: 40px; background: #fef3c7; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                        <i class="fab fa-bitcoin" style="font-size: 24px; color: #eab308;"></i>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Cryptocurrency Payment</h3>
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">BTC, USDT, Binance Pay</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-danger py-2 px-3 mb-3" style="background-color: #fee2e2; border-left: 4px solid #dc2626;">
                                    <i class="fas fa-exclamation-triangle me-1"></i> 
                                    <strong>Important:</strong> Please include <strong class="ref-code ref-code-display">XXXXXXXX</strong> in your payment note. Payments without this reference cannot be tracked.
                                </div>
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
                                    <div class="alert alert-warning mb-3">
                                        <small>Please send the exact amount: <strong id="cryptoAmount">€<span class="amount-display">0</span></strong></small>
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">USDT (Tether)</h4>
                                        <div style="margin-bottom: 12px;">
                                            <p style="font-size: 12px; font-weight: 500; margin-bottom: 4px;">BEP20 Network Address</p>
                                            <div id="usdtBep20" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; word-break: break-all; font-family: monospace;">0x1d8a41af7060c8ce6596f6c023692037a3173817</div>
                                            <button type="button" class="copy-btn mt-1" data-target="usdtBep20" style="padding: 4px 12px; font-size: 11px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Copy Address</button>
                                        </div>
                                        <div>
                                            <p style="font-size: 12px; font-weight: 500; margin-bottom: 4px;">TRC20 Network Address</p>
                                            <div id="usdtTrc20" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; word-break: break-all; font-family: monospace;">TLsBTcjhpqLYKkA5nbha3bEe9CCmpCAeqR</div>
                                            <button type="button" class="copy-btn mt-1" data-target="usdtTrc20" style="padding: 4px 12px; font-size: 11px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Copy Address</button>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-bottom: 20px;">
                                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Bitcoin (BTC)</h4>
                                        <p style="font-size: 12px; font-weight: 500; margin-bottom: 4px;">BTC Address</p>
                                        <div id="btcAddress" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; word-break: break-all; font-family: monospace;">3GT1yUfnDMbvkXhzUccxAEVgQhynbuxfGD</div>
                                        <button type="button" class="copy-btn mt-1" data-target="btcAddress" style="padding: 4px 12px; font-size: 11px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Copy Address</button>
                                    </div>
                                    
                                    <div>
                                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Binance Pay</h4>
                                        <p style="font-size: 12px; font-weight: 500; margin-bottom: 4px;">Binance Pay ID</p>
                                        <div id="binancePayId" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">723746770</div>
                                        <button type="button" class="copy-btn mt-1" data-target="binancePayId" style="padding: 4px 12px; font-size: 11px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Copy ID</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bank Transfer Payment Details -->
                        <div id="bankPaymentDetails" class="card border-0 shadow-sm mb-4" style="display: none;">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                                    <div style="width: 40px; height: 40px; background: #eff6ff; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                        <i class="fas fa-university" style="font-size: 24px; color: #185054;"></i>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Bank Transfer Payment</h3>
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">Local Bank Transfer</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-danger py-2 px-3 mb-3" style="background-color: #fee2e2; border-left: 4px solid #dc2626;">
                                    <i class="fas fa-exclamation-triangle me-1"></i> 
                                    <strong>Important:</strong> Please include <strong class="ref-code ref-code-display">XXXXXXXX</strong> in your payment note. Payments without this reference cannot be tracked.
                                </div>
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
                                    <div class="alert alert-warning mb-3">
                                        <small>Please send the exact amount: <strong id="bankAmount">€<span class="amount-display">0</span></strong></small>
                                    </div>
                                    <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #9333ea;">Bank Account Information</h4>
                                    <div style="margin-bottom: 12px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Account Holder:</p>
                                        <p style="font-weight: 600; margin: 0;">TopURLZ Ltd</p>
                                    </div>
                                    <div style="margin-bottom: 12px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">IBAN:</p>
                                        <div id="bankIban" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">BE04905543949331</div>
                                        <button type="button" class="copy-btn mt-1" data-target="bankIban" style="padding: 4px 12px; font-size: 11px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Copy IBAN</button>
                                    </div>
                                    <div>
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">BIC/SWIFT:</p>
                                        <div id="bankBic" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">TRWIBEB1XXX</div>
                                        <button type="button" class="copy-btn mt-1" data-target="bankBic" style="padding: 4px 12px; font-size: 11px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">Copy BIC</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card Payment Details - Stripe Checkout / saved card -->
                        <div id="cardPaymentDetails" class="card border-0 shadow-sm mb-4" style="display: none;">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                                    <div style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                        <i class="fab fa-stripe" style="font-size: 24px; color: #635bff;"></i>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Card Payment</h3>
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">Instant wallet credit via Stripe</p>
                                    </div>
                                </div>

                                @if(count($walletSavedCards) > 0)
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small">Pay with</label>
                                        @foreach($walletSavedCards as $card)
                                            <label class="d-flex align-items-center gap-2 border rounded-3 p-3 mb-2" style="cursor:pointer;">
                                                <input type="radio" name="deposit_saved_card" class="form-check-input"
                                                       value="{{ $card['id'] }}" {{ !empty($card['is_default']) ? 'checked' : '' }}>
                                                <span class="small text-capitalize">{{ $card['brand'] }} •••• {{ $card['last4'] }}</span>
                                            </label>
                                        @endforeach
                                        <label class="d-flex align-items-center gap-2 border rounded-3 p-3" style="cursor:pointer;">
                                            <input type="radio" name="deposit_saved_card" class="form-check-input" value="new"
                                                   {{ count($walletSavedCards) === 0 ? 'checked' : '' }}>
                                            <span class="small fw-semibold">New card (Stripe Checkout)</span>
                                        </label>
                                    </div>
                                @endif

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <div class="col-lg-4">
            <div class="card wallet-panel mb-3">
                <div class="card-header fw-semibold py-3">
                    <i class="fa fa-calculator me-2 text-primary"></i> Payment Summary
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Amount to Add:</span>
                        <span id="summaryAmount" class="fw-semibold">€0.00</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-3">
                        <strong>Total:</strong>
                        <strong class="text-primary fs-5" id="summaryTotal">€0.00</strong>
                    </div>
                    <div class="alert alert-secondary py-2 px-3 mb-3" style="background-color: #f8f9fa;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small">Reference Code:</span>
                            <div>
                                <strong id="referenceCode" class="ref-code font-monospace">XXXXXXXX</strong>
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2 copy-ref-btn" data-target="referenceCode">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-warning py-2 px-3 mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <small>Include <strong id="refCodeDisplay" class="ref-code">XXXXXXXX</strong> in manual payment notes. Card payments record the reference automatically.</small>
                    </div>
                    <button type="button" id="proceedBtn" class="btn btn-primary w-100 mt-2 py-2">
                        <i class="fa fa-arrow-right me-2"></i> Get invoice &amp; pay
                    </button>
                    <div class="mt-3">
                        @include('partials.payment-trust', ['compact' => true])
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        $walletSavedCards = $savedCards ?? [];
        $stripeReady = $stripeConfigured ?? false;
        $openCardsTab = !empty($cardsTab);
    @endphp
    <div class="card border-0 shadow-sm mb-4" id="savedCardsSection">
        <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <button type="button" class="btn btn-link text-decoration-none text-dark p-0 fw-semibold"
                    data-bs-toggle="collapse" data-bs-target="#savedCardsCollapse"
                    aria-expanded="{{ !empty($openCardsTab) ? 'true' : 'false' }}" aria-controls="savedCardsCollapse">
                <i class="fa fa-credit-card me-2"></i> Saved cards
                <i class="fa fa-chevron-down ms-1 small text-muted"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addCardBtn" {{ $stripeReady ? '' : 'disabled' }}>
                <i class="fa fa-plus me-1"></i> Add card
            </button>
        </div>
        <div id="savedCardsCollapse" class="collapse{{ !empty($openCardsTab) ? ' show' : '' }}">
        <div class="card-body">
            <p class="small text-muted mb-3">
                Save a card once (via Stripe) and reuse it for wallet top-ups and checkout. We never store full card numbers.
            </p>
            <div id="savedCardsList">
                @forelse($walletSavedCards as $card)
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border rounded-3 p-3 mb-2 saved-card-row"
                         data-pm-id="{{ $card['id'] }}">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fab fa-cc-{{ strtolower($card['brand']) === 'american express' ? 'amex' : strtolower($card['brand']) }} fa-lg text-muted"></i>
                            <div class="small">
                                <strong class="text-capitalize">{{ $card['brand'] }}</strong>
                                •••• {{ $card['last4'] }}
                                <span class="text-muted">· {{ sprintf('%02d/%d', $card['exp_month'], $card['exp_year'] % 100) }}</span>
                                @if(!empty($card['is_default']))
                                    <span class="badge text-bg-success ms-1">Default</span>
                                @endif
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            @if(empty($card['is_default']))
                                <button type="button" class="btn btn-sm btn-outline-secondary set-default-card" data-pm-id="{{ $card['id'] }}">Set default</button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-danger remove-card" data-pm-id="{{ $card['id'] }}">Remove</button>
                        </div>
                    </div>
                @empty
                    <div class="text-muted small" id="savedCardsEmpty">No cards saved yet. Click <strong>Add card</strong> to store one securely with Stripe.</div>
                @endforelse
            </div>
        </div>
        </div>
    </div>


    <div class="d-flex flex-wrap gap-3 mb-3 small">
        <a href="#walletHistory" class="link-secondary">View transactions</a>
        <a href="{{ route('advertiser.balance.export') }}" class="link-secondary" id="exportStatementBtn">Download statement</a>
        <a href="{{ route('advertiser.billing.index') }}" class="link-secondary">Billing &amp; invoices</a>
        <a href="{{ route('advertiser.analytics') }}" class="link-secondary">Spending analytics</a>
    </div>

    {{-- History (demoted) --}}
    <div class="card wallet-panel mb-4" id="walletHistory">
        <div class="card-header fw-semibold py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <button type="button" class="btn btn-link text-decoration-none text-dark p-0 fw-semibold"
                    data-bs-toggle="collapse" data-bs-target="#historyFiltersCollapse" aria-expanded="false">
                <i class="fa fa-history me-2"></i> Recent activity
            </button>
            <small class="text-muted" id="historyCount"></small>
        </div>
        <div id="historyFiltersCollapse" class="collapse">
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

</div>

{{-- Withdraw Modal --}}
@php $payout = $payoutProfile ?? auth()->user()->payoutProfile(); @endphp
<div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-semibold" id="withdrawModalLabel">Withdraw Funds</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="withdrawForm">
                <div class="modal-body">
                    <div class="p-3 rounded mb-3" style="background:#e6f5f5;border:1px solid #b8e4e4;">
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
                        <div class="alert alert-light border small mb-3">
                            <i class="fa fa-info-circle me-1 text-primary"></i>
                            Business name, PayPal email, and bank account holder name are locked after the first successful save.
                            Crypto TRX wallets must be entered twice to verify. Contact support to change locked details.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Amount (€)</label>
                                <input type="number" name="amount" id="withdrawAmount" class="form-control" step="0.01" min="0.01" max="{{ $available }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Send payout via</label>
                                <select name="payment_method" id="withdrawMethod" class="form-select" required>
                                    <option value="bank">Bank Transfer</option>
                                    <option value="paypal">PayPal</option>
                                    <option value="wise">Wise</option>
                                    <option value="crypto">Crypto (TRX / USDT TRC20)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Business / Billing Name</label>
                                <input type="text" name="business_name" id="withdrawBusinessName" class="form-control"
                                       value="{{ $payout['business_name'] ?? '' }}"
                                       @if(!empty($payout['business_name'])) readonly @endif required>
                                @if(!empty($payout['business_name']))
                                    <small class="text-muted">Locked — contact support to change.</small>
                                @endif
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
<script>
(function () {
    const csrf = '{{ csrf_token() }}';
    const routes = {
        transactions: @json(route('advertiser.balance.transactions')),
        transactionShow: @json(url('/advertiser/balance/transactions')),
        analytics: @json(route('advertiser.balance.analytics')),
        export: @json(route('advertiser.balance.export')),
        withdraw: @json(route('advertiser.balance.withdraw')),
        addFunds: @json(route('advertiser.add-funds')),
        catalog: @json(route('advertiser.catalog')),
    };
    const promoMessage = @json($promotionalBonusMessage);
    const payoutProfile = @json($payout ?? ($payoutProfile ?? []));
    let availableBalance = {{ json_encode($available) }};
    let bonusBalance = {{ json_encode($bonus) }};
    let advertiserBalance = {{ json_encode($spendable) }};
    let publisherBalance = {{ json_encode((float) ($publisherBalance ?? 0)) }};
    let selectedAddAmount = null;
    let currentPage = 1;
    let walletChart = null;
    let chartData = @json($analytics);
    let activeChartRange = '30d';
    let chartOrderIndex = {};

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

    function lockedHint() {
        return '<small class="text-muted">Locked — contact support to change.</small>';
    }

    function renderWithdrawFields(method) {
        const wrap = $('#withdrawMethodFields');
        const p = payoutProfile || {};
        if (method === 'bank') {
            const holderLocked = !!p.bank_holder_name;
            wrap.html(`
                <div class="col-md-6"><label class="form-label small fw-semibold">Bank Name</label>
                    <input class="form-control" name="bank_name" value="${escapeHtml(p.bank_name || '')}" required></div>
                <div class="col-md-6"><label class="form-label small fw-semibold">Account Holder Name</label>
                    <input class="form-control" name="account_holder" value="${escapeHtml(p.bank_holder_name || '')}" ${holderLocked ? 'readonly' : ''} required>
                    ${holderLocked ? lockedHint() : ''}</div>
                <div class="col-md-6"><label class="form-label small fw-semibold">Account Number / IBAN</label>
                    <input class="form-control" name="account_number" value="${escapeHtml(p.bank_account || '')}" required></div>
                <div class="col-md-6"><label class="form-label small fw-semibold">SWIFT / BIC</label>
                    <input class="form-control" name="swift_code" value="${escapeHtml(p.bank_swift || '')}"></div>
            `);
        } else if (method === 'paypal') {
            const locked = !!p.paypal_email;
            wrap.html(`
                <div class="col-12"><label class="form-label small fw-semibold">PayPal Email</label>
                    <input type="email" class="form-control" name="paypal_email" value="${escapeHtml(p.paypal_email || '')}" ${locked ? 'readonly' : ''} required>
                    ${locked ? lockedHint() : '<small class="text-muted">This email cannot be changed later without contacting support.</small>'}
                </div>
            `);
        } else if (method === 'wise') {
            wrap.html(`
                <div class="col-12"><label class="form-label small fw-semibold">Wise Email</label>
                    <input type="email" class="form-control" name="wise_email" required>
                </div>
            `);
        } else {
            const locked = !!p.crypto_trx_wallet;
            wrap.html(`
                <div class="col-md-4"><label class="form-label small fw-semibold">Network</label>
                    <select class="form-select" name="crypto_type" required>
                        <option value="USDT_TRC20">USDT (TRC20)</option>
                        <option value="TRX">TRX</option>
                    </select>
                </div>
                <div class="col-md-8"><label class="form-label small fw-semibold">TRX / TRC20 Wallet</label>
                    <input class="form-control" name="wallet_address" id="withdrawWallet" value="${escapeHtml(p.crypto_trx_wallet || '')}" ${locked ? 'readonly' : ''} required autocomplete="off">
                    ${locked ? lockedHint() : '<small class="text-muted">Enter twice below to verify.</small>'}
                </div>
                ${locked ? '' : `
                <div class="col-12"><label class="form-label small fw-semibold">Confirm TRX Wallet</label>
                    <input class="form-control" name="wallet_address_confirm" id="withdrawWalletConfirm" required autocomplete="off">
                    <small class="text-muted">Must match exactly — wallets are verified twice.</small>
                </div>`}
            `);
            if (locked) {
                wrap.append(`<input type="hidden" name="wallet_address_confirm" value="${escapeHtml(p.crypto_trx_wallet || '')}">`);
            }
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
                        <a class="btn btn-primary btn-sm" href="#depositSection">
                            <i class="fa fa-plus me-1"></i> Add Funds</a>
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
                <td><code class="ref-code small">${escapeHtml(row.reference || '—')}</code></td>
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

    const crosshairPlugin = {
        id: 'spendCrosshair',
        afterDraw(chart) {
            if (chart.tooltip && chart.tooltip._active && chart.tooltip._active.length) {
                const ctx = chart.ctx;
                const x = chart.tooltip._active[0].element.x;
                const topY = chart.chartArea.top;
                const bottomY = chart.chartArea.bottom;
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(x, topY);
                ctx.lineTo(x, bottomY);
                ctx.lineWidth = 1;
                ctx.strokeStyle = 'rgba(24, 80, 84, 0.35)';
                ctx.setLineDash([4, 4]);
                ctx.stroke();
                ctx.restore();
            }
        }
    };

    function buildOrderIndex(data) {
        chartOrderIndex = {};
        (data.order_details || []).forEach(function (o) {
            if (!chartOrderIndex[o.bucket]) chartOrderIndex[o.bucket] = [];
            chartOrderIndex[o.bucket].push(o);
        });
    }

    function openSpendDetails(point) {
        const body = $('#spendDetailBody');
        const key = point.key;
        const orders = chartOrderIndex[key] || [];
        const canvasEl = document.getElementById('spendDetailOffcanvas');
        const canvas = bootstrap.Offcanvas.getOrCreateInstance(canvasEl);
        canvas.show();

        if (!orders.length) {
            body.html(`
                <div class="mb-3">
                    <div class="wallet-detail-row"><span>Period</span><strong>${escapeHtml(point.label)}</strong></div>
                    <div class="wallet-detail-row"><span>Total Spend</span><strong>${money(point.total_spend)}</strong></div>
                    <div class="wallet-detail-row"><span>Orders</span><strong>${point.order_count || 0}</strong></div>
                </div>
                <p class="text-muted small mb-0">No order details for this period.</p>
            `);
            return;
        }

        let html = `
            <div class="mb-3 pb-2 border-bottom">
                <div class="small text-muted">${escapeHtml(point.label)}</div>
                <div class="fw-semibold">${money(point.total_spend)} · ${point.order_count} order${point.order_count === 1 ? '' : 's'}</div>
            </div>
        `;
        orders.forEach(function (o) {
            html += `
                <div class="mb-3 p-3 rounded" style="border:1px solid #e5eef0;background:#fbfdfe;">
                    <div class="wallet-detail-row"><span>Order ID</span><strong>${escapeHtml(o.order_number || o.id)}</strong></div>
                    <div class="wallet-detail-row"><span>Order Name</span><strong>${escapeHtml(o.site_name || 'Marketplace order')}</strong></div>
                    <div class="wallet-detail-row"><span>Publisher Website</span><strong>${escapeHtml(o.site_url || '—')}</strong></div>
                    <div class="wallet-detail-row"><span>Amount Paid</span><strong>${money(o.amount)}</strong></div>
                    <div class="wallet-detail-row"><span>Order Status</span><strong><span class="${statusClass(o.status)}">${escapeHtml(o.status || '')}</span></strong></div>
                    <div class="wallet-detail-row"><span>Payment Status</span><strong><span class="${statusClass(o.payment_status)}">${escapeHtml(o.payment_status || '')}</span></strong></div>
                    <div class="wallet-detail-row"><span>Order Date</span><strong>${o.date ? new Date(o.date).toLocaleString() : '—'}</strong></div>
                    <div class="wallet-detail-row"><span>Completion Date</span><strong>${o.completed_at ? new Date(o.completed_at).toLocaleString() : '—'}</strong></div>
                    <div class="wallet-detail-row"><span>Invoice Number</span><strong>${escapeHtml(o.invoice_number || '—')}</strong></div>
                    <a class="btn btn-sm btn-primary w-100 mt-2" href="${escapeHtml(o.order_url)}">View Order</a>
                </div>
            `;
        });
        body.html(html);
    }

    function externalTooltipHandler(context) {
        let tip = document.getElementById('walletChartTooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'walletChartTooltip';
            tip.className = 'wallet-chart-tooltip';
            document.body.appendChild(tip);
        }
        const { chart, tooltip } = context;
        if (tooltip.opacity === 0) {
            tip.style.opacity = '0';
            tip.style.pointerEvents = 'none';
            return;
        }
        const idx = tooltip.dataPoints?.[0]?.dataIndex;
        const points = chart.$spendPoints || [];
        const point = points[idx];
        if (!point) return;

        tip.innerHTML = `
            <div class="wallet-chart-tooltip__title">${escapeHtml(point.label)}</div>
            <div class="wallet-chart-tooltip__row"><span>Total Spend</span><strong>${money(point.total_spend)}</strong></div>
            <div class="wallet-chart-tooltip__row"><span>Orders</span><strong>${point.order_count}</strong></div>
            <div class="wallet-chart-tooltip__row"><span>Avg Order Value</span><strong>${money(point.avg_order)}</strong></div>
            <div class="wallet-chart-tooltip__row"><span>Largest Order</span><strong>${money(point.largest_order)}</strong></div>
            <button type="button" class="btn btn-sm btn-primary w-100 mt-2 wallet-chart-tooltip__btn" data-idx="${idx}">Quick View</button>
        `;
        const rect = chart.canvas.getBoundingClientRect();
        const left = rect.left + window.pageXOffset + tooltip.caretX + 14;
        const top = rect.top + window.pageYOffset + tooltip.caretY - 20;
        tip.style.opacity = '1';
        tip.style.pointerEvents = 'auto';
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
        tip.querySelector('.wallet-chart-tooltip__btn')?.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openSpendDetails(point);
        });
    }

    function renderChart(data) {
        const canvas = document.getElementById('walletChart');
        if (!canvas) return;
        if (!canvas) return;
        chartData = data || {};
        buildOrderIndex(chartData);
        const points = chartData.points || [];
        const hasSpend = !!chartData.has_spend;

        if (!hasSpend) {
            $('#walletChartEmpty').show();
            $('#walletChartWrap').hide();
            if (walletChart) {
                walletChart.destroy();
                walletChart = null;
            }
            return;
        }

        $('#walletChartEmpty').hide();
        $('#walletChartWrap').show();

        if (walletChart) walletChart.destroy();

        const values = points.map(p => p.total_spend);
        walletChart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: chartData.labels || points.map(p => p.label),
                datasets: [{
                    label: 'Spending',
                    data: values,
                    borderColor: '#185054',
                    backgroundColor: (ctx) => {
                        const chart = ctx.chart;
                        const {ctx: c, chartArea} = chart;
                        if (!chartArea) return 'rgba(24,80,84,.10)';
                        const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        g.addColorStop(0, 'rgba(63,174,178,.28)');
                        g.addColorStop(1, 'rgba(24,80,84,.02)');
                        return g;
                    },
                    borderWidth: 2.5,
                    tension: 0.35,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#185054',
                    pointBorderWidth: 2,
                    pointHoverBorderWidth: 3,
                    pointHitRadius: 14,
                    cursor: 'pointer',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 650, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: false,
                        external: externalTooltipHandler,
                    },
                    zoom: {
                        pan: { enabled: true, mode: 'x', modifierKey: null },
                        zoom: {
                            wheel: { enabled: true, speed: 0.08 },
                            pinch: { enabled: true },
                            drag: { enabled: true, backgroundColor: 'rgba(24,80,84,.08)', borderColor: 'rgba(24,80,84,.35)', borderWidth: 1 },
                            mode: 'x',
                        },
                        limits: { x: { min: 'original', max: 'original' } },
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148,163,184,.18)' },
                        ticks: { callback: (v) => '€' + v, color: '#64748b', font: { size: 11 } },
                        border: { display: false },
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#64748b', font: { size: 11 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
                        border: { display: false },
                    }
                },
                onHover: (evt, elements) => {
                    evt.native.target.style.cursor = elements.length ? 'pointer' : 'grab';
                },
                onClick: (evt, elements) => {
                    if (!elements.length) return;
                    const idx = elements[0].index;
                    const point = points[idx];
                    if (point) openSpendDetails(point);
                }
            },
            plugins: [crosshairPlugin],
        });
        walletChart.$spendPoints = points;

        canvas.ondblclick = function () {
            if (walletChart && walletChart.resetZoom) walletChart.resetZoom();
        };
    }

    function fetchAnalytics(range, from, to) {
        const params = { range: range };
        if (range === 'custom') {
            params.from = from || $('#chartFrom').val();
            params.to = to || $('#chartTo').val();
            if (!params.from || !params.to) return;
        }
        $.get(routes.analytics, params).done(function (res) {
            if (res.success) renderChart(res.analytics);
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
        // Spending chart lives on advertiser analytics — skip here.
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
            activeChartRange = $(this).data('range');
            if (activeChartRange === 'custom') {
                $('#chartCustomRange').show();
                return;
            }
            $('#chartCustomRange').hide();
            fetchAnalytics(activeChartRange);
        });

        $('#chartCustomApply').on('click', function () {
            fetchAnalytics('custom');
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

            const method = $('#withdrawMethod').val();
            if (method === 'crypto' && !payoutProfile.crypto_trx_wallet) {
                const a = ($('#withdrawWallet').val() || '').trim();
                const b = ($('#withdrawWalletConfirm').val() || '').trim();
                if (!a || a !== b) {
                    Swal.fire('Verify wallet', 'TRX wallet must be entered twice and both values must match.', 'warning');
                    return;
                }
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
                    let msg = xhr.responseJSON?.message || promoMessage;
                    if (xhr.responseJSON?.errors) {
                        msg = Object.values(xhr.responseJSON.errors).flat().join(' ');
                    }
                    Swal.fire('Unable to withdraw', msg, 'warning');
                })
                .always(function () {
                    $('#withdrawSubmitBtn').prop('disabled', availableBalance <= 0).text('Submit Withdrawal');
                });
        });
    });
})();
</script>
<!-- Billing Information Modal -->
<div class="modal fade" id="billingInfoModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fa fa-user-edit me-2"></i> Billing Information
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Please provide your billing information for the invoice.</p>
                
                <form id="billingForm">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Billing Name <span class="text-danger">*</span></label>
                            <input type="text" name="billing_name" id="billing_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" id="company_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                            <input type="text" name="country" id="country" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">State/Province <span class="text-danger">*</span></label>
                            <input type="text" name="state" id="state" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" id="city" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Postal Code <span class="text-danger">*</span></label>
                            <input type="text" name="postal_code" id="postal_code" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <textarea name="address" id="address" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">VAT Number</label>
                            <input type="text" name="vat_number" id="vat_number" class="form-control">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBillingInfo">
                    <i class="fa fa-save"></i> Save & Continue
                </button>
            </div>
        </div>
    </div>

<style>
.payment-option {
    cursor: pointer;
}

.payment-option.selected .payment-option-card {
    border-color: #185054 !important;
    background: #eff6ff !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.payment-option-card {
    transition: all 0.2s;
}

.payment-option-card:hover {
    border-color: #60a5fa !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.amount-btn {
    transition: all 0.2s;
}

.amount-btn:hover {
    background-color: #e9ecef;
    transform: translateY(-1px);
}

.amount-btn.active {
    background-color: #185054;
    color: white;
    border-color: #185054;
}

.copy-btn {
    cursor: pointer;
    transition: background 0.2s;
}

.copy-btn:hover {
    background: #d1d5db !important;
}

#customAmount:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

.font-monospace {
    font-family: monospace;
    letter-spacing: 1px;
}

.copy-ref-btn {
    font-size: 12px;
}

.copy-ref-btn:hover {
    background-color: #e9ecef;
    border-radius: 4px;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let selectedAmount = 0;
    let selectedMethod = null;
    let referenceCode = generateReferenceCode();
    const prefillAmount = @json($prefillAmount ?? null);
    const prefillMethod = @json($prefillMethod ?? null);
    
    // Generate 6-digit reference code
    function generateReferenceCode() {
        return Math.floor(100000 + Math.random() * 900000).toString();
    }
    
    function updateReferenceCode() {
        referenceCode = generateReferenceCode();
        const refCodeDisplay = document.getElementById('referenceCode');
        const refCodeTexts = document.querySelectorAll('.ref-code-display');
        const refCodeDisplaySpan = document.getElementById('refCodeDisplay');
        
        if (refCodeDisplay) refCodeDisplay.innerText = referenceCode;
        if (refCodeDisplaySpan) refCodeDisplaySpan.innerText = `REF${referenceCode}`;
        refCodeTexts.forEach(el => {
            el.innerText = `REF${referenceCode}`;
        });
    }
    
    // Initialize reference code
    updateReferenceCode();

    const amountBtns = document.querySelectorAll('.amount-btn');
    const customAmountInput = document.getElementById('customAmount');

    function applyPrefill() {
        if (prefillAmount && Number(prefillAmount) >= 10) {
            setSelectedAmount(Number(prefillAmount));
            const matchBtn = Array.from(document.querySelectorAll('.amount-btn')).find(
                btn => Number(btn.dataset.amount) === Number(prefillAmount)
            );
            if (matchBtn) {
                document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('active'));
                matchBtn.classList.add('active');
            } else if (customAmountInput) {
                customAmountInput.value = String(prefillAmount);
            }
        }
        if (prefillMethod) {
            const opt = document.querySelector('.payment-option[data-method="' + prefillMethod + '"]');
            if (opt) opt.click();
        }
    }
    const selectedAmountDisplay = document.getElementById('selectedAmountDisplay');
    const selectedAmountValue = document.getElementById('selectedAmountValue');
    const paymentOptions = document.querySelectorAll('.payment-option');
    const paymentDetailsSection = document.getElementById('paymentDetailsSection');
    const wiseDetails = document.getElementById('wisePaymentDetails');
    const cryptoDetails = document.getElementById('cryptoPaymentDetails');
    const bankDetails = document.getElementById('bankPaymentDetails');
    const cardDetails = document.getElementById('cardPaymentDetails');
    const proceedBtn = document.getElementById('proceedBtn');
    const depositWorkflowHint = document.getElementById('depositWorkflowHint');
    window.__afProceedLabel = function () {
        const amt = (typeof selectedAmount !== 'undefined' && selectedAmount) ? Number(selectedAmount) : 0;
        const formatted = '€' + (amt || 0).toFixed(2);
        if (selectedMethod === 'card') {
            return '<i class="fa fa-credit-card me-2"></i> Pay ' + formatted + ' with card';
        }
        return '<i class="fa fa-file-invoice me-2"></i> Get invoice & pay ' + formatted;
    };
    function syncProceedLabel() {
        if (!proceedBtn || proceedBtn.disabled) return;
        proceedBtn.innerHTML = window.__afProceedLabel();
        if (depositWorkflowHint) {
            if (selectedMethod && selectedMethod !== 'card') depositWorkflowHint.classList.remove('d-none');
            else depositWorkflowHint.classList.add('d-none');
        }
    };

    const paymentError = document.getElementById('paymentError');
    const summaryAmount = document.getElementById('summaryAmount');
    const summaryTotal = document.getElementById('summaryTotal');

    // Amount button click
    amountBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const amount = parseFloat(this.dataset.amount);
            setSelectedAmount(amount);
            amountBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            customAmountInput.value = '';
        });
    });

    // Custom amount input
    customAmountInput.addEventListener('input', function() {
        const amount = parseFloat(this.value);
        if (!isNaN(amount) && amount >= 10) {
            setSelectedAmount(amount);
            amountBtns.forEach(b => b.classList.remove('active'));
        } else if (this.value === '') {
            selectedAmountDisplay.style.display = 'none';
            selectedAmount = 0;
            updateSummary(0);
        } else if (amount < 10) {
            Swal.fire({
                title: 'Invalid Amount',
                text: 'Minimum amount is €10',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            this.value = '';
        }
    });

    function setSelectedAmount(amount) {
        selectedAmount = amount;
        selectedAmountValue.innerText = `€${amount.toFixed(2)}`;
        selectedAmountDisplay.style.display = 'block';
        updateSummary(amount);
        
        // Update amount displays
        document.querySelectorAll('.amount-display').forEach(el => {
            el.innerText = amount.toFixed(2);
        });
        document.querySelectorAll('.amount-link').forEach(el => {
            el.innerText = amount;
        });
        
        // Update Wise QR code
        const wiseQRCode = document.getElementById('wiseQRCode');
        if (wiseQRCode) {
            wiseQRCode.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://wise.com/pay/business/topurlzltd?amount=${amount}&currency=EUR`;
        }
        
        // Update crypto and bank amounts
        const cryptoAmount = document.getElementById('cryptoAmount');
        const bankAmount = document.getElementById('bankAmount');
        if (cryptoAmount) cryptoAmount.innerText = `€${amount.toFixed(2)}`;
        if (bankAmount) bankAmount.innerText = `€${amount.toFixed(2)}`;
        
        // Update Wise link
        const wiseLink = document.getElementById('wisePaymentLink');
        if (wiseLink) {
            wiseLink.innerHTML = `https://wise.com/pay/business/topurlzltd?amount=${amount}&currency=EUR`;
        }
    }
    
    function updateSummary(amount) {
        summaryAmount.innerText = `€${amount.toFixed(2)}`;
        summaryTotal.innerText = `€${amount.toFixed(2)}`;
        if (typeof syncProceedLabel === 'function') syncProceedLabel();
    }

    // Prefill amount/method comes from applyPrefill() above (server + ?amount=&method=).

    // Payment option click
    paymentOptions.forEach(option => {
        option.addEventListener('click', function() {
            const method = this.dataset.method;
            selectedMethod = method;
            
            // Generate new reference code on payment method selection
            updateReferenceCode();
            
            // Update all reference code displays in payment details
            document.querySelectorAll('.ref-code-display').forEach(el => {
                el.innerText = `REF${referenceCode}`;
            });
            const refCodeDisplaySpan = document.getElementById('refCodeDisplay');
            if (refCodeDisplaySpan) refCodeDisplaySpan.innerText = `REF${referenceCode}`;
            
            // Update UI
            paymentOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            
            // Hide error
            if (paymentError) paymentError.style.display = 'none';
            
            // Hide all details
            if (wiseDetails) wiseDetails.style.display = 'none';
            if (cryptoDetails) cryptoDetails.style.display = 'none';
            if (bankDetails) bankDetails.style.display = 'none';
            if (cardDetails) cardDetails.style.display = 'none';
            
            // Show selected
            if (method === 'wise' && wiseDetails) wiseDetails.style.display = 'block';
            if (method === 'crypto' && cryptoDetails) cryptoDetails.style.display = 'block';
            if (method === 'bank' && bankDetails) bankDetails.style.display = 'block';
            if (method === 'card' && cardDetails) cardDetails.style.display = 'block';
            
            if (paymentDetailsSection) paymentDetailsSection.style.display = 'block';
            if (typeof syncProceedLabel === 'function') syncProceedLabel();
        });
    });
    
    // Copy buttons
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const textEl = document.getElementById(targetId);
            if (textEl) {
                const textToCopy = textEl.innerText;
                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalHtml = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => this.innerHTML = originalHtml, 1500);
                });
            }
        });
    });
    
    // Copy reference code button
    document.querySelectorAll('.copy-ref-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const textEl = document.getElementById(targetId);
            if (textEl) {
                const textToCopy = `REF${textEl.innerText}`;
                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalHtml = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => this.innerHTML = originalHtml, 1500);
                });
            }
        });
    });
    
    // Function to submit deposit
    function submitDeposit() {
        proceedBtn.disabled = true;
        proceedBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
        
        fetch('{{ route("advertiser.add-funds.store") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                amount: selectedAmount,
                payment_method: selectedMethod,
                reference_code: referenceCode
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const invoiceLink = data.invoice_url
                    ? `<a href="${data.invoice_url}" target="_blank" class="btn btn-primary mt-2 me-2">
                           <i class="fa fa-file-invoice"></i> View / download invoice
                       </a>`
                    : '';
                const markPaidBtn = data.mark_paid_url
                    ? `<button type="button" class="btn btn-success mt-2" id="swalMarkPaidBtn">
                           <i class="fa fa-check"></i> OK, I have made the payment
                       </button>`
                    : '';
                Swal.fire({
                    title: 'Invoice ready',
                    html: `Transfer <strong>€${selectedAmount.toFixed(2)}</strong> and include<br>
                           <strong class="font-monospace">REF${data.reference_code}</strong> in the payment note.<br><br>
                           After you send the transfer, click <strong>OK, I have made the payment</strong>.<br>
                           Status stays <strong>Pending</strong> until we confirm and credit your wallet.<br>
                           <div class="mt-2">${invoiceLink}${markPaidBtn}</div>`,
                    icon: 'success',
                    confirmButtonText: 'View wallet',
                    showCancelButton: false,
                    didOpen: () => {
                        const btn = document.getElementById('swalMarkPaidBtn');
                        if (!btn || !data.mark_paid_url) return;
                        btn.addEventListener('click', () => {
                            markDepositPaid(data.mark_paid_url, {
                                ref: 'REF' + data.reference_code,
                                amount: selectedAmount.toFixed(2),
                                reloadOnSuccess: true,
                            });
                        });
                    }
                }).then(() => {
                    window.location.href = '{{ route("advertiser.add-funds") }}';
                });
            } else if (data.requires_billing) {
                // Show billing info modal
                const modal = new bootstrap.Modal(document.getElementById('billingInfoModal'));
                modal.show();
                proceedBtn.disabled = false;
                proceedBtn.innerHTML = window.__afProceedLabel ? window.__afProceedLabel() : '<i class="fa fa-arrow-right me-2"></i> Get invoice &amp; pay';
            } else {
                Swal.fire({
                    title: 'Error', 
                    text: data.message || 'Failed to submit request. Please try again.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                proceedBtn.disabled = false;
                proceedBtn.innerHTML = window.__afProceedLabel ? window.__afProceedLabel() : '<i class="fa fa-arrow-right me-2"></i> Get invoice &amp; pay';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error',
                text: 'Failed to submit request. Please try again.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            proceedBtn.disabled = false;
            proceedBtn.innerHTML = window.__afProceedLabel ? window.__afProceedLabel() : '<i class="fa fa-arrow-right me-2"></i> Get invoice &amp; pay';
        });
    }
    
    // Save billing info
    document.getElementById('saveBillingInfo').addEventListener('click', function() {
        const formData = {
            billing_name: document.getElementById('billing_name').value,
            company_name: document.getElementById('company_name').value,
            country: document.getElementById('country').value,
            state: document.getElementById('state').value,
            city: document.getElementById('city').value,
            address: document.getElementById('address').value,
            postal_code: document.getElementById('postal_code').value,
            vat_number: document.getElementById('vat_number').value,
            _token: '{{ csrf_token() }}'
        };
        
        if (!formData.billing_name || !formData.country || !formData.city || !formData.address) {
            Swal.fire('Error', 'Please fill in all required fields', 'error');
            return;
        }
        
        fetch('{{ route("advertiser.save-billing-info") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('billingInfoModal'));
                modal.hide();
                submitDeposit();
            } else {
                Swal.fire('Error', data.message || 'Failed to save billing information', 'error');
            }
        })
        .catch(error => {
            Swal.fire('Error', 'Failed to save billing information', 'error');
        });
    });
    
    // Proceed button
    proceedBtn.addEventListener('click', async function() {
        if (selectedAmount <= 0) {
            Swal.fire({
                title: 'Amount Required',
                text: 'Please select or enter an amount to add.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        if (!selectedMethod) {
            if (paymentError) paymentError.style.display = 'block';
            Swal.fire({
                title: 'Payment Method Required',
                text: 'Please select a payment method to continue.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        // For card payments: saved card charge or Stripe Checkout
        if (selectedMethod === 'card') {
            proceedBtn.disabled = true;
            proceedBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
            const picked = document.querySelector('input[name="deposit_saved_card"]:checked');
            const savedPm = picked && picked.value !== 'new' ? picked.value : null;

            try {
                if (savedPm) {
                    const response = await fetch('{{ route("advertiser.add-funds.pay-saved-card") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            amount: selectedAmount,
                            reference_code: referenceCode,
                            payment_method_id: savedPm
                        })
                    });
                    const data = await response.json();
                    if (data.success && data.requires_action && data.client_secret && data.stripe_key) {
                        await new Promise((resolve, reject) => {
                            const script = document.createElement('script');
                            script.src = 'https://js.stripe.com/v3/';
                            script.onload = resolve;
                            script.onerror = reject;
                            document.head.appendChild(script);
                        });
                        const stripe = Stripe(data.stripe_key);
                        const result = await stripe.confirmCardPayment(data.client_secret, {
                            return_url: data.return_url
                        });
                        if (result.error) throw new Error(result.error.message || 'Authentication failed');
                        if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                            window.location.href = data.return_url + '&payment_intent=' + encodeURIComponent(result.paymentIntent.id);
                            return;
                        }
                    }
                    if (data.success && data.requires_payment && data.checkout_url) {
                        window.location.href = data.checkout_url;
                        return;
                    }
                    if (data.success) {
                        Swal.fire('Success', data.message || 'Funds added', 'success').then(() => {
                            window.location.href = data.redirect_url || '{{ route("advertiser.add-funds") }}';
                        });
                        return;
                    }
                    throw new Error(data.message || 'Saved card payment failed');
                }

                const response = await fetch('{{ route("advertiser.create-checkout-session") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        amount: selectedAmount,
                        reference_code: referenceCode
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.checkout_url) {
                    window.location.href = data.checkout_url;
                } else {
                    throw new Error(data.message || 'Failed to create checkout session');
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({
                    title: 'Error',
                    text: error.message || 'Failed to process card payment. Please try again.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                proceedBtn.disabled = false;
                proceedBtn.innerHTML = window.__afProceedLabel ? window.__afProceedLabel() : '<i class="fa fa-arrow-right me-2"></i> Get invoice &amp; pay';
            }
        } else {
            // Bank / Wise invoices need company billing details
            if (selectedMethod === 'bank' || selectedMethod === 'wise') {
                fetch('{{ route("advertiser.get-billing-info") }}', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(billingData => {
                    if (!billingData.success || !billingData.data.has_info) {
                        const modal = new bootstrap.Modal(document.getElementById('billingInfoModal'));
                        modal.show();
                    } else {
                        submitDeposit();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    submitDeposit();
                });
            } else {
                submitDeposit();
            }
        }
    });

    applyPrefill();

    if (@json($openCardsTab ?? false)) {
        const cardsSection = document.getElementById('savedCardsSection');
        if (cardsSection) cardsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const addCardBtn = document.getElementById('addCardBtn');
    if (addCardBtn) {
        addCardBtn.addEventListener('click', async function () {
            addCardBtn.disabled = true;
            try {
                const res = await fetch('{{ route("advertiser.payment-methods.setup") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: '{}'
                });
                const data = await res.json();
                if (data.success && data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                throw new Error(data.message || 'Unable to start card setup');
            } catch (e) {
                Swal.fire('Error', e.message || 'Unable to add card', 'error');
                addCardBtn.disabled = false;
            }
        });
    }

    document.querySelectorAll('.remove-card').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.pmId;
            const confirm = await Swal.fire({
                title: 'Remove this card?',
                text: 'You can add it again later.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remove'
            });
            if (!confirm.isConfirmed) return;
            const res = await fetch('{{ url("/advertiser/payment-methods") }}/' + encodeURIComponent(id), {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                Swal.fire('Error', data.message || 'Could not remove card', 'error');
            }
        });
    });

    document.querySelectorAll('.set-default-card').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.pmId;
            const res = await fetch('{{ url("/advertiser/payment-methods") }}/' + encodeURIComponent(id) + '/default', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                Swal.fire('Error', data.message || 'Could not set default card', 'error');
            }
        });
    });

    window.markDepositPaid = function markDepositPaid(url, opts = {}) {
        const ref = opts.ref || 'this invoice';
        const amount = opts.amount ? ('€' + opts.amount) : 'the amount';

        return Swal.fire({
            title: 'Confirm payment sent?',
            html: `Have you already transferred <strong>${amount}</strong> with <strong>${ref}</strong> in the payment note?<br><br>
                   <span class="text-muted small">Your deposit stays <strong>Pending</strong> until we confirm funds and credit your wallet.</span>`,
            icon: 'question',
            input: 'text',
            inputPlaceholder: 'Optional: Wise/bank transfer reference',
            showCancelButton: true,
            confirmButtonText: 'OK, I have made the payment',
            cancelButtonText: 'Not yet',
            confirmButtonColor: '#185054',
        }).then((result) => {
            if (!result.isConfirmed) {
                return null;
            }

            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    user_payment_note: result.value || null,
                }),
            })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        Swal.fire('Error', data.message || 'Could not mark payment as sent.', 'error');
                        return data;
                    }

                    return Swal.fire({
                        icon: 'success',
                        title: 'Payment reported',
                        text: data.message,
                        confirmButtonText: 'OK',
                    }).then(() => {
                        if (opts.reloadOnSuccess !== false) {
                            window.location.reload();
                        }
                        return data;
                    });
                })
                .catch(() => {
                    Swal.fire('Error', 'Could not mark payment as sent. Please try again.', 'error');
                    return null;
                });
        });
    };

    document.querySelectorAll('.mark-deposit-paid-btn').forEach((btn) => {
        btn.addEventListener('click', function () {
            markDepositPaid(this.dataset.markUrl, {
                ref: this.dataset.ref,
                amount: this.dataset.amount,
                reloadOnSuccess: true,
            });
        });
    });
});
</script>
@endsection
