@php
    $compact = $compact ?? false;
    $brief = $brief ?? false;
    $showMethods = $showMethods ?? true;
    $asset = fn (string $file) => asset('assets/img/payments/'.$file);
    $paypalConfigured = app(\App\Services\PaypalCheckoutService::class)->configured();
    $refundUrl = function_exists('localized_url')
        ? localized_url('refund-policy')
        : url('/refund-policy');
@endphp
<div class="payment-trust {{ $compact ? 'payment-trust--compact' : '' }}" role="note" aria-label="Secure payments">
    <div class="payment-trust__secure">
        <i class="fas fa-lock" aria-hidden="true"></i>
        <span>
            Payments secured by <strong>Stripe</strong>.@unless($brief)
                Card details never touch our servers.
            @endunless
            <a href="{{ $refundUrl }}" class="payment-trust__refund-link">See refund policy</a>
        </span>
    </div>
    @if($showMethods)
        <div class="payment-trust__methods" aria-label="Accepted payment methods">
            <img class="payment-trust__logo payment-trust__logo--card" src="{{ $asset('visa.svg') }}" alt="Visa" title="Visa" width="48" height="30" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--card" src="{{ $asset('mastercard.svg') }}" alt="Mastercard" title="Mastercard" width="40" height="30" loading="lazy" decoding="async">
            @if(config('billing.show_apple_pay'))
                <img class="payment-trust__logo payment-trust__logo--card" src="{{ $asset('apple-pay.svg') }}" alt="Apple Pay" title="Apple Pay" width="56" height="30" loading="lazy" decoding="async">
            @endif
            <img class="payment-trust__logo payment-trust__logo--bank" src="{{ $asset('bank.svg') }}" alt="Bank transfer" title="Bank transfer" width="72" height="36" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--paypal{{ $paypalConfigured ? '' : ' is-offline' }}" src="{{ $asset('paypal.svg') }}" alt="PayPal" title="{{ $paypalConfigured ? 'PayPal' : 'PayPal (temporarily unavailable)' }}" width="48" height="16" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--wise" src="{{ $asset('wise.png') }}" alt="Wise" title="Wise" width="72" height="16" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--crypto" src="{{ $asset('bitcoin.svg') }}" alt="Cryptocurrency" title="Cryptocurrency" width="24" height="24" loading="lazy" decoding="async">
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
            color: #1a585e;
            flex-shrink: 0;
        }
        .payment-trust__refund-link {
            color: #1a585e;
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: 2px;
            white-space: nowrap;
        }
        .payment-trust__secure span {
            min-width: 0;
            overflow-wrap: anywhere;
        }
        @media (max-width: 575.98px) {
            .payment-trust__refund-link {
                white-space: normal;
            }
            .payment-trust__methods {
                width: 100%;
            }
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
        .payment-trust__logo--bank {
            width: auto;
            height: 32px;
            max-height: 32px;
        }
        .payment-trust__logo--wise {
            height: 16px;
        }
        .payment-trust__logo--paypal {
            height: 16px;
        }
        .payment-trust__logo.is-offline {
            opacity: 0.45;
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
        .payment-trust--compact .payment-trust__logo--bank {
            height: 28px;
            max-height: 28px;
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
