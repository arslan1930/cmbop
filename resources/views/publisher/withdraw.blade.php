@extends('publisher.layouts.app')

@section('content')

@php
    $wallet = auth()->user()->activeWallet();
    $availableBalance = $wallet ? $wallet->withdrawableBalance() : 0;
    $bonusBalance = $wallet ? $wallet->lockedBonusBalance() : 0;
    $reservedBalance = $wallet ? (float) $wallet->reserved_balance : 0;
    $promotionalBonusMessage = \App\Models\Wallet::PROMOTIONAL_BONUS_MESSAGE;
    $payoutProfile = $payoutProfile ?? auth()->user()->payoutProfile();
    $payoutLocked = $payoutLocked ?? auth()->user()->payoutProfileLocked();
    $supportEmail = $supportEmail ?? config('email_notifications.brand.support_email', config('mail.from.address'));
    $preferredMethod = $payoutProfile['preferred_method'] ?? null;

    $recentWithdrawals = \App\Models\Withdrawal::where('user_id', auth()->id())
        ->orderBy('created_at', 'desc')
        ->limit(10)
        ->get();
@endphp

<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Withdraw Funds</h2>
            <p class="text-muted mb-0">Request a withdrawal of your earnings. Withdrawals are processed within 1–2 business days.</p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between">
                    <div>
                        <span class="text-muted small">Can Withdraw</span>
                        <h3 class="mb-1 fw-bold" style="color: var(--brand-primary, #185054);">€{{ number_format($availableBalance, 2) }}</h3>
                        <p class="text-muted small mb-0">Money you can cash out</p>
                    </div>
                    <div class="kpi-icon-mist rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;">
                        <i class="fa fa-wallet"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between">
                    <div>
                        <span class="text-muted small">Free Credit</span>
                        <h3 class="mb-1 fw-bold">€{{ number_format($bonusBalance, 2) }}</h3>
                        <p class="text-muted small mb-0">For orders only — not cash</p>
                    </div>
                    <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;">
                        <i class="fa fa-gift"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between">
                    <div>
                        <span class="text-muted small">On Hold</span>
                        <h3 class="mb-1 fw-bold">€{{ number_format($reservedBalance, 2) }}</h3>
                        <p class="text-muted small mb-0">Locked for open orders</p>
                    </div>
                    <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#fff;color:#1e293b;border:1px solid #e2e8f0;">
                        <i class="fa fa-lock"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(($platformChargePercent ?? 0) > 0)
        <div class="ui-callout ui-callout--info mb-4">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
            <div class="ui-callout__body">Withdrawal fee: {{ $platformChargePercent }}% of the amount you withdraw.</div>
        </div>
    @endif

    @if($payoutLocked)
        <div class="ui-callout ui-callout--attention mb-4">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
            <div class="ui-callout__body">
                <strong>Payout details are locked.</strong>
                You confirmed them once and they cannot be edited here.
                To change a payment method, email
                <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                — our team will update them and notify you by email.
            </div>
        </div>
    @else
        <div class="ui-callout ui-callout--attention mb-4">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
            <div class="ui-callout__body">
                <strong>Double-check your payout details.</strong>
                Enter each critical field twice. After your first withdrawal request, these details lock permanently until support changes them.
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4">Request Withdrawal</h5>

                    <form id="withdrawForm" method="POST">
                        @csrf

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="amount">Amount (€)</label>
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
                                Available: <strong>€{{ number_format($availableBalance, 2) }}</strong>
                                @if($bonusBalance > 0)
                                    <span class="d-block mt-1 text-muted">{{ $promotionalBonusMessage }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="p-3 rounded mb-4" style="background: var(--surface-2, #f7fafb); border: 1px solid var(--border-subtle, #e2e8f0);">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold">You will receive</span>
                                <strong class="fs-5" style="color: var(--brand-primary, #185054);" id="previewAmount">€0.00</strong>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="paymentMethod">Payment Method</label>
                            <select name="payment_method" id="paymentMethod" class="form-select" required @if($payoutLocked && $preferredMethod) disabled @endif>
                                <option value="">Select</option>
                                <option value="bank" @selected($preferredMethod === 'bank')>Bank Transfer</option>
                                <option value="paypal" @selected($preferredMethod === 'paypal')>PayPal</option>
                                <option value="wise" @selected($preferredMethod === 'wise')>Wise</option>
                                <option value="crypto" @selected($preferredMethod === 'crypto')>Cryptocurrency</option>
                            </select>
                            @if($payoutLocked && $preferredMethod)
                                <input type="hidden" name="payment_method" value="{{ $preferredMethod }}">
                            @endif
                        </div>

                        <div id="bankFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control" value="{{ $payoutProfile['bank_name'] ?? '' }}" @disabled($payoutLocked)>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Account Holder</label>
                                <input type="text" name="account_holder" class="form-control" value="{{ $payoutProfile['bank_holder_name'] ?? '' }}" @disabled($payoutLocked)>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">IBAN / Account Number</label>
                                <input type="text" name="account_number" class="form-control" value="{{ $payoutProfile['bank_account'] ?? '' }}" @disabled($payoutLocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm IBAN / Account Number</label>
                                    <input type="text" name="account_number_confirm" class="form-control" autocomplete="off">
                                </div>
                            @endunless
                            <div class="mb-3">
                                <label class="form-label small">SWIFT / BIC <span class="text-muted">(optional)</span></label>
                                <input type="text" name="swift_code" class="form-control" value="{{ $payoutProfile['bank_swift'] ?? '' }}" @disabled($payoutLocked)>
                            </div>
                        </div>

                        <div id="paypalFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">PayPal Email</label>
                                <input type="email" name="paypal_email" class="form-control" value="{{ $payoutProfile['paypal_email'] ?? '' }}" @disabled($payoutLocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm PayPal Email</label>
                                    <input type="email" name="paypal_email_confirm" class="form-control" autocomplete="off">
                                </div>
                            @endunless
                        </div>

                        <div id="wiseFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">Wise Email</label>
                                <input type="email" name="wise_email" class="form-control" value="{{ $payoutProfile['wise_email'] ?? '' }}" @disabled($payoutLocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm Wise Email</label>
                                    <input type="email" name="wise_email_confirm" class="form-control" autocomplete="off">
                                </div>
                            @endunless
                        </div>

                        <div id="cryptoFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">Coin Type</label>
                                <select name="crypto_type" class="form-select" @disabled($payoutLocked)>
                                    @foreach(['BTC' => 'Bitcoin (BTC)', 'ETH' => 'Ethereum (ETH)', 'USDT' => 'Tether (USDT)', 'BNB' => 'Binance Coin (BNB)'] as $code => $label)
                                        <option value="{{ $code }}" @selected(($payoutProfile['crypto_type'] ?? 'USDT') === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Wallet Address</label>
                                <input type="text" name="wallet_address" class="form-control" value="{{ $payoutProfile['crypto_wallet'] ?? '' }}" @disabled($payoutLocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm Wallet Address</label>
                                    <input type="text" name="wallet_address_confirm" class="form-control" autocomplete="off">
                                </div>
                            @endunless
                        </div>

                        @unless($payoutLocked)
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" value="1" id="detailsConfirmed" name="details_confirmed" required>
                                <label class="form-check-label small" for="detailsConfirmed">
                                    I have double-checked these payout details. I understand they cannot be changed later without contacting support.
                                </label>
                            </div>
                        @else
                            <input type="hidden" name="details_confirmed" value="1">
                        @endunless

                        <button type="button" id="submitWithdrawBtn" class="btn btn-primary btn-lg w-100">
                            <i class="fa fa-paper-plane me-2"></i>Request Withdrawal
                        </button>
                    </form>
                </div>
            </div>
        </div>

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
                                        <td>
                                            @php
                                                $statusClass = match ($w->status) {
                                                    'completed' => 'status-paid',
                                                    'cancelled' => 'status-rejected',
                                                    default => 'status-pending',
                                                };
                                            @endphp
                                            <span class="badge {{ $statusClass }}">{{ ucfirst($w->status) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center py-4 text-muted">
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

            <div class="ui-callout ui-callout--info mt-3 mb-0">
                <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                <div class="ui-callout__body">Withdrawals are processed within 1–2 business days.</div>
            </div>
        </div>
    </div>
</div>

<style>
.ui-callout {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    padding: 0.75rem 0.9rem;
    background: transparent;
    border: 1px solid var(--border-subtle, #e2e8f0);
    border-radius: var(--radius-md, 10px);
    color: var(--brand-ink, #1e293b);
    font-size: 0.9rem;
    line-height: 1.45;
}
.ui-callout__icon { flex: 0 0 auto; margin-top: 0.1rem; color: var(--brand-danger, #dc2626); }
.ui-callout--info .ui-callout__icon { color: var(--brand-neutral, #64748b); }
.ui-callout--attention .ui-callout__icon { color: var(--brand-danger, #dc2626); }
.ui-callout__body { flex: 1 1 auto; min-width: 0; }
.table td { vertical-align: middle; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amount');
    const previewAmount = document.getElementById('previewAmount');
    const paymentMethod = document.getElementById('paymentMethod');
    const submitBtn = document.getElementById('submitWithdrawBtn');
    const form = document.getElementById('withdrawForm');
    const maxAmount = {{ $availableBalance }};
    const payoutLocked = @json((bool) $payoutLocked);
    const preferredMethod = @json($preferredMethod);
    const brandPrimary = getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim() || '#185054';

    function updatePreview() {
        let amount = parseFloat(amountInput.value) || 0;
        if (amount > maxAmount) amount = maxAmount;
        if (amount < 0) amount = 0;
        previewAmount.textContent = `€${amount.toFixed(2)}`;
    }

    function currentMethod() {
        if (payoutLocked && preferredMethod) return preferredMethod;
        return paymentMethod.value;
    }

    function togglePaymentFields() {
        const method = currentMethod();
        document.querySelectorAll('.payout-fields').forEach(el => el.classList.add('d-none'));
        if (method === 'bank') document.getElementById('bankFields')?.classList.remove('d-none');
        if (method === 'paypal') document.getElementById('paypalFields')?.classList.remove('d-none');
        if (method === 'wise') document.getElementById('wiseFields')?.classList.remove('d-none');
        if (method === 'crypto') document.getElementById('cryptoFields')?.classList.remove('d-none');
    }

    function showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'Check your details',
            text: message,
            confirmButtonColor: brandPrimary,
        });
    }

    function summaryHtml(amount, method) {
        let details = '';
        if (method === 'bank') {
            details = `<p class="mb-1"><strong>Bank:</strong> ${form.bank_name?.value || '—'}</p>
                       <p class="mb-1"><strong>Holder:</strong> ${form.account_holder?.value || '—'}</p>
                       <p class="mb-1"><strong>Account:</strong> ${form.account_number?.value || '—'}</p>`;
        } else if (method === 'paypal') {
            details = `<p class="mb-1"><strong>PayPal:</strong> ${form.paypal_email?.value || '—'}</p>`;
        } else if (method === 'wise') {
            details = `<p class="mb-1"><strong>Wise:</strong> ${form.wise_email?.value || '—'}</p>`;
        } else if (method === 'crypto') {
            details = `<p class="mb-1"><strong>Coin:</strong> ${form.crypto_type?.value || '—'}</p>
                       <p class="mb-1"><strong>Wallet:</strong> ${form.wallet_address?.value || '—'}</p>`;
        }
        return `
            <div style="text-align:left">
                <p><strong>You will receive:</strong> €${amount.toFixed(2)}</p>
                <hr>
                ${details}
                ${!payoutLocked ? '<p class="text-muted small mt-2 mb-0">These payout details will lock after this request. Contact support to change them later.</p>' : ''}
            </div>`;
    }

    function validateForm() {
        const amount = parseFloat(amountInput.value) || 0;
        const method = currentMethod();

        if (amount <= 0) { showError('Please enter a valid amount greater than 0.'); return false; }
        if (amount > maxAmount) {
            showError(maxAmount <= 0 ? @json($promotionalBonusMessage) : `Maximum withdrawal amount is €${maxAmount.toFixed(2)}.`);
            return false;
        }
        if (!method) { showError('Please select a payment method'); return false; }

        if (!payoutLocked) {
            if (!form.details_confirmed?.checked) {
                showError('Please confirm you have double-checked your payout details.');
                return false;
            }
            if (method === 'bank') {
                if (!form.bank_name.value || !form.account_holder.value || !form.account_number.value) {
                    showError('Please fill in all bank details'); return false;
                }
                if (form.account_number.value !== form.account_number_confirm.value) {
                    showError('IBAN / account numbers must match.'); return false;
                }
            }
            if (method === 'paypal') {
                if (!form.paypal_email.value) { showError('Please enter your PayPal email'); return false; }
                if (form.paypal_email.value !== form.paypal_email_confirm.value) {
                    showError('PayPal emails must match.'); return false;
                }
            }
            if (method === 'wise') {
                if (!form.wise_email.value) { showError('Please enter your Wise email'); return false; }
                if (form.wise_email.value !== form.wise_email_confirm.value) {
                    showError('Wise emails must match.'); return false;
                }
            }
            if (method === 'crypto') {
                if (!form.wallet_address.value) { showError('Please enter your wallet address'); return false; }
                if (form.wallet_address.value !== form.wallet_address_confirm.value) {
                    showError('Wallet addresses must match.'); return false;
                }
            }
        }

        return true;
    }

    amountInput.addEventListener('input', updatePreview);
    paymentMethod.addEventListener('change', togglePaymentFields);
    updatePreview();
    togglePaymentFields();

    submitBtn.addEventListener('click', async function() {
        if (!validateForm()) return;

        const amount = parseFloat(amountInput.value);
        const method = currentMethod();
        const result = await Swal.fire({
            title: 'Confirm withdrawal',
            html: summaryHtml(amount, method),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: brandPrimary,
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, withdraw',
            cancelButtonText: 'Cancel'
        });

        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Submitting…',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const formData = new FormData(form);
        if (payoutLocked && preferredMethod) {
            formData.set('payment_method', preferredMethod);
        }
        // Disabled fields are omitted from FormData — re-attach locked values.
        if (payoutLocked) {
            ['bank_name','account_holder','account_number','swift_code','paypal_email','wise_email','crypto_type','wallet_address']
                .forEach(name => {
                    const el = form.elements.namedItem(name);
                    if (el && el.disabled && el.value) formData.set(name, el.value);
                });
        }

        try {
            const response = await fetch(@json(route('publisher.withdraw.request')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': form.querySelector('[name=_token]').value,
                    'Accept': 'application/json',
                },
                body: formData,
            });
            const data = await response.json();
            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: 'Submitted',
                    text: data.message,
                    confirmButtonColor: brandPrimary,
                });
                window.location.reload();
            } else {
                showError(data.message || 'Withdrawal failed.');
            }
        } catch (e) {
            showError('Network error. Please try again.');
        }
    });
});
</script>
@endsection
