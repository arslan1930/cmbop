@extends('publisher.layouts.app')

@section('title', 'Balance')

@section('content')
@php
    $publisher = $publisher ?? \App\Models\Wallet::emptyRoleSnapshot();
    $advertiser = $advertiser ?? \App\Models\Wallet::emptyRoleSnapshot();
    $minWithdrawalAmount = (float) ($minWithdrawalAmount ?? config('billing.withdrawal_min_amount', 20));
    $canWithdraw = (bool) ($canWithdraw ?? false);
    $showAdvertiserWallet = (bool) ($showAdvertiserWallet ?? false);
    $canMove = (bool) ($canMove ?? false);
    $roleMoveMinAmount = max(0.01, round((float) ($roleMoveMinAmount ?? config('billing.role_move.min_amount', 0.01)), 2));
    $publisherDebt = (float) ($publisher['debt'] ?? $publisherDebt ?? 0);
    $withdrawDisabledReason = $publisherDebt > 0
        ? 'Withdrawals are blocked while you have outstanding clawback debt of '.format_money($publisherDebt).'. Contact support to resolve this before withdrawing.'
        : 'You need at least '.format_money($minWithdrawalAmount).' withdrawable balance to request a payout. Available now: '.format_money($publisher['withdrawable']).'.';
    $moveDisabledReason = $publisherDebt > 0
        ? 'Moves are blocked while you have outstanding clawback debt of '.format_money($publisherDebt).'. Contact support to resolve this before moving earnings.'
        : 'No withdrawable earnings to move. Bonus credit cannot be moved.';
    $supportEmail = $supportEmail ?? config('email_notifications.brand.support_email', config('mail.from.address'));
@endphp
<link rel="stylesheet" href="{{ asset('assets/css/publisher-notice.css') }}?v={{ @filemtime(public_path('assets/css/publisher-notice.css')) ?: '1' }}">
<link rel="stylesheet" href="{{ asset('assets/css/publisher-balance.css') }}?v={{ @filemtime(public_path('assets/css/publisher-balance.css')) ?: '1' }}">

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="mb-1 fw-semibold">Balance</h1>
            <p class="text-muted mb-0">
                @if($showAdvertiserWallet)
                    Withdraw earnings, or move withdrawable cash to your advertiser wallet to spend on placements.
                @else
                    Publisher earnings on this wallet.
                @endif
            </p>
        </div>
    </div>

    @if($publisherDebt > 0)
        <div class="publisher-needs-alert mb-4" role="alert">
            <div class="d-md-flex justify-content-md-between">
                <p class="publisher-needs-alert__copy mb-0">
                    <svg class="publisher-needs-alert__icon" xmlns="http://www.w3.org/2000/svg" viewBox="118 4 72 244" fill="currentColor" aria-hidden="true" focusable="false"><g transform="translate(1.4065934065934016 1.4065934065934016) scale(2.81 2.81)"><path d="M 49.083 71.489 l 5.776 -21.96 l 4.186 -15.247 c 3.497 -16.18 -32.704 -2.439 -38.002 1.695 l 0.425 4.853 c 4.824 -3.395 23.091 -7.744 19.449 4.275 l -1.634 6.135 l 0 0 l -8.329 31.071 c -3.497 16.18 32.704 2.439 38.002 -1.695 l -0.425 -4.853 C 63.708 79.159 45.441 83.508 49.083 71.489 z"/><circle cx="53.871" cy="11.201" r="11.201"/></g></svg>
                    <strong>Outstanding clawback debt</strong>
                    <span class="ms-1">{{ format_money($publisherDebt) }}. Withdrawals and moves to your advertiser wallet are blocked until support clears this debt.</span>
                </p>
                <p class="mb-0 mt-3 mt-md-0 ms-md-4">
                    <a class="publisher-needs-alert__link" href="mailto:{{ $supportEmail }}">Contact support</a>
                </p>
            </div>
        </div>
    @endif

    <div class="pb-wallet-grid mb-4">
        <article class="pb-wallet-card" aria-labelledby="publisherEarningsLabel">
            <div class="pb-wallet-card__header">
                <span class="pb-wallet-card__label" id="publisherEarningsLabel">Publisher earnings</span>
                <x-glass-tip
                    title="Publisher earnings"
                    body="Cash you can withdraw or move to your advertiser wallet. Bonus is for purchases only and is not included. Amounts on hold have already left this total. Clawback debt blocks withdrawals and moves; it does not reduce this number."
                    label="About publisher earnings"
                    placement="top" />
            </div>
            <div class="pb-wallet-card__value" id="publisherBalance">{{ format_money($publisher['withdrawable']) }}</div>
            <p class="pb-wallet-card__sub">Withdrawable</p>

            @if((float) $publisher['reserved'] > 0 || (float) $publisher['bonus'] > 0 || (float) $publisher['debt'] > 0)
                <div class="pb-wallet-card__chips">
                    @if((float) $publisher['reserved'] > 0)
                        <div class="pb-wallet-card__chip">
                            <span class="pb-wallet-card__chip-label">On hold</span>
                            <span class="pb-wallet-card__chip-value">{{ format_money($publisher['reserved']) }}</span>
                        </div>
                    @endif
                    @if((float) $publisher['bonus'] > 0)
                        <div class="pb-wallet-card__chip pb-wallet-card__chip--bonus">
                            <span class="pb-wallet-card__chip-label">Bonus</span>
                            <span class="pb-wallet-card__chip-value">{{ format_money($publisher['bonus']) }}</span>
                        </div>
                    @endif
                    @if((float) $publisher['debt'] > 0)
                        <div class="pb-wallet-card__chip pb-wallet-card__chip--debt">
                            <span class="pb-wallet-card__chip-label">Debt</span>
                            <span class="pb-wallet-card__chip-value">{{ format_money($publisher['debt']) }}</span>
                        </div>
                    @endif
                </div>
            @endif

            @if($canWithdraw)
                <p class="pb-wallet-card__status pb-wallet-card__status--ready">Ready to withdraw</p>
            @else
                <div class="publisher-needs-alert mb-0" role="status">
                    <p class="publisher-needs-alert__copy mb-0" id="withdrawBlockedReason">
                        <svg class="publisher-needs-alert__icon" xmlns="http://www.w3.org/2000/svg" viewBox="118 4 72 244" fill="currentColor" aria-hidden="true" focusable="false"><g transform="translate(1.4065934065934016 1.4065934065934016) scale(2.81 2.81)"><path d="M 49.083 71.489 l 5.776 -21.96 l 4.186 -15.247 c 3.497 -16.18 -32.704 -2.439 -38.002 1.695 l 0.425 4.853 c 4.824 -3.395 23.091 -7.744 19.449 4.275 l -1.634 6.135 l 0 0 l -8.329 31.071 c -3.497 16.18 32.704 2.439 38.002 -1.695 l -0.425 -4.853 C 63.708 79.159 45.441 83.508 49.083 71.489 z"/><circle cx="53.871" cy="11.201" r="11.201"/></g></svg>
                        {{ $withdrawDisabledReason }}
                    </p>
                </div>
            @endif

            <div class="pb-wallet-card__actions">
                @if($canWithdraw)
                    <a href="{{ route('publisher.withdraw') }}" class="btn btn-primary" id="withdrawCta">
                        Withdraw
                    </a>
                @else
                    <span class="pb-disabled-wrap" tabindex="0" data-glass-tip data-glass-tip-body="{{ $withdrawDisabledReason }}" data-glass-tip-placement="top">
                        <button type="button" class="btn btn-primary" id="withdrawCta" disabled>
                            Withdraw
                        </button>
                    </span>
                @endif
            </div>

            @if($showAdvertiserWallet)
                <form
                    id="roleMoveForm"
                    class="pb-role-move"
                    method="post"
                    action="{{ route('publisher.balance.transfer') }}"
                    data-url="{{ route('publisher.balance.transfer') }}"
                    data-min="{{ number_format($roleMoveMinAmount, 2, '.', '') }}"
                    data-max="{{ number_format((float) $publisher['withdrawable'], 2, '.', '') }}"
                    data-can-move="{{ $canMove ? '1' : '0' }}"
                    data-blocked-reason="{{ $moveDisabledReason }}"
                    novalidate
                >
                    @csrf
                    <div class="pb-role-move__header">
                        <span class="pb-role-move__label" id="roleMoveLabel">Use for spending</span>
                        <x-glass-tip
                            title="Use for spending"
                            body="Moves withdrawable earnings into your advertiser wallet as Money. No fee. Bonus stays here and cannot be moved. The €20 payout minimum does not apply."
                            label="About using earnings for spending"
                            placement="top" />
                    </div>
                    <p class="pb-role-move__hint">Move withdrawable earnings into your advertiser wallet. No fee. Bonus cannot be moved.</p>
                    <div class="pb-role-move__row">
                        <label class="visually-hidden" for="roleMoveAmount">Amount in euro</label>
                        <div class="input-group">
                            <span class="input-group-text">€</span>
                            <input
                                type="number"
                                id="roleMoveAmount"
                                name="amount"
                                class="form-control"
                                inputmode="decimal"
                                step="0.01"
                                min="{{ number_format($roleMoveMinAmount, 2, '.', '') }}"
                                max="{{ number_format((float) $publisher['withdrawable'], 2, '.', '') }}"
                                placeholder="0.00"
                                @disabled(! $canMove)
                                required
                            >
                        </div>
                        @if($canMove)
                            <button type="button" class="btn btn-outline-secondary" id="roleMoveAllBtn">Move all</button>
                            <button type="submit" class="btn btn-primary" id="roleMoveBtn">Move</button>
                        @else
                            <span class="pb-disabled-wrap" tabindex="0" data-glass-tip data-glass-tip-body="{{ $moveDisabledReason }}" data-glass-tip-placement="top">
                                <button type="submit" class="btn btn-primary" id="roleMoveBtn" disabled>Move</button>
                            </span>
                        @endif
                    </div>
                </form>
            @endif
        </article>

        @if($showAdvertiserWallet)
            <article class="pb-wallet-card" aria-labelledby="advertiserSpendableLabel">
                <div class="pb-wallet-card__header">
                    <span class="pb-wallet-card__label" id="advertiserSpendableLabel">Advertiser (spendable)</span>
                    <x-glass-tip
                        title="Advertiser spendable"
                        body="Money you can spend on placements. Bonus is welcome credit for purchases only and cannot be withdrawn."
                        label="About advertiser spendable"
                        placement="top" />
                </div>
                <div class="pb-wallet-card__value" id="advertiserBalance">{{ format_money($advertiser['spendable']) }}</div>
                <p class="pb-wallet-card__sub">Spendable</p>

                <div class="pb-wallet-card__chips">
                    <div class="pb-wallet-card__chip">
                        <span class="pb-wallet-card__chip-label">Money</span>
                        <span class="pb-wallet-card__chip-value">{{ format_money($advertiser['withdrawable']) }}</span>
                    </div>
                    <div class="pb-wallet-card__chip pb-wallet-card__chip--bonus">
                        <span class="pb-wallet-card__chip-label">Bonus</span>
                        <span class="pb-wallet-card__chip-value">{{ format_money($advertiser['bonus']) }}</span>
                    </div>
                </div>

                @if((float) $advertiser['bonus'] > 0)
                    <p class="pb-wallet-card__note">
                        <strong>Bonus {{ format_money($advertiser['bonus']) }}</strong>
                        (purchases only) — {{ \App\Models\Wallet::PROMOTIONAL_BONUS_MESSAGE }}
                    </p>
                @endif

                <p class="pb-wallet-card__note">Moved earnings arrive here as Money and can be spent in Catalog.</p>

                <div class="pb-wallet-card__actions">
                    <a href="{{ route('advertiser.add-funds') }}" class="btn btn-primary" id="addFundsCta">Add Funds</a>
                    <a href="{{ route('advertiser.catalog') }}" class="btn btn-outline-secondary" id="catalogCta">Catalog</a>
                </div>
            </article>
        @endif
    </div>

    @if(! $showAdvertiserWallet)
        <div class="alert alert-light border mb-0" role="status">
            Withdraw for payouts. Catalog spend uses an advertiser wallet.
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/publisher-balance.js') }}?v={{ @filemtime(public_path('assets/js/publisher-balance.js')) ?: '1' }}"></script>
@endpush
