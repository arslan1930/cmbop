@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Add Funds</h2>
            <p class="text-muted mb-0">
                Add funds to your wallet to start purchasing placements.
            </p>
        </div>
    </div>

    <div class="row">
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
                        <div class="row g-3">
                            <!-- Wise Payment -->
                            <div class="col-6 col-md-3">
                                <div class="payment-option" data-method="wise" style="cursor: pointer;">
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
                            <div class="col-6 col-md-3">
                                <div class="payment-option" data-method="crypto" style="cursor: pointer;">
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
                            <div class="col-6 col-md-3">
                                <div class="payment-option" data-method="bank" style="cursor: pointer;">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #eff6ff; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fas fa-university" style="font-size: 28px; color: #3b82f6;"></i>
                                        </div>
                                        <span style="font-weight: 600; font-size: 12px; color: #1f2937;">Bank Transfer</span>
                                        <span style="font-size: 10px; color: #6b7280; display: block; margin-top: 4px;">Traditional bank transfer</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Payment with Stripe Checkout -->
                            <div class="col-6 col-md-3">
                                <div class="payment-option" data-method="card" style="cursor: pointer;">
                                    <div class="payment-option-card" style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 16px; text-align: center; background: white; transition: all 0.2s;">
                                        <div style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 8px; margin: 0 auto 8px;">
                                            <i class="fab fa-stripe" style="font-size: 28px; color: #635bff;"></i>
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
                                        <i class="fas fa-university" style="font-size: 24px; color: #3b82f6;"></i>
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

        <!-- Right Column - Order Summary -->
        <div class="col-lg-4">
            <!-- Current Balance Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-wallet me-2"></i> Current Balance
                </div>
                <div class="card-body text-center py-4">
                    <h2 class="mb-0 text-primary">€{{ number_format(auth()->user()->activeWallet()?->balance ?? 0, 2) }}</h2>
                    <small class="text-muted">Available for purchases</small>
                </div>
            </div>

            <!-- Order Total Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-calculator me-2"></i> Payment Summary
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

                    <!-- Reference Code -->
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
                        <small>Please include <strong id="refCodeDisplay">XXXXXXXX</strong> in your payment note for manual payments. For card payments, reference is auto-recorded.</small>
                    </div>

                    <button type="button" id="proceedBtn" class="btn btn-primary w-100 mt-2 py-2">
                        <i class="fa fa-arrow-right me-2"></i> Proceed to Payment
                    </button>
                </div>
            </div>

            <!-- Deposit History -->
            @if(isset($allRequests) && $allRequests->count() > 0)
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-history me-2"></i> Deposit History
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($allRequests as $request)
                                <tr>
                                    <td><small>{{ $request->created_at->format('M d, Y') }}</small></td>
                                    <td class="fw-semibold">€{{ number_format($request->amount, 2) }}</td>
                                    <td><code class="small">{{ $request->reference_code }}</code></td>
                                    <td>
                                        @if($request->status == 'pending')
                                            <span class="badge bg-warning">Pending</span>
                                        @elseif($request->status == 'approved')
                                            <span class="badge bg-info">Approved</span>
                                        @elseif($request->status == 'completed')
                                            <span class="badge bg-success">Completed</span>
                                        @elseif($request->status == 'rejected')
                                            <span class="badge bg-danger">Rejected</span>
                                        @endif
                                    </td>
                                </td>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($allRequests->hasPages())
                <div class="card-footer bg-white py-2">
                    {{ $allRequests->links('pagination::bootstrap-5') }}
                </div>
                @endif  
            </div>
            @endif
        </div>
    </div>
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
</div>

<style>
.payment-option {
    cursor: pointer;
}

.payment-option.selected .payment-option-card {
    border-color: #3b82f6 !important;
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
    background-color: #0d6efd;
    color: white;
    border-color: #0d6efd;
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

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

@endsection