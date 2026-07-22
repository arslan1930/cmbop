@php
    $compact = $compact ?? false;
    $showMethods = $showMethods ?? true;
    $asset = fn (string $file) => asset('assets/img/payments/'.$file);
    $refundUrl = function_exists('localized_url')
        ? localized_url('refund-policy')
        : url('/refund-policy');
@endphp
<div class="payment-trust {{ $compact ? 'payment-trust--compact' : '' }}" role="note" aria-label="Secure payments">
    <div class="payment-trust__secure">
        <i class="fas fa-lock" aria-hidden="true"></i>
        <span>
            Payments secured by <strong>Stripe</strong>. Card details never touch our servers.
            <a href="{{ $refundUrl }}" class="payment-trust__refund-link">See refund policy</a>
        </span>
    </div>
    @if($showMethods)
        <div class="payment-trust__methods" aria-label="Accepted payment methods">
            <img class="payment-trust__logo payment-trust__logo--card" src="{{ $asset('visa.svg') }}" alt="Visa" title="Visa" width="48" height="30" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--card" src="{{ $asset('mastercard.svg') }}" alt="Mastercard" title="Mastercard" width="40" height="30" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--card" src="{{ $asset('amex.svg') }}" alt="American Express" title="American Express" width="48" height="30" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--stripe" src="{{ $asset('stripe.svg') }}" alt="Stripe" title="Stripe" width="56" height="24" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--wise" src="{{ $asset('wise.png') }}" alt="Wise" title="Wise" width="72" height="16" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--crypto" src="{{ $asset('bitcoin.svg') }}" alt="Bitcoin" title="Bitcoin" width="24" height="24" loading="lazy" decoding="async">
        </div>
    @endif
</div>

@once
    <style>
        .payment-trust {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px 16px;
            font-size: 12px;
            color: #4b5563;
        }
        .payment-trust__secure {
            display: flex;
            align-items: center;
            gap: 8px;
            line-height: 1.35;
        }
        .payment-trust__secure .fa-lock {
            color: #185054;
            flex-shrink: 0;
        }
        .payment-trust__refund-link {
            color: #185054;
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: 2px;
            white-space: nowrap;
        }
        .payment-trust__refund-link:hover {
            color: #3faeb2;
        }
        .payment-trust__methods {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 12px;
        }
        .payment-trust__logo {
            display: block;
            width: auto;
            height: 22px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .payment-trust__logo--card {
            height: 26px;
        }
        .payment-trust__logo--stripe {
            height: 20px;
        }
        .payment-trust__logo--wise {
            height: 16px;
        }
        .payment-trust__logo--crypto {
            height: 22px;
            width: 22px;
        }
        .payment-trust--compact .payment-trust__logo {
            height: 18px;
        }
        .payment-trust--compact .payment-trust__logo--card {
            height: 22px;
        }
        .payment-trust--compact .payment-trust__logo--crypto {
            height: 20px;
            width: 20px;
        }
        .payment-trust--compact {
            font-size: 11px;
        }
    </style>
@endonce
