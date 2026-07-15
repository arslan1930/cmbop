@extends('publisher.layouts.app')

@section('content')

@php
    $wallet = auth()->user()->activeWallet();
    $walletBalance = $wallet ? (float) $wallet->balance : 0;
    $bonusBalance = $wallet ? $wallet->lockedBonusBalance() : 0;
    $availableBalance = $wallet ? $wallet->withdrawableBalance() : 0;
    $reservedBalance = $wallet ? (float) $wallet->reserved_balance : 0;
    $totalEarnings = $walletBalance + $reservedBalance;
    $platformChargePercent = 0.00; 
    
    $recentWithdrawals = \App\Models\Withdrawal::where('user_id', auth()->id())
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();
@endphp

<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Withdraw Funds</h2>
            <p class="text-muted">Request a withdrawal of your earnings. Withdrawals are processed within 1-2 business days.</p>
        </div>
    </div>

    <!-- Balance Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="text-muted small">
                                Available to Withdraw
                                <i class="fa fa-info-circle text-muted ms-1"
                                   data-bs-toggle="tooltip"
                                   data-bs-placement="top"
                                   title="Earnings and deposits you can cash out. Welcome site credit is not included."></i>
                            </span>
                            <h3 class="mb-0 fw-bold">€{{ number_format($availableBalance, 2) }}</h3>
                        </div>
                        <i class="fa fa-wallet fa-2x text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="text-muted small">
                                Site Credit
                                <i class="fa fa-info-circle text-muted ms-1"
                                   data-bs-toggle="tooltip"
                                   data-bs-placement="top"
                                   title="Promotional credit for orders on this site only. It cannot be withdrawn or transferred."></i>
                            </span>
                            <h3 class="mb-0 fw-bold">€{{ number_format($bonusBalance, 2) }}</h3>
                        </div>
                        <i class="fa fa-gift fa-2x text-secondary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="text-muted small">
                                Reserved
                                <i class="fa fa-info-circle text-muted ms-1"
                                   data-bs-toggle="tooltip"
                                   data-bs-placement="top"
                                   title="Funds held for open orders until they are approved or refunded."></i>
                            </span>
                            <h3 class="mb-0 fw-bold">€{{ number_format($reservedBalance, 2) }}</h3>
                        </div>
                        <i class="fa fa-lock fa-2x text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="text-muted small">
                                Total Balance
                                <i class="fa fa-info-circle text-muted ms-1"
                                   data-bs-toggle="tooltip"
                                   data-bs-placement="top"
                                   title="Available wallet balance plus reserved funds (includes site credit)."></i>
                            </span>
                            <h3 class="mb-0 fw-bold">€{{ number_format($totalEarnings, 2) }}</h3>
                        </div>
                        <i class="fa fa-chart-line fa-2x text-success opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="text-muted small">
                                Platform Fee
                                <i class="fa fa-info-circle text-muted ms-1"
                                   data-bs-toggle="tooltip"
                                   data-bs-placement="top"
                                   title="Percentage fee deducted from each withdrawal. Currently {{ $platformChargePercent }}%."></i>
                            </span>
                            <h3 class="mb-0 fw-bold">{{ $platformChargePercent }}%</h3>
                        </div>
                        <i class="fa fa-percent fa-2x text-info opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Withdrawal Form -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4">Request Withdrawal</h5>
                    
                    <form id="withdrawForm" method="POST">
                        @csrf
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Amount (€)</label>
                            <input type="number" 
                                   name="amount" 
                                   id="amount" 
                                   class="form-control form-control-lg" 
                                   placeholder="0.00"
                                   step="0.01"
                                   min="0.01"
                                   max="{{ $availableBalance }}"
                                   required>
                            <div class="form-text">
                                Maximum withdrawable: €{{ number_format($availableBalance, 2) }}
                                @if($bonusBalance > 0)
                                    <span class="d-block">€{{ number_format($bonusBalance, 2) }} site credit can only be spent on orders, not withdrawn.</span>
                                @endif
                            </div>
                        </div>
                        
                        <!-- Fee Preview -->
                        <div class="bg-light p-3 rounded mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Amount</span>
                                <strong id="previewAmount">€0.00</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2 text-danger">
                                <span>Fee ({{ $platformChargePercent }}%)</span>
                                <strong id="previewFee">€0.00</strong>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">You receive</span>
                                <strong class="text-success fs-5" id="previewNet">€0.00</strong>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" id="paymentMethod" class="form-select" required>
                                <option value="">Select</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="paypal">PayPal</option>
                                <option value="wise">Wise</option>
                                <option value="crypto">Cryptocurrency</option>
                            </select>
                        </div>
                        
                        <!-- Dynamic Fields -->
                        <div id="bankFields" class="d-none">
                            <div class="mb-3">
                                <label class="form-label small">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Account Holder</label>
                                <input type="text" name="account_holder" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">IBAN/Account Number</label>
                                <input type="text" name="account_number" class="form-control">
                            </div>
                        </div>
                        
                        <div id="paypalFields" class="d-none">
                            <div class="mb-3">
                                <label class="form-label small">PayPal Email</label>
                                <input type="email" name="paypal_email" class="form-control">
                            </div>
                        </div>
                        
                        <div id="wiseFields" class="d-none">
                            <div class="mb-3">
                                <label class="form-label small">Wise Email</label>
                                <input type="email" name="wise_email" class="form-control">
                            </div>
                        </div>
                        
                        <div id="cryptoFields" class="d-none">
                            <div class="mb-3">
                                <label class="form-label small">Coin Type</label>
                                <select name="crypto_type" class="form-select">
                                    <option value="BTC">Bitcoin (BTC)</option>
                                    <option value="ETH">Ethereum (ETH)</option>
                                    <option value="USDT">Tether (USDT)</option>
                                    <option value="BNB">Binance Coin (BNB)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Wallet Address</label>
                                <input type="text" name="wallet_address" class="form-control">
                            </div>
                        </div>
                        
                        <button type="button" id="submitWithdrawBtn" class="btn btn-primary btn-lg w-100">
                            <i class="fa fa-paper-plane me-2"></i>Request Withdrawal
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Recent Withdrawals -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="p-3 border-bottom">
                        <h5 class="mb-0">Recent Withdrawals</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Fee</th>
                                    <th>Net Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentWithdrawals as $w)
                                <tr>
                                    <td class="small">{{ $w->created_at->format('M d, Y') }}<br>
                                        <small class="text-muted">{{ $w->created_at->format('h:i A') }}</small>
                                    </td>
                                    <td class="fw-semibold">€{{ number_format($w->amount, 2) }}</td>
                                    <td class="text-danger">-€{{ number_format($w->fee, 2) }}</td>
                                    <td class="text-success fw-semibold">€{{ number_format($w->net_amount, 2) }}</td>
                                    <td>
                                        @php
                                            $statusColors = [
                                                'pending' => 'warning',
                                                'processing' => 'info',
                                                'completed' => 'success',
                                                'cancelled' => 'danger'
                                            ];
                                            $color = $statusColors[$w->status] ?? 'secondary';
                                        @endphp
                                        <span class="badge bg-{{ $color }}">
                                            {{ ucfirst($w->status) }}
                                        </span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="fa fa-receipt fa-2x mb-2 d-block opacity-50"></i>
                                        No withdrawal requests yet
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Info Box -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body py-3">
                    <div class="d-flex gap-2">
                        <i class="fa fa-info-circle text-primary mt-1"></i>
                        <small class="text-muted">Withdrawals processed within 1-2 business days.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .btn-primary {
        background-color: #4ECDCB;
        border-color: #4ECDCB;
    }
    .btn-primary:hover {
        background-color: #3db8b6;
        border-color: #3db8b6;
    }
    input:focus, select:focus {
        border-color: #4ECDCB !important;
        box-shadow: none !important;
    }
    .table td {
        vertical-align: middle;
    }
    .badge {
        font-weight: 500;
        padding: 5px 10px;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amount');
    const previewAmount = document.getElementById('previewAmount');
    const previewFee = document.getElementById('previewFee');
    const previewNet = document.getElementById('previewNet');
    const paymentMethod = document.getElementById('paymentMethod');
    const submitBtn = document.getElementById('submitWithdrawBtn');
    const form = document.getElementById('withdrawForm');
    const maxAmount = {{ $availableBalance }};
    const feePercent = {{ $platformChargePercent }};
    
    // Calculate fee preview
    function updatePreview() {
        let amount = parseFloat(amountInput.value) || 0;
        if (amount > maxAmount) amount = maxAmount;
        if (amount < 0) amount = 0;
        
        const fee = amount * feePercent / 100;
        const net = amount - fee;
        
        previewAmount.textContent = `€${amount.toFixed(2)}`;
        previewFee.textContent = `€${fee.toFixed(2)}`;
        previewNet.textContent = `€${net.toFixed(2)}`;
    }
    
    // Show/hide payment fields
    function togglePaymentFields() {
        const method = paymentMethod.value;
        document.getElementById('bankFields')?.classList.add('d-none');
        document.getElementById('paypalFields')?.classList.add('d-none');
        document.getElementById('wiseFields')?.classList.add('d-none');
        document.getElementById('cryptoFields')?.classList.add('d-none');
        
        if (method === 'bank') document.getElementById('bankFields')?.classList.remove('d-none');
        if (method === 'paypal') document.getElementById('paypalFields')?.classList.remove('d-none');
        if (method === 'wise') document.getElementById('wiseFields')?.classList.remove('d-none');
        if (method === 'crypto') document.getElementById('cryptoFields')?.classList.remove('d-none');
    }
    
    // Show error with SweetAlert
    function showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'Validation Error',
            text: message,
            confirmButtonColor: '#4ECDCB',
            confirmButtonText: 'OK'
        });
    }
    
    // Show confirmation dialog
    function showConfirmation(amount, fee, net) {
        return Swal.fire({
            title: 'Confirm Withdrawal',
            html: `
                <div style="text-align: left;">
                    <p><strong>Amount:</strong> €${amount.toFixed(2)}</p>
                    <p><strong>Fee (${feePercent}%):</strong> €${fee.toFixed(2)}</p>
                    <p><strong>You will receive:</strong> €${net.toFixed(2)}</p>
                    <hr>
                    <p class="text-muted">Please review the details before confirming.</p>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#4ECDCB',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, withdraw',
            cancelButtonText: 'Cancel'
        });
    }
    
    // Validate form
    function validateForm() {
        const amount = parseFloat(amountInput.value) || 0;
        const method = paymentMethod.value;
        
        if (amount <= 0) {
            showError('Please enter a valid amount greater than 0.');
            return false;
        }
        if (amount > maxAmount) {
            showError(`Maximum withdrawal amount is €${maxAmount.toFixed(2)}`);
            return false;
        }
        if (!method) {
            showError('Please select a payment method');
            return false;
        }
        
        // Validate payment details
        if (method === 'bank') {
            if (!document.querySelector('input[name="bank_name"]')?.value || 
                !document.querySelector('input[name="account_holder"]')?.value || 
                !document.querySelector('input[name="account_number"]')?.value) {
                showError('Please fill in all bank details');
                return false;
            }
        }
        if (method === 'paypal') {
            if (!document.querySelector('input[name="paypal_email"]')?.value) {
                showError('Please enter your PayPal email address');
                return false;
            }
            const email = document.querySelector('input[name="paypal_email"]').value;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showError('Please enter a valid email address');
                return false;
            }
        }
        if (method === 'wise') {
            if (!document.querySelector('input[name="wise_email"]')?.value) {
                showError('Please enter your Wise email address');
                return false;
            }
            const email = document.querySelector('input[name="wise_email"]').value;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showError('Please enter a valid email address');
                return false;
            }
        }
        if (method === 'crypto') {
            if (!document.querySelector('input[name="wallet_address"]')?.value) {
                showError('Please enter your wallet address');
                return false;
            }
        }
        
        return true;
    }
    
    // Submit withdrawal
    submitBtn.addEventListener('click', async function() {
        if (!validateForm()) return;
        
        const amount = parseFloat(amountInput.value);
        const fee = amount * feePercent / 100;
        const net = amount - fee;
        
        // Show confirmation dialog
        const result = await showConfirmation(amount, fee, net);
        
        if (result.isConfirmed) {
            // Show loading state
            Swal.fire({
                title: 'Processing...',
                text: 'Please wait while we process your request',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Disable button
            submitBtn.disabled = true;
            
            const formData = new FormData(form);
            
            fetch('{{ route("publisher.withdraw.request") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Withdrawal Request Submitted!',
                        html: `
                            <div style="text-align: left;">
                                <p><strong>Amount:</strong> €${amount.toFixed(2)}</p>
                                <p><strong>Fee:</strong> €${fee.toFixed(2)}</p>
                                <p><strong>You will receive:</strong> €${net.toFixed(2)}</p>
                                <hr>
                                <p class="text-muted small">Your withdrawal request has been received and is pending admin approval.</p>
                            </div>
                        `,
                        confirmButtonColor: '#4ECDCB',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Request Failed',
                        text: data.message,
                        confirmButtonColor: '#4ECDCB',
                        confirmButtonText: 'Try Again'
                    });
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Something went wrong!',
                    text: 'Unable to process your request. Please try again later.',
                    confirmButtonColor: '#4ECDCB',
                    confirmButtonText: 'OK'
                });
                submitBtn.disabled = false;
            });
        }
    });
    
    // Event listeners
    amountInput.addEventListener('input', updatePreview);
    amountInput.addEventListener('change', function() {
        let val = parseFloat(this.value);
        if (val > maxAmount) this.value = maxAmount;
        if (val < 0) this.value = 0;
        updatePreview();
    });
    paymentMethod.addEventListener('change', togglePaymentFields);
    
    // Initial preview
    updatePreview();
});
</script>

@endsection