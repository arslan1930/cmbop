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

    <div class="row mb-4 align-items-end g-3">
        <div class="col-lg-7">
            <h2 class="mb-1 fw-semibold">Add Funds</h2>
            <p class="text-muted mb-0">
                Deposit money, track every wallet movement, and withdraw available funds. Your €20 bonus can only be spent on this marketplace.
            </p>
        </div>
        <div class="col-lg-5">
            <div class="wallet-actions justify-content-lg-end">
                <a href="#depositSection" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i> Add Funds</a>
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


    <div class="row g-3 mb-4" id="depositSection">
                <!-- Left Column - Add Funds Form -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-plus-circle me-2"></i> Add Funds
                </div>
                <div class="card-body">
                    
                    <!-- Amount Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Select Amount</label>
                        <div class="row g-2 mb-3">
                            @foreach([10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000] as $amount)
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
                            <!-- Wise Payment -->
                            <div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="wise" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with Wise transfer">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                            <img src="{{ asset('assets/img/wiseImg-logo.png') }}" alt="Wise Logo" style="width: 32px; height: 32px; object-fit: contain;">
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Wise Transfer</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Bank transfer via Wise</span>
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
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">BTC, USDT, Binance Pay</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Bank Transfer -->
                            <div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="bank" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with bank transfer">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fas fa-university" style="font-size: 28px; color: #0b6266;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Bank Transfer</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Traditional bank transfer</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Payment with Stripe Checkout -->
                            <div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="card" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with credit or debit card">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fab fa-stripe" style="font-size: 28px; color: #635bff;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Credit/Debit Card</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Secure Stripe checkout</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Paypal Coming Soon -->
                            <div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" style="cursor:not-allowed;" aria-disabled="true" aria-label="PayPal coming soon">
                                    <div class="payment-option-card" style="border:2px solid #e5e7eb;border-radius:12px;padding:16px;text-align:center;background:white;transition:all 0.2s;position:relative;">
                                        <div style="width:48px;height:48px;display:flex;align-items:center;justify-content:center;background:#eff6ff;border-radius:8px;margin:0 auto 8px;">
                                            <i class="fab fa-paypal" style="font-size:28px;color:#0070ba;" aria-hidden="true"></i>
                                        </div>
                                        <span style="font-weight:600;font-size:12px;color:#1f2937;">PayPal</span>
                                        <span style="font-size:10px;color:#6b7280;display:block;margin-top:4px;">Coming Soon</span>
                                    </div>
                                </div>
                            </div>
                              
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
                                    <strong>Important:</strong> Please include <strong class="ref-code-display">XXXXXXXX</strong> in your payment note. Payments without this reference cannot be tracked.
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
                                    <strong>Important:</strong> Please include <strong class="ref-code-display">XXXXXXXX</strong> in your payment note. Payments without this reference cannot be tracked.
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
                                        <i class="fas fa-university" style="font-size: 24px; color: #0b6266;"></i>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Bank Transfer Payment</h3>
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">Local Bank Transfer</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-danger py-2 px-3 mb-3" style="background-color: #fee2e2; border-left: 4px solid #dc2626;">
                                    <i class="fas fa-exclamation-triangle me-1"></i> 
                                    <strong>Important:</strong> Please include <strong class="ref-code-display">XXXXXXXX</strong> in your payment note. Payments without this reference cannot be tracked.
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

                        <!-- Card Payment Details - Stripe Checkout -->
                        <div id="cardPaymentDetails" class="card border-0 shadow-sm mb-4" style="display: none;">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                                    <div style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                        <i class="fab fa-stripe" style="font-size: 24px; color: #635bff;"></i>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Card Payment</h3>
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">Secure card payment via Stripe</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info py-3 px-3 mb-3">
                                    <div class="d-flex align-items-center">
                                        <i class="fab fa-cc-visa fa-2x me-2 text-primary"></i>
                                        <i class="fab fa-cc-mastercard fa-2x me-2 text-warning"></i>
                                        <i class="fab fa-cc-amex fa-2x me-2 text-info"></i>
                                        <i class="fab fa-cc-discover fa-2x me-2 text-secondary"></i>
                                    </div>
                                </div>
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
                                    <div class="alert alert-success mt-3 py-2">
                                        <i class="fas fa-shield-alt me-1"></i>
                                        <small>Your payment is secure and encrypted. We accept Visa, Mastercard, American Express, and Discover.</small>
                                    </div>
                                </div>
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
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Processing Fee:</span>
                        <span class="text-muted">€0.00</span>
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
                                <strong id="referenceCode" class="font-monospace">XXXXXXXX</strong>
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2 copy-ref-btn" data-target="referenceCode">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-warning py-2 px-3 mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <small>Include <strong id="refCodeDisplay">XXXXXXXX</strong> in manual payment notes. Card payments record the reference automatically.</small>
                    </div>
                    <button type="button" id="proceedBtn" class="btn w-100 mt-2 py-2" style="background-color: #3aaeb2; color: white;">
                        <i class="fa fa-arrow-right me-2"></i> Proceed to Payment
                    </button>
                </div>
            </div>
            <div class="alert alert-info small mb-0">
                <i class="fa fa-gift me-1"></i>
                Available Balance can be spent or withdrawn. Bonus credit (€20 welcome bonus) can only be used for purchases on this website.
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
                    <a href="#depositSection" class="btn btn-primary"><i class="fa fa-plus me-1"></i> Add Funds</a>
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
    border-color: #0b6266 !important;
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
    background-color: #0b6266;
    color: white;
    border-color: #0b6266;
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
    const selectedAmountDisplay = document.getElementById('selectedAmountDisplay');
    const selectedAmountValue = document.getElementById('selectedAmountValue');
    const paymentOptions = document.querySelectorAll('.payment-option');
    const paymentDetailsSection = document.getElementById('paymentDetailsSection');
    const wiseDetails = document.getElementById('wisePaymentDetails');
    const cryptoDetails = document.getElementById('cryptoPaymentDetails');
    const bankDetails = document.getElementById('bankPaymentDetails');
    const cardDetails = document.getElementById('cardPaymentDetails');
    const proceedBtn = document.getElementById('proceedBtn');
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
    }

    // Prefill from Wallet page (?amount=)
    const prefillAmount = parseFloat(new URLSearchParams(window.location.search).get('amount') || '');
    if (!isNaN(prefillAmount) && prefillAmount >= 10) {
        const matchingBtn = Array.from(amountBtns).find(b => parseFloat(b.dataset.amount) === prefillAmount);
        if (matchingBtn) {
            matchingBtn.click();
        } else {
            customAmountInput.value = prefillAmount;
            setSelectedAmount(prefillAmount);
            amountBtns.forEach(b => b.classList.remove('active'));
        }
    }
    
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
                if (selectedMethod === 'bank' && data.invoice_url) {
                    Swal.fire({
                        title: 'Request Submitted!',
                        html: `Your deposit request has been submitted.<br><br>
                               <strong>Amount:</strong> €${selectedAmount.toFixed(2)}<br>
                               <strong>Reference Code:</strong> <code class="font-monospace">${data.reference_code}</code><br><br>
                               <a href="${data.invoice_url}" target="_blank" class="btn btn-primary">
                                   <i class="fa fa-file-invoice"></i> View Invoice
                               </a>`,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.href = '{{ route("advertiser.reports") }}';
                    });
                } else {
                    Swal.fire({
                        title: 'Request Submitted!',
                        html: `Your deposit request has been submitted.<br><br>
                               <strong>Amount:</strong> €${selectedAmount.toFixed(2)}<br>
                               <strong>Reference Code:</strong> <code class="font-monospace">${data.reference_code}</code><br><br>`,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.href = '{{ route("advertiser.reports") }}';
                    });
                }
            } else if (data.requires_billing) {
                // Show billing info modal
                const modal = new bootstrap.Modal(document.getElementById('billingInfoModal'));
                modal.show();
                proceedBtn.disabled = false;
                proceedBtn.innerHTML = '<i class="fa fa-arrow-right me-2"></i> Proceed to Payment';
            } else {
                Swal.fire({
                    title: 'Error', 
                    text: data.message || 'Failed to submit request. Please try again.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                proceedBtn.disabled = false;
                proceedBtn.innerHTML = '<i class="fa fa-arrow-right me-2"></i> Proceed to Payment';
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
            proceedBtn.innerHTML = '<i class="fa fa-arrow-right me-2"></i> Proceed to Payment';
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
        
        // For card payments, redirect to Stripe Checkout
        if (selectedMethod === 'card') {
            proceedBtn.disabled = true;
            proceedBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Redirecting...';
            
            try {
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
                    text: error.message || 'Failed to redirect to Stripe. Please try again.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                proceedBtn.disabled = false;
                proceedBtn.innerHTML = '<i class="fa fa-arrow-right me-2"></i> Proceed to Payment';
            }
        } else {
            // Check if bank transfer and need billing info
            if (selectedMethod === 'bank') {
                // First check if billing info exists
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
});
</script>
@endsection
