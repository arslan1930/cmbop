@php
    $compact = $compact ?? false;
    $brief = $brief ?? false;
    $showMethods = $showMethods ?? true;
    $asset = fn (string $file) => asset('assets/img/payments/'.$file);
    $paypalConfigured = false;
    try {
        $paypalConfigured = app(\App\Services\PaypalCheckoutService::class)->configured();
    } catch (\Throwable) {
        $paypalConfigured = false;
    }
    $refundUrl = url('/refund-policy');
    try {
        $refundUrl = function_exists('localized_url')
            ? localized_url('refund-policy')
            : url('/refund-policy');
    } catch (\Throwable) {
        $refundUrl = url('/refund-policy');
    }
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
            <img class="payment-trust__logo payment-trust__logo--card payment-trust__logo--visa" src="{{ $asset('visa.svg') }}" alt="Visa" title="Visa" width="48" height="30" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--card payment-trust__logo--mastercard" src="{{ $asset('mastercard.svg') }}" alt="Mastercard" title="Mastercard" width="40" height="30" loading="lazy" decoding="async">
            @if(config('billing.show_apple_pay'))
                <img class="payment-trust__logo payment-trust__logo--card payment-trust__logo--apple" src="{{ $asset('apple-pay.svg') }}" alt="Apple Pay" title="Apple Pay" width="56" height="30" loading="lazy" decoding="async">
            @endif
            <img class="payment-trust__logo payment-trust__logo--bank" src="{{ $asset('bank.svg') }}" alt="Bank transfer" title="Bank transfer" width="72" height="36" loading="lazy" decoding="async">
            <img class="payment-trust__logo payment-trust__logo--paypal{{ $paypalConfigured ? '' : ' is-offline' }}" src="{{ $asset('paypal.png') }}" alt="PayPal" title="{{ $paypalConfigured ? 'PayPal' : 'PayPal (temporarily unavailable)' }}" width="48" height="47" loading="lazy" decoding="async">
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
            object-fit: contain;
            flex-shrink: 0;
        }
        .payment-trust__logo--visa {
            width: 42px;
            height: 26px;
            aspect-ratio: 48 / 30;
        }
        .payment-trust__logo--mastercard {
            width: 35px;
            height: 26px;
            aspect-ratio: 40 / 30;
        }
        .payment-trust__logo--apple {
            width: 49px;
            height: 26px;
            aspect-ratio: 56 / 30;
        }
        .payment-trust__logo--bank {
            width: 64px;
            height: 32px;
            aspect-ratio: 72 / 36;
        }
        .payment-trust__logo--wise {
            width: 72px;
            height: 16px;
            aspect-ratio: 72 / 16;
        }
        .payment-trust__logo--paypal {
            width: 41px;
            height: 40px;
            aspect-ratio: 48 / 47;
        }
        .payment-trust__logo.is-offline {
            opacity: 0.45;
        }
        .payment-trust__logo--crypto {
            width: 22px;
            height: 22px;
            aspect-ratio: 1 / 1;
        }
        .payment-trust--compact .payment-trust__logo--visa {
            width: 35px;
            height: 22px;
        }
        .payment-trust--compact .payment-trust__logo--mastercard {
            width: 29px;
            height: 22px;
        }
        .payment-trust--compact .payment-trust__logo--apple {
            width: 41px;
            height: 22px;
        }
        .payment-trust--compact .payment-trust__logo--bank {
            width: 56px;
            height: 28px;
        }
        .payment-trust--compact .payment-trust__logo--crypto {
            width: 20px;
            height: 20px;
        }
        .payment-trust--compact .payment-trust__logo--paypal {
            width: 33px;
            height: 32px;
        }
        .payment-trust--compact .payment-trust__logo--wise {
            width: 72px;
            height: 16px;
        }
        .payment-trust--compact {
            font-size: 11px;
        }
    </style>
@endonce
