@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Checkout</h2>
            <p class="text-muted mb-0">
                Review your order and proceed to payment.
            </p>
        </div>
    </div>

    @if(empty($cartItems) || count($cartItems) == 0)
        <div class="row">
            <div class="col-md-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="fa fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <h5>Your cart is empty</h5>
                        <p class="text-muted">You haven't added any items to your cart yet.</p>
                        <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary">
                            <i class="fa fa-list"></i> Browse Publishers
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @else
        <form id="checkoutForm">
            <div class="row">
                <!-- Left Column - Order Summary & Payment Methods -->
                <div class="col-lg-8">
                    <!-- 1. Order Summary -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white fw-semibold">
                            <i class="fa fa-shopping-cart me-2"></i> 1. Order Summary
                        </div>
                        <div class="card-body">
                            <div class="site-summary-list">
                                @php
                                    $placementNumber = 0;
                                @endphp
                                @foreach($cartItems as $index => $item)
                                    @for($i = 0; $i < $item['quantity']; $i++)
                                    @php
                                        $placementNumber++;
                                        $hasSensitive = !empty($item['sensitive_type']) && ($item['additional_price'] ?? 0) > 0;
                                    @endphp
                                    <div class="site-summary-card"
                                         data-site-id="{{ $item['id'] }}"
                                         data-copy-index="{{ $i }}"
                                         data-placement-number="{{ $placementNumber }}">
                                        <div class="site-summary-top">
                                            <div class="d-flex align-items-start gap-2 flex-grow-1 min-w-0">
                                                <span class="placement-number" aria-hidden="true">{{ $placementNumber }}</span>
                                                <div class="min-w-0">
                                                    <div class="site-summary-name">{{ $item['name'] }}</div>
                                                    <a href="{{ $item['url'] }}" target="_blank" class="site-summary-url text-decoration-none">
                                                        {{ Str::limit($item['url'], 55) }}
                                                        <i class="fa fa-external-link fa-xs"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="site-summary-price text-end">
                                                <div class="site-summary-price-label">Placement total</div>
                                                <div class="site-summary-price-value">€{{ number_format($item['price'], 2) }}</div>
                                            </div>
                                        </div>

                                        <div class="site-summary-details">
                                            <div class="site-summary-row">
                                                <span>Base price</span>
                                                <span class="site-summary-amount">€{{ number_format($item['base_price'], 2) }}</span>
                                            </div>
                                            @if($hasSensitive)
                                                <div class="site-summary-row site-summary-sensitive">
                                                    <span>
                                                        Sensitive topic
                                                        <strong>{{ ucfirst($item['sensitive_type']) }}</strong>
                                                    </span>
                                                    <span class="site-summary-amount site-summary-amount-accent">+€{{ number_format($item['additional_price'], 2) }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    @endfor
                                @endforeach
                            </div>
                        </div>
                    </div>

                    @if(!empty($librarySubmission) && $librarySubmission->isReadyForCheckout())
                        <div class="card border-0 shadow-sm mb-4" id="contentSubmissionWizard" data-library-ready="1">
                            <div class="card-header bg-white fw-semibold">
                                <i class="fa fa-file-word me-2"></i> 2. Approved article
                            </div>
                            <div class="card-body">
                                <div class="alert alert-success mb-3">
                                    This order uses an approved article from your Content Library. You can proceed to payment.
                                </div>
                                <div class="fw-semibold">{{ $librarySubmission->title ?: $librarySubmission->original_filename }}</div>
                                <div class="small text-muted mb-2">
                                    Uniqueness {{ $librarySubmission->uniqueness_score }}% · Quality {{ $librarySubmission->quality_score }}%
                                </div>
                                <div class="small mb-1"><strong>Anchor:</strong> {{ $librarySubmission->anchor_text }}</div>
                                <div class="small mb-1"><strong>Target URL:</strong> <a href="{{ $librarySubmission->target_url }}" target="_blank" rel="noopener">{{ $librarySubmission->target_url }}</a></div>
                                @if(($checkoutSchedule['mode'] ?? 'immediate') === 'scheduled')
                                    <div class="small mt-2">
                                        <strong>Scheduled for:</strong>
                                        {{ $checkoutSchedule['date'] ?? '' }} {{ $checkoutSchedule['time'] ?? '' }}
                                        ({{ $checkoutSchedule['timezone'] ?? 'UTC' }})
                                        · Charged in advance · Publisher will be notified to publish on this date
                                    </div>
                                @endif
                                <a href="{{ route('advertiser.content-library') }}" class="btn btn-sm btn-outline-secondary mt-3">Back to Content Library</a>
                            </div>
                        </div>
                        <script>
                        window.ContentCheckout = {
                            ready: function () { return true; },
                            payload: function () {
                                return {
                                    content_submissions: {},
                                    publication_mode: @json($checkoutSchedule['mode'] ?? 'immediate'),
                                    scheduled_date: @json($checkoutSchedule['date'] ?? null),
                                    scheduled_time: @json($checkoutSchedule['time'] ?? null),
                                    timezone: @json($checkoutSchedule['timezone'] ?? 'UTC'),
                                };
                            }
                        };
                        </script>
                    @else
                        <div class="alert alert-warning shadow-sm">
                            Articles must be uploaded and approved in your
                            <a href="{{ route('advertiser.content-library') }}" class="alert-link">Content Library</a>
                            before checkout. Only Microsoft Word (.docx) files are accepted. Uniqueness must be at least 50%.
                        </div>
                        @include('advertiser.partials.content-submission-wizard')
                    @endif

                    <!-- 3. Payment Methods -->
                    <div class="card border-0 shadow-sm mb-4" id="paymentSectionCard">
                        <div class="card-header bg-white fw-semibold">
                            <i class="fa fa-credit-card me-2"></i> 3. Payment
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">Recommended for fastest checkout.</p>

                            <!-- Recommended: Wallet -->
                            <div class="payment-option payment-option-recommended mb-3" data-method="wallet" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with wallet balance">
                                <div class="payment-option-card recommended" style="border: 2px solid #4ECDCB; border-radius: 12px; padding: 16px; background: #f0fbfb; transition: all 0.2s; display:flex; align-items:center; gap:14px;">
                                    <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #dcfce7; border-radius: 8px; flex-shrink:0;">
                                        <i class="fas fa-wallet" style="font-size: 24px; color: #16a34a;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                            <span style="font-weight: 700; font-size: 14px; color: #0b6266;">Wallet Balance</span>
                                            <span style="font-size: 11px; font-weight: 600; color: #0b6266; background: #c8ebe9; padding: 2px 8px; border-radius: 999px;">Recommended</span>
                                        </div>
                                        <span style="font-size: 12px; color: #6b7280; display: block; margin-top: 2px;">Pay instantly from your available balance</span>
                                    </div>
                                    <i class="fa fa-check-circle payment-check" style="color:#4ECDCB; font-size:20px; opacity:0;"></i>
                                </div>
                            </div>

                            <button type="button" class="btn btn-link p-0 text-decoration-none" id="toggleOtherPayments" style="color:#0b6266; font-weight:600;">
                                <i class="fa fa-chevron-down me-1" id="otherPaymentsChevron"></i> Other methods
                            </button>

                            <div id="otherPaymentMethods" style="display: none; margin-top: 14px;">
                                <div class="other-payments-grid">
                                    <div class="payment-option" data-method="wise" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with Wise transfer">
                                        <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                            <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                                <img src="{{ asset('assets/img/wiseImg-logo.png') }}" alt="" style="width: 32px; height: 32px; object-fit: contain;">
                                            </div>
                                            <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Wise Transfer</span>
                                            <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Bank transfer via Wise</span>
                                        </div>
                                    </div>

                                    <div class="payment-option" data-method="crypto" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with cryptocurrency">
                                        <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                            <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #fef3c7; border-radius: 8px; margin: 0 auto 8px;">
                                                <i class="fab fa-bitcoin" style="font-size: 28px; color: #eab308;" aria-hidden="true"></i>
                                            </div>
                                            <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Cryptocurrency</span>
                                            <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">BTC, USDT, Binance Pay</span>
                                        </div>
                                    </div>

                                    <div class="payment-option" data-method="bank" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with bank transfer">
                                        <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                            <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                                <i class="fas fa-university" style="font-size: 28px; color: #0b6266;" aria-hidden="true"></i>
                                            </div>
                                            <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Bank Transfer</span>
                                            <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Traditional bank transfer</span>
                                        </div>
                                    </div>

                                    <div class="payment-option" data-method="card" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with credit or debit card">
                                        <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                            <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 8px; margin: 0 auto 8px;">
                                                <i class="fab fa-stripe" style="font-size: 28px; color: #635bff;" aria-hidden="true"></i>
                                            </div>
                                            <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Credit/Debit Card</span>
                                            <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Secure Stripe checkout</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="paymentError" style="display: none; margin-top: 12px; font-size: 14px; color: #dc2626;">
                                Please select a payment method
                            </div>
                        </div>
                    </div>

                    <!-- Payment Details Section -->
                    <div id="paymentDetailsSection" style="display: none;">
                        <!-- Wallet Payment Details -->
                        <div id="walletPaymentDetails" class="card border-0 shadow-sm mb-4" style="display: none;">
                            <div class="card-body">
                                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                                    <div style="width: 40px; height: 40px; background: #dcfce7; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                                        <i class="fas fa-wallet" style="font-size: 24px; color: #16a34a;"></i>
                                    </div>
                                    <div>
                                        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Wallet Balance Payment</h3>
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">Pay using your wallet balance</p>
                                    </div>
                                </div>
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
                                    @php
                                        $checkoutWallet = auth()->user()->activeWallet();
                                        $checkoutBonus = $checkoutWallet ? $checkoutWallet->lockedBonusBalance() : 0;
                                    @endphp
                                    <div style="margin-bottom: 16px;">
                                        <p style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">
                                            Your wallet
                                            <i class="fas fa-info-circle text-muted ms-1"
                                               data-bs-toggle="tooltip"
                                               data-bs-placement="top"
                                               title="You can use this full amount to pay for this order."></i>
                                        </p>
                                        <p style="font-size: 24px; font-weight: 700; color: #16a34a; margin: 0;">
                                            €{{ number_format($checkoutWallet?->balance ?? 0, 2) }}
                                        </p>
                                        @if($checkoutBonus > 0)
                                            <p style="font-size: 12px; color: #6b7280; margin: 6px 0 0;">
                                                Includes €{{ number_format($checkoutBonus, 2) }} free credit
                                                <i class="fas fa-info-circle text-muted ms-1"
                                                   data-bs-toggle="tooltip"
                                                   data-bs-placement="top"
                                                   title="Free credit is a welcome gift for orders. You can spend it here, but you cannot withdraw it as cash."></i>
                                                — spend on orders only, not withdrawable
                                            </p>
                                        @endif
                                    </div>
                                    
                                    <div style="margin-bottom: 16px;">
                                        <p style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">Amount to Pay:</p>
                                        <p style="font-size: 20px; font-weight: 600; color: #1f2937; margin: 0;">
                                            €{{ number_format($total, 2) }}
                                        </p>
                                    </div>
                                    
                                    @php
                                        $balance = auth()->user()->activeWallet()?->balance ?? 0;
                                        $totalAmount = $total;
                                        $hasInsufficientBalance = $balance < $totalAmount;
                                    @endphp
                                    
                                    @if($hasInsufficientBalance)
                                        <div style="background: #fee2e2; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                                            <div style="display: flex; align-items: center;">
                                                <i class="fas fa-exclamation-triangle" style="color: #dc2626; margin-right: 8px;"></i>
                                                <p style="font-size: 12px; color: #991b1b; margin: 0;">
                                                    Insufficient balance. Please add funds or choose another payment method.
                                                </p>
                                            </div>
                                        </div>
                                    @else
                                        <div style="background: #dcfce7; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                                            <div style="display: flex; align-items: center;">
                                                <i class="fas fa-check-circle" style="color: #16a34a; margin-right: 8px;"></i>
                                                <p style="font-size: 12px; color: #166534; margin: 0;">
                                                    Sufficient balance available. Payment will be deducted from your wallet.
                                                </p>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

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
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
                                    <p style="font-size: 14px; color: #6b7280; margin-bottom: 12px;">Send <strong style="color: #1f2937;">€{{ number_format($total, 2) }}</strong> using the link or QR code below:</p>
                                    
                                    <div style="margin-bottom: 16px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">Wise Payment Link</p>
                                        <div id="wisePaymentLink" style="background: white; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; word-break: break-all; font-family: monospace;">
                                            https://wise.com/pay/business/topurlzltd?amount={{ $total }}&currency=EUR
                                        </div>
                                        <button type="button" class="copy-btn mt-2" data-target="wisePaymentLink" style="padding: 4px 12px; font-size: 12px; background: #e5e7eb; border: none; border-radius: 4px; cursor: pointer;">
                                            <i class="fas fa-copy"></i> Copy Payment Link
                                        </button>
                                    </div>
                                    
                                    <div style="text-align: center; margin-bottom: 16px;">
                                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">QR Code for Payment</p>
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://wise.com/pay/business/topurlzltd?amount={{ $total }}&currency=EUR" 
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
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
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
                                
                                <div style="background: #f9fafb; border-radius: 12px; padding: 20px; border: 1px solid #e5e7eb;">
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

                        <!-- Card Payment Details -->
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

                <!-- Right Column - Order Total -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white fw-semibold">
                            <i class="fa fa-calculator me-2"></i> Order Total
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Subtotal:</span>
                                <span id="subtotal">€{{ number_format($total, 2) }}</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Tax (0%):</span>
                                <span id="taxAmount">€0.00</span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between mb-3">
                                <strong>Total:</strong>
                                <strong class="checkout-theme-price fs-5" id="grandTotal">€{{ number_format($total, 2) }}</strong>
                            </div>

                            <!-- Reference Code -->
                            <div class="alert alert-secondary py-2 px-3 mb-3" style="background-color: #f8f9fa;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="small">Reference Code:</span>
                                    <div>
                                        <strong id="referenceCode" class="font-monospace">{{ sprintf('%06d', mt_rand(1, 999999)) }}</strong>
                                        <button type="button" class="btn btn-sm btn-link p-0 ms-2 copy-ref-btn" data-target="referenceCode">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-warning py-2 px-3 mb-3">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <small>Please include <strong id="refCodeDisplay">REF{{ sprintf('%06d', mt_rand(1, 999999)) }}</strong> in your payment note for manual payments. For card payments, reference is auto-recorded.</small>
                            </div>

                            <button type="button" id="placeOrderBtn" class="btn btn-primary w-100 mt-3">
                                <i class="fa fa-check-circle"></i> Place Order
                            </button>

                            <a href="{{ route('advertiser.catalog') }}" class="btn btn-outline-secondary w-100 mt-2">
                                <i class="fa fa-arrow-left"></i> Continue Shopping
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @endif
</div>

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
                            <label class="form-label">Company Name</label>
                            <input type="text" name="company_name" id="company_name" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                            <select name="country" id="country" class="form-select" required>
                                @include('partials.marketplace-country-options', ['selectedCountry' => old('country', auth()->user()->country ?? '')])
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">State/Province</label>
                            <input type="text" name="state" id="state" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" id="city" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Postal Code</label>
                            <input type="text" name="postal_code" id="postal_code" class="form-control">
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
</div>

<style>   
.table td, .table th {
    padding: 12px 15px;
    vertical-align: middle;
}

.card-header {
    border-bottom: 1px solid #eee;
}

.content-link {
    font-size: 12px;
}

.content-link:focus {
    border-color: #4ECDCB;
    box-shadow: 0 0 0 0.2rem rgba(78, 205, 203, 0.25);
}

.payment-option {
    cursor: pointer;
}

.other-payments-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

@media (min-width: 576px) {
    .other-payments-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 992px) {
    .other-payments-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

.payment-option.selected .payment-option-card {
    border-color: #0b6266 !important;
    background: #f0fbfb !important;
    box-shadow: 0 4px 6px -1px rgba(11, 98, 102, 0.12);
}

.payment-option.selected .payment-check {
    opacity: 1 !important;
}

.content-link-row {
    padding-bottom: 4px;
    border-bottom: 1px solid #f1f5f9;
}

.content-link-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.placement-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #0b6266;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
    line-height: 1;
}

.site-summary-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.site-summary-card {
    border: 1px solid #cfe8e9;
    border-radius: 12px;
    padding: 16px;
    background: #fff;
    box-shadow: 0 1px 0 rgba(11, 98, 102, 0.04);
    border-left: 4px solid #4ECDCB;
}

.site-summary-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 12px;
}

.site-summary-name {
    font-weight: 600;
    color: #212529;
    font-size: 15px;
    margin-bottom: 2px;
}

.site-summary-url {
    font-size: 12px;
    color: #6b7280;
}

.site-summary-url:hover {
    color: #0b6266;
}

.site-summary-price-label {
    font-size: 11px;
    color: #6b7280;
    margin-bottom: 2px;
}

.site-summary-price-value,
.checkout-theme-price {
    font-weight: 800;
    font-size: 1.35rem;
    color: #3aaeb2;
    letter-spacing: -0.02em;
}

.site-summary-price {
    background: rgba(78, 205, 203, 0.12);
    border-radius: 10px;
    padding: 8px 12px;
    min-width: 120px;
}

.site-summary-details {
    border-top: 1px dashed #d7e7e8;
    padding-top: 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.site-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    color: #4b5563;
}

.site-summary-amount {
    font-weight: 600;
    color: #3aaeb2;
}

.site-summary-amount-accent {
    color: #0b6266;
}

.site-summary-sensitive {
    background: rgba(78, 205, 203, 0.12);
    border-radius: 8px;
    padding: 8px 10px;
}

@media (max-width: 575.98px) {
    .site-summary-top {
        flex-direction: column;
    }
    .site-summary-price {
        text-align: left !important;
        width: 100%;
        padding-left: 32px;
    }
}

.copy-btn {
    cursor: pointer;
    transition: background 0.2s;
}

.cm-box {
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
}
.cm-loading {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    color: #075985;
}
.cm-pass {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
}
.cm-fail {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}
.cm-checks {
    padding-left: 1.1rem;
    margin: 0;
}
.cm-checks li {
    margin-bottom: 0.2rem;
}
.content-link.is-invalid {
    border-color: #dc2626;
}
.content-link.is-valid {
    border-color: #16a34a;
}

.copy-btn:hover {
    background: #d1d5db !important;
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
    let referenceCode = Math.floor(100000 + Math.random() * 900000).toString();
    
    const refCodeDisplay = document.getElementById('referenceCode');
    const refCodeDisplaySpan = document.getElementById('refCodeDisplay');
    const refCodeTexts = document.querySelectorAll('.ref-code-display');
    
    function updateReferenceCode() {
        if (refCodeDisplay) refCodeDisplay.innerText = referenceCode;
        if (refCodeDisplaySpan) refCodeDisplaySpan.innerText = `REF${referenceCode}`;
        refCodeTexts.forEach(el => {
            el.innerText = `REF${referenceCode}`;
        });
    }
    
    updateReferenceCode();
    
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

    const paymentOptions = document.querySelectorAll('.payment-option');
    const paymentDetailsSection = document.getElementById('paymentDetailsSection');
    const walletDetails = document.getElementById('walletPaymentDetails');
    const wiseDetails = document.getElementById('wisePaymentDetails');
    const cryptoDetails = document.getElementById('cryptoPaymentDetails');
    const bankDetails = document.getElementById('bankPaymentDetails');
    const cardDetails = document.getElementById('cardPaymentDetails');
    const placeOrderBtn = document.getElementById('placeOrderBtn');
    const toggleOtherPayments = document.getElementById('toggleOtherPayments');
    const otherPaymentMethods = document.getElementById('otherPaymentMethods');
    const otherPaymentsChevron = document.getElementById('otherPaymentsChevron');

    if (toggleOtherPayments && otherPaymentMethods) {
        toggleOtherPayments.addEventListener('click', function() {
            const open = otherPaymentMethods.style.display !== 'none';
            otherPaymentMethods.style.display = open ? 'none' : 'block';
            if (otherPaymentsChevron) {
                otherPaymentsChevron.classList.toggle('fa-chevron-down', open);
                otherPaymentsChevron.classList.toggle('fa-chevron-up', !open);
            }
            toggleOtherPayments.childNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                    node.textContent = open ? ' Other methods' : ' Hide other methods';
                }
            });
        });
    }
    
    let selectedMethod = null;
    const totalAmount = {{ $total }};
    const walletBalance = {{ auth()->user()->activeWallet()?->balance ?? 0 }};

    paymentOptions.forEach(option => {
        option.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
        option.addEventListener('click', function() {
            const method = this.dataset.method;
            if (!method) return;
            selectedMethod = method;
            
            paymentOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            
            if (walletDetails) walletDetails.style.display = 'none';
            if (wiseDetails) wiseDetails.style.display = 'none';
            if (cryptoDetails) cryptoDetails.style.display = 'none';
            if (bankDetails) bankDetails.style.display = 'none';
            if (cardDetails) cardDetails.style.display = 'none';
            
            if (method === 'wallet' && walletDetails) walletDetails.style.display = 'block';
            else if (method === 'wise' && wiseDetails) wiseDetails.style.display = 'block';
            else if (method === 'crypto' && cryptoDetails) cryptoDetails.style.display = 'block';
            else if (method === 'bank' && bankDetails) bankDetails.style.display = 'block';
            else if (method === 'card' && cardDetails) cardDetails.style.display = 'block';
            
            if (paymentDetailsSection) paymentDetailsSection.style.display = 'block';

            if (method !== 'wallet' && otherPaymentMethods && otherPaymentMethods.style.display === 'none') {
                otherPaymentMethods.style.display = 'block';
                if (otherPaymentsChevron) {
                    otherPaymentsChevron.classList.remove('fa-chevron-down');
                    otherPaymentsChevron.classList.add('fa-chevron-up');
                }
            }
            
            const paymentError = document.getElementById('paymentError');
            if (paymentError) paymentError.style.display = 'none';
        });
    });

    document.querySelectorAll('.copy-btn').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const textElement = document.getElementById(targetId);
            if (textElement) {
                const textToCopy = textElement.innerText;
                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => {
                        this.innerHTML = originalText;
                    }, 2000);
                });
            }
        });
    });

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (ch) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]);
        });
    }

    function contentReady() {
        return window.ContentCheckout && typeof window.ContentCheckout.ready === 'function'
            ? window.ContentCheckout.ready()
            : false;
    }

    function syncPlaceOrderForModeration() {
        if (!placeOrderBtn) return;
        if (!contentReady()) {
            placeOrderBtn.disabled = true;
            if (!placeOrderBtn.dataset.busy) {
                placeOrderBtn.innerHTML = '<i class="fa fa-file-alt"></i> Complete content steps to continue';
            }
        } else if (!placeOrderBtn.dataset.busy) {
            placeOrderBtn.disabled = !selectedMethod;
            placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
        }
    }

    setInterval(syncPlaceOrderForModeration, 1200);

    // Get billing info from user profile
    function getBillingInfo() {
        return fetch('{{ route("advertiser.get-billing-info") }}', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        }).then(response => response.json());
    }

    // Save billing info to user profile
    function saveBillingInfo(formData) {
        return fetch('{{ route("advertiser.save-billing-info") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(formData)
        }).then(response => response.json());
    }

    // Submit order function
    function submitOrder() {
        const contentPayload = window.ContentCheckout.payload();
        fetch('{{ route("advertiser.checkout.process") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(Object.assign({
                payment_method: selectedMethod,
                reference_code: referenceCode
            }, contentPayload || {}))
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.requires_payment && data.checkout_url) {
                    window.location.href = data.checkout_url;
                } else if (selectedMethod === 'bank') {
                    // Use the invoice.blade.php template
                    const invoiceUrl = '/advertiser/invoice/' + referenceCode;
                    
                    Swal.fire({
                        title: 'Order Placed Successfully!',
                        html: `Your order has been placed.<br><br>
                               <strong>Reference Code:</strong> REF${referenceCode}<br>
                               <strong>Total Amount:</strong> €${totalAmount.toFixed(2)}<br><br>
                               <a href="${invoiceUrl}" target="_blank" class="btn btn-primary">
                                   <i class="fa fa-file-invoice"></i> View Invoice
                               </a>`,
                        icon: 'success',
                        confirmButtonText: 'Go to Orders',
                        showCancelButton: true,
                        cancelButtonText: 'Stay Here'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = '{{ route("advertiser.orders") }}';
                        } else {
                            placeOrderBtn.disabled = false;
                            placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
                            window.open(invoiceUrl, '_blank');
                        }
                    });
                    
                    placeOrderBtn.disabled = false;
                    placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
                } else if (data.message) {
                    Swal.fire('Success', data.message, 'success').then(() => {
                        window.location.href = '{{ route("advertiser.orders") }}';
                    });
                } else {
                    Swal.fire('Success', 'Order placed successfully!', 'success').then(() => {
                        window.location.href = '{{ route("advertiser.orders") }}';
                    });
                }
            } else {
                const modTitle = data.moderation?.title || 'Error';
                const modMsg = data.moderation?.failures?.[0]?.message || data.message || 'Failed to process order';
                Swal.fire({
                    icon: 'error',
                    title: modTitle,
                    html: `<div style="white-space:pre-line;text-align:left;">${escapeHtml(modMsg)}</div>`
                });
                placeOrderBtn.dataset.busy = '';
                placeOrderBtn.disabled = false;
                placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
                syncPlaceOrderForModeration();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Network error. Please try again.', 'error');
            placeOrderBtn.disabled = false;
            placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
        });
    }

    // Place order click handler
    placeOrderBtn.addEventListener('click', function() {
        if (!selectedMethod) {
            Swal.fire('Error', 'Please select a payment method', 'warning');
            return;
        }

        if (!contentReady()) {
            Swal.fire({
                icon: 'warning',
                title: 'Content submission incomplete',
                text: 'Upload an approved article and complete anchor text, target URL, and schedule steps for every placement.'
            });
            return;
        }
        
        // For bank transfer, check billing info
        if (selectedMethod === 'bank') {
            getBillingInfo().then(billingResponse => {
                if (billingResponse.success && billingResponse.data.has_info) {
                    placeOrderBtn.dataset.busy = '1';
                    placeOrderBtn.disabled = true;
                    placeOrderBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
                    submitOrder();
                } else {
                    const modal = new bootstrap.Modal(document.getElementById('billingInfoModal'));
                    modal.show();
                    
                    document.getElementById('saveBillingInfo').onclick = function() {
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
                        
                        saveBillingInfo(formData).then(data => {
                            if (data.success) {
                                modal.hide();
                                placeOrderBtn.dataset.busy = '1';
                                placeOrderBtn.disabled = true;
                                placeOrderBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
                                submitOrder();
                            } else {
                                Swal.fire('Error', data.message || 'Failed to save billing information', 'error');
                            }
                        });
                    };
                }
            });
        } else {
            placeOrderBtn.dataset.busy = '1';
            placeOrderBtn.disabled = true;
            placeOrderBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
            submitOrder();
        }
    });

    syncPlaceOrderForModeration();
});
</script>

<!-- SweetAlert2 for better alerts -->  
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

@endsection