@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    @php
        $checkoutInWizard = request()->boolean('wizard')
            || ! empty(\App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language']);
    @endphp
    @include('advertiser.partials.ordering-path', [
        'step' => 4,
        'title' => 'Place a guest post · Pay',
        'subtitle' => 'One job here: confirm articles and pay. Live URL tracking starts after the order is placed.',
        'linkAll' => true,
        'contentRoute' => $checkoutInWizard
            ? route('advertiser.wizard.content')
            : route('advertiser.content-library'),
        'actions' => $checkoutInWizard
            ? '<a href="'.e(route('advertiser.catalog', ['wizard' => 1])).'" class="btn btn-sm btn-outline-primary">Browse more publishers</a>'
                .'<a href="'.e(route('advertiser.wizard.content')).'" class="btn btn-sm btn-outline-secondary">Back to content</a>'
            : '<a href="'.e(route('advertiser.catalog')).'" class="btn btn-sm btn-outline-primary">Browse more publishers</a>'
                .'<button type="button" class="btn btn-sm btn-outline-secondary" onclick="openCart()">Review cart</button>',
    ])

    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Checkout</h2>
            <p class="text-muted mb-0">
                Pay only for websites that are ready (approved article assigned). Others stay in your cart.
            </p>
        </div>
    </div>

    @if(($deferredCount ?? 0) > 0)
        <div class="alert alert-light border mb-4" role="status">
            <div class="fw-semibold mb-1">
                <i class="fa fa-info-circle me-1"></i>
                Paying {{ (int) ($payableCount ?? 0) }} ready site{{ (int) ($payableCount ?? 0) === 1 ? '' : 's' }}
                @if((float) $total > 0)
                    · €{{ number_format($total, 2) }}
                @endif
            </div>
            <div class="small text-muted mb-0">
                {{ (int) $deferredCount }} website{{ (int) $deferredCount === 1 ? '' : 's' }} without a ready article
                will stay in your cart and will not be charged yet.
            </div>
        </div>
    @elseif(!($payableReady ?? true))
        <div class="alert alert-warning border mb-4" role="status">
            <div class="fw-semibold mb-1">Nothing ready to pay yet</div>
            <div class="small mb-0">
                Assign an approved Content Library article to at least one website below, then place the order.
            </div>
        </div>
    @endif
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
                                    @php
                                        $summaryArticleId = (int) ($item['content_submission_id'] ?? ($librarySubmission->id ?? 0));
                                        $summaryArticle = $summaryArticleId && isset($checkoutArticles)
                                            ? ($checkoutArticles[$summaryArticleId] ?? null)
                                            : null;
                                        if (! $summaryArticle && $librarySubmission && (int) $librarySubmission->id === $summaryArticleId) {
                                            $summaryArticle = $librarySubmission;
                                        }
                                    @endphp
                                    <div class="site-summary-card {{ !empty($item['paying_now']) ? 'is-paying-now' : 'is-stays-in-cart' }}"
                                         data-site-id="{{ $item['id'] }}"
                                         data-copy-index="{{ $i }}"
                                         data-placement-number="{{ $placementNumber }}"
                                         data-content-submission-id="{{ $summaryArticleId ?: '' }}"
                                         data-paying-now="{{ !empty($item['paying_now']) ? '1' : '0' }}">
                                        <div class="site-summary-top">
                                            <div class="d-flex align-items-start gap-2 flex-grow-1 min-w-0">
                                                <span class="placement-number" aria-hidden="true">{{ $placementNumber }}</span>
                                                <div class="min-w-0">
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                        <div class="site-summary-name mb-0">{{ $item['name'] }}</div>
                                                        @if(!empty($item['paying_now']))
                                                            <span class="badge text-bg-success border">Paying now</span>
                                                        @else
                                                            <span class="badge text-bg-light border text-muted">Stays in cart</span>
                                                        @endif
                                                    </div>
                                                    <a href="{{ $item['url'] }}" target="_blank" class="site-summary-url text-decoration-none">
                                                        {{ Str::limit($item['url'], 55) }}
                                                        <i class="fa fa-external-link fa-xs"></i>
                                                    </a>
                                                    @if(empty($item['paying_now']))
                                                        <div class="small text-muted mt-1">Assign an approved article to include this site in payment.</div>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="site-summary-price text-end">
                                                <div class="site-summary-price-label">{{ !empty($item['paying_now']) ? 'Charged now' : 'Not charged yet' }}</div>
                                                @if(!empty($item['line_savings']) && $item['line_savings'] > 0)
                                                    <div class="small text-muted text-decoration-line-through">€{{ number_format($item['line_list_total'] ?? ($item['list_total'] * $item['quantity']), 2) }}</div>
                                                @endif
                                                <div class="site-summary-price-value {{ empty($item['paying_now']) ? 'text-muted' : '' }}">€{{ number_format($item['total'] ?? $item['price'], 2) }}</div>
                                                @if(!empty($item['discount_labels']))
                                                    <div class="small text-success">{{ implode(' · ', $item['discount_labels']) }}</div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="site-summary-details">
                                            <div class="site-summary-row">
                                                <span>Base price</span>
                                                <span class="site-summary-amount">€{{ number_format($item['base_price'], 2) }}</span>
                                            </div>
                                            @if(!empty($item['line_savings']) && $item['line_savings'] > 0)
                                            <div class="site-summary-row">
                                                <span>Discount savings</span>
                                                <span class="site-summary-amount text-success">−€{{ number_format($item['line_savings'], 2) }}</span>
                                            </div>
                                            @endif
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

                                        @if($summaryArticle)
                                            <div class="order-summary-article mt-3">
                                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                                    <div>
                                                        <div class="small text-uppercase text-muted fw-semibold">Article</div>
                                                        <div class="fw-semibold">{{ $summaryArticle->title ?: $summaryArticle->original_filename }}</div>
                                                        <div class="small text-muted">
                                                            {{ strtoupper((string) $summaryArticle->country) }}/{{ strtoupper((string) $summaryArticle->language) }}
                                                            @if($summaryArticle->word_count)
                                                                · {{ $summaryArticle->word_count }} words
                                                            @endif
                                                            @if($summaryArticle->uniqueness_score !== null)
                                                                · {{ $summaryArticle->uniqueness_score }}% unique
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                                @if($summaryArticle->preview_html)
                                                    <div class="order-summary-article-preview">
                                                        {!! \App\Services\ContentUpload\ArticlePreviewHtml::normalize((string) $summaryArticle->preview_html) !!}
                                                    </div>
                                                @endif
                                                @php $history = $summaryArticle->articleHistory(); @endphp
                                                @if(!empty($history))
                                                    <div class="order-summary-article-history mt-2">
                                                        <div class="small text-uppercase text-muted fw-semibold mb-1">Article history</div>
                                                        <ul class="order-summary-history-list">
                                                            @foreach(array_slice($history, -6) as $event)
                                                                <li>
                                                                    <span class="history-label">{{ $event['label'] }}</span>
                                                                    @if(!empty($event['detail']))
                                                                        <span class="history-detail">{{ \Illuminate\Support\Str::limit($event['detail'], 80) }}</span>
                                                                    @endif
                                                                    @if(!empty($event['at']))
                                                                        <span class="history-at">{{ \Illuminate\Support\Carbon::parse($event['at'])->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span>
                                                                    @endif
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    @endfor
                                @endforeach
                            </div>
                        </div>
                    </div>

                    @include('advertiser.partials.checkout-content-assignment')

                    <!-- 3. Payment Methods -->
                    <div class="card border-0 shadow-sm mb-4" id="paymentSectionCard">
                        <div class="card-header bg-white fw-semibold">
                            <i class="fa fa-credit-card me-2"></i> 3. Payment
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">Pay from your wallet, or by card. Bank, Wise, and crypto fund your wallet via invoice first.</p>

                            <!-- Recommended: Wallet -->
                            <div class="payment-option payment-option-recommended mb-3" data-method="wallet" style="cursor: pointer;" role="button" tabindex="0" aria-label="Pay with wallet balance">
                                <div class="payment-option-card recommended">
                                    <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #dcfce7; border-radius: 8px; flex-shrink:0;">
                                        <i class="fas fa-wallet" style="font-size: 24px; color: #16a34a;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                            <span style="font-weight: 700; font-size: 14px; color: #185054;">Wallet Balance</span>
                                            <span style="font-size: 11px; font-weight: 600; color: #185054; background: #c8ebe9; padding: 2px 8px; border-radius: 999px;">Recommended</span>
                                        </div>
                                        <span style="font-size: 12px; color: #6b7280; display: block; margin-top: 2px;">Instant — publisher notified as soon as you place the order</span>
                                    </div>
                                    <i class="fa fa-check-circle payment-check" aria-hidden="true"></i>
                                </div>
                            </div>

                            @php $cardNeedsAmount = (float) $total > 0; @endphp
                            <div class="payment-option mb-3 {{ (empty($stripeConfigured) || ! $cardNeedsAmount) ? 'payment-option-disabled' : '' }}"
                                 data-method="card"
                                 data-requires-amount="1"
                                 style="cursor: {{ (empty($stripeConfigured) || ! $cardNeedsAmount) ? 'not-allowed' : 'pointer' }}; {{ (empty($stripeConfigured) || ! $cardNeedsAmount) ? 'opacity:.55;' : '' }}"
                                 role="button"
                                 tabindex="0"
                                 aria-label="Pay with credit or debit card"
                                 @if(empty($stripeConfigured)) aria-disabled="true" data-stripe-disabled="1" @endif
                                 @if(! $cardNeedsAmount) aria-disabled="true" data-zero-amount="1" @endif>
                                <div class="payment-option-card">
                                    <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 8px; flex-shrink:0;">
                                        <i class="fab fa-stripe" style="font-size: 28px; color: #635bff;" aria-hidden="true"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <span style="font-weight: 700; font-size: 14px; color: #1f2937;">Credit / Debit Card</span>
                                        <span style="font-size: 12px; color: #6b7280; display: block; margin-top: 2px;">
                                            @if(! $cardNeedsAmount)
                                                Available when amount due is greater than €0
                                            @else
                                                Secure Stripe checkout — ready sites only
                                            @endif
                                        </span>
                                    </div>
                                    <i class="fa fa-check-circle payment-check" aria-hidden="true"></i>
                                </div>
                            </div>
                            <div class="alert alert-light border py-2 px-3 mb-3 small d-none" id="stripeZeroAmountAlert">
                                Card / Stripe is only for amounts greater than €0. When bonus covers the order, use <strong>Wallet</strong>.
                            </div>
                            @if(empty($stripeConfigured))
                                <div class="alert alert-warning py-2 px-3 mb-3 small" id="stripeNotConfiguredAlert">
                                    Card payments are not configured on this server. Set <code>STRIPE_KEY</code> and <code>STRIPE_SECRET</code> in <code>.env</code>, then run <code>php artisan config:clear</code>, or pay with wallet.
                                </div>
                            @elseif(!app(\App\Services\StripeCustomerService::class)->usersTableReady())
                                <div class="alert alert-warning py-2 px-3 mb-3 small" id="stripeSchemaAlert">
                                    Stripe keys are present, but the database is missing <code>users.stripe_customer_id</code>.
                                    Run <code>database/sql/fix_users_stripe_customer_columns.sql</code> in phpMyAdmin (Hostinger), then retry card checkout.
                                </div>
                            @endif

                            <div class="border rounded-3 p-3 mb-1" style="background:#f8fafc; border-color:#e2e8f0 !important;" id="fundWalletCheckoutHint">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold" style="color:#185054;">Paying by Bank, Wise, or crypto?</div>
                                        <p class="small text-muted mb-0 mt-1">
                                            Get an invoice on Add Funds, transfer with your REF, then we credit your wallet after funds arrive. Come back and pay this order from your wallet.
                                        </p>
                                    </div>
                                    <a href="{{ route('advertiser.add-funds', ['amount' => max(10, (int) ceil((float) $total))]) }}"
                                       class="btn btn-sm btn-outline-primary flex-shrink-0"
                                       id="fundWalletFromCheckout">
                                        <i class="fa fa-file-invoice me-1"></i> Add funds &amp; get invoice
                                    </a>
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
                                        $checkoutCash = $checkoutCashBalance ?? 0;
                                        $checkoutBonus = $checkoutBonusBalance ?? 0;
                                        $checkoutSpendable = $checkoutSpendableBalance ?? (($checkoutCash + $checkoutBonus));
                                    @endphp
                                    <div style="margin-bottom: 16px;">
                                        <p style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">Your wallet</p>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="small text-muted">Spendable (header total)</span>
                                            <strong style="color:#185054;">€{{ number_format($checkoutSpendable, 2) }}</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="small text-muted">Cash (withdrawable)</span>
                                            <strong style="color:#185054;">€{{ number_format($checkoutCash, 2) }}</strong>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="small text-muted">Bonus (purchases only)</span>
                                            <strong style="color:#d97706;">€{{ number_format($checkoutBonus, 2) }}</strong>
                                        </div>
                                        <p style="font-size: 12px; color: #6b7280; margin: 8px 0 0;">
                                            Spendable €{{ number_format($checkoutSpendable, 2) }}
                                            = cash €{{ number_format($checkoutCash, 2) }}
                                            + bonus €{{ number_format($checkoutBonus, 2) }}.
                                            Wallet pay uses cash unless you enable <strong>Use bonus balance</strong> in Order Total.
                                            Bonus credit cannot be withdrawn.
                                        </p>
                                    </div>
                                    
                                    <div style="margin-bottom: 16px;">
                                        <p style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">Amount to Pay:</p>
                                        <p style="font-size: 20px; font-weight: 600; color: #1f2937; margin: 0;" id="walletAmountDue">
                                            €{{ number_format($total, 2) }}
                                        </p>
                                    </div>
                                    
                                    <div id="walletBalanceOk" style="background: #dcfce7; padding: 12px; border-radius: 8px; margin-bottom: 16px; display:none;">
                                        <div style="display: flex; align-items: center;">
                                            <i class="fas fa-check-circle" style="color: #16a34a; margin-right: 8px;"></i>
                                            <p style="font-size: 12px; color: #166534; margin: 0;">
                                                Sufficient balance for this order.
                                            </p>
                                        </div>
                                    </div>
                                    <div id="walletBalanceLow" style="background: #fee2e2; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                                        <div style="display: flex; align-items: flex-start; gap: 8px;">
                                            <i class="fas fa-exclamation-triangle" style="color: #dc2626; margin-top: 2px;"></i>
                                            <div>
                                                <p style="font-size: 12px; color: #991b1b; margin: 0;" id="walletBalanceLowMsg">
                                                    Insufficient balance. Add funds (invoice) or apply your bonus credit.
                                                </p>
                                                <a href="{{ route('advertiser.add-funds', ['amount' => max(10, (int) ceil((float) $total))]) }}"
                                                   class="btn btn-sm btn-outline-danger mt-2">
                                                    <i class="fa fa-file-invoice me-1"></i> Add funds &amp; get invoice
                                                </a>
                                            </div>
                                        </div>
                                    </div>
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
                                        <i class="fas fa-university" style="font-size: 24px; color: #185054;"></i>
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
                                        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0;">Pay with a saved card or enter a new one via Stripe</p>
                                    </div>
                                </div>

                                @php $checkoutSavedCards = $savedCards ?? []; @endphp
                                @if(count($checkoutSavedCards) > 0)
                                    <div class="mb-3" id="savedCardsCheckoutList">
                                        <label class="form-label fw-semibold small">Saved cards</label>
                                        @foreach($checkoutSavedCards as $card)
                                            <label class="d-flex align-items-center gap-2 border rounded-3 p-3 mb-2 saved-card-choice{{ !empty($card['is_default']) ? ' is-default' : '' }}"
                                                   style="cursor:pointer;">
                                                <input type="radio" name="saved_card_choice" class="form-check-input saved-card-radio"
                                                       value="{{ $card['id'] }}"
                                                       {{ !empty($card['is_default']) ? 'checked' : '' }}>
                                                <i class="fab fa-cc-{{ strtolower($card['brand']) === 'american express' ? 'amex' : strtolower($card['brand']) }} fa-lg text-muted"></i>
                                                <span class="small">
                                                    <strong class="text-capitalize">{{ $card['brand'] }}</strong>
                                                    •••• {{ $card['last4'] }}
                                                    <span class="text-muted">· {{ sprintf('%02d/%d', $card['exp_month'], $card['exp_year'] % 100) }}</span>
                                                    @if(!empty($card['is_default']))
                                                        <span class="badge bg-success-subtle text-success ms-1">Default</span>
                                                    @endif
                                                </span>
                                            </label>
                                        @endforeach
                                        <label class="d-flex align-items-center gap-2 border rounded-3 p-3 mb-0" style="cursor:pointer;">
                                            <input type="radio" name="saved_card_choice" class="form-check-input saved-card-radio" value="new"
                                                   {{ count($checkoutSavedCards) === 0 ? 'checked' : '' }}>
                                            <span class="small fw-semibold">Use a new card (Stripe Checkout)</span>
                                        </label>
                                        <p class="small text-muted mt-2 mb-0">You can save the new card for next time on Stripe’s secure page.</p>
                                    </div>
                                @else
                                    <div class="alert alert-light border small mb-3">
                                        No saved cards yet. You’ll pay on Stripe’s secure page and can tick
                                        <strong>Save payment details for future purchases</strong>.
                                        Manage cards anytime under
                                        <a href="{{ route('advertiser.add-funds', ['tab' => 'cards']) }}">Add Funds → Cards</a>.
                                    </div>
                                @endif

                                @include('partials.payment-trust', ['compact' => true])
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Order Total -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white fw-semibold">
                            <i class="fa fa-calculator me-2"></i> Pay now
                            @if(($deferredCount ?? 0) > 0)
                                <span class="badge text-bg-light border ms-1">Ready sites only</span>
                            @endif
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
                            @php $bonusForCheckout = (float) ($checkoutBonusBalance ?? 0); @endphp
                            @if($bonusForCheckout > 0)
                                <p class="small text-muted mb-2">
                                    Header <strong>Spendable</strong> includes this bonus. Check the box below to spend it on this order.
                                </p>
                                <div class="form-check d-flex align-items-start gap-2 py-2 px-3 mb-2 rounded ui-callout ui-callout--attention ui-callout--sm ui-callout--flush">
                                    <input class="form-check-input mt-1" type="checkbox" id="useBonusBalance" value="1">
                                    <label class="form-check-label small" for="useBonusBalance" style="cursor:pointer;">
                                        <strong>Use bonus balance</strong>
                                        <span class="d-block text-muted">
                                            Apply up to €{{ number_format($bonusForCheckout, 2) }} promotional credit (not withdrawable)
                                        </span>
                                    </label>
                                </div>
                                <div class="d-flex justify-content-between mb-2 d-none" id="bonusAppliedRow">
                                    <span class="text-success">Bonus applied:</span>
                                    <span class="text-success fw-semibold" id="bonusAppliedAmount">−€0.00</span>
                                </div>
                            @endif
                            <hr>
                            <div class="d-flex justify-content-between mb-1">
                                <strong>Amount due:</strong>
                                <strong class="checkout-theme-price fs-5" id="grandTotal">€{{ number_format($total, 2) }}</strong>
                            </div>
                            @if(!empty($savings) && $savings > 0)
                                <div class="small text-success mb-3">You save €{{ number_format($savings, 2) }} with active discounts</div>
                            @else
                                <div class="mb-3"></div>
                            @endif

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
                            @include('partials.buy-confidence')
                            <div class="mt-3">
                                @include('partials.payment-trust', ['compact' => true, 'showMethods' => false])
                            </div>

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
                <p class="text-muted mb-3">Company and address appear on invoices for your finance team. VAT / tax ID is optional.</p>
                
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
                            <label class="form-label">VAT / Tax ID</label>
                            <input type="text" name="vat_number" id="vat_number" class="form-control" placeholder="Optional">
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
    border-color: var(--brand-primary-soft, #3faeb2);
    box-shadow: 0 0 0 0.2rem var(--focus-ring, rgba(63, 174, 178, 0.35));
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

.payment-option-card {
    border: 2px solid var(--border-subtle, #e2e8f0);
    border-radius: var(--radius-lg, 12px);
    padding: 16px;
    background: var(--surface-1, #fff);
    transition: border-color var(--motion-fast, 150ms ease),
                background-color var(--motion-fast, 150ms ease),
                box-shadow var(--motion-fast, 150ms ease);
    display: flex;
    align-items: center;
    gap: 14px;
}

.payment-option-card.recommended {
    border-color: var(--brand-primary-tint, #5bc4c7);
    background: var(--brand-primary-bg, #e6f5f5);
}

.payment-check {
    color: var(--brand-primary-tint, #5bc4c7);
    font-size: 20px;
    opacity: 0;
}

.payment-option.selected .payment-option-card {
    border-color: var(--brand-primary, #185054) !important;
    background: var(--brand-primary-bg, #e6f5f5) !important;
    box-shadow: 0 4px 6px -1px rgba(24, 80, 84, 0.12);
}

.saved-card-choice.is-default {
    border-color: var(--brand-primary-tint, #5bc4c7) !important;
    background: var(--brand-primary-bg, #e6f5f5);
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
    background: #185054;
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
    box-shadow: 0 1px 0 rgba(24, 80, 84, 0.04);
    border-left: 4px solid var(--brand-primary-tint, #5bc4c7);
}

.site-summary-card.is-paying-now {
    border-left-color: #16a34a;
    background: #f7fdf9;
}

.site-summary-card.is-stays-in-cart {
    border-left-color: #d1d5db;
    background: #f9fafb;
    opacity: 0.92;
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
    color: #185054;
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
    color: #3faeb2;
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
    color: #3faeb2;
}

.site-summary-amount-accent {
    color: #185054;
}

.site-summary-sensitive {
    background: rgba(78, 205, 203, 0.12);
    border-radius: 8px;
    padding: 8px 10px;
}

.order-summary-article {
    border-top: 1px dashed #dbe4ee;
    padding-top: 12px;
}

.order-summary-article-preview {
    max-height: 110px;
    overflow: hidden;
    position: relative;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    padding: 10px 12px;
    font-size: .8rem;
    line-height: 1.45;
    color: #475569;
}

.order-summary-article-preview::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 36px;
    background: linear-gradient(transparent, #f8fafc);
    pointer-events: none;
}

.order-summary-article-preview img {
    max-width: 100%;
    max-height: 64px;
    width: auto;
    height: auto;
    border-radius: 6px;
    display: block;
    margin: .35rem 0;
}

.order-summary-article-preview p {
    margin-bottom: .35rem;
}

.order-summary-history-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.order-summary-history-list li {
    display: grid;
    grid-template-columns: 88px 1fr auto;
    gap: 8px;
    align-items: baseline;
    font-size: .75rem;
    padding: 4px 0;
    border-bottom: 1px solid #f1f5f9;
}

.order-summary-history-list .history-label {
    font-weight: 600;
    color: #185054;
}

.order-summary-history-list .history-detail {
    color: var(--brand-ink-muted, #75787B);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.order-summary-history-list .history-at {
    color: #94a3b8;
    white-space: nowrap;
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
    .order-summary-history-list li {
        grid-template-columns: 1fr;
        gap: 2px;
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

    const paymentOptions = document.querySelectorAll('.payment-option[data-method]');
    const paymentDetailsSection = document.getElementById('paymentDetailsSection');
    const walletDetails = document.getElementById('walletPaymentDetails');
    const cardDetails = document.getElementById('cardPaymentDetails');
    const placeOrderBtn = document.getElementById('placeOrderBtn');
    // Legacy detail panels (no longer selectable at order checkout)
    const wiseDetails = document.getElementById('wisePaymentDetails');
    const cryptoDetails = document.getElementById('cryptoPaymentDetails');
    const bankDetails = document.getElementById('bankPaymentDetails');
    if (wiseDetails) wiseDetails.style.display = 'none';
    if (cryptoDetails) cryptoDetails.style.display = 'none';
    if (bankDetails) bankDetails.style.display = 'none';
    
    let selectedMethod = null;
    const totalAmount = {{ (float) $total }};
    const walletCash = {{ (float) ($checkoutCashBalance ?? 0) }};
    const walletBonus = {{ (float) ($checkoutBonusBalance ?? 0) }};
    const walletSpendable = {{ (float) ($checkoutSpendableBalance ?? 0) }};
    const useBonusEl = document.getElementById('useBonusBalance');
    const bonusAppliedRow = document.getElementById('bonusAppliedRow');
    const bonusAppliedAmount = document.getElementById('bonusAppliedAmount');
    const grandTotalEl = document.getElementById('grandTotal');
    const walletAmountDueEl = document.getElementById('walletAmountDue');

    function bonusToApply() {
        if (!useBonusEl || !useBonusEl.checked || walletBonus <= 0) return 0;
        return Math.min(walletBonus, totalAmount);
    }

    function amountDue() {
        return Math.max(0, Math.round((totalAmount - bonusToApply()) * 100) / 100);
    }

    function moneyFmt(n) {
        return '€' + Number(n || 0).toFixed(2);
    }

    function refreshBonusUi() {
        const applied = bonusToApply();
        const due = amountDue();
        if (grandTotalEl) grandTotalEl.textContent = moneyFmt(due);
        if (walletAmountDueEl) walletAmountDueEl.textContent = moneyFmt(due);
        if (bonusAppliedRow && bonusAppliedAmount) {
            if (applied > 0) {
                bonusAppliedRow.classList.remove('d-none');
                bonusAppliedAmount.textContent = '−' + moneyFmt(applied);
            } else {
                bonusAppliedRow.classList.add('d-none');
            }
        }

        const ok = document.getElementById('walletBalanceOk');
        const low = document.getElementById('walletBalanceLow');
        const lowMsg = document.getElementById('walletBalanceLowMsg');
        const availableForWallet = useBonusEl && useBonusEl.checked ? walletSpendable : walletCash;
        const sufficient = availableForWallet + 0.00001 >= totalAmount;
        if (ok && low) {
            ok.style.display = sufficient ? 'block' : 'none';
            low.style.display = sufficient ? 'none' : 'block';
            if (lowMsg && !sufficient) {
                lowMsg.textContent = (!useBonusEl || !useBonusEl.checked) && walletBonus > 0
                    ? 'Insufficient cash balance. Check “Use bonus balance”, or add funds via invoice (Bank / Wise / crypto).'
                    : 'Insufficient balance. Add funds via invoice (Bank / Wise / crypto), then pay from your wallet.';
            }
        }

        // Stripe only when amount due > 0 (bonus-covered / €0 orders use wallet).
        const cardOption = document.querySelector('.payment-option[data-method="card"]');
        const zeroAlert = document.getElementById('stripeZeroAmountAlert');
        if (cardOption && cardOption.dataset.stripeDisabled !== '1') {
            const blockCard = due <= 0;
            cardOption.classList.toggle('payment-option-disabled', blockCard);
            cardOption.style.opacity = blockCard ? '.55' : '';
            cardOption.style.cursor = blockCard ? 'not-allowed' : 'pointer';
            if (blockCard) {
                cardOption.setAttribute('aria-disabled', 'true');
                cardOption.dataset.zeroAmount = '1';
            } else {
                cardOption.removeAttribute('aria-disabled');
                delete cardOption.dataset.zeroAmount;
            }
            if (zeroAlert) {
                zeroAlert.classList.toggle('d-none', !blockCard);
            }
            if (blockCard && selectedMethod === 'card') {
                selectedMethod = null;
                cardOption.classList.remove('selected');
                if (cardDetails) cardDetails.style.display = 'none';
                if (typeof syncPlaceOrderForModeration === 'function') {
                    syncPlaceOrderForModeration();
                }
            }
        }
    }

    if (useBonusEl) {
        useBonusEl.addEventListener('change', refreshBonusUi);
    }
    refreshBonusUi();

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
            if (method === 'card' && this.dataset.stripeDisabled === '1') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Card payments unavailable',
                    html: 'Stripe is not configured on this server. Set <code>STRIPE_KEY</code> and <code>STRIPE_SECRET</code> in <code>.env</code>, then run <code>php artisan config:clear</code> — or pay with wallet.'
                });
                return;
            }
            if (method === 'card' && (this.dataset.zeroAmount === '1' || amountDue() <= 0)) {
                Swal.fire({
                    icon: 'info',
                    title: 'Nothing to charge by card',
                    text: 'Stripe is only for amounts greater than €0. Use Wallet when bonus covers the order, or assign ready sites that need payment.'
                });
                return;
            }
            selectedMethod = method;
            
            paymentOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            
            if (walletDetails) walletDetails.style.display = 'none';
            if (cardDetails) cardDetails.style.display = 'none';
            if (wiseDetails) wiseDetails.style.display = 'none';
            if (cryptoDetails) cryptoDetails.style.display = 'none';
            if (bankDetails) bankDetails.style.display = 'none';

            if (method === 'wallet' && walletDetails) walletDetails.style.display = 'block';
            else if (method === 'card' && cardDetails) cardDetails.style.display = 'block';

            if (paymentDetailsSection) paymentDetailsSection.style.display = 'block';

            document.querySelectorAll('.payment-option .payment-check').forEach(icon => {
                icon.style.opacity = '0';
            });
            const check = this.querySelector('.payment-check');
            if (check) check.style.opacity = '1';
            
            const paymentError = document.getElementById('paymentError');
            if (paymentError) paymentError.style.display = 'none';
            refreshBonusUi();
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
                placeOrderBtn.innerHTML = '<i class="fa fa-file-alt"></i> Select an approved article to continue';
            }
        } else if (!placeOrderBtn.dataset.busy) {
            placeOrderBtn.disabled = !selectedMethod;
            placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
        }
    }
    window.syncPlaceOrderForModeration = syncPlaceOrderForModeration;

    // Prefer event-driven sync; light fallback instead of 1.2s polling
    document.addEventListener('change', function (e) {
        if (e.target && (e.target.matches('[name="payment_method"]') || e.target.closest('#contentAssignment') || e.target.closest('[data-content-checkout]'))) {
            syncPlaceOrderForModeration();
        }
    });
    document.addEventListener('click', function (e) {
        if (e.target && e.target.closest('.payment-method, .content-pick, [data-select-submission], .article-option')) {
            setTimeout(syncPlaceOrderForModeration, 0);
        }
    });
    setInterval(syncPlaceOrderForModeration, 5000);

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
        const contentPayload = window.ContentCheckout ? window.ContentCheckout.payload() : {};
        fetch('{{ route("advertiser.checkout.process") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(Object.assign({
                payment_method: selectedMethod,
                reference_code: referenceCode,
                use_bonus: !!(useBonusEl && useBonusEl.checked),
                payment_method_id: (function () {
                    if (selectedMethod !== 'card') return null;
                    const picked = document.querySelector('input[name="saved_card_choice"]:checked');
                    if (!picked || picked.value === 'new') return null;
                    return picked.value;
                })()
            }, contentPayload || {}))
        })
        .then(async response => {
            let data = null;
            try {
                data = await response.json();
            } catch (e) {
                throw new Error('Server returned a non-JSON response (' + response.status + ').');
            }
            return { response, data };
        })
        .then(({ response, data }) => {
            if (data.success) {
                if (data.requires_action && data.client_secret && data.stripe_key) {
                    const script = document.createElement('script');
                    script.src = 'https://js.stripe.com/v3/';
                    script.onload = function () {
                        const stripe = Stripe(data.stripe_key);
                        stripe.confirmCardPayment(data.client_secret, {
                            return_url: data.return_url
                        }).then(function (result) {
                            if (result.error) {
                                Swal.fire('Payment', result.error.message || 'Authentication failed', 'error');
                                placeOrderBtn.dataset.busy = '';
                                placeOrderBtn.disabled = false;
                                placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
                            } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                                window.location.href = data.return_url + '&payment_intent=' + encodeURIComponent(result.paymentIntent.id);
                            }
                        });
                    };
                    document.head.appendChild(script);
                    return;
                }
                if (data.requires_payment && data.checkout_url) {
                    window.location.href = data.checkout_url;
                } else if (data.message) {
                    Swal.fire('Success', data.message, 'success').then(() => {
                        window.location.href = '{{ route("advertiser.orders") }}';
                    });
                } else {
                    Swal.fire('Success', 'Order placed successfully!', 'success').then(() => {
                        window.location.href = '{{ route("advertiser.orders") }}';
                    });
                }
            } else if (data.code === 'fund_wallet_first' && data.redirect_url) {
                Swal.fire({
                    icon: 'info',
                    title: 'Fund your wallet first',
                    html: `<div style="text-align:left;">${escapeHtml(data.message || '')}</div>`,
                    confirmButtonText: 'Add funds & get invoice',
                    showCancelButton: true,
                    cancelButtonText: 'Stay here'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = data.redirect_url;
                    }
                });
                placeOrderBtn.dataset.busy = '';
                placeOrderBtn.disabled = false;
                placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
                syncPlaceOrderForModeration();
            } else {
                const modTitle = data.moderation?.title || (response.status === 503 ? 'Card payments unavailable' : 'Error');
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
            Swal.fire('Error', error.message || 'Network error. Please try again.', 'error');
            placeOrderBtn.dataset.busy = '';
            placeOrderBtn.disabled = false;
            placeOrderBtn.innerHTML = '<i class="fa fa-check-circle"></i> Place Order';
            syncPlaceOrderForModeration();
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
        
        if (!['wallet', 'card'].includes(selectedMethod)) {
            Swal.fire({
                icon: 'info',
                title: 'Fund your wallet first',
                text: 'Bank, Wise, and crypto payments use an invoice on Add Funds. After we credit your wallet, pay this order from your balance.',
                confirmButtonText: 'Add funds & get invoice'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '{{ route("advertiser.add-funds", ["amount" => max(10, (int) ceil((float) $total))]) }}';
                }
            });
            return;
        }

        placeOrderBtn.dataset.busy = '1';
        placeOrderBtn.disabled = true;
        placeOrderBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
        submitOrder();
    });

    syncPlaceOrderForModeration();
});
</script>

<!-- SweetAlert2 for better alerts -->  
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

@endsection