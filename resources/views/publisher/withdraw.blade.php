@extends('publisher.layouts.app')

@section('content')

@php
    $wallet = $wallet ?? null;
    $availableBalance = $wallet ? $wallet->withdrawableBalance() : 0;
    $bonusBalance = $wallet ? $wallet->lockedBonusBalance() : 0;
    $reservedBalance = $wallet ? (float) $wallet->reserved_balance : 0;
    $debtBalance = $wallet ? $wallet->debtBalance() : 0;
    $hasDebt = $wallet ? $wallet->hasDebt() : false;
    $promotionalBonusMessage = \App\Models\Wallet::PROMOTIONAL_BONUS_MESSAGE;
    $payoutProfile = $payoutProfile ?? auth()->user()->payoutProfile();
    $payoutLocked = $payoutLocked ?? auth()->user()->payoutProfileLocked();
    $supportEmail = $supportEmail ?? config('email_notifications.brand.support_email', config('mail.from.address'));
    $platformChargePercent = (float) ($platformChargePercent ?? 0);
    $minWithdrawalAmount = (float) ($minWithdrawalAmount ?? 20);
    $formBlocked = $hasDebt || $availableBalance < $minWithdrawalAmount;
    $preferredMethod = $payoutProfile['preferred_method'] ?? null;
    $availableMethods = $availableMethods ?? app(\App\Services\Wallet\PayoutProfileService::class)->availableMethods(auth()->user());
    $methodLabels = [
        'bank' => 'Bank Transfer',
        'paypal' => 'PayPal',
        'wise' => 'Wise',
        'crypto' => 'Cryptocurrency',
    ];
    $maskEmail = static function (?string $email): string {
        if (! $email || ! str_contains($email, '@')) {
            return '—';
        }
        $at = strpos($email, '@');

        return substr($email, 0, 1).'***'.substr($email, $at);
    };
    $methodSummaries = [
        'paypal' => ! empty($payoutProfile['paypal_email']) ? 'PayPal · '.$maskEmail($payoutProfile['paypal_email']) : null,
        'wise' => ! empty($payoutProfile['wise_email']) ? 'Wise · '.$maskEmail($payoutProfile['wise_email']) : null,
        'bank' => ! empty($payoutProfile['bank_account'])
            ? 'Bank · ···'.substr(preg_replace('/\s+/', '', (string) $payoutProfile['bank_account']), -4)
            : null,
        'crypto' => ! empty($payoutProfile['crypto_wallet'])
            ? ($payoutProfile['crypto_type'] ?? 'Crypto').' · ···'.substr((string) $payoutProfile['crypto_wallet'], -4)
            : null,
    ];

    $recentWithdrawals = $recentWithdrawals ?? collect();
@endphp

<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-8">
            <h2 class="mb-1 fw-semibold">Withdraw Funds</h2>
            <p class="text-muted mb-0">Request a withdrawal of your earnings. Withdrawals are processed within 1–2 business days.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <a href="{{ route('publisher.billing.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-file-invoice-dollar me-1" aria-hidden="true"></i> Payout documents
            </a>
        </div>
    </div>

    @if($hasDebt)
        <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
            <strong>Withdrawals blocked</strong> — you have outstanding clawback debt of
            <strong>€{{ number_format($debtBalance, 2) }}</strong> from a removed post-completion placement.
            Contact support at {{ $supportEmail }} to resolve this before withdrawing.
        </div>
    @elseif($availableBalance < $minWithdrawalAmount)
        <div class="alert alert-warning border-0 shadow-sm mb-4" role="alert">
            <strong>Below minimum</strong> — you need at least
            <strong>€{{ number_format($minWithdrawalAmount, 2) }}</strong> withdrawable balance to request a payout.
            Available now: €{{ number_format($availableBalance, 2) }}.
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between">
                    <div>
                        <span class="text-muted small">Can Withdraw</span>
                        <h3 class="mb-1 fw-bold" style="color: var(--brand-primary, #1a585e);">€{{ number_format($availableBalance, 2) }}</h3>
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
                    <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#f1f5f9;color: var(--brand-ink-muted, #75787B);border:1px solid #e2e8f0;">
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

    @if($platformChargePercent > 0)
        <div class="ui-callout ui-callout--info mb-4">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
            <div class="ui-callout__body">Withdrawal fee: {{ $platformChargePercent }}% of the amount you withdraw.</div>
        </div>
    @endif

    @if($payoutLocked)
        <div class="ui-callout ui-callout--attention mb-4">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
            <div class="ui-callout__body">
                <strong>Choose a saved payout method.</strong>
                Details are locked — contact
                <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                to change them or add a new destination.
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

                    <form id="withdrawForm" method="POST" @if($formBlocked) aria-disabled="true" @endif>
                        @csrf

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="amount">Amount (€)</label>
                            <input type="number"
                                   name="amount"
                                   id="amount"
                                   class="form-control form-control-lg"
                                   placeholder="{{ number_format($minWithdrawalAmount, 2, '.', '') }}"
                                   step="0.01"
                                   min="{{ $minWithdrawalAmount }}"
                                   max="{{ $availableBalance }}"
                                   @disabled($formBlocked)
                                   required>
                            <div class="form-text">
                                Available: <strong>€{{ number_format($availableBalance, 2) }}</strong>
                                · Minimum: <strong>€{{ number_format($minWithdrawalAmount, 2) }}</strong>
                                @if($bonusBalance > 0)
                                    <span class="d-block mt-1 text-muted">{{ $promotionalBonusMessage }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="p-3 rounded mb-4" style="background: var(--surface-2, #f7fafb); border: 1px solid var(--border-subtle, #e2e8f0);">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Requested</span>
                                <span id="previewGross">€0.00</span>
                            </div>
                            @if($platformChargePercent > 0)
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted small">Fee ({{ $platformChargePercent }}%)</span>
                                    <span id="previewFee">€0.00</span>
                                </div>
                            @endif
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold">You will receive</span>
                                <strong class="fs-5" style="color: var(--brand-primary, #1a585e);" id="previewAmount">€0.00</strong>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="paymentMethod">Payment Method</label>
                            <select name="payment_method" id="paymentMethod" class="form-select" required @disabled($formBlocked)>
                                @if(! $payoutLocked)
                                    <option value="">Select</option>
                                    @foreach($methodLabels as $value => $label)
                                        <option value="{{ $value }}" @selected($preferredMethod === $value)>{{ $label }}</option>
                                    @endforeach
                                @else
                                    @php
                                        $selectedMethod = in_array($preferredMethod, $availableMethods, true)
                                            ? $preferredMethod
                                            : ($availableMethods[0] ?? null);
                                    @endphp
                                    @forelse($availableMethods as $value)
                                        <option value="{{ $value }}" @selected($selectedMethod === $value)>
                                            {{ $methodLabels[$value] ?? ucfirst($value) }}
                                        </option>
                                    @empty
                                        <option value="" selected disabled>No saved payout methods</option>
                                    @endforelse
                                @endif
                            </select>
                            @if($payoutLocked)
                                <div class="form-text">Choose a saved payout method. Details are locked — contact support to change them.</div>
                                @if(count($availableMethods) > 0)
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        @foreach($availableMethods as $value)
                                            @if(!empty($methodSummaries[$value]))
                                                <span class="badge rounded-pill text-bg-light border fw-normal">{{ $methodSummaries[$value] }}</span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </div>

                        <div id="bankFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control" value="{{ $payoutProfile['bank_name'] ?? '' }}" @disabled($payoutLocked || $formBlocked)>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Account Holder</label>
                                <input type="text" name="account_holder" class="form-control" value="{{ $payoutProfile['bank_holder_name'] ?? '' }}" @disabled($payoutLocked || $formBlocked)>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">IBAN / Account Number</label>
                                <input type="text" name="account_number" class="form-control" value="{{ $payoutProfile['bank_account'] ?? '' }}" @disabled($payoutLocked || $formBlocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm IBAN / Account Number</label>
                                    <input type="text" name="account_number_confirm" class="form-control" autocomplete="off" @disabled($formBlocked)>
                                </div>
                            @endunless
                            <div class="mb-3">
                                <label class="form-label small">SWIFT / BIC <span class="text-muted">(optional)</span></label>
                                <input type="text" name="swift_code" class="form-control" value="{{ $payoutProfile['bank_swift'] ?? '' }}" @disabled($payoutLocked || $formBlocked)>
                            </div>
                        </div>

                        <div id="paypalFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">PayPal Email</label>
                                <input type="email" name="paypal_email" class="form-control" value="{{ $payoutProfile['paypal_email'] ?? '' }}" @disabled($payoutLocked || $formBlocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm PayPal Email</label>
                                    <input type="email" name="paypal_email_confirm" class="form-control" autocomplete="off" @disabled($formBlocked)>
                                </div>
                            @endunless
                        </div>

                        <div id="wiseFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">Wise Email</label>
                                <input type="email" name="wise_email" class="form-control" value="{{ $payoutProfile['wise_email'] ?? '' }}" @disabled($payoutLocked || $formBlocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm Wise Email</label>
                                    <input type="email" name="wise_email_confirm" class="form-control" autocomplete="off" @disabled($formBlocked)>
                                </div>
                            @endunless
                        </div>

                        <div id="cryptoFields" class="d-none payout-fields">
                            <div class="mb-3">
                                <label class="form-label small">Coin Type</label>
                                <select name="crypto_type" class="form-select" @disabled($payoutLocked || $formBlocked)>
                                    @foreach(['BTC' => 'Bitcoin (BTC)', 'ETH' => 'Ethereum (ETH)', 'USDT' => 'Tether (USDT)', 'BNB' => 'Binance Coin (BNB)'] as $code => $label)
                                        <option value="{{ $code }}" @selected(($payoutProfile['crypto_type'] ?? 'USDT') === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Wallet Address</label>
                                <input type="text" name="wallet_address" class="form-control" value="{{ $payoutProfile['crypto_wallet'] ?? '' }}" @disabled($payoutLocked || $formBlocked) autocomplete="off">
                            </div>
                            @unless($payoutLocked)
                                <div class="mb-3">
                                    <label class="form-label small">Confirm Wallet Address</label>
                                    <input type="text" name="wallet_address_confirm" class="form-control" autocomplete="off" @disabled($formBlocked)>
                                </div>
                            @endunless
                        </div>

                        @unless($payoutLocked)
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" value="1" id="detailsConfirmed" name="details_confirmed" required @disabled($formBlocked)>
                                <label class="form-check-label small" for="detailsConfirmed">
                                    I have double-checked these payout details. I understand they cannot be changed later without contacting support.
                                </label>
                            </div>
                        @else
                            <input type="hidden" name="details_confirmed" value="1">
                        @endunless

                        <button type="button" id="submitWithdrawBtn" class="btn btn-primary btn-lg w-100" @disabled($formBlocked)>
                            <i class="fa fa-paper-plane me-2"></i>Request Withdrawal
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Recent Withdrawals</h5>
                            @if($payoutLocked && count($availableMethods) > 0)
                                <p class="small text-muted mb-0 mt-1">
                                    Saved methods:
                                    {{ collect($availableMethods)->map(fn ($m) => $methodLabels[$m] ?? ucfirst($m))->implode(', ') }}
                                </p>
                            @endif
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ref</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Pays to</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="withdrawalsHistoryBody">
                                @forelse($recentWithdrawals as $w)
                                    @php
                                        $statusClass = match ($w->status) {
                                            'completed' => 'status-paid',
                                            'cancelled' => 'status-rejected',
                                            'processing' => 'status-pending',
                                            default => 'status-pending',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="small fw-semibold">WD-{{ $w->id }}</td>
                                        <td class="small">{{ $w->created_at->format('M d, Y') }}</td>
                                        <td class="fw-semibold">
                                            €{{ number_format($w->amount, 2) }}
                                            @if((float) $w->fee > 0)
                                                <div class="small text-muted">Fee €{{ number_format($w->fee, 2) }} · Net €{{ number_format($w->net_amount, 2) }}</div>
                                            @endif
                                        </td>
                                        <td class="small text-muted">{{ $w->destination_snippet }}</td>
                                        <td><span class="badge {{ $statusClass }}">{{ $w->publisher_status_label }}</span></td>
                                        <td class="text-end">
                                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                                @if(filled($w->destination_copy_text))
                                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-copy-destination" data-copy="{{ e($w->destination_copy_text) }}">Copy</button>
                                                @endif
                                                @if($w->isCancellableByPublisher())
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-cancel-withdrawal" data-id="{{ $w->id }}">Cancel</button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="fa fa-receipt fa-2x mb-2 d-block opacity-50"></i>
                                            No withdrawal requests yet
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div id="withdrawalsPagination" class="p-3 border-top d-none"></div>
                </div>
            </div>

            <div class="ui-callout ui-callout--info mt-3 mb-0">
                <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                <div class="ui-callout__body">Withdrawals are processed within 1–2 business days. Minimum request: €{{ number_format($minWithdrawalAmount, 2) }}.</div>
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
.ui-callout--info .ui-callout__icon { color: var(--brand-ink-muted, #697078); }
.ui-callout--attention .ui-callout__icon { color: var(--brand-danger, #dc2626); }
.ui-callout__body { flex: 1 1 auto; min-width: 0; }
.table td { vertical-align: middle; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amount');
    const previewAmount = document.getElementById('previewAmount');
    const previewGross = document.getElementById('previewGross');
    const previewFee = document.getElementById('previewFee');
    const paymentMethod = document.getElementById('paymentMethod');
    const submitBtn = document.getElementById('submitWithdrawBtn');
    const form = document.getElementById('withdrawForm');
    const maxAmount = {{ json_encode((float) $availableBalance) }};
    const minAmount = {{ json_encode((float) $minWithdrawalAmount) }};
    const feePercent = {{ json_encode((float) $platformChargePercent) }};
    const payoutLocked = @json((bool) $payoutLocked);
    const formBlocked = @json((bool) $formBlocked);
    const historyUrl = @json(route('publisher.withdrawals.history'));
    const cancelUrlTemplate = @json(url('/publisher/withdrawals/__ID__/cancel'));
    const csrfToken = form.querySelector('[name=_token]').value;
    let submitting = false;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function money(n) {
        return `€${(Number(n) || 0).toFixed(2)}`;
    }

    function calcNet(gross) {
        const fee = Math.round((gross * feePercent) / 100 * 100) / 100;
        const net = Math.round((gross - fee) * 100) / 100;
        return { fee, net };
    }

    function updatePreview() {
        let amount = parseFloat(amountInput.value) || 0;
        if (amount > maxAmount) amount = maxAmount;
        if (amount < 0) amount = 0;
        const { fee, net } = calcNet(amount);
        if (previewGross) previewGross.textContent = money(amount);
        if (previewFee) previewFee.textContent = money(fee);
        previewAmount.textContent = money(net);
    }

    function currentMethod() {
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
        });
    }

    function summaryHtml(amount, method) {
        const { fee, net } = calcNet(amount);
        let details = '';
        if (method === 'bank') {
            details = `<p class="mb-1"><strong>Bank:</strong> ${escapeHtml(form.bank_name?.value)}</p>
                       <p class="mb-1"><strong>Holder:</strong> ${escapeHtml(form.account_holder?.value)}</p>
                       <p class="mb-1"><strong>Account:</strong> ${escapeHtml(form.account_number?.value)}</p>`;
        } else if (method === 'paypal') {
            details = `<p class="mb-1"><strong>PayPal:</strong> ${escapeHtml(form.paypal_email?.value)}</p>`;
        } else if (method === 'wise') {
            details = `<p class="mb-1"><strong>Wise:</strong> ${escapeHtml(form.wise_email?.value)}</p>`;
        } else if (method === 'crypto') {
            details = `<p class="mb-1"><strong>Coin:</strong> ${escapeHtml(form.crypto_type?.value)}</p>
                       <p class="mb-1"><strong>Wallet:</strong> ${escapeHtml(form.wallet_address?.value)}</p>`;
        }
        const feeLine = feePercent > 0
            ? `<p class="mb-1"><strong>Fee (${escapeHtml(feePercent)}%):</strong> ${money(fee)}</p>`
            : '';
        return `
            <div style="text-align:left">
                <p class="mb-1"><strong>Requested:</strong> ${money(amount)}</p>
                ${feeLine}
                <p><strong>You will receive:</strong> ${money(net)}</p>
                <hr>
                ${details}
                ${!payoutLocked ? '<p class="text-muted small mt-2 mb-0">These payout details will lock after this request. Contact support to change them later.</p>' : ''}
            </div>`;
    }

    function validateForm() {
        if (formBlocked) {
            showError(@json($hasDebt
                ? 'Withdrawals are blocked while you have outstanding clawback debt.'
                : 'You need at least €'.number_format($minWithdrawalAmount, 2).' withdrawable balance.'));
            return false;
        }

        const amount = parseFloat(amountInput.value) || 0;
        const method = currentMethod();

        if (amount < minAmount) {
            showError(`Minimum withdrawal amount is €${minAmount.toFixed(2)}.`);
            return false;
        }
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

    function statusBadgeClass(status) {
        if (status === 'completed') return 'status-paid';
        if (status === 'cancelled') return 'status-rejected';
        return 'status-pending';
    }

    function formatDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return escapeHtml(iso);
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    async function loadHistory(page = 1) {
        const body = document.getElementById('withdrawalsHistoryBody');
        const pager = document.getElementById('withdrawalsPagination');
        body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr>';
        try {
            const res = await fetch(`${historyUrl}?page=${page}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed');
            const pageData = data.data;
            const rows = pageData.data || [];
            if (!rows.length) {
                body.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">
                    <i class="fa fa-receipt fa-2x mb-2 d-block opacity-50"></i>No withdrawal requests yet
                </td></tr>`;
                pager.classList.add('d-none');
                return;
            }
            body.innerHTML = rows.map(w => {
                const feeNote = Number(w.fee) > 0
                    ? `<div class="small text-muted">Fee ${money(w.fee)} · Net ${money(w.net_amount)}</div>`
                    : (Number(w.net_amount) !== Number(w.amount)
                        ? `<div class="small text-muted">Net ${money(w.net_amount)}</div>`
                        : '');
                const cancelBtn = w.cancellable
                    ? `<button type="button" class="btn btn-sm btn-outline-danger btn-cancel-withdrawal" data-id="${escapeHtml(w.id)}">Cancel</button>`
                    : '';
                const copyBtn = w.destination_copy_text
                    ? `<button type="button" class="btn btn-sm btn-outline-secondary btn-copy-destination" data-copy="${escapeHtml(w.destination_copy_text)}">Copy</button>`
                    : '';
                return `<tr>
                    <td class="small fw-semibold">${escapeHtml(w.reference)}</td>
                    <td class="small">${formatDate(w.created_at)}</td>
                    <td class="fw-semibold">${money(w.amount)}${feeNote}</td>
                    <td class="small text-muted">${escapeHtml(w.destination_snippet)}</td>
                    <td><span class="badge ${statusBadgeClass(w.status)}">${escapeHtml(w.status_label)}</span></td>
                    <td class="text-end"><div class="d-flex gap-1 justify-content-end flex-wrap">${copyBtn}${cancelBtn}</div></td>
                </tr>`;
            }).join('');

            if (pageData.last_page > 1) {
                pager.classList.remove('d-none');
                let links = '';
                for (let p = 1; p <= pageData.last_page; p++) {
                    links += `<button type="button" class="btn btn-sm ${p === pageData.current_page ? 'btn-primary' : 'btn-outline-secondary'} me-1 btn-history-page" data-page="${p}">${p}</button>`;
                }
                pager.innerHTML = links;
            } else {
                pager.classList.add('d-none');
            }
        } catch (e) {
            body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load history.</td></tr>';
        }
    }

    amountInput?.addEventListener('input', updatePreview);
    paymentMethod?.addEventListener('change', togglePaymentFields);
    updatePreview();
    togglePaymentFields();
    loadHistory(1);

    document.getElementById('withdrawalsPagination')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-history-page');
        if (!btn) return;
        loadHistory(Number(btn.dataset.page) || 1);
    });

    document.getElementById('withdrawalsHistoryBody')?.addEventListener('click', async (e) => {
        const copyBtn = e.target.closest('.btn-copy-destination');
        if (copyBtn) {
            try {
                await navigator.clipboard.writeText(copyBtn.dataset.copy || '');
                Swal.fire({ icon: 'success', title: 'Copied', timer: 1200, showConfirmButton: false });
            } catch (_) {
                showError('Could not copy to clipboard.');
            }
            return;
        }

        const cancelBtn = e.target.closest('.btn-cancel-withdrawal');
        if (!cancelBtn) return;
        const id = cancelBtn.dataset.id;
        const confirm = await Swal.fire({
            title: 'Cancel this withdrawal?',
            text: 'The full amount will be returned to your wallet.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel',
        });
        if (!confirm.isConfirmed) return;

        try {
            const res = await fetch(cancelUrlTemplate.replace('__ID__', id), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (!data.success) {
                showError(data.message || 'Cancel failed.');
                return;
            }
            await Swal.fire({ icon: 'success', title: 'Cancelled', text: data.message });
            window.location.reload();
        } catch (_) {
            showError('Network error. Please try again.');
        }
    });

    submitBtn?.addEventListener('click', async function() {
        if (submitting || formBlocked) return;
        if (!validateForm()) return;

        const amount = parseFloat(amountInput.value);
        const method = currentMethod();
        const result = await Swal.fire({
            title: 'Confirm withdrawal',
            html: summaryHtml(amount, method),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, withdraw',
            cancelButtonText: 'Cancel'
        });

        if (!result.isConfirmed) return;

        submitting = true;
        submitBtn.disabled = true;

        Swal.fire({
            title: 'Submitting…',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const formData = new FormData(form);
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
                    'X-CSRF-TOKEN': csrfToken,
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
                });
                window.location.reload();
            } else {
                submitting = false;
                submitBtn.disabled = formBlocked;
                showError(data.message || 'Withdrawal failed.');
            }
        } catch (e) {
            submitting = false;
            submitBtn.disabled = formBlocked;
            showError('Network error. Please try again.');
        }
    });
});
</script>
@endsection
