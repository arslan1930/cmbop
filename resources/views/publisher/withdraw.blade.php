@extends('publisher.layouts.app')

@section('title', 'Withdraw')

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
    $pendingOut = (float) ($pendingOut ?? 0);
    $lifetimeWithdrawn = (float) ($lifetimeWithdrawn ?? 0);
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
    $historyLastPage = (int) ($historyLastPage ?? 1);
    $blockedMessage = $hasDebt
        ? 'Withdrawals are blocked while you have outstanding clawback debt.'
        : 'You need at least €'.number_format($minWithdrawalAmount, 2).' withdrawable balance.';
@endphp
<link rel="stylesheet" href="{{ asset('assets/css/publisher-withdraw.css') }}?v={{ @filemtime(public_path('assets/css/publisher-withdraw.css')) ?: '1' }}">

<div
    id="publisherWithdrawApp"
    class="container-fluid"
    data-max-amount="{{ number_format((float) $availableBalance, 2, '.', '') }}"
    data-min-amount="{{ number_format((float) $minWithdrawalAmount, 2, '.', '') }}"
    data-fee-percent="{{ number_format((float) $platformChargePercent, 2, '.', '') }}"
    data-payout-locked="{{ $payoutLocked ? '1' : '0' }}"
    data-form-blocked="{{ $formBlocked ? '1' : '0' }}"
    data-history-url="{{ route('publisher.withdrawals.history') }}"
    data-cancel-url-template="{{ url('/publisher/withdrawals/__ID__/cancel') }}"
    data-request-url="{{ route('publisher.withdraw.request') }}"
    data-blocked-message="{{ $blockedMessage }}"
    data-promo-message="{{ $promotionalBonusMessage }}"
>
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-8">
            <h2 class="mb-1 fw-semibold">Withdraw</h2>
            <p class="text-muted mb-0">Request a withdrawal of your earnings. Withdrawals are processed within 1–2 business days.</p>
        </div>
        <div class="col-md-4 text-md-end d-flex flex-wrap gap-2 justify-content-md-end">
            <a href="{{ route('publisher.balance') }}" class="btn btn-sm btn-outline-secondary" id="backToBalance">
                Back to Balance
            </a>
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
                        <span class="text-muted small">Withdrawable</span>
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
                        <span class="text-muted small">Pending payout</span>
                        <h3 class="mb-1 fw-bold">€{{ number_format($pendingOut, 2) }}</h3>
                        <p class="text-muted small mb-0">Requested, not paid yet</p>
                    </div>
                    <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#fff;color:#1e293b;border:1px solid #e2e8f0;">
                        <i class="fa fa-clock"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between">
                    <div>
                        <span class="text-muted small">Paid out</span>
                        <h3 class="mb-1 fw-bold">€{{ number_format($lifetimeWithdrawn, 2) }}</h3>
                        <p class="text-muted small mb-0">Completed withdrawals, after fees</p>
                    </div>
                    <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#fff;color:#1e293b;border:1px solid #e2e8f0;">
                        <i class="fa fa-check"></i>
                    </div>
                </div>
            </div>
        </div>
        @if($bonusBalance > 0)
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex justify-content-between">
                        <div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="text-muted small">Bonus</span>
                                <x-glass-tip
                                    title="Bonus"
                                    body="{{ $promotionalBonusMessage }}"
                                    label="About bonus credit"
                                    placement="top" />
                            </div>
                            <h3 class="mb-1 fw-bold">€{{ number_format($bonusBalance, 2) }}</h3>
                            <p class="text-muted small mb-0">Purchases only — cannot withdraw</p>
                        </div>
                        <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#f1f5f9;color: var(--brand-ink-muted, #75787B);border:1px solid #e2e8f0;">
                            <i class="fa fa-gift"></i>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        @if($reservedBalance > 0)
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex justify-content-between">
                        <div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="text-muted small">On hold</span>
                                <x-glass-tip
                                    title="On hold"
                                    body="Amounts on hold have already left this total. They are not withdrawable cash."
                                    label="About amounts on hold"
                                    placement="top" />
                            </div>
                            <h3 class="mb-1 fw-bold">€{{ number_format($reservedBalance, 2) }}</h3>
                            <p class="text-muted small mb-0">Already left withdrawable</p>
                        </div>
                        <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#fff;color:#1e293b;border:1px solid #e2e8f0;">
                            <i class="fa fa-lock"></i>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if($pendingOut > 0)
        <div class="ui-callout ui-callout--info mb-4">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
            <div class="ui-callout__body">
                €{{ number_format($pendingOut, 2) }} is already reserved for open requests. Cancel a pending row to return it.
            </div>
        </div>
    @endif

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
                    <div id="withdrawalsPagination" class="p-3 border-top {{ $historyLastPage > 1 ? '' : 'd-none' }}">
                        @if($historyLastPage > 1)
                            @for($p = 1; $p <= $historyLastPage; $p++)
                                <button type="button" class="btn btn-sm {{ $p === 1 ? 'btn-primary' : 'btn-outline-secondary' }} me-1 btn-history-page" data-page="{{ $p }}">{{ $p }}</button>
                            @endfor
                        @endif
                    </div>
                </div>
            </div>

            <div class="ui-callout ui-callout--info mt-3 mb-0">
                <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
                <div class="ui-callout__body">Withdrawals are processed within 1–2 business days. Minimum request: €{{ number_format($minWithdrawalAmount, 2) }}.</div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('assets/js/publisher-withdraw.js') }}?v={{ @filemtime(public_path('assets/js/publisher-withdraw.js')) ?: '1' }}"></script>
@endpush
