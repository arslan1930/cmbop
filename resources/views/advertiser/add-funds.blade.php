{{-- resources/views/advertiser/add-funds.blade.php --}}
@extends('advertiser.layouts.app')

@section('title', 'Add Funds')

@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/add-funds.css') }}?v={{ @filemtime(public_path('assets/css/add-funds.css')) ?: '1' }}">
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
    $publisher = $publisher ?? \App\Models\Wallet::emptyRoleSnapshot();
    $showPublisherWallet = (bool) ($showPublisherWallet ?? false);
@endphp


<div class="container-fluid">

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

    @if($showPublisherWallet)
        <aside class="af-role-strip mb-3" id="publisherRoleStrip" aria-label="Publisher earnings">
            <div class="af-role-strip__main">
                <span class="af-role-strip__label">Publisher earnings</span>
                <span class="af-role-strip__value" id="publisherEarningsKpi">€{{ number_format((float) $publisher['withdrawable'], 2) }}</span>
                <p class="af-role-strip__note mb-0">Withdrawable. Open Balance to move earnings here for catalog spend (no fee).</p>
            </div>
            <div class="af-role-strip__actions">
                <a href="{{ route('publisher.balance') }}" class="btn btn-sm btn-outline-secondary" id="publisherBalanceCta">Balance</a>
                <a href="{{ route('publisher.withdraw') }}" class="btn btn-sm btn-outline-secondary" id="publisherWithdrawCta">Withdraw</a>
            </div>
        </aside>
    @endif
    <span id="kpiDeposits" class="d-none">€{{ number_format($lifetimeDeposits, 2) }}</span>
    <span id="bonusReceivedLabel" class="d-none">€{{ number_format($bonusReceived, 2) }}</span>
    <span id="bonusUsedLabel" class="d-none">€{{ number_format($bonusUsed, 2) }}</span>
    <span id="bonusRemainingLabel" class="d-none">€{{ number_format($bonus, 2) }}</span>

    @php
        $walletSavedCards = $savedCards ?? [];
        $stripeReady = $stripeConfigured ?? false;
        $openCardsTab = !empty($cardsTab);
        $pendingInvoiceCount = ($pendingRequests ?? collect())->count();
        $depositMethodLabels = ['card' => 'Card', 'bank' => 'Bank transfer', 'wise' => 'Wise', 'crypto' => 'Crypto'];
        $depositPayment = $depositPayment ?? config('billing.deposit_payment', []);
    @endphp

    @if(($pendingRequests ?? collect())->isNotEmpty())
        <div class="alert alert-warning border mb-3" id="pendingInvoicesBanner" role="status">
            <div class="fw-semibold mb-1">
                <i class="fa fa-clock me-1"></i> Pending deposit invoices
            </div>
            <p class="small text-muted mb-2">Transfer the amount, include the REF, then mark as paid — we credit your wallet after confirmation.</p>
            <ul class="list-unstyled mb-0">
                @foreach(($pendingRequests ?? collect())->take(3) as $deposit)
                    @php
                        $pendingRef = 'REF' . $deposit->reference_code;
                    @endphp
                    <li class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 {{ $loop->last && $pendingInvoiceCount <= 3 ? '' : 'border-bottom' }}">
                        <div class="small">
                            <strong>€{{ number_format((float) $deposit->amount, 2) }}</strong>
                            <span class="text-muted"> · {{ $depositMethodLabels[$deposit->payment_method] ?? ucfirst($deposit->payment_method) }}</span>
                            <code class="ms-1">{{ $pendingRef }}</code>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('advertiser.invoice', $deposit->reference_code) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                                <i class="fa fa-file-invoice me-1"></i> Invoice
                            </a>
                            @if($deposit->canUserMarkPaid())
                                <button type="button" class="btn btn-sm btn-outline-primary mark-deposit-paid-btn"
                                        data-mark-url="{{ route('advertiser.add-funds.mark-paid', $deposit, false) }}"
                                        data-ref="{{ $pendingRef }}"
                                        data-amount="{{ number_format((float) $deposit->amount, 2, '.', '') }}">
                                    <i class="fa fa-check me-1"></i> Mark paid
                                </button>
                            @elseif($deposit->userHasMarkedPaid())
                                <span class="small text-success align-self-center"><i class="fa fa-check-circle me-1"></i> Payment reported</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if($pendingInvoiceCount > 3)
                <p class="small text-muted mt-2 mb-0">+ {{ $pendingInvoiceCount - 3 }} more in recent activity below.</p>
            @endif
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
                    <div class="alert alert-light border mb-3" id="depositWorkflowHint" style="background:var(--brand-primary-bg,#e6f5f5); border-color:var(--brand-primary-border,#b8e4e4) !important;">
                        <div class="fw-semibold mb-1" style="color:var(--brand-primary,var(--brand-primary, #1a585e));">How wallet top-ups work</div>
                        <p class="small text-muted mb-0">
                            <strong>Card:</strong> Pay instantly — credited immediately after Stripe confirms.<br>
                            <strong>Bank, Wise, or Crypto:</strong> We create an invoice with a REF. Transfer the exact amount, include the REF, then mark as paid — wallet credits after confirmation.
                        </p>
                    </div>

                    @unless($stripeReady)
                        <div class="alert alert-warning py-2 px-3 mb-3" role="alert">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Card top-ups are offline. Use Bank, Wise, or Crypto.
                        </div>
                    @endunless
                    
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
                                <div class="payment-option"
                                     @if($stripeReady) data-method="card" style="cursor: pointer;" role="button" tabindex="0" @else aria-disabled="true" style="cursor: not-allowed; opacity: 0.6;" @endif
                                     aria-label="Pay with credit or debit card">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fab fa-stripe" style="font-size: 28px; color: #635bff;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Credit/Debit Card</span>
                                        @if($stripeReady)
                                            <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Instant — credited immediately</span>
                                        @else
                                            <span style="font-size: 10px; color: #dc2626; display: block; margin-top: 4px;">Temporarily unavailable</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

<div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="bank" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with bank transfer">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fas fa-university" style="font-size: 28px; color: var(--brand-primary, #1a585e);"></i>
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

                            @if($cryptoEnabled ?? false)
                            <div class="col-12 col-sm-6 col-xl-4">
                                <div class="payment-option" data-method="crypto" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with cryptocurrency">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #fef3c7; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fab fa-bitcoin" style="font-size: 28px; color: #eab308;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Cryptocurrency</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">USDT TRC20 · invoice → send → credit</span>
                                    </div>
                                </div>
                            </div>
                            @endif

                                                </div>

                        <p class="small text-muted mt-2 mb-0">PayPal coming soon.</p>

                        <div id="depositFeeNote" class="small text-muted mt-2" style="display: none;" aria-live="polite"></div>

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
                                            {{ rtrim($wisePayUrl ?? config('billing.deposit_payment.wise_pay_url', 'https://wise.com/pay/business/topurlzltd'), '?&') }}?amount=<span class="amount-link">0</span>&currency=EUR
                                        </div>
                                        <button type="button" class="copy-btn mt-2" data-target="wisePaymentLink">
                                            <i class="fas fa-copy"></i> Copy Payment Link
                                        </button>
                                    </div>
                                    
                                    <div style="text-align: center; margin-bottom: 16px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">QR Code for Payment</p>
                                        {{-- Relative path: absolute route() breaks when APP_URL host ≠ visit host (Hostinger). --}}
                                        <img id="wiseQRCode"
                                             data-qr-base="{{ route('advertiser.add-funds.wise-qr', absolute: false) }}"
                                             alt="Scan to pay with Wise"
                                             style="display: none; width: 150px; height: 150px; margin: 0 auto;"
                                             width="150"
                                             height="150">
                                        <p id="wiseQrHint" class="small text-muted">Select an amount (≥ €10) to generate your Wise QR.</p>
                                        <p id="wiseQrFallback" class="small text-muted d-none">QR unavailable — use the payment link above.</p>
                                        <a id="wiseOpenLink" href="#" target="_blank" rel="noopener" class="small d-none">Open in Wise</a>
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
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">USDT TRC20</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-danger py-2 px-3 mb-3" style="background-color: #fee2e2; border-left: 4px solid #dc2626;">
                                    <i class="fas fa-exclamation-triangle me-1"></i> 
                                    <strong>Important:</strong> Please include <strong class="ref-code ref-code-display">XXXXXXXX</strong> in your payment note. Payments without this reference cannot be tracked.
                                </div>
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
                                    <div class="alert alert-warning mb-3">
                                        @if(!empty($cryptoNote))
                                            <small>{{ $cryptoNote }}</small>
                                        @else
                                            <small>Send the USDT TRC20 equivalent of the invoice amount in EUR.</small>
                                        @endif
                                        <div class="mt-1">Invoice amount: <strong id="cryptoAmount">€<span class="amount-display">0</span></strong></div>
                                    </div>
                                    @foreach(($cryptoNetworks ?? []) as $network)
                                        <div style="margin-bottom: 12px;">
                                            <p style="font-size: 12px; font-weight: 500; margin-bottom: 4px;">{{ $network['label'] }}</p>
                                            <div id="crypto-{{ $network['key'] }}" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; word-break: break-all; font-family: monospace;">{{ $network['address'] }}</div>
                                            <button type="button" class="copy-btn mt-1" data-target="crypto-{{ $network['key'] }}">Copy Address</button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <!-- Bank Transfer Payment Details -->
                        <div id="bankPaymentDetails" class="card border-0 shadow-sm mb-4" style="display: none;">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                                    <div style="width: 40px; height: 40px; background: #eff6ff; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                        <i class="fas fa-university" style="font-size: 24px; color: var(--brand-primary, #1a585e);"></i>
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
                                    <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--brand-primary, #1a585e);">Bank Account Information</h4>
                                    <div style="margin-bottom: 12px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Seller / Service Provider:</p>
                                        <p style="font-weight: 600; margin: 0;">{{ $depositPayment['seller_name'] ?? 'SEOLinkBuildings Partner' }}</p>
                                    </div>
                                    <div style="margin-bottom: 12px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Beneficiary:</p>
                                        <p style="font-weight: 600; margin: 0;">{{ $depositPayment['beneficiary'] ?? 'Topurlz Ltd' }}</p>
                                    </div>
                                    <div style="margin-bottom: 12px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">IBAN:</p>
                                        <div id="bankIban" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $depositPayment['iban'] ?? 'BE04905543949331' }}</div>
                                        <button type="button" class="copy-btn mt-1" data-target="bankIban">Copy IBAN</button>
                                    </div>
                                    <div style="margin-bottom: 12px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">BIC/SWIFT:</p>
                                        <div id="bankBic" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; font-family: monospace;">{{ $depositPayment['bic'] ?? 'TRWIBEB1XXX' }}</div>
                                        <button type="button" class="copy-btn mt-1" data-target="bankBic">Copy BIC</button>
                                    </div>
                                    @if(!empty($depositPayment['phone']))
                                        <div style="margin-bottom: 12px;">
                                            <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Phone no:</p>
                                            <p style="font-weight: 600; margin: 0;">{{ $depositPayment['phone'] }}</p>
                                        </div>
                                    @endif
                                    @foreach(($depositPayment['address_lines'] ?? []) as $line)
                                        <div style="margin-bottom: 12px;">
                                            @if($loop->first)
                                                <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Address:</p>
                                            @endif
                                            <p style="font-weight: 600; margin: 0;">{{ $line }}</p>
                                        </div>
                                    @endforeach
                                    @if(!empty($depositPayment['registration_no']))
                                        <div style="margin-bottom: 12px;">
                                            <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Registration No:</p>
                                            <p style="font-weight: 600; margin: 0;">{{ $depositPayment['registration_no'] }}</p>
                                        </div>
                                    @endif
                                    <div>
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">VAT:</p>
                                        <p style="font-weight: 600; margin: 0;">{{ $depositPayment['vat_note'] ?? 'Not VAT registered – no VAT charged' }}</p>
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

    {{-- History + pending invoice deposits (merged live feed) --}}
    <div class="card wallet-panel mb-4" id="walletHistory">
        <div class="card-header fw-semibold py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-link text-decoration-none text-dark p-0 fw-semibold"
                        data-bs-toggle="collapse" data-bs-target="#historyFiltersCollapse" aria-expanded="false">
                    <i class="fa fa-history me-2"></i> Recent activity
                </button>
                <span class="af-live-badge" title="Feed refreshes automatically">
                    <span class="af-live-dot" aria-hidden="true"></span> Live
                </span>
                @if($pendingInvoiceCount > 0)
                    <span class="badge text-bg-light border" id="pendingActivityBadge">
                        {{ $pendingInvoiceCount }} pending invoice{{ $pendingInvoiceCount === 1 ? '' : 's' }}
                    </span>
                @endif
            </div>
            <small class="text-muted" id="historyCount"></small>
        </div>
        <div id="historyFiltersCollapse" class="collapse">
        <div class="card-body border-bottom">
            <form id="historyFilters" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <x-slb-search-field name="search" id="addFundsSearchInput" placeholder="Reference, description…" mode="" />
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
                        <option value="role_move_in">Earnings Moved for Spending</option>
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
            <div id="activityFeed" class="af-activity-feed" aria-live="polite">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <small class="text-muted" id="activityLiveHint">Updates every 30s · pending invoices appear here with download</small>
            <nav id="historyPagination" class="d-flex justify-content-center"></nav>
        </div>
    </div>

</div>

{{-- Withdraw Modal --}}
@php
    $payout = $payoutProfile ?? auth()->user()->payoutProfile();
    $payoutLocked = $payoutLocked ?? auth()->user()->payoutProfileLocked();
    $availableMethods = $availableMethods ?? app(\App\Services\Wallet\PayoutProfileService::class)->availableMethods(auth()->user());
    $withdrawMethodLabels = [
        'bank' => 'Bank Transfer',
        'paypal' => 'PayPal',
        'wise' => 'Wise',
        'crypto' => 'Crypto (TRX / USDT TRC20)',
    ];
    $preferredWithdrawMethod = $payout['preferred_method'] ?? null;
    $selectedWithdrawMethod = $payoutLocked
        ? (in_array($preferredWithdrawMethod, $availableMethods, true)
            ? $preferredWithdrawMethod
            : ($availableMethods[0] ?? 'bank'))
        : ($preferredWithdrawMethod ?: 'bank');
@endphp
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
                            @if($payoutLocked)
                                Choose a saved payout method. Details are locked — contact support to change them.
                            @else
                                Business name and payout emails/IBAN lock after the first successful save.
                                Crypto TRX wallets must be entered twice to verify. Contact support to change locked details.
                            @endif
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Amount (€)</label>
                                <input type="number" name="amount" id="withdrawAmount" class="form-control" step="0.01" min="0.01" max="{{ $available }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Send payout via</label>
                                <select name="payment_method" id="withdrawMethod" class="form-select" required>
                                    @if($payoutLocked)
                                        @forelse($availableMethods as $value)
                                            <option value="{{ $value }}" @selected($selectedWithdrawMethod === $value)>
                                                {{ $withdrawMethodLabels[$value] ?? ucfirst($value) }}
                                            </option>
                                        @empty
                                            <option value="" selected disabled>No saved payout methods</option>
                                        @endforelse
                                    @else
                                        @foreach($withdrawMethodLabels as $value => $label)
                                            <option value="{{ $value }}" @selected($selectedWithdrawMethod === $value)>{{ $label }}</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Business / Billing Name</label>
                                <input type="text" name="business_name" id="withdrawBusinessName" class="form-control"
                                       value="{{ $payout['business_name'] ?? '' }}"
                                       @if($payoutLocked || !empty($payout['business_name'])) readonly @endif required>
                                @if($payoutLocked || !empty($payout['business_name']))
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
        withdraw: @json(route('advertiser.balance.withdraw', [], false)),
        addFunds: @json(route('advertiser.add-funds')),
        catalog: @json(route('advertiser.catalog')),
    };
    const promoMessage = @json($promotionalBonusMessage);
    const payoutProfile = @json($payout ?? ($payoutProfile ?? []));
    const payoutLocked = @json((bool) $payoutLocked);
    const availableMethods = @json(array_values($availableMethods ?? []));
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

    function fieldLocked(hasValue) {
        return payoutLocked || !!hasValue;
    }

    function renderWithdrawFields(method) {
        const wrap = $('#withdrawMethodFields');
        const p = payoutProfile || {};
        if (method === 'bank') {
            const locked = fieldLocked(p.bank_account || p.bank_holder_name);
            wrap.html(`
                <div class="col-md-6"><label class="form-label small fw-semibold">Bank Name</label>
                    <input class="form-control" name="bank_name" value="${escapeHtml(p.bank_name || '')}" ${locked ? 'readonly' : ''} required>
                    ${locked ? lockedHint() : ''}</div>
                <div class="col-md-6"><label class="form-label small fw-semibold">Account Holder Name</label>
                    <input class="form-control" name="account_holder" value="${escapeHtml(p.bank_holder_name || '')}" ${locked ? 'readonly' : ''} required>
                    ${locked ? lockedHint() : ''}</div>
                <div class="col-md-6"><label class="form-label small fw-semibold">Account Number / IBAN</label>
                    <input class="form-control" name="account_number" value="${escapeHtml(p.bank_account || '')}" ${locked ? 'readonly' : ''} required>
                    ${locked ? lockedHint() : ''}</div>
                <div class="col-md-6"><label class="form-label small fw-semibold">SWIFT / BIC</label>
                    <input class="form-control" name="swift_code" value="${escapeHtml(p.bank_swift || '')}" ${locked ? 'readonly' : ''}></div>
            `);
        } else if (method === 'paypal') {
            const locked = fieldLocked(p.paypal_email);
            wrap.html(`
                <div class="col-12"><label class="form-label small fw-semibold">PayPal Email</label>
                    <input type="email" class="form-control" name="paypal_email" value="${escapeHtml(p.paypal_email || '')}" ${locked ? 'readonly' : ''} required>
                    ${locked ? lockedHint() : '<small class="text-muted">This email cannot be changed later without contacting support.</small>'}
                </div>
            `);
        } else if (method === 'wise') {
            const locked = fieldLocked(p.wise_email);
            wrap.html(`
                <div class="col-12"><label class="form-label small fw-semibold">Wise Email</label>
                    <input type="email" class="form-control" name="wise_email" value="${escapeHtml(p.wise_email || '')}" ${locked ? 'readonly' : ''} required>
                    ${locked ? lockedHint() : '<small class="text-muted">This email cannot be changed later without contacting support.</small>'}
                </div>
            `);
        } else {
            const locked = fieldLocked(p.crypto_trx_wallet);
            const cryptoType = p.crypto_type || 'USDT_TRC20';
            wrap.html(`
                <div class="col-md-4"><label class="form-label small fw-semibold">Network</label>
                    <select class="form-select" name="crypto_type" ${locked ? 'disabled' : ''} required>
                        <option value="USDT_TRC20" ${cryptoType === 'USDT_TRC20' ? 'selected' : ''}>USDT (TRC20)</option>
                        <option value="TRX" ${cryptoType === 'TRX' ? 'selected' : ''}>TRX</option>
                    </select>
                    ${locked ? `<input type="hidden" name="crypto_type" value="${escapeHtml(cryptoType)}">` : ''}
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

    function relativeTime(iso) {
        if (!iso) return '';
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return '';
        const diff = (Date.now() - date.getTime()) / 1000;
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 172800) return 'Yesterday';
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function loadTransactions(page = 1, opts = {}) {
        currentPage = page;
        const silent = !!opts.silent;
        const params = $('#historyFilters').serialize() + '&page=' + page;
        $('#exportStatementBtn').attr('href', routes.export + '?' + $('#historyFilters').serialize());

        if (!silent) {
            $('#activityFeed').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        }

        $.get(routes.transactions + '?' + params)
            .done(function (res) {
                if (!res.success) return;
                renderTransactions(res.transactions || []);
                renderPagination(res.pagination || {});
                const p = res.pagination || {};
                $('#historyCount').text((p.total ? ('Showing ' + (p.from || 0) + '–' + (p.to || 0) + ' of ' + p.total) : 'No results'));
                const pendingCount = (res.transactions || []).filter(function (r) { return r.is_live_pending; }).length;
                const badge = document.getElementById('pendingActivityBadge');
                if (badge) {
                    if (pendingCount > 0) {
                        badge.textContent = pendingCount + ' pending invoice' + (pendingCount === 1 ? '' : 's');
                        badge.classList.remove('d-none');
                    } else if ({{ (int) $pendingInvoiceCount }} === 0) {
                        badge.classList.add('d-none');
                    }
                }
            })
            .fail(function () {
                if (!silent) {
                    $('#activityFeed').html('<div class="text-center text-danger py-4">Failed to load activity</div>');
                }
            });
    }

    function renderTransactions(rows) {
        if (!rows.length) {
            $('#activityFeed').html(`
                <div class="wallet-empty">
                    <div class="wallet-empty-illu"><i class="fa fa-wallet"></i></div>
                    <h5 class="fw-semibold mb-1">No wallet activity yet.</h5>
                    <p class="text-muted mb-3">Add funds to start purchasing placements on the marketplace.</p>
                    <a class="btn btn-primary btn-sm" href="#depositSection">
                        <i class="fa fa-plus me-1"></i> Add Funds</a>
                </div>
            `);
            return;
        }

        let html = '<ul class="af-activity-feed mb-0">';
        rows.forEach(function (row) {
            const debit = row.direction === 'debit';
            const iconClass = row.type === 'bonus_credit' ? 'is-bonus' : (debit ? 'is-debit' : '');
            const amountClass = debit ? 'wallet-amount-debit' : 'wallet-amount-credit';
            const sign = debit ? '−' : '+';
            const pending = !!row.is_live_pending;
            const bal = row.balance_after != null ? ('Balance after ' + money(row.balance_after)) : '';

            // No stopPropagation here: the row handler already ignores clicks on
            // a/button, and swallowing the event stops it reaching the delegated
            // handlers bound on document — which is what killed "I paid".
            let actions = '';
            if (row.invoice_download_url) {
                actions += `<a class="btn btn-sm btn-primary" href="${escapeHtml(row.invoice_download_url)}" download>
                    <i class="fa fa-download me-1"></i> Download invoice</a>`;
            } else if (row.invoice_view_url) {
                actions += `<a class="btn btn-sm btn-outline-secondary" href="${escapeHtml(row.invoice_view_url)}" target="_blank" rel="noopener">
                    <i class="fa fa-file-invoice me-1"></i> Invoice</a>`;
            }
            if (row.can_mark_paid && row.mark_paid_url) {
                actions += `<button type="button" class="btn btn-sm btn-outline-primary mark-deposit-paid-btn"
                    data-mark-url="${escapeHtml(row.mark_paid_url)}"
                    data-ref="REF${escapeHtml(row.reference || '')}"
                    data-amount="${escapeHtml(String(row.amount || ''))}">
                    <i class="fa fa-check me-1"></i> I paid</button>`;
            } else if (row.user_marked_paid) {
                actions += `<span class="small text-success"><i class="fa fa-check-circle me-1"></i> Payment reported</span>`;
            }

            html += `<li class="af-activity-item ${pending ? 'is-pending' : ''} wallet-tx-row"
                data-source="${escapeHtml(row.source)}" data-id="${escapeHtml(row.id)}">
                <div class="af-activity-rail">
                    <span class="wallet-type-icon ${iconClass}"><i class="fa ${escapeHtml(row.icon || 'fa-circle')}"></i></span>
                </div>
                <div class="af-activity-main">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <p class="af-activity-title mb-0">${escapeHtml(row.type_label || '')}</p>
                        <span class="${statusClass(row.status)}">${escapeHtml(row.status || '')}</span>
                        ${pending ? '<span class="af-live-badge"><span class="af-live-dot"></span> Live</span>' : ''}
                    </div>
                    <p class="af-activity-desc">${escapeHtml(row.description || '')}</p>
                    <div class="af-activity-meta">
                        <code class="ref-code small">${escapeHtml(row.reference || '—')}</code>
                        <span class="af-activity-time" data-iso="${escapeHtml(row.date || '')}">${relativeTime(row.date)}</span>
                        ${bal ? `<span class="small text-muted">${bal}</span>` : ''}
                    </div>
                </div>
                <div class="af-activity-actions">
                    <div class="af-activity-amount ${amountClass}">${sign} ${money(row.amount)}</div>
                    ${actions}
                </div>
            </li>`;
        });
        html += '</ul>';
        $('#activityFeed').html(html);
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
                ctx.strokeStyle = 'rgba(26, 88, 94, 0.35)';
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
                    borderColor: '#1a585e',
                    backgroundColor: (ctx) => {
                        const chart = ctx.chart;
                        const {ctx: c, chartArea} = chart;
                        if (!chartArea) return 'rgba(26, 88, 94,.10)';
                        const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        g.addColorStop(0, 'rgba(14,165,233,.22)');
                        g.addColorStop(1, 'rgba(26, 88, 94,.02)');
                        return g;
                    },
                    borderWidth: 2.5,
                    tension: 0.35,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#0ea5e9',
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
                            drag: { enabled: true, backgroundColor: 'rgba(14,165,233,.08)', borderColor: 'rgba(26, 88, 94,.35)', borderWidth: 1 },
                            mode: 'x',
                        },
                        limits: { x: { min: 'original', max: 'original' } },
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148,163,184,.18)' },
                        ticks: { callback: (v) => '€' + v, color: '#75787B', font: { size: 11 } },
                        border: { display: false },
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#75787B', font: { size: 11 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
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

        setInterval(function () {
            loadTransactions(currentPage || 1, { silent: true });
            document.querySelectorAll('.af-activity-time[data-iso]').forEach(function (el) {
                el.textContent = relativeTime(el.getAttribute('data-iso'));
            });
        }, 30000);

        $('#historyFilters').on('submit', function (e) {
            e.preventDefault();
            loadTransactions(1);
        });

        if (typeof window.SlbLiveSearch !== 'undefined') {
            window.SlbLiveSearch.init(document.getElementById('addFundsSearchInput'), {
                mode: 'event',
                statusEl: document.getElementById('addFundsSearchInputStatus'),
                clearBtn: document.getElementById('addFundsSearchInputClear'),
                onSearch: function () { loadTransactions(1); },
            });
        }

        $(document).on('click', '#historyPagination .page-link', function () {
            const page = parseInt($(this).data('page'), 10);
            if (page) loadTransactions(page);
        });

        $(document).on('click', '.wallet-tx-row', function (e) {
            if ($(e.target).closest('a, button').length) return;
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


<script>
window.AddFundsBoot = {
    csrfToken: @json(csrf_token()),
    stripeReady: @json((bool) ($stripeConfigured ?? false)),
    cryptoEnabled: @json((bool) ($cryptoEnabled ?? false)),
    wisePayUrl: @json($wisePayUrl ?? config('billing.deposit_payment.wise_pay_url')),
    prefillAmount: @json($prefillAmount ?? null),
    prefillMethod: @json($prefillMethod ?? null),
    openCardsTab: @json((bool) ($openCardsTab ?? false)),
    routes: {
        store: @json(route('advertiser.add-funds.store', [], false)),
        addFunds: @json(route('advertiser.add-funds')),
        saveBilling: @json(route('advertiser.save-billing-info')),
        paySavedCard: @json(route('advertiser.add-funds.pay-saved-card', [], false)),
        createCheckout: @json(route('advertiser.create-checkout-session', [], false)),
        getBilling: @json(route('advertiser.get-billing-info')),
        paymentMethodsSetup: @json(route('advertiser.payment-methods.setup')),
        paymentMethodsBase: @json(url('/advertiser/payment-methods')),
        // Relative so APP_URL host mismatches do not break the QR <img> request.
        wiseQr: @json(route('advertiser.add-funds.wise-qr', absolute: false)),
    },
};
</script>
<script src="{{ asset('assets/js/add-funds.js') }}?v={{ @filemtime(public_path('assets/js/add-funds.js')) ?: '1' }}" defer></script>

@endsection
